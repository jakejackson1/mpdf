<?php

namespace Mpdf;

use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\Filter\AsciiHex;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;
use setasign\Fpdi\PdfReader\DataStructure\Rectangle;
use setasign\Fpdi\PdfReader\PageBoundaries;

/**
 * @mixin Mpdf
 */
trait FpdiTrait
{
	use \setasign\Fpdi\FpdiTrait {
		writePdfType as fpdiWritePdfType;
		useImportedPage as fpdiUseImportedPage;
		importPage as fpdiImportPage;
		setSourceFile as fpdiSetSourceFile;
		setSourceFileWithParserParams as fpdiSetSourceFileWithParserParams;
	}

	protected $k = Mpdf::SCALE;

	/**
	 * The currently used object number.
	 *
	 * @var int
	 */
	public $currentObjectNumber;

	/**
	 * A counter for template ids.
	 *
	 * @var int
	 */
	protected $templateId = 0;

	/**
	 * Set of synthetic pageIds returned in place of FPDI page ids when the source
	 * PDF is encrypted (Tier 0 in PDF/UA-1 mode).
	 *
	 * Populated by handleEncryptedImportInUaMode() during importPage() when
	 * vendor/setasign/fpdi throws CrossReferenceException::ENCRYPTED (0x010C).
	 * Queried by isEncryptedPlaceholder() inside useImportedPage() to short-
	 * circuit the FPDI Form-XObject draw path and emit an Artifact placeholder
	 * instead.
	 *
	 * Keys are the synthetic pageId strings; values are arrays carrying the
	 * original source-file path and page number for diagnostics.
	 *
	 * @var array<string, array{file: string|null, pageNumber: int}>
	 */
	protected $encryptedPageIds = [];

	/**
	 * Tracks source files whose setSourceFile() call was rejected by FPDI as
	 * encrypted, indexed by the realpath (or raw path) of the source. importPage()
	 * consults this map after a successful or failed setSourceFile() so it can
	 * synthesise a Tier 0 placeholder pageId without re-attempting the FPDI parse.
	 *
	 * @var array<string, true>
	 */
	protected $encryptedSourceFiles = [];

	protected function setPageFormat($format, $orientation)
	{
		// in mPDF this needs to be "P" (why ever)
		$orientation = 'P';
		$this->_setPageSize([$format['width'], $format['height']], $orientation);

		if ($orientation != $this->DefOrientation) {
			$this->OrientationChanges[$this->page] = true;
		}

		$this->wPt = $this->fwPt;
		$this->hPt = $this->fhPt;
		$this->w = $this->fw;
		$this->h = $this->fh;

		$this->CurOrientation = $orientation;
		$this->ResetMargins();
		$this->pgwidth = $this->w - $this->lMargin - $this->rMargin;
		$this->PageBreakTrigger = $this->h - $this->bMargin;

		$this->pageDim[$this->page]['w'] = $this->w;
		$this->pageDim[$this->page]['h'] = $this->h;
	}

	/**
	 * Set the minimal PDF version.
	 *
	 * @param string $pdfVersion
	 */
	protected function setMinPdfVersion($pdfVersion)
	{
		if (\version_compare($pdfVersion, $this->pdf_version, '>')) {
			$this->pdf_version = $pdfVersion;
		}
	}

	/**
	 * Return a PdfReader for an already-opened source file by its reader id.
	 *
	 * Wraps the vendor trait's protected getPdfReader() so that collaborators
	 * outside the class hierarchy (e.g. FpdiStructMerger) can access the reader
	 * without calling a protected method directly.
	 *
	 * @param  string $readerId  reader id obtained from $importedPages[$pageId]['readerId']
	 * @return \setasign\Fpdi\PdfReader\PdfReader
	 */
	public function getSourcePdfReader($readerId)
	{
		return $this->getPdfReader($readerId);
	}

	/**
	 * Set the source PDF file, intercepting encryption errors in PDF/UA-1 mode.
	 *
	 * In non-PDFUA mode, delegates straight to vendor/setasign/fpdi (existing
	 * behaviour — encrypted sources continue to throw CrossReferenceException).
	 *
	 * In PDFUA mode, catches CrossReferenceException::ENCRYPTED (0x010C) so the
	 * caller can fall through to the Tier 0 (Artifact placeholder) path:
	 *   - Strict mode (PDFUAauto=false): throws \Mpdf\MpdfException with a
	 *     PDF/UA-1-aware citation; the caller must decrypt the source upstream.
	 *   - Auto mode (PDFUAauto=true):   marks the file as encrypted in
	 *     $encryptedSourceFiles, returns 1 (synthetic page count) so the caller
	 *     can still call importPage(), which then returns a placeholder pageId.
	 *
	 * Other CrossReferenceException codes (XREF_MISSING, etc.) are re-thrown so
	 * non-encryption parser failures continue to surface as before.
	 *
	 * ISO 32000-1:2008 §7.6 — encryption (general). FPDI exposes no password
	 * setter, so any document with an /Encrypt entry in the trailer is rejected
	 * regardless of cipher (RC4, AES) or scope (full-document or strings-only
	 * via §7.6.5 crypt filters).
	 *
	 * @param  string|resource|\setasign\Fpdi\PdfParser\StreamReader $file
	 * @return int  page count, or 1 in auto mode for an encrypted source
	 * @throws \Mpdf\MpdfException             in strict mode for an encrypted source
	 * @throws CrossReferenceException         for non-encryption parser failures
	 */
	public function setSourceFile($file)
	{
		try {
			return $this->fpdiSetSourceFile($file);
		} catch (CrossReferenceException $e) {
			if ($e->getCode() !== CrossReferenceException::ENCRYPTED) {
				throw $e;
			}
			return $this->handleEncryptedSetSourceFile($file, $e);
		}
	}

	/**
	 * Set the source PDF file with parser parameters; mirror of setSourceFile().
	 *
	 * Uses the same encryption-detection wrapper so callers using the params
	 * variant receive identical Tier 0 fallback behaviour. Note: FPDI's vendor
	 * implementation accepts an optional second argument typed `array`; that
	 * default is preserved here so the override keeps the same public signature.
	 *
	 * @param  string|resource|\setasign\Fpdi\PdfParser\StreamReader $file
	 * @param  array $parserParams
	 * @return int  page count, or 1 in auto mode for an encrypted source
	 * @throws \Mpdf\MpdfException
	 * @throws CrossReferenceException
	 */
	public function setSourceFileWithParserParams($file, array $parserParams = [])
	{
		try {
			return $this->fpdiSetSourceFileWithParserParams($file, $parserParams);
		} catch (CrossReferenceException $e) {
			if ($e->getCode() !== CrossReferenceException::ENCRYPTED) {
				throw $e;
			}
			return $this->handleEncryptedSetSourceFile($file, $e);
		}
	}

	/**
	 * Common encrypted-source handler invoked by setSourceFile() variants.
	 *
	 * Strict mode (PDFUA=true && PDFUAauto=false) throws \Mpdf\MpdfException
	 * with the citation message; auto mode flags the file in
	 * $encryptedSourceFiles so importPage() can return a Tier 0 placeholder.
	 *
	 * Non-PDFUA callers re-throw the original CrossReferenceException so the
	 * pre-existing behaviour is preserved exactly.
	 *
	 * @param  mixed                    $file the original $file argument
	 * @param  CrossReferenceException  $e    the underlying FPDI exception
	 * @return int                            1 (synthetic page count) in auto mode
	 * @throws \Mpdf\MpdfException
	 * @throws CrossReferenceException
	 */
	private function handleEncryptedSetSourceFile($file, CrossReferenceException $e)
	{
		if (empty($this->PDFUA)) {
			throw $e;
		}

		if (empty($this->PDFUAauto)) {
			throw new \Mpdf\MpdfException(
				'Imported PDF source is encrypted (ISO 32000-1:2008 §7.6) and cannot be tagged. '
				. 'vendor/setasign/fpdi exposes no password setter and refuses any document with '
				. 'an /Encrypt entry. Decrypt the source upstream (e.g. `qpdf --decrypt input.pdf '
				. 'output.pdf`), disable PDFUA on this import, or enable PDFUAauto to fall back '
				. 'to /Artifact wrapping (Matterhorn 01-007).',
				$e->getCode()
			);
		}

		// Auto mode — record the source as encrypted so importPage() can
		// synthesise a placeholder pageId. We try to canonicalise via realpath
		// for string inputs; resource and StreamReader inputs are tracked by
		// spl_object_hash() / resource id (matches FPDI's getPdfReaderId logic).
		$key = $this->encryptedSourceKey($file);
		$this->encryptedSourceFiles[$key] = true;

		// Surface a UA-aware warning at this stage so callers inspecting
		// getPdfUaWarnings() after Output() see the encrypted-source diagnostic
		// even if importPage() is not subsequently called for some reason.
		if ($this->ua !== null) {
			$this->ua->addWarning(
				'Imported PDF source is encrypted (ISO 32000-1:2008 §7.6) and cannot be parsed by '
				. 'vendor/setasign/fpdi. Auto-mode fallback: importPage() will return a synthetic '
				. 'pageId and useImportedPage() will draw a Tier 0 /Artifact <</Type /Layout>> '
				. 'placeholder in place of the original page. Matterhorn 01-007.'
			);
		}

		// Return a synthetic page count so the caller's loop (typically
		// `for ($i = 1; $i <= setSourceFile($file); $i++) importPage($i)`) still
		// invokes importPage() at least once and reaches the Tier 0 path.
		return 1;
	}

	/**
	 * Build a stable lookup key for $encryptedSourceFiles from a $file argument.
	 *
	 * Mirrors vendor/setasign/fpdi's getPdfReaderId() logic (string → realpath,
	 * resource → (string) cast, object → spl_object_hash) without invoking the
	 * vendor reader machinery (which would re-trigger the encryption throw).
	 *
	 * @param  mixed $file
	 * @return string
	 */
	private function encryptedSourceKey($file)
	{
		if (is_resource($file)) {
			return 'resource:' . (string) $file;
		}
		if (is_string($file)) {
			$rp = @realpath($file);
			return 'file:' . ($rp !== false ? $rp : $file);
		}
		if (is_object($file)) {
			return 'object:' . spl_object_hash($file);
		}
		return 'unknown:' . gettype($file);
	}

	/**
	 * Whether $pageId is a Tier 0 encrypted-source placeholder.
	 *
	 * @param  mixed $pageId
	 * @return bool
	 */
	public function isEncryptedPlaceholder($pageId)
	{
		return is_string($pageId)
			&& strpos($pageId, \Mpdf\Ua\Import\FpdiStructMerger::ENCRYPTED_PAGE_PLACEHOLDER_ID_PREFIX) === 0
			&& isset($this->encryptedPageIds[$pageId]);
	}

	/**
	 * Get the next template id.
	 *
	 * @return int
	 */
	protected function getNextTemplateId()
	{
		return $this->templateId++;
	}

	/**
	 * Draws an imported page or a template onto the page or another template.
	 *
	 * Omit one of the size parameters (width, height) to calculate the other one automatically in view to the aspect
	 * ratio.
	 *
	 * @param mixed $tpl The template id
	 * @param float|int|array $x The abscissa of upper-left corner. Alternatively you could use an assoc array
	 *                           with the keys "x", "y", "width", "height", "adjustPageSize".
	 * @param float|int $y The ordinate of upper-left corner.
	 * @param float|int|null $width The width.
	 * @param float|int|null $height The height.
	 * @param bool $adjustPageSize
	 * @return array The size
	 * @see Fpdi::getTemplateSize()
	 */
	public function useTemplate($tpl, $x = 0, $y = 0, $width = null, $height = null, $adjustPageSize = false)
	{
		return $this->useImportedPage($tpl, $x, $y, $width, $height, $adjustPageSize);
	}

	/**
	 * Draws an imported page onto the page.
	 *
	 * Omit one of the size parameters (width, height) to calculate the other one automatically in view to the aspect
	 * ratio.
	 *
	 * @param mixed $pageId The page id
	 * @param float|int|array $x The abscissa of upper-left corner. Alternatively you could use an assoc array
	 *                           with the keys "x", "y", "width", "height", "adjustPageSize".
	 * @param float|int $y The ordinate of upper-left corner.
	 * @param float|int|null $width The width.
	 * @param float|int|null $height The height.
	 * @param bool $adjustPageSize
	 * @return array The size.
	 * @see Fpdi::getTemplateSize()
	 */
	public function useImportedPage($pageId, $x = 0, $y = 0, $width = null, $height = null, $adjustPageSize = false)
	{
		if ($this->state == 0) {
			$this->AddPage();
		}

		/* Extract $x if an array */
		if (is_array($x)) {
			unset($x['pageId']);
			extract($x, EXTR_IF_EXISTS);
			if (is_array($x)) {
				$x = 0;
			}
		}

		// PDF/UA-1 Tier 0 — encrypted source (FPDI cannot parse).
		//
		// $pageId comes from handleEncryptedImportInUaMode() and is NOT in
		// $this->importedPages (no Form XObject was created). Draw an Artifact
		// placeholder bracketed with BDC/EMC and return a size so the caller's
		// downstream layout logic remains valid. ISO 32000-1:2008 §14.6 permits
		// empty content between BMC/EMC, so the Artifact wraps nothing visible
		// unless a placeholder rectangle is requested.
		if ($this->PDFUA && $this->isEncryptedPlaceholder($pageId)) {
			$pdfuaMerger = $this->ua->getFpdiStructMerger();

			// Default placeholder dimensions to the host page's content area so
			// the caller does not have to supply width/height for an opaque
			// fallback. Width/height passed by the caller take precedence.
			$resolvedWidth  = ($width !== null) ? $width : $this->pgwidth;
			$resolvedHeight = ($height !== null) ? $height : ($this->h - $this->tMargin - $this->bMargin);

			$this->writer->write('/Artifact <</Type /Layout>> BDC');
			$this->drawEncryptedSourcePlaceholder($x, $y, $resolvedWidth, $resolvedHeight);
			$this->writer->write('EMC');

			$pdfuaMerger->addUntaggedWarning(
				'Imported PDF page is encrypted (ISO 32000-1:2008 §7.6) and cannot be parsed by '
				. 'vendor/setasign/fpdi. Treated as Tier 0: a placeholder /Artifact <</Type /Layout>> '
				. 'BDC … EMC pair is drawn in place of the original page content. Decrypt the source '
				. 'upstream (e.g. `qpdf --decrypt`) for accessible imports. Matterhorn 01-007.'
			);

			// Flush accumulated warnings into UaState so getPdfUaWarnings() sees them.
			foreach ($pdfuaMerger->getUntaggedWarnings() as $w) {
				$this->ua->addWarning($w);
			}

			return [
				'width'       => $resolvedWidth,
				'height'      => $resolvedHeight,
				0             => $resolvedWidth,
				1             => $resolvedHeight,
				'orientation' => $resolvedWidth >= $resolvedHeight ? 'L' : 'P',
			];
		}

		// PDF/UA-1 — two-tier treatment for imported PDF pages.
		//
		// Tier 1 (untagged source): The Do operator emitted by FPDI is bracketed with
		//   /Artifact <</Type /Layout>> BDC … EMC
		// (ISO 14289-1:2014 §7.1; Matterhorn 01-007; ISO 32000-1:2008 §14.7.4.4 Table 324).
		// A diagnostic warning is emitted via getPdfUaWarnings().
		//
		// Tier 2 (tagged source): The source PDF's struct subtree is cloned into the host
		// StructureTree via FpdiStructMerger::mergePageStructSubtree(). The Form XObject
		// receives a /StructParents entry at write time (see writeImportedPagesAndResolvedObjects).
		// No Artifact wrap is emitted; the cloned struct elements provide the tagging.
		// Tier 2 is gated on verifyAndPrepareMerge() succeeding — if the source struct
		// strings fail the sanity gauntlet (forward-compat guard for ISO 32000-1 §7.6.5
		// strings-only encryption), auto mode demotes to Tier 1 and strict mode throws.
		//
		// The readerId is read from importedPages because $this->currentReaderId is null
		// at this point in the call stack (it is only set during writeImportedPagesAndResolvedObjects).
		$pdfuaMerger    = null;
		$useTaggedMerge = false;
		if ($this->PDFUA && isset($this->importedPages[$pageId])) {
			$pdfuaMerger = $this->ua->getFpdiStructMerger();
			$readerId    = $this->importedPages[$pageId]['readerId'];

			if ($pdfuaMerger->sourceIsTagged($readerId) && $pdfuaMerger->verifyAndPrepareMerge($pageId)) {
				$useTaggedMerge = true;
				// Tier 2: merge struct subtree. Object numbers are not yet known at
				// render time (they are allocated in writeImportedPagesAndResolvedObjects),
				// so pass 0 for foXObjectObjNum and hostPageObjNum; the actual numbers
				// are patched by patchMergedSubtreeObjectNumbers() at write time.
				$pdfuaMerger->mergePageStructSubtree($pageId, 0, 0);
				// Record this host page so patchMergedSubtreeObjectNumbers() can resolve
				// pageDim[$hostPage]['n'] after writePages() has run. Called on every
				// placement (first use + all SetPageTemplate reuses).
				$pdfuaMerger->recordHostPage($pageId, $this->page);
			} else {
				// Tier 1: wrap Do as Artifact. This is also the demotion path for
				// tagged sources whose verifyAndPrepareMerge() failed in auto mode.
				$this->writer->write('/Artifact <</Type /Layout>> BDC');
			}
		}

		$newSize = $this->fpdiUseImportedPage($pageId, $x, $y, $width, $height, $adjustPageSize);

		if ($this->PDFUA && $pdfuaMerger !== null) {
			if ($useTaggedMerge) {
				// Tier 2: nothing to close; the struct elements carry the tagging.
				// No EMC needed because no BDC was emitted.
			} else {
				// Tier 1: close the Artifact sequence.
				$this->writer->write('EMC');
				$pdfuaMerger->addUntaggedWarning(
					'Imported PDF page treated as untagged and wrapped as /Artifact <</Type /Layout>> — '
					. 'content is not tagged with a struct element. '
					. 'Matterhorn 01-007: use a workflow that preserves struct tagging to avoid this warning.'
				);

				// Flush accumulated warnings into UaState so they appear in getPdfUaWarnings().
				foreach ($pdfuaMerger->getUntaggedWarnings() as $w) {
					$this->ua->addWarning($w);
				}
			}
		}

		$this->setImportedPageLinks($pageId, $x, $y, $newSize);

		return $newSize;
	}

	/**
	 * Draw the visible content of a Tier 0 (encrypted-source) placeholder.
	 *
	 * The Artifact wrap brackets are emitted by useImportedPage(); this method
	 * is responsible only for what (if anything) appears between them. The
	 * default implementation emits no marks — a zero-content BMC/EMC pair is
	 * spec-legal under ISO 32000-1:2008 §14.6 and keeps the imported page
	 * footprint visually invisible (matching the behaviour of an empty
	 * page-template region). Subclasses may override to draw a faint outline
	 * rectangle for debugging.
	 *
	 * @param  float|int $x       upper-left x in user units
	 * @param  float|int $y       upper-left y in user units
	 * @param  float|int $width   placeholder width in user units
	 * @param  float|int $height  placeholder height in user units
	 * @return void
	 */
	protected function drawEncryptedSourcePlaceholder($x, $y, $width, $height)
	{
		// Intentionally empty content stream — see method docblock.
		unset($x, $y, $width, $height);
	}

	/**
	 * Imports a page.
	 *
	 * @param int $pageNumber The page number.
	 * @param string $box The page boundary to import. Default set to PageBoundaries::CROP_BOX.
	 * @param bool $groupXObject Define the form XObject as a group XObject to support transparency (if used).
	 * @return string A unique string identifying the imported page.
	 * @throws CrossReferenceException
	 * @throws FilterException
	 * @throws PdfParserException
	 * @throws PdfTypeException
	 * @throws PdfReaderException
	 * @see PageBoundaries
	 */
	public function importPage($pageNumber, $box = PageBoundaries::CROP_BOX, $groupXObject = true)
	{
		// PDF/UA-1 Tier 0 fast path — if setSourceFile() previously caught a
		// CrossReferenceException::ENCRYPTED for the current source in auto mode,
		// the FPDI parser was never wired up (currentReaderId may still be set
		// to the file, but the trailer was never loaded). Synthesise a placeholder
		// pageId without re-attempting fpdiImportPage(), which would re-throw.
		if ($this->PDFUA && !empty($this->encryptedSourceFiles)) {
			$activeKey = $this->currentEncryptedSourceKey();
			if ($activeKey !== null && isset($this->encryptedSourceFiles[$activeKey])) {
				return $this->handleEncryptedImportInUaMode($pageNumber, $activeKey);
			}
		}

		try {
			$pageId = $this->fpdiImportPage($pageNumber, $box, $groupXObject);
		} catch (CrossReferenceException $e) {
			if ($this->PDFUA && $e->getCode() === CrossReferenceException::ENCRYPTED) {
				// Late-detected encryption — vendor parser threw during page parse
				// rather than during setSourceFile(). Reuse the same Tier 0 path.
				return $this->handleEncryptedImportInUaMode($pageNumber, $this->currentEncryptedSourceKey());
			}
			throw $e;
		}

		$this->importedPages[$pageId]['externalLinks'] = $this->getImportedExternalPageLinks($pageNumber);

		return $pageId;
	}

	/**
	 * Identify the source-file key for the currently-active FPDI reader, if any.
	 *
	 * Used by importPage() to determine whether the in-flight setSourceFile() call
	 * was rejected as encrypted (auto mode). Returns the same string shape as
	 * encryptedSourceKey() so the lookup against $encryptedSourceFiles is direct.
	 *
	 * Falls back to the most-recently-flagged key when the reader id is not
	 * resolvable, on the assumption that the immediately-preceding setSourceFile()
	 * is the source under test (this matches the typical mPDF usage pattern of
	 * one setSourceFile + one importPage per template).
	 *
	 * @return string|null
	 */
	private function currentEncryptedSourceKey()
	{
		if (empty($this->encryptedSourceFiles)) {
			return null;
		}
		// Most recently inserted key — PHP arrays preserve insertion order.
		$keys = array_keys($this->encryptedSourceFiles);
		return end($keys);
	}

	/**
	 * Tier 0 — register an encrypted-source placeholder pageId or throw in strict mode.
	 *
	 * Strict mode (PDFUAauto=false): throws \Mpdf\MpdfException directly. There is
	 * no Form XObject to wrap, so the caller cannot silently demote.
	 *
	 * Auto mode (PDFUAauto=true): builds a synthetic pageId
	 * (ENCRYPTED_PAGE_PLACEHOLDER_ID_PREFIX + monotonically-increasing suffix),
	 * tracks it in $encryptedPageIds, and returns it. useImportedPage() consults
	 * isEncryptedPlaceholder() to short-circuit the FPDI draw path and emit the
	 * Artifact placeholder.
	 *
	 * @param  int         $pageNumber  the page number the caller requested
	 * @param  string|null $sourceKey   key returned by encryptedSourceKey() for diagnostics
	 * @return string                   the synthetic placeholder pageId
	 * @throws \Mpdf\MpdfException      in strict mode
	 */
	private function handleEncryptedImportInUaMode($pageNumber, $sourceKey = null)
	{
		if (empty($this->PDFUAauto)) {
			throw new \Mpdf\MpdfException(
				'Imported PDF source is encrypted (ISO 32000-1:2008 §7.6) and cannot be tagged. '
				. 'vendor/setasign/fpdi exposes no password setter and refuses any document with '
				. 'an /Encrypt entry. Decrypt the source upstream (e.g. `qpdf --decrypt input.pdf '
				. 'output.pdf`), disable PDFUA on this import, or enable PDFUAauto to fall back '
				. 'to /Artifact wrapping (Matterhorn 01-007).'
			);
		}

		$pageId = \Mpdf\Ua\Import\FpdiStructMerger::ENCRYPTED_PAGE_PLACEHOLDER_ID_PREFIX
			. ($sourceKey !== null ? $sourceKey . ':' : '')
			. ((int) $pageNumber)
			. ':' . count($this->encryptedPageIds);

		$this->encryptedPageIds[$pageId] = [
			'file'       => is_string($sourceKey) ? $sourceKey : null,
			'pageNumber' => (int) $pageNumber,
		];

		return $pageId;
	}

	/**
	 * Imports the external page links
	 *
	 * @param int $pageNumber The page number.
	 * @return array
	 * @throws CrossReferenceException
	 * @throws PdfTypeException
	 * @throws \setasign\Fpdi\PdfParser\PdfParserException
	 */
	public function getImportedExternalPageLinks($pageNumber)
	{
		$links = [];

		$reader = $this->getPdfReader($this->currentReaderId);
		$parser = $reader->getParser();

		$page = $reader->getPage($pageNumber);
		$page->getPageDictionary();

		$annotations = $page->getAttribute('Annots');
		if ($annotations instanceof PdfIndirectObjectReference) {
			$annotations = PdfType::resolve($parser->getIndirectObject($annotations->value), $parser);
		}

		if ($annotations instanceof PdfArray) {
			$annotations = PdfType::resolve($annotations, $parser);

			foreach ($annotations->value as $annotation) {
				try {
					$annotation = PdfType::resolve($annotation, $parser);

					$type = PdfName::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'Type'), $parser));
					$subtype = PdfName::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'Subtype'), $parser));
					$link = PdfDictionary::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'A'), $parser));

					/* Skip over annotations that aren't links */
					if ($type->value !== 'Annot' || $subtype->value !== 'Link') {
						continue;
					}

					/* Calculate the link positioning */
					$position = PdfArray::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'Rect'), $parser), 4);
					$rect = Rectangle::byPdfArray($position, $parser);
					$uri = PdfString::ensure(PdfType::resolve(PdfDictionary::get($link, 'URI'), $parser));

					$links[] = [
						'x' => $rect->getLlx() / Mpdf::SCALE,
						'y' => $rect->getLly() / Mpdf::SCALE,
						'width' => $rect->getWidth() / Mpdf::SCALE,
						'height' => $rect->getHeight() / Mpdf::SCALE,
						'url' => $uri->value
					];
				} catch (PdfTypeException $e) {
					continue;
				}
			}
		}

		return $links;
	}

	/**
	 * @param mixed $pageId The page id
	 * @param int|float $x The abscissa of upper-left corner.
	 * @param int|float $y The ordinate of upper-right corner.
	 * @param array $newSize The size.
	 */
	public function setImportedPageLinks($pageId, $x, $y, $newSize)
	{
		$originalSize = $this->getTemplateSize($pageId);
		$pageHeightDifference = $this->h - $newSize['height'];

		/* Handle different aspect ratio */
		$widthRatio = $newSize['width'] / $originalSize['width'];
		$heightRatio = $newSize['height'] / $originalSize['height'];

		foreach ($this->importedPages[$pageId]['externalLinks'] as $item) {

			$item['x'] *= $widthRatio;
			$item['width'] *= $widthRatio;

			$item['y'] *= $heightRatio;
			$item['height'] *= $heightRatio;

			$this->Link(
				$item['x'] + $x,
				/* convert Y to be measured from the top of the page */
				$this->h - $item['y'] - $item['height'] - $pageHeightDifference + $y,
				$item['width'],
				$item['height'],
				$item['url']
			);
		}
	}

	/**
	 * Get the size of an imported page or template.
	 *
	 * Omit one of the size parameters (width, height) to calculate the other one automatically in view to the aspect
	 * ratio.
	 *
	 * @param mixed $tpl The template id
	 * @param float|int|null $width The width.
	 * @param float|int|null $height The height.
	 * @return array|bool An array with following keys: width, height, 0 (=width), 1 (=height), orientation (L or P)
	 */
	public function getTemplateSize($tpl, $width = null, $height = null)
	{
		return $this->getImportedPageSize($tpl, $width, $height);
	}

	/**
	 * @throws CrossReferenceException
	 * @throws PdfTypeException
	 * @throws \setasign\Fpdi\PdfParser\PdfParserException
	 */
	public function writeImportedPagesAndResolvedObjects()
	{
		$this->currentReaderId = null;

		foreach ($this->importedPages as $key => $pageData) {
			$this->writer->object();
			$foXObjectObjNum                           = $this->n;
			$this->importedPages[$key]['objectNumber'] = $foXObjectObjNum;
			$this->currentReaderId = $pageData['readerId'];

			// PDF/UA-1 Tier 2 — inject /StructParents into the Form XObject dict so
			// the ParentTree NumTree back-reference from the Form XObject to its struct
			// elements resolves correctly (ISO 32000-1:2008 §14.7.4.4).
			if ($this->PDFUA) {
				$merger         = $this->ua->getFpdiStructMerger();
				$structParentsN = $merger->getFormXObjectStructParents($key);
				if ($structParentsN >= 0 && $pageData['stream'] instanceof PdfStream) {
					$pageData['stream']->value->value['StructParents'] = PdfNumeric::create($structParentsN);
				}

				// Patch MCR placeholder entries (pageRef=0, stm=0) with real object
				// numbers now that both the Form XObject and page dicts have been
				// written (writePages runs before writeImportedPagesAndResolvedObjects).
				$merger->patchMergedSubtreeObjectNumbers($key, $foXObjectObjNum);
			}

			$this->writePdfType($pageData['stream']);
			$this->_put('endobj');
		}

		foreach (\array_keys($this->readers) as $readerId) {
			$parser = $this->getPdfReader($readerId)->getParser();
			$this->currentReaderId = $readerId;

			while (($objectNumber = \array_pop($this->objectsToCopy[$readerId])) !== null) {
				try {
					$object = $parser->getIndirectObject($objectNumber);

				} catch (CrossReferenceException $e) {
					if ($e->getCode() === CrossReferenceException::OBJECT_NOT_FOUND) {
						$object = PdfIndirectObject::create($objectNumber, 0, new PdfNull());
					} else {
						throw $e;
					}
				}

				$this->writePdfType($object);
			}
		}

		$this->currentReaderId = null;
	}

	public function getImportedPages()
	{
		return $this->importedPages;
	}

	protected function _put($s, $newLine = true)
	{
		$this->buffer->append($s, $newLine);
	}

	/**
	 * Writes a PdfType object to the resulting buffer.
	 *
	 * @param PdfType $value
	 * @throws PdfTypeException
	 */
	public function writePdfType(PdfType $value)
	{
		if (!$this->encrypted) {
			if ($value instanceof PdfIndirectObject) {
				/**
				 * @var $value PdfIndirectObject
				 */
				$n = $this->objectMap[$this->currentReaderId][$value->objectNumber];
				$this->writer->object($n);
				$this->writePdfType($value->value);
				$this->_put('endobj');
				return;
			}

			$this->fpdiWritePdfType($value);
			return;
		}

		if ($value instanceof PdfString) {
			$string = PdfString::unescape($value->value);
			$string = $this->protection->rc4($this->protection->objectKey($this->currentObjectNumber), $string);
			$value->value = $this->writer->escape($string);

		} elseif ($value instanceof PdfHexString) {
			$filter = new AsciiHex();
			$string = $filter->decode($value->value);
			$string = $this->protection->rc4($this->protection->objectKey($this->currentObjectNumber), $string);
			$value->value = $filter->encode($string, true);

		} elseif ($value instanceof PdfStream) {
			$stream = $value->getStream();
			$stream = $this->protection->rc4($this->protection->objectKey($this->currentObjectNumber), $stream);
			$dictionary = $value->value;
			$dictionary->value['Length'] = PdfNumeric::create(\strlen($stream));
			$value = PdfStream::create($dictionary, $stream);

		} elseif ($value instanceof PdfIndirectObject) {
			/**
			 * @var $value PdfIndirectObject
			 */
			$this->currentObjectNumber = $this->objectMap[$this->currentReaderId][$value->objectNumber];
			/**
			 * @var $value PdfIndirectObject
			 */
			$n = $this->objectMap[$this->currentReaderId][$value->objectNumber];
			$this->writer->object($n);
			$this->writePdfType($value->value);
			$this->_put('endobj');
			return;
		}

		$this->fpdiWritePdfType($value);
	}
}
