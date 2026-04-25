<?php

namespace Mpdf\Ua;

use Mpdf\Mpdf;
use Mpdf\Writer\BaseWriter;

/**
 * Serialise the in-memory StructureTree to the PDF file.
 *
 * Called once from ResourceWriter::writeResources() when PDFUA is active,
 * before the catalog is written. Produces four groups of PDF objects:
 *
 *   1. One /Type /StructElem dict per StructureElement (recursive walk of the tree).
 *   2. The ParentTree NumTree (maps /StructParents integers → struct elements).
 *   3. The /RoleMap dict (if any custom role= values were registered).
 *   4. The StructTreeRoot dict itself.
 *
 * Returns the StructTreeRoot's PDF object number to its caller
 * (ResourceWriter::writeResources()) which records it on UaState so
 * MetadataWriter::writeCatalog() can emit /StructTreeRoot N 0 R on the
 * document catalog.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — StructTreeRoot dict
 *   - ISO 32000-1:2008 §14.7.2 Table 323 — StructElem dict
 *   - ISO 32000-1:2008 §14.7.4.4 Table 324 — MCR dict
 *   - ISO 32000-1:2008 §14.7.4.4.2 Table 338 — OBJR dict
 *   - ISO 32000-1:2008 §14.7.3 — RoleMap dict
 *   - ISO 32000-1:2008 §7.9.7 — Number trees (NumTree) used for ParentTree
 *
 * @see StructureTree    source of truth for what gets serialised
 * @see StructureElement the node type serialised per PDF struct element
 */
class StructureWriter
{

	/** @var Mpdf */
	private $mpdf;

	/** @var BaseWriter */
	private $writer;

	/** @var StructureTree */
	private $tree;

	/**
	 * @param Mpdf          $mpdf   host Mpdf for object-number allocation and page-ref lookups
	 * @param BaseWriter    $writer PDF byte emitter
	 * @param StructureTree $tree   in-memory element stack + ParentTree accumulator to serialise
	 */
	public function __construct(Mpdf $mpdf, BaseWriter $writer, StructureTree $tree)
	{
		$this->mpdf   = $mpdf;
		$this->writer = $writer;
		$this->tree   = $tree;
	}

	/**
	 * Walk the in-memory structure tree and emit it as PDF objects.
	 *
	 * Ordering is fixed: struct element objects first (they need to know each
	 * other's object numbers for /P and /K cross-refs), then ParentTree, then
	 * RoleMap, then StructTreeRoot last. StructureWriter reserves object
	 * numbers bottom-up so parent /P back-references resolve correctly.
	 *
	 * Side effects:
	 *   - Sets elem->objNum on every StructureElement as its object number is allocated.
	 *
	 * The StructTreeRoot's PDF object number is returned to the caller
	 * (ResourceWriter) which in turn records it on UaState via
	 * setStructTreeRootObjNum(). StructureWriter holds no back-reference to
	 * UaState — breaking what used to be a construction-time cycle between the two.
	 *
	 * $parentTreeNextKey is the /ParentTreeNextKey value (ISO 32000-1 Table 322 /
	 * Appendix A13) — an integer greater than any key in the parent tree.
	 * ResourceWriter passes UaState::getStructParentsCounter() here, which equals
	 * one more than the highest /StructParents integer assigned (since
	 * nextStructParents() pre-increments).
	 *
	 * @param  int $parentTreeNextKey  one more than the highest /StructParents key used
	 * @return int                     the PDF object number assigned to the StructTreeRoot dict
	 */
	public function writeStructTree($parentTreeNextKey = 0)
	{
		// ---- 1. Pre-allocate object numbers for all struct elements ----
		//
		// BaseWriter::object($id, $onlynewobj = true) increments $mpdf->n and stores
		// the number but does NOT emit a "N 0 obj" header and does NOT record the
		// buffer offset. This reserves numbers so that parent /P and child /K cross-refs
		// are all valid before any dict body is written.
		$this->reserveObjectNumbers($this->tree->getRoot());

		// ---- 2. Emit struct element dicts (depth-first, children before parents) ----
		//
		// ISO 32000-1 §14.7.2 Table 323 — StructElem dict entries.
		// Each writeElement() call opens its pre-reserved slot with
		// object($elem->getObjNum(), false), which records the offset at the current
		// buffer position and emits the "N 0 obj" header exactly once.
		$this->writeElement($this->tree->getRoot());

		// ---- 3. ParentTree NumTree object ----
		//
		// ISO 32000-1 §7.9.7 + §14.7.4.4 — a NumTree keyed by the
		// /StructParents integers emitted on page dicts (PageWriter §1h) and
		// Form XObject dicts (FormWriter for SVG/FPDI). For each key the value
		// is either a dense array of struct elem obj refs indexed by MCID, or
		// a single struct elem ref (singular /StructParent annotation entry).
		$parentTreeObjNum = $this->writeParentTree();

		// ---- 4. RoleMap dict (only if non-empty) ----
		//
		// ISO 32000-1 §14.7.3 — /RoleMap <<custom => standard …>>.
		// veraPDF rejects an empty /RoleMap dict in some configurations; skip when empty.
		$roleMappings   = $this->tree->getRoleMappings();
		$roleMapObjNum  = 0;
		if (!empty($roleMappings)) {
			$roleMapObjNum = $this->writeRoleMap($roleMappings);
		}

		// ---- 5. StructTreeRoot dict (last — references everything above) ----
		//
		// ISO 32000-1 §14.7.2 Table 322 — StructTreeRoot dict entries.
		$this->writer->object();
		$rootObjNum = $this->mpdf->n;

		$rootElem = $this->tree->getRoot();

		$this->writer->write('<</Type /StructTreeRoot');
		// /K — the Document root struct element
		$this->writer->write('/K [' . $rootElem->getObjNum() . ' 0 R]');
		// /ParentTree reference
		$this->writer->write('/ParentTree ' . $parentTreeObjNum . ' 0 R');
		// ISO 32000-1 Table 322 / Appendix A13 — /ParentTreeNextKey is required:
		// "An integer greater than any key in the parent tree."
		// Passed in from ResourceWriter which reads UaState::getStructParentsCounter()
		// after all pages have been processed — that value equals one more than the
		// highest /StructParents key assigned (nextStructParents() pre-increments).
		$this->writer->write('/ParentTreeNextKey ' . $parentTreeNextKey);
		if ($roleMapObjNum > 0) {
			$this->writer->write('/RoleMap ' . $roleMapObjNum . ' 0 R');
		}
		$this->writer->write('>>');
		$this->writer->write('endobj');

		return $rootObjNum;
	}

	// ================== internals ==================

	/**
	 * Recursively walk the element tree bottom-up and allocate a PDF object number
	 * for each element WITHOUT emitting any bytes.
	 *
	 * Uses BaseWriter::object($id = false, $onlynewobj = true) which only increments
	 * $mpdf->n and stores the element's number via setObjNum(). No "N 0 obj" header
	 * is emitted and no buffer offset is recorded. The separate writeElement() pass
	 * then opens each pre-reserved slot at the correct buffer position.
	 *
	 * @param  StructureElement $elem
	 * @return void
	 */
	private function reserveObjectNumbers(StructureElement $elem)
	{
		foreach ($elem->getChildren() as $child) {
			$this->reserveObjectNumbers($child);
		}
		// $onlynewobj = true: allocate number without emitting "N 0 obj" header.
		$this->writer->object(false, true);
		$elem->setObjNum($this->mpdf->n);
	}

	/**
	 * Emit the /Type /StructElem dict for one element after its children.
	 *
	 * ISO 32000-1 §14.7.2 Table 323 — children emitted first so that /K
	 * references are valid when the parent dict is written.
	 *
	 * Object numbers are pre-allocated by reserveObjectNumbers() so that both
	 * /P (parent ref on child dicts) and /K (child refs on parent dicts) are
	 * available before any dict body is written. Here we open each pre-reserved
	 * slot with object($preAllocatedId, false) which records the buffer offset and
	 * emits the "N 0 obj" header exactly once at the current write position.
	 *
	 * @param  StructureElement $elem
	 * @return void
	 */
	private function writeElement(StructureElement $elem)
	{
		// Emit children first (depth-first, children before parents).
		foreach ($elem->getChildren() as $child) {
			$this->writeElement($child);
		}

		// Open the pre-reserved object slot at the current buffer position.
		// object($id, false) records offsets[$id] and emits "$id 0 obj" once.
		$this->writer->object($elem->getObjNum(), false);

		$attrs  = $elem->getAttributes();
		$mcids  = $elem->getMcids();
		$objrefs = $elem->getObjrefs();

		// Open the dict body.
		$this->writer->write('<</Type /StructElem');
		$this->writer->write('/S /' . $elem->getType());

		$parent = $elem->getParent();
		if ($parent !== null) {
			$this->writer->write('/P ' . $parent->getObjNum() . ' 0 R');
		}

		// /ID — direct key (ISO 32000-1 Table 322).
		if ($elem->getId() !== null) {
			$this->writer->write('/ID ' . $this->writer->utf16BigEndianTextString($elem->getId()));
		}

		// ---- Direct dict keys (Appendix A1) ----
		// /Alt, /ActualText, /Lang, /E, /T are written directly on the StructElem
		// dict — NOT inside /A attribute objects (ISO 32000-1 Table 322).
		$directKeys = ['Alt', 'ActualText', 'Lang', 'E', 'T'];
		foreach ($directKeys as $key) {
			if (isset($attrs[$key])) {
				$this->writer->write('/' . $key . ' ' . $this->writer->utf16BigEndianTextString($attrs[$key]));
			}
		}

		// ---- /A attribute objects ----
		// /O /Table owner: Scope, ColSpan, RowSpan, Headers, Summary
		// /O /List  owner: ListNumbering
		// /O /Layout owner: Placement, BBox, WritingMode
		$tableKeys  = ['Scope', 'ColSpan', 'RowSpan', 'Headers', 'Summary'];
		$listKeys   = ['ListNumbering'];
		$layoutKeys = ['Placement', 'BBox', 'WritingMode'];

		$tableAttrs  = [];
		$listAttrs   = [];
		$layoutAttrs = [];

		foreach ($tableKeys as $key) {
			if (isset($attrs[$key])) {
				$tableAttrs[$key] = $attrs[$key];
			}
		}
		foreach ($listKeys as $key) {
			if (isset($attrs[$key])) {
				$listAttrs[$key] = $attrs[$key];
			}
		}
		foreach ($layoutKeys as $key) {
			if (isset($attrs[$key])) {
				$layoutAttrs[$key] = $attrs[$key];
			}
		}

		$attrObjects = [];
		if (!empty($tableAttrs)) {
			$attrObjects[] = $this->buildAttrObject('/Table', $tableAttrs);
		}
		if (!empty($listAttrs)) {
			$attrObjects[] = $this->buildAttrObject('/List', $listAttrs);
		}
		if (!empty($layoutAttrs)) {
			$attrObjects[] = $this->buildAttrObject('/Layout', $layoutAttrs);
		}

		if (!empty($attrObjects)) {
			// ISO 32000-1 Table 322 — /A is an array when multiple owners are present.
			if (count($attrObjects) === 1) {
				$this->writer->write('/A ' . $attrObjects[0]);
			} else {
				$this->writer->write('/A [' . implode(' ', $attrObjects) . ']');
			}
		}

		// ---- /K — kids: MCIDs, MCR dicts, OBJR dicts, child struct elem refs ----
		$kParts = [];

		// Child struct elements first.
		foreach ($elem->getChildren() as $child) {
			$kParts[] = $child->getObjNum() . ' 0 R';
		}

		// MCR entries (marked content references).
		// ISO 32000-1 §14.7.4.4 Table 324 — MCR dict.
		// Single-page single-MCID: bare integer is allowed and preferred.
		// Multi-page or multi-MCID: MCR dicts.
		$pageRefs = $this->buildPageRefMap();
		if (count($mcids) === 1 && empty($objrefs) && empty($elem->getChildren())) {
			// Single MCR on one page with no other kids: bare integer is valid.
			// ISO 32000-1 §14.7.4.4 — bare integer /K means a simple content item on
			// the same page as the struct element (single-page case).
			$kParts[] = (string) $mcids[0]['mcid'];
		} else {
			foreach ($mcids as $mcr) {
				$pageObjNum = isset($pageRefs[$mcr['page']]) ? $pageRefs[$mcr['page']] : 0;
				if ($pageObjNum > 0) {
					$kParts[] = '<</Type /MCR /Pg ' . $pageObjNum . ' 0 R /MCID ' . $mcr['mcid'] . '>>';
				} else {
					// Fallback: bare integer when page ref is unavailable.
					$kParts[] = (string) $mcr['mcid'];
				}
			}
		}

		// OBJR entries (annotation object references).
		// ISO 32000-1 §14.7.4.4.2 Table 338 — OBJR dict.
		foreach ($objrefs as $objref) {
			$kParts[] = '<</Type /OBJR /Obj ' . $objref['obj'] . ' 0 R>>';
		}

		if (!empty($kParts)) {
			if (count($kParts) === 1) {
				$this->writer->write('/K ' . $kParts[0]);
			} else {
				$this->writer->write('/K [' . implode(' ', $kParts) . ']');
			}
		}

		$this->writer->write('>>');
		$this->writer->write('endobj');
	}

	/**
	 * Build the string for one /A attribute object dict.
	 *
	 * Attribute values: strings are emitted as PDF name or literal depending on
	 * the key; numeric values are emitted directly; arrays (BBox) are emitted
	 * as PDF arrays.
	 *
	 * ISO 32000-1 §14.7.5.2 — attribute dicts carry /O (owner) plus the
	 * owner-specific entries. The owner constants are defined in §14.7.5.3 ff.
	 *
	 * @param  string $owner   /O value, e.g. '/Table', '/List', '/Layout'
	 * @param  array  $attrs   key => value pairs for this owner
	 * @return string          inline PDF dict string
	 */
	private function buildAttrObject($owner, $attrs)
	{
		$parts = ['<</O ' . $owner];
		foreach ($attrs as $key => $value) {
			if ($key === 'BBox' && is_array($value)) {
				// BBox is an array of four numbers in user space.
				$parts[] = '/' . $key . ' [' . implode(' ', $value) . ']';
			} elseif (is_int($value) || is_float($value)) {
				$parts[] = '/' . $key . ' ' . $value;
			} else {
				// String values are emitted as PDF names (e.g. /Scope /Column).
				$parts[] = '/' . $key . ' /' . $value;
			}
		}
		$parts[] = '>>';
		return implode(' ', $parts);
	}

	/**
	 * Emit the ParentTree NumTree object and return its PDF object number.
	 *
	 * The NumTree maps /StructParents integers to dense arrays of struct element
	 * references (one ref per MCID for page-dict keys) or to single struct element
	 * references (for annotation /StructParent singular keys).
	 *
	 * ISO 32000-1 §7.9.7 — NumTree format: /Nums [key value key value …].
	 *
	 * @return int  PDF object number of the NumTree object
	 */
	private function writeParentTree()
	{
		$this->writer->object();
		$objNum = $this->mpdf->n;

		$parentTree     = $this->tree->getParentTree();
		$annotTree      = $this->tree->getAnnotParentTree();

		// Merge page-level (plural /StructParents) and annotation-level
		// (singular /StructParent) entries into one sorted NumTree.
		$entries = [];

		// Page-level entries: value is a dense array of struct element refs.
		foreach ($parentTree as $key => $mcidMap) {
			$refs = [];
			// mcidMap is keyed by MCID — sort by MCID to ensure dense, 0-based order.
			ksort($mcidMap);
			foreach ($mcidMap as $structElem) {
				$refs[] = $structElem->getObjNum() . ' 0 R';
			}
			$entries[$key] = '[' . implode(' ', $refs) . ']';
		}

		// Annotation-level entries: value is a single struct element ref.
		foreach ($annotTree as $key => $structElem) {
			$entries[$key] = $structElem->getObjNum() . ' 0 R';
		}

		// NumTree /Nums array must be sorted by integer key.
		ksort($entries);

		$this->writer->write('<</Nums [');
		foreach ($entries as $key => $value) {
			$this->writer->write($key . ' ' . $value);
		}
		$this->writer->write(']>>');
		$this->writer->write('endobj');

		return $objNum;
	}

	/**
	 * Emit the /RoleMap dict object and return its PDF object number.
	 *
	 * Only called when $roleMappings is non-empty — veraPDF rejects an empty
	 * /RoleMap in some configurations.
	 *
	 * ISO 32000-1 §14.7.3 — /RoleMap is a dict on the StructTreeRoot mapping
	 * custom role names to standard struct types.
	 *
	 * @param  array<string,string> $roleMappings
	 * @return int  PDF object number of the RoleMap object
	 */
	private function writeRoleMap($roleMappings)
	{
		$this->writer->object();
		$objNum = $this->mpdf->n;

		$this->writer->write('<<');
		foreach ($roleMappings as $custom => $standard) {
			$this->writer->write('/' . $custom . ' /' . $standard);
		}
		$this->writer->write('>>');
		$this->writer->write('endobj');

		return $objNum;
	}

	/**
	 * Build a map from /StructParents integer to PDF page object number.
	 *
	 * Used by writeElement() to populate MCR dict /Pg entries. Page object
	 * numbers are found in $mpdf->offsets — the offset table doubles as the
	 * object-number lookup since offsets[n] is non-zero iff object n exists.
	 *
	 * $mpdf->pageDim[$pageNum]['structParents'] holds the /StructParents integer
	 * assigned to page $pageNum; $mpdf->pageDim[$pageNum]['n'] is the page dict's
	 * PDF object number.
	 *
	 * @return array<int,int>  /StructParents integer => page object number
	 */
	private function buildPageRefMap()
	{
		$map = [];
		if (!isset($this->mpdf->pageDim) || !is_array($this->mpdf->pageDim)) {
			return $map;
		}
		foreach ($this->mpdf->pageDim as $pageNum => $dim) {
			if (isset($dim['structParents'], $dim['n'])) {
				$map[$dim['structParents']] = $dim['n'];
			}
		}
		return $map;
	}
}
