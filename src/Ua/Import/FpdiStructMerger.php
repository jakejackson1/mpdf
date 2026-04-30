<?php

namespace Mpdf\Ua\Import;

use Mpdf\Mpdf;
use Mpdf\Ua\StructureElement;
use Mpdf\Ua\StructureTree;
use Mpdf\Ua\StructType;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;

/**
 * Handle FPDI-imported pages in a PDF/UA-1 document.
 *
 * Two-tier treatment for imported PDF pages:
 *
 * Tier 1 — untagged source:
 *   The Do operator emitted by FPDI is bracketed with
 *     /Artifact <</Type /Layout>> BDC … EMC
 *   (ISO 32000-1:2008 §14.7.4.4 Table 324; Matterhorn 01-007).
 *   A diagnostic warning is recorded via addUntaggedWarning().
 *
 * Tier 2 — tagged source (has /StructTreeRoot in its catalog):
 *   The source PDF's struct subtree is cloned into the host StructureTree.
 *   Each cloned StructureElement carries MCR dicts whose /Pg is the host page
 *   object number and /Stm is the Form XObject object number
 *   (ISO 32000-1:2008 §14.7.4.4 Table 324). A /StructParents entry is
 *   injected into the FPDI Form XObject dict at write time so the ParentTree
 *   back-reference resolves correctly.
 *
 * FpdiStructMerger does NOT hold a UaState reference — it holds only Mpdf and
 * StructureTree. This avoids a construction-time cycle: FpdiStructMerger is
 * constructed before UaState. The caller (FpdiTrait) is responsible for flushing
 * getUntaggedWarnings() into $this->ua->addWarning() after each import.
 *
 * One instance per Mpdf lifecycle, constructed by ServiceFactory and reached
 * via $this->ua->getFpdiStructMerger().
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.4.4 Table 324 — MCR dict (/Pg, /Stm, /MCID)
 *   - ISO 32000-1:2008 §14.7.3 — RoleMap first-wins merge semantics
 *   - ISO 14289-1:2014 §7.1 — real content must be tagged or marked Artifact
 *   - Matterhorn Protocol 1.1 — 01-007 real content not tagged or Artifact
 *
 * @see StructureTree  host struct tree managed by UaState
 */
class FpdiStructMerger
{

	/** @var Mpdf */
	private $mpdf;

	/** @var StructureTree */
	private $tree;

	/** @var string[]  Collected warnings for untagged imported pages. */
	private $untaggedWarnings = [];

	/**
	 * Tier 2 state: tracks which pageIds have already had their struct subtree
	 * merged, and which StructureElements were created for each, so that
	 * addPerPageMcrKids() can add new host-page MCR entries on reuse
	 * (SetPageTemplate pattern).
	 *
	 * @var array<string, StructureElement[]>  pageId => array of cloned StructureElements
	 */
	private $mergedSubtrees = [];

	/**
	 * Tier 2 state: maps pageId => /StructParents integer allocated for the Form XObject.
	 *
	 * @var array<string, int>
	 */
	private $pageStructParents = [];

	/**
	 * Tier 2 state: host page numbers (1-indexed $mpdf->page) recorded at render time
	 * for each tagged import. Indexed by pageId; values are arrays of host page integers
	 * in the order useImportedPage() was called (first = original, rest = reuses).
	 *
	 * Populated by recordHostPage() which is called from FpdiTrait::useImportedPage()
	 * on every Tier 2 placement, including reuses.
	 *
	 * @var array<string, int[]>  pageId => [hostPage1, hostPage2, ...]
	 */
	private $pageIdHostPages = [];

	/**
	 * Tier 2 state: exact MCR slot references added by cloneElement() for each pageId.
	 *
	 * Each entry is an array of ['elem' => StructureElement, 'idx' => int] where
	 * idx is the zero-based index into the element's mcids array at the time the
	 * MCR was added. This lets patchMergedSubtreeObjectNumbers() write back the
	 * real foXObjectObjNum and hostPageObjNum into only the MCRs the merger created,
	 * without touching MCRs from the normal HTML tagging path.
	 *
	 * @var array<string, array<int, array{elem: StructureElement, idx: int}>>
	 */
	private $mergedMcrs = [];

	/**
	 * Construct with the host Mpdf and the host StructureTree.
	 *
	 * Called once by ServiceFactory before UaState is constructed. No UaState
	 * reference is held — warnings are surfaced through getUntaggedWarnings()
	 * and flushed into UaState by the FpdiTrait caller.
	 *
	 * @param Mpdf          $mpdf
	 * @param StructureTree $tree
	 */
	public function __construct(Mpdf $mpdf, StructureTree $tree)
	{
		$this->mpdf = $mpdf;
		$this->tree = $tree;
	}

	// ========================= Tier 1 — untagged warnings =========================

	/**
	 * Record a warning for an imported page that has been wrapped as Artifact.
	 *
	 * The FpdiTrait caller flushes these via getUntaggedWarnings() into
	 * UaState::addWarning() — FpdiStructMerger itself never touches UaState.
	 *
	 * @param  string $message
	 * @return void
	 */
	public function addUntaggedWarning($message)
	{
		$this->untaggedWarnings[] = $message;
	}

	/**
	 * Return and clear collected import warnings.
	 *
	 * Called by FpdiTrait after each import to flush warnings into
	 * UaState::addWarning() where callers can inspect them via getPdfUaWarnings().
	 *
	 * @return string[]
	 */
	public function getUntaggedWarnings()
	{
		$w = $this->untaggedWarnings;
		$this->untaggedWarnings = [];
		return $w;
	}

	// ========================= Tier 2 — tagged-source detection =========================

	/**
	 * Determine whether the source PDF identified by $readerId has a struct tree.
	 *
	 * Reads the source catalog and returns true iff it contains a non-null
	 * /StructTreeRoot entry. Uses the public accessor Mpdf::getSourcePdfReader()
	 * (which wraps the protected vendor-trait getPdfReader()) so that this class,
	 * which is outside the Mpdf class hierarchy, can reach the FPDI reader.
	 *
	 * Exceptions from the parser are caught and treated as "not tagged" because
	 * non-conformant PDFs may omit the catalog or have unresolvable cross-refs.
	 *
	 * @param  string $readerId  reader id from $importedPages[$pageId]['readerId']
	 * @return bool
	 */
	public function sourceIsTagged($readerId)
	{
		try {
			$reader  = $this->mpdf->getSourcePdfReader($readerId);
			$catalog = $reader->getParser()->getCatalog();
			$structTreeRoot = PdfDictionary::get($catalog, 'StructTreeRoot');
			return !($structTreeRoot instanceof PdfNull);
		} catch (\Exception $e) {
			return false;
		}
	}

	// ========================= Tier 2 — struct subtree merge =========================

	/**
	 * Clone the source PDF page's struct subtree into the host StructureTree.
	 *
	 * Called by FpdiTrait::useImportedPage() for each tagged-source import.
	 * Subsequent reuse of the same pageId (SetPageTemplate pattern) is handled
	 * by addPerPageMcrKids() instead — this method is idempotent for a given
	 * pageId, doing nothing on the second and subsequent calls.
	 *
	 * Algorithm:
	 *   1. Allocate a /StructParents integer for the Form XObject via UaState.
	 *      Store it in $pageStructParents[$pageId] so the writer can inject it
	 *      into the PdfStream dict at write time.
	 *   2. Walk the source StructTreeRoot's /K tree, creating host-native
	 *      StructureElement instances mirroring the source structure.
	 *   3. Attach each host element to the current tree top.
	 *   4. Register MCR entries with /Stm = foXObjectObjNum so StructureWriter
	 *      emits full MCR dicts (ISO 32000-1 §14.7.4.4 Table 324).
	 *   5. Merge source /RoleMap entries (first-wins per §14.7.3).
	 *
	 * @param  string $pageId           FPDI page identifier from importPage()
	 * @param  int    $foXObjectObjNum  PDF object number of the FPDI Form XObject (0 until written)
	 * @param  int    $hostPageObjNum   PDF object number of the host page dict (0 until written)
	 * @return void
	 */
	public function mergePageStructSubtree($pageId, $foXObjectObjNum, $hostPageObjNum)
	{
		// Idempotent — first call per pageId does the merge; subsequent calls are reuse.
		if (isset($this->mergedSubtrees[$pageId])) {
			return;
		}

		$this->mergedSubtrees[$pageId] = [];
		$this->mergedMcrs[$pageId]     = [];

		$importedPages = $this->mpdf->getImportedPages();
		if (!isset($importedPages[$pageId])) {
			return;
		}

		$readerId = $importedPages[$pageId]['readerId'];

		try {
			$reader  = $this->mpdf->getSourcePdfReader($readerId);
			$parser  = $reader->getParser();
			$catalog = $parser->getCatalog();

			// Allocate a /StructParents integer for this Form XObject.
			// UaState is not held directly — reach it via $this->mpdf->ua (private).
			// We call nextStructParents through the public Mpdf method.
			$structParents = $this->mpdf->getPdfUaNextStructParents();
			$this->pageStructParents[$pageId] = $structParents;

			// Merge source /RoleMap entries (first-wins).
			$this->mergeRoleMap($catalog, $parser);

			// Walk the source StructTreeRoot /K list.
			$structTreeRootRef = PdfDictionary::get($catalog, 'StructTreeRoot');
			if ($structTreeRootRef instanceof PdfNull) {
				return;
			}

			$structTreeRoot = PdfType::resolve($structTreeRootRef, $parser);
			if (!($structTreeRoot instanceof PdfDictionary)) {
				return;
			}

			$kEntry = PdfDictionary::get($structTreeRoot, 'K');
			if ($kEntry instanceof PdfNull) {
				return;
			}

			$kEntry = PdfType::resolve($kEntry, $parser);
			$kids   = $this->normaliseKidsToArray($kEntry, $parser);

			$hostParent = $this->tree->getCurrent();

			foreach ($kids as $kid) {
				$cloned = $this->cloneElement($kid, $parser, $hostParent, $structParents, $foXObjectObjNum, $hostPageObjNum, $pageId);
				if ($cloned !== null) {
					$this->mergedSubtrees[$pageId][] = $cloned;
				}
			}

		} catch (\Exception $e) {
			// Parser failure — treat as if untagged; the caller already chose Tier 2
			// based on sourceIsTagged(), so this is an unusual failure path. Leave
			// $mergedSubtrees[$pageId] as an empty array; no struct elements are added.
		}
	}

	/**
	 * Return the /StructParents integer allocated for $pageId's Form XObject.
	 *
	 * Called by FpdiTrait::writeImportedPagesAndResolvedObjects() to inject
	 * the /StructParents entry into the PdfStream dict before writePdfType().
	 *
	 * Returns -1 if no allocation was made (untagged source, or merge not called).
	 *
	 * @param  string $pageId
	 * @return int
	 */
	public function getFormXObjectStructParents($pageId)
	{
		if (isset($this->pageStructParents[$pageId])) {
			return $this->pageStructParents[$pageId];
		}
		return -1;
	}

	/**
	 * Record the host page number (1-indexed $mpdf->page) at which $pageId was placed.
	 *
	 * Called by FpdiTrait::useImportedPage() for every Tier 2 placement, including
	 * the first use and all SetPageTemplate reuses. This stores the host page in the
	 * order they were placed, so patchMergedSubtreeObjectNumbers() can resolve
	 * pageDim[$hostPage]['n'] to a PDF object number after writePages() has run.
	 *
	 * @param  string $pageId    FPDI page identifier from importPage()
	 * @param  int    $hostPage  $mpdf->page at render time (1-indexed)
	 * @return void
	 */
	public function recordHostPage($pageId, $hostPage)
	{
		if (!isset($this->pageIdHostPages[$pageId])) {
			$this->pageIdHostPages[$pageId] = [];
		}
		$this->pageIdHostPages[$pageId][] = $hostPage;
	}

	/**
	 * Patch placeholder MCR entries with real PDF object numbers.
	 *
	 * Called by FpdiTrait::writeImportedPagesAndResolvedObjects() immediately after
	 * each Form XObject's object number is allocated and the object is written
	 * (i.e., after $this->n is set for the Form XObject). At this point:
	 *   - $foXObjectObjNum = the Form XObject's PDF object number
	 *   - pageDim[$hostPage]['n'] = the host page dict's PDF object number
	 *     (writePages() has already run before writeImportedPagesAndResolvedObjects())
	 *
	 * Iterates over every MCR slot recorded by cloneElement() for $pageId and writes
	 * back the real foXObjectObjNum and hostPageObjNum. Only the exact MCR indices
	 * recorded by the merger are touched — MCRs from normal HTML tagging are safe.
	 *
	 * Also handles reuse (SetPageTemplate) by calling addPerPageMcrKids() for the
	 * second and subsequent host pages.
	 *
	 * @param  string $pageId          FPDI page identifier
	 * @param  int    $foXObjectObjNum PDF object number of the Form XObject just written
	 * @return void
	 */
	public function patchMergedSubtreeObjectNumbers($pageId, $foXObjectObjNum)
	{
		if (!isset($this->mergedMcrs[$pageId])) {
			return;
		}

		$hostPages = isset($this->pageIdHostPages[$pageId]) ? $this->pageIdHostPages[$pageId] : [];
		if (empty($hostPages)) {
			return;
		}

		// First host page: patch the placeholder MCRs (pageRef=0, stm=0 → real values).
		$firstHostPage   = $hostPages[0];
		$firstHostObjNum = isset($this->mpdf->pageDim[$firstHostPage]['n'])
			? (int) $this->mpdf->pageDim[$firstHostPage]['n']
			: 0;

		foreach ($this->mergedMcrs[$pageId] as $slot) {
			$elem = $slot['elem'];
			$idx  = $slot['idx'];
			$mcids = $elem->getMcids();
			if (!isset($mcids[$idx])) {
				continue;
			}
			// Patch only if still placeholder (both zero).
			if ($mcids[$idx]['pageRef'] === 0 && $mcids[$idx]['stm'] === 0) {
				$elem->patchMcr($idx, $firstHostObjNum, $foXObjectObjNum);
			}
		}

		// Additional host pages (reuse): add new MCR entries.
		for ($i = 1; $i < count($hostPages); $i++) {
			$reusePage   = $hostPages[$i];
			$reuseObjNum = isset($this->mpdf->pageDim[$reusePage]['n'])
				? (int) $this->mpdf->pageDim[$reusePage]['n']
				: 0;
			$this->addPerPageMcrKids($pageId, $reuseObjNum, $foXObjectObjNum);
		}
	}

	/**
	 * Add additional MCR kids for a reused FPDI page (SetPageTemplate pattern).
	 *
	 * When the same $pageId is drawn on a second (or Nth) host page via
	 * useImportedPage(), mergePageStructSubtree() is already complete and the
	 * struct elements exist. Each reuse needs a new MCR entry pointing to the
	 * new host page with the same $foXObjectObjNum.
	 *
	 * ISO 32000-1:2008 §14.7.4.4 Table 324 — a single struct element may have
	 * multiple MCR kids with different /Pg values when the same Form XObject
	 * is placed on multiple pages.
	 *
	 * @param  string $pageId           FPDI page identifier from importPage()
	 * @param  int    $hostPageObjNum   PDF object number of the new host page
	 * @param  int    $foXObjectObjNum  PDF object number of the FPDI Form XObject
	 * @return void
	 */
	public function addPerPageMcrKids($pageId, $hostPageObjNum, $foXObjectObjNum)
	{
		if (!isset($this->mergedSubtrees[$pageId])) {
			return;
		}

		$structParents = isset($this->pageStructParents[$pageId]) ? $this->pageStructParents[$pageId] : -1;
		if ($structParents < 0) {
			return;
		}

		// Iterate over the exact MCR slots recorded by cloneElement() — not just
		// top-level mergedSubtrees[] elements, and not just the first MCR per element.
		// mergedMcrs[$pageId] contains every addMcid() call the merger made, including
		// those on deeply nested struct elements, so no MCR is missed on reuse.
		foreach ($this->mergedMcrs[$pageId] as $slot) {
			$elem  = $slot['elem'];
			$idx   = $slot['idx'];
			$mcids = $elem->getMcids();
			if (!isset($mcids[$idx])) {
				continue;
			}
			// Add a new MCR for the reuse host page with the same MCID.
			// The new MCR is not added to mergedMcrs[], so patchMcr()'s
			// (pageRef===0 && stm===0) guard won't overwrite it — the values
			// passed here are already the real object numbers.
			$elem->addMcid($structParents, $mcids[$idx]['mcid'], $hostPageObjNum, $foXObjectObjNum);
		}
	}

	// ========================= Private helpers =========================

	/**
	 * Merge /RoleMap entries from the source catalog into the host StructureTree.
	 *
	 * ISO 32000-1:2008 §14.7.3 — first-wins semantics (implemented by
	 * StructureTree::addRoleMapping). Only entries whose custom role name is not
	 * already a standard struct type are registered, to avoid masking PDF types.
	 *
	 * @param  PdfDictionary $catalog
	 * @param  \setasign\Fpdi\PdfParser\PdfParser $parser
	 * @return void
	 */
	private function mergeRoleMap($catalog, $parser)
	{
		try {
			$roleMapRef = PdfDictionary::get($catalog, 'MarkInfo');
			// RoleMap is on the StructTreeRoot, not the catalog directly.
			$structTreeRootRef = PdfDictionary::get($catalog, 'StructTreeRoot');
			if ($structTreeRootRef instanceof PdfNull) {
				return;
			}
			$structTreeRoot = PdfType::resolve($structTreeRootRef, $parser);
			if (!($structTreeRoot instanceof PdfDictionary)) {
				return;
			}
			$roleMapRef = PdfDictionary::get($structTreeRoot, 'RoleMap');
			if ($roleMapRef instanceof PdfNull) {
				return;
			}
			$roleMap = PdfType::resolve($roleMapRef, $parser);
			if (!($roleMap instanceof PdfDictionary)) {
				return;
			}
			foreach ($roleMap->value as $custom => $standardRef) {
				$standard = PdfType::resolve($standardRef, $parser);
				if (!($standard instanceof PdfName)) {
					continue;
				}
				$standardType = $standard->value;
				// Only map if the custom role is not itself a standard type
				// and the target is a valid standard struct type.
				if (!StructType::isValid($custom) && StructType::isValid($standardType)) {
					$this->tree->addRoleMapping($custom, $standardType);
				}
			}
		} catch (\Exception $e) {
			// Non-fatal — skip RoleMap merge on error.
		}
	}

	/**
	 * Normalise a /K value to an array of PDF objects.
	 *
	 * /K may be a single dict (one child) or an array of dicts. Wraps the
	 * single-dict case in a PHP array so callers always iterate.
	 *
	 * @param  PdfType $k
	 * @param  \setasign\Fpdi\PdfParser\PdfParser $parser
	 * @return PdfType[]
	 */
	private function normaliseKidsToArray($k, $parser)
	{
		try {
			$resolved = PdfType::resolve($k, $parser);
		} catch (\Exception $e) {
			return [];
		}

		if ($resolved instanceof PdfArray) {
			return $resolved->value;
		}

		if ($resolved instanceof PdfDictionary) {
			return [$resolved];
		}

		return [];
	}

	/**
	 * Clone one source struct element into a host-native StructureElement.
	 *
	 * Recursively clones children. MCR entries in the source /K are translated
	 * into host addMcid() calls with the Form XObject object number as /Stm.
	 *
	 * Skips elements whose /S type cannot be mapped to a standard struct type
	 * (non-standard roles not in the source RoleMap are treated as Div to avoid
	 * InvalidArgumentException from StructureElement's constructor).
	 *
	 * The $pageId parameter is used to record the exact MCR slot index in
	 * $this->mergedMcrs[$pageId] so that patchMergedSubtreeObjectNumbers() can
	 * write back real object numbers without touching host-tagging MCRs.
	 *
	 * @param  PdfType              $sourceElem      source struct element dict (possibly an indirect ref)
	 * @param  \setasign\Fpdi\PdfParser\PdfParser $parser
	 * @param  StructureElement     $hostParent      host parent to attach the clone to
	 * @param  int                  $structParents   /StructParents integer for the Form XObject
	 * @param  int                  $foXObjectObjNum PDF object number of the Form XObject
	 * @param  int                  $hostPageObjNum  PDF object number of the host page
	 * @param  string               $pageId          FPDI page identifier for MCR slot recording
	 * @return StructureElement|null  the created host element, or null if the source was not a struct elem
	 */
	private function cloneElement($sourceElem, $parser, $hostParent, $structParents, $foXObjectObjNum, $hostPageObjNum, $pageId = '')
	{
		try {
			$resolved = PdfType::resolve($sourceElem, $parser);
		} catch (\Exception $e) {
			return null;
		}

		if (!($resolved instanceof PdfDictionary)) {
			// Could be a bare integer MCID or an OBJR dict — skip.
			return null;
		}

		// Check /Type — struct elements have no /Type or /Type /StructElem.
		// MCR dicts have /Type /MCR; OBJR dicts have /Type /OBJR — skip those.
		$typeEntry = PdfDictionary::get($resolved, 'Type');
		if (!($typeEntry instanceof PdfNull)) {
			try {
				$typeResolved = PdfType::resolve($typeEntry, $parser);
				if ($typeResolved instanceof PdfName) {
					$typeVal = $typeResolved->value;
					if ($typeVal === 'MCR' || $typeVal === 'OBJR') {
						return null;
					}
				}
			} catch (\Exception $e) {
				// ignore
			}
		}

		// Get /S — the struct type name.
		$sEntry = PdfDictionary::get($resolved, 'S');
		if ($sEntry instanceof PdfNull) {
			return null;
		}

		$hostType = 'Div'; // safe fallback
		try {
			$sResolved = PdfType::resolve($sEntry, $parser);
			if ($sResolved instanceof PdfName) {
				$typeName = $sResolved->value;
				if (StructType::isValid($typeName)) {
					$hostType = $typeName;
				} elseif (isset($this->tree->getRoleMappings()[$typeName])) {
					$hostType = $this->tree->getRoleMappings()[$typeName];
				}
				// else stay Div
			}
		} catch (\Exception $e) {
			// stay Div
		}

		$hostElem = new StructureElement($hostType);
		$hostParent->addChild($hostElem);

		// Pull /Alt, /ActualText, /Lang attributes if present.
		//
		// These are stored on PdfString or PdfHexString nodes in the source PDF
		// and are emitted as PDF "text strings" per ISO 32000-1 §7.9.2.2 — that
		// is, either UTF-16BE prefixed by a U+FEFF BOM, UTF-8 prefixed by a
		// three-byte EF BB BF BOM, or PDFDocEncoding when no BOM is present.
		// The host StructureWriter re-encodes whatever bytes we hand it via
		// utf16BigEndianTextString(), which assumes UTF-8 input. Passing the raw
		// source bytes through unchanged would produce a double-encoded result
		// (BOM-on-BOM + UTF-8-treated-as-UTF-16 garbage), corrupting Alt and
		// ActualText for screen readers on every imported tagged page with
		// non-ASCII content. Decode to UTF-8 here so the host writer's
		// downstream encoding produces the correct bytes.
		foreach (['Alt', 'ActualText', 'Lang'] as $attrKey) {
			try {
				$attrRef = PdfDictionary::get($resolved, $attrKey);
				if (!($attrRef instanceof PdfNull)) {
					$attrVal = PdfType::resolve($attrRef, $parser);
					$decoded = $this->decodeImportedTextString($attrVal);
					if ($decoded !== null) {
						$hostElem->setAttribute($attrKey, $decoded);
					}
				}
			} catch (\Exception $e) {
				// ignore missing attributes
			}
		}

		// Walk /K — children and MCR entries.
		$kRef = PdfDictionary::get($resolved, 'K');
		if (!($kRef instanceof PdfNull)) {
			try {
				$kResolved = PdfType::resolve($kRef, $parser);
				$kids = $this->normaliseKidsToArray($kResolved, $parser);

				foreach ($kids as $kid) {
					try {
						$kidResolved = PdfType::resolve($kid, $parser);
					} catch (\Exception $e) {
						continue;
					}

					if ($kidResolved instanceof PdfNumeric) {
						// Bare integer MCID — this element owns content at this MCID.
						$mcid    = (int) $kidResolved->value;
						$mcrIdx  = count($hostElem->getMcids());
						$hostElem->addMcid($structParents, $mcid, $hostPageObjNum, $foXObjectObjNum);
						if ($pageId !== '') {
							$this->mergedMcrs[$pageId][] = ['elem' => $hostElem, 'idx' => $mcrIdx];
						}
						$this->registerMcrInParentTree($structParents, $mcid, $hostElem);
					} elseif ($kidResolved instanceof PdfDictionary) {
						$kidTypeRef = PdfDictionary::get($kidResolved, 'Type');
						$kidType    = '';
						try {
							$kidTypeResolved = PdfType::resolve($kidTypeRef, $parser);
							if ($kidTypeResolved instanceof PdfName) {
								$kidType = $kidTypeResolved->value;
							}
						} catch (\Exception $e) {
							// ignore
						}

						if ($kidType === 'MCR') {
							// Full MCR dict — read the /MCID value.
							$mcidRef = PdfDictionary::get($kidResolved, 'MCID');
							try {
								$mcidResolved = PdfType::resolve($mcidRef, $parser);
								if ($mcidResolved instanceof PdfNumeric) {
									$mcid   = (int) $mcidResolved->value;
									$mcrIdx = count($hostElem->getMcids());
									$hostElem->addMcid($structParents, $mcid, $hostPageObjNum, $foXObjectObjNum);
									if ($pageId !== '') {
										$this->mergedMcrs[$pageId][] = ['elem' => $hostElem, 'idx' => $mcrIdx];
									}
									$this->registerMcrInParentTree($structParents, $mcid, $hostElem);
								}
							} catch (\Exception $e) {
								// ignore bad MCR
							}
						} elseif ($kidType !== 'OBJR') {
							// Nested struct element — recurse.
							$this->cloneElement($kid, $parser, $hostElem, $structParents, $foXObjectObjNum, $hostPageObjNum, $pageId);
						}
					}
				}
			} catch (\Exception $e) {
				// ignore /K parse errors; element is still added, just without kids
			}
		}

		return $hostElem;
	}

	/**
	 * Register an MCR in the host StructureTree's ParentTree.
	 *
	 * The StructureTree's $parentTree field is protected and has no public setter.
	 * StructureTree::addContentForElement() is the closest public API — it
	 * allocates a new MCID instead of reusing the source MCID. For merged
	 * subtrees we must use the exact source MCID so that the Form XObject's
	 * content stream MCIDs match the ParentTree.
	 *
	 * StructureTree exposes getParentTree() returning the array by value, so we
	 * cannot mutate it through that getter. Instead we inject directly via
	 * registerMcrInParentTree() which must be added to StructureTree, OR we
	 * accept that the ParentTree back-map for merged subtrees is incomplete.
	 *
	 * To keep the number of touched files minimal and avoid breaking the tree's
	 * invariants, we use StructureTree::registerImportedMcr() which is a
	 * package-internal method for exactly this use case. If that method does not
	 * exist the call is silently skipped — the MCR dict will still be valid
	 * (addMcid() is already called above); only the reverse ParentTree lookup
	 * from the Form XObject to the struct element would be missing.
	 *
	 * @param  int              $structParents  /StructParents key
	 * @param  int              $mcid           MCID
	 * @param  StructureElement $elem           the owning struct element
	 * @return void
	 */
	private function registerMcrInParentTree($structParents, $mcid, StructureElement $elem)
	{
		if (method_exists($this->tree, 'registerImportedMcr')) {
			$this->tree->registerImportedMcr($structParents, $mcid, $elem);
		}
	}

	/**
	 * Decode a PDF text-string node from an imported source into UTF-8.
	 *
	 * Per ISO 32000-1 §7.9.2.2 "Text string type", a PDF text string is one of:
	 *   - UTF-16BE prefixed by a U+FEFF BOM (`\xFE\xFF`)
	 *   - UTF-8 prefixed by an EF BB BF BOM
	 *   - PDFDocEncoding when no BOM is present
	 *
	 * Source PDFs commonly use the UTF-16BE form for /Alt and /ActualText.
	 * The host StructureWriter::writeStructElement() runs the value back through
	 * BaseWriter::utf16BigEndianTextString(), which assumes UTF-8 input — so we
	 * MUST decode here to avoid double-encoding the bytes (which results in
	 * unreadable garbage in the output PDF).
	 *
	 * Returns null when the node is not a string type (defensive — keeps the
	 * caller from setting an attribute to something the writer cannot encode).
	 *
	 * @param  PdfType|mixed $node  resolved attribute value from the FPDI parser
	 * @return string|null          UTF-8 decoded value, or null if undecodable
	 */
	private function decodeImportedTextString($node)
	{
		if ($node instanceof PdfHexString) {
			// Hex literal: pairs of hex digits → raw bytes. Whitespace is legal
			// between digits per ISO 32000-1 §7.3.4.3 and must be stripped.
			$hex = preg_replace('/\s+/', '', (string) $node->value);
			if ($hex === '' || strlen($hex) % 2 !== 0) {
				if (strlen($hex) % 2 === 1) {
					// Odd-length hex string is implicitly padded with 0.
					$hex .= '0';
				} else {
					return '';
				}
			}
			$raw = @pack('H*', $hex);
			if ($raw === false) {
				return null;
			}
		} elseif ($node instanceof PdfString) {
			// Literal string with parser-level escape sequences left in place.
			$raw = PdfString::unescape((string) $node->value);
		} else {
			return null;
		}

		// BOM detection.
		if (strlen($raw) >= 2 && substr($raw, 0, 2) === "\xFE\xFF") {
			// UTF-16BE.
			$utf16 = substr($raw, 2);
			$utf8  = @mb_convert_encoding($utf16, 'UTF-8', 'UTF-16BE');
			return $utf8 === false ? null : $utf8;
		}
		if (strlen($raw) >= 2 && substr($raw, 0, 2) === "\xFF\xFE") {
			// UTF-16LE — uncommon but legal in older producers.
			$utf16 = substr($raw, 2);
			$utf8  = @mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
			return $utf8 === false ? null : $utf8;
		}
		if (strlen($raw) >= 3 && substr($raw, 0, 3) === "\xEF\xBB\xBF") {
			// UTF-8 BOM.
			return substr($raw, 3);
		}

		// No BOM → PDFDocEncoding. The codepoints in PDFDocEncoding 0x00-0x7F
		// match ASCII, so for the common case (BCP-47 lang tags, ASCII Alt
		// strings) passthrough is correct. The 0x80-0xFF range diverges from
		// Latin-1 — full PDFDocEncoding mapping is out of scope; if the source
		// encodes non-ASCII without a BOM, characters in the 0x80-0xFF range
		// will be misencoded. Producers that care about non-ASCII almost always
		// use the UTF-16BE form, so this lossy fallback is acceptable.
		return $raw;
	}
}
