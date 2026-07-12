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

	/**
	 * Key (encryptedSourceKey() shape) of the source passed to the most recent
	 * setSourceFile() call, whether it succeeded or was flagged encrypted.
	 *
	 * importPage() reads from whichever source was set last, so this — not the
	 * most-recently-*flagged* key — is what decides the Tier 0 placeholder path.
	 * Using the last-flagged key instead would blank every later import from a
	 * valid source once any earlier source was encrypted.
	 *
	 * @var string|null
	 */
	protected $lastSetSourceKey = null;

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
	 *     $encryptedSourceFiles and returns the source page count (recovered from
	 *     the cleartext page tree; UA1 audit E2) so the caller's per-page loop
	 *     reaches importPage() once per page, each returning a placeholder pageId.
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
	 * @return int  page count (recovered source page count in auto mode for an encrypted source)
	 * @throws \Mpdf\MpdfException             in strict mode for an encrypted source
	 * @throws CrossReferenceException         for non-encryption parser failures
	 */
	public function setSourceFile($file)
	{
		try {
			$pageCount = $this->fpdiSetSourceFile($file);
			if ($this->PDFUA) {
				$this->lastSetSourceKey = $this->encryptedSourceKey($file);
			}
			return $pageCount;
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
	 * @return int  page count (recovered source page count in auto mode for an encrypted source)
	 * @throws \Mpdf\MpdfException
	 * @throws CrossReferenceException
	 */
	public function setSourceFileWithParserParams($file, array $parserParams = [])
	{
		try {
			$pageCount = $this->fpdiSetSourceFileWithParserParams($file, $parserParams);
			if ($this->PDFUA) {
				$this->lastSetSourceKey = $this->encryptedSourceKey($file);
			}
			return $pageCount;
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
	 * @return int                            recovered source page count in auto mode
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
		$this->lastSetSourceKey = $key;

		// UA1 audit E2 — recover the real page count so a multi-page encrypted
		// source produces one placeholder per source page instead of collapsing
		// to a single blank page. FPDI never wired up a parser (it threw), but
		// encryption only enciphers strings and streams (ISO 32000-1:2008
		// §7.6.2), so the page-tree root's /Type /Pages … /Count N stays in
		// cleartext and can be scanned without the key.
		$pageCount = $this->countEncryptedSourcePages($file);

		// Surface a UA-aware warning at this stage so callers inspecting
		// getPdfUaWarnings() after Output() see the encrypted-source diagnostic
		// even if importPage() is not subsequently called for some reason.
		if ($this->ua !== null) {
			$this->ua->addWarning(sprintf(
				'Imported PDF source is encrypted (ISO 32000-1:2008 §7.6) and cannot be parsed by '
				. 'vendor/setasign/fpdi. Auto-mode fallback: importPage() will return a synthetic '
				. 'pageId and useImportedPage() will draw a Tier 0 /Artifact <</Type /Layout>> '
				. 'visible placeholder (border + caption) for each of the %d source page(s) in place '
				. 'of the original content. Matterhorn 01-007.',
				$pageCount
			));
		}

		// Return the (recovered) source page count so the caller's loop —
		// typically `for ($i = 1; $i <= setSourceFile($file); $i++) importPage($i)` —
		// visits every page and reaches the Tier 0 path once per page rather than
		// silently discarding pages 2..N.
		return $pageCount;
	}

	/**
	 * Best-effort page count for an encrypted source that FPDI refused to parse.
	 *
	 * A standard-security-handler document (ISO 32000-1:2008 §7.6.4) enciphers
	 * only string and stream objects; name tokens and integers — including the
	 * page-tree root's `/Type /Pages … /Count N` — remain in cleartext. Scanning
	 * the raw bytes for the largest `/Count` sitting in a `/Pages` dictionary
	 * therefore recovers the total leaf-page count without the decryption key.
	 *
	 * Falls back to 1 when the bytes are unreadable (e.g. an opaque StreamReader
	 * whose underlying resource cannot be rewound) or no page tree is found, so
	 * at least one visible placeholder is still emitted.
	 *
	 * @param  mixed $file the original $file argument passed to setSourceFile()
	 * @return int         source page count, clamped to a minimum of 1
	 */
	private function countEncryptedSourcePages($file)
	{
		$bytes = $this->readSourceBytes($file);
		if ($bytes === null || $bytes === '') {
			return 1;
		}

		// Match /Count and /Type /Pages in either order within a single dict.
		// [^>]*? cannot cross the dict's closing `>>`, so a /Count from one
		// object never binds to a /Pages in another. The tree root carries the
		// total; nested /Pages nodes carry sub-counts, so take the maximum.
		$max = 0;
		if (preg_match_all(
			'#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)|/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#s',
			$bytes,
			$matches,
			PREG_SET_ORDER
		)) {
			foreach ($matches as $m) {
				$n = max((int) ($m[1] ?? 0), (int) ($m[2] ?? 0));
				if ($n > $max) {
					$max = $n;
				}
			}
		}

		return $max > 0 ? $max : 1;
	}

	/**
	 * Read the full byte content of a setSourceFile() argument for scanning.
	 *
	 * Handles the same input shapes FPDI accepts — a path string, a stream
	 * resource, or an FPDI StreamReader wrapping one — restoring any stream
	 * position it touches so a later (auto-mode) reader is unaffected. Returns
	 * null when no bytes can be obtained.
	 *
	 * @param  mixed $file
	 * @return string|null
	 */
	private function readSourceBytes($file)
	{
		if (is_string($file)) {
			$bytes = @file_get_contents($file);
			return $bytes !== false ? $bytes : null;
		}

		$stream = null;
		if ($file instanceof \setasign\Fpdi\PdfParser\StreamReader) {
			$stream = $file->getStream();
		} elseif (is_resource($file)) {
			$stream = $file;
		}

		if (is_resource($stream)) {
			$pos   = @ftell($stream);
			@rewind($stream);
			$bytes = @stream_get_contents($stream);
			if ($pos !== false) {
				@fseek($stream, $pos);
			}
			return $bytes !== false ? $bytes : null;
		}

		return null;
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
			// UA1 audit L-5 — synthesise a stable, non-leaking key. The full
			// resolved path used to surface in synthetic pageIds (and any
			// warning that quoted them), exposing $_SERVER['DOCUMENT_ROOT']
			// or container internals to PDF consumers. Hash the canonical
			// path for the matching identity, but only emit a redacted
			// human-readable hint (basename) so logs still carry context.
			$rp     = @realpath($file);
			$canon  = $rp !== false ? $rp : $file;
			$digest = substr(sha1($canon), 0, 12);
			return 'file:' . $this->redactPath($canon) . '@' . $digest;
		}
		if (is_object($file)) {
			return 'object:' . spl_object_hash($file);
		}
		return 'unknown:' . gettype($file);
	}

	/**
	 * Redact a filesystem path for inclusion in user-facing diagnostics.
	 *
	 * Returns the basename only; absolute paths leak deployment topology
	 * (web root, container layout, build paths) when warnings are surfaced
	 * to PDF consumers via getPdfUaWarnings(). Empty / non-string inputs
	 * round-trip as the empty string.
	 *
	 * UA1 audit I-6 — structural guarantee that future warning sites
	 * cannot regress past path redaction.
	 *
	 * @param  mixed $path
	 * @return string
	 */
	private function redactPath($path)
	{
		if (!is_string($path) || $path === '') {
			return '';
		}
		return basename($path);
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
		// downstream layout logic remains valid. UA1 audit E2 — the placeholder
		// draws a visible border + caption naming the source so the content loss
		// is not silent (it is an Artifact, so it needs no tagging).
		if ($this->PDFUA && $this->isEncryptedPlaceholder($pageId)) {
			$pdfuaMerger = $this->ua->getFpdiStructMerger();

			// Default placeholder dimensions to the host page's content area so
			// the caller does not have to supply width/height for an opaque
			// fallback. Width/height passed by the caller take precedence.
			$resolvedWidth  = ($width !== null) ? $width : $this->pgwidth;
			$resolvedHeight = ($height !== null) ? $height : ($this->h - $this->tMargin - $this->bMargin);

			$this->writer->write('/Artifact <</Type /Layout>> BDC');
			$this->drawEncryptedSourcePlaceholder(
				$x,
				$y,
				$resolvedWidth,
				$resolvedHeight,
				$this->encryptedPlaceholderCaption($pageId)
			);
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
				// Flush merger-side warnings (e.g. cycle / depth-cap / node-budget
				// diagnostics from FpdiStructMerger; UA1 audit H-2 / M-4) into
				// UaState so callers can see them via getPdfUaWarnings().
				foreach ($pdfuaMerger->getUntaggedWarnings() as $w) {
					$this->ua->addWarning($w);
				}
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
	 * draws what appears between them. UA1 audit E2 — an encrypted source cannot
	 * be parsed, so rather than an invisible zero-content BMC/EMC pair (which
	 * loses the page silently) it strokes a faint border around the placeholder
	 * footprint and prints a caption naming the source. The marks sit inside the
	 * /Artifact <</Type /Layout>> wrap, so they are decorative (non-content) and
	 * require no tagging, while making the omission visible to a sighted reader.
	 *
	 * Runs inside a q…Q graphics-state save so its colour/line-width changes do
	 * not leak; the mPDF-side state trackers are realigned afterwards because
	 * Q reverts the actual PDF state but not mPDF's cached model of it.
	 *
	 * @param  float|int $x        upper-left x in user units
	 * @param  float|int $y        upper-left y in user units
	 * @param  float|int $width    placeholder width in user units
	 * @param  float|int $height   placeholder height in user units
	 * @param  string    $caption  human-readable source label drawn top-left
	 * @return void
	 */
	protected function drawEncryptedSourcePlaceholder($x, $y, $width, $height, $caption = '')
	{
		if ($width <= 0 || $height <= 0) {
			return;
		}

		// Snapshot the mPDF-side drawing state so it can be realigned after the
		// q…Q pair (Q restores the actual PDF state; these cached fields are not).
		$prevLineWidth  = $this->LineWidth;
		$prevDrawColor  = $this->DrawColor;
		$prevFillColor  = $this->FillColor;
		$prevTextColor  = $this->TextColor;
		$prevColorFlag  = $this->ColorFlag;
		$prevFontFamily = $this->FontFamily;
		$prevFontStyle  = $this->FontStyle;
		$prevFontSizePt = $this->FontSizePt;

		$this->writer->write('q');

		// Faint grey border tracing the lost page's footprint.
		$this->SetDrawColor(128);
		$this->SetLineWidth(0.2);
		$this->Rect($x, $y, $width, $height, 'S');

		// Caption naming the (redacted) source, in an embedded font so the
		// document stays PDF/UA-1 conformant (ISO 14289-1:2014 §7.21).
		if ($caption !== '') {
			$this->SetFont($prevFontFamily !== '' ? $prevFontFamily : '', '', 8);
			$this->SetTextColor(128);
			$this->Text($x + 2, $y + $this->FontSize + 1, $caption);
		}

		$this->writer->write('Q');

		// Realign mPDF's cached graphics state with the post-Q actual state.
		$this->LineWidth = $prevLineWidth;
		$this->DrawColor = $prevDrawColor;
		$this->FillColor = $prevFillColor;
		$this->TextColor = $prevTextColor;
		$this->ColorFlag = $prevColorFlag;
		if (isset($this->pageoutput[$this->page])) {
			unset(
				$this->pageoutput[$this->page]['LineWidth'],
				$this->pageoutput[$this->page]['DrawColor'],
				$this->pageoutput[$this->page]['FillColor']
			);
		}
		if ($prevFontFamily !== '') {
			$this->SetFont($prevFontFamily, $prevFontStyle, $prevFontSizePt);
		}
	}

	/**
	 * Build the caption drawn on a Tier 0 encrypted-source placeholder.
	 *
	 * Uses only the redacted diagnostics recorded in $encryptedPageIds (never a
	 * full filesystem path — UA1 audit L-5 / I-6), reducing the stored source
	 * key `file:<basename>@<digest>` back to its basename for a friendly label.
	 *
	 * @param  string $pageId  the synthetic placeholder pageId
	 * @return string
	 */
	private function encryptedPlaceholderCaption($pageId)
	{
		$meta = isset($this->encryptedPageIds[$pageId]) ? $this->encryptedPageIds[$pageId] : [];
		$label = (isset($meta['file']) && is_string($meta['file'])) ? $meta['file'] : '';
		if (preg_match('/^file:(?P<name>.*)@[0-9a-f]+$/', $label, $m) && $m['name'] !== '') {
			$label = $m['name'];
		}
		if ($label === '') {
			$label = 'encrypted PDF';
		}
		$pageNumber = isset($meta['pageNumber']) ? (int) $meta['pageNumber'] : 0;

		return sprintf('Encrypted PDF source omitted (page %d): %s', $pageNumber, $label);
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
		// PDF/UA-1 Tier 0 fast path — if the source set by the most recent
		// setSourceFile() was caught as CrossReferenceException::ENCRYPTED in auto
		// mode, its parser was never wired up. Synthesise a placeholder pageId
		// without re-attempting fpdiImportPage(), which would re-throw. Keying on
		// the last-set source (not the last-flagged one) is what stops a valid
		// import that follows an encrypted one from being blanked.
		if ($this->PDFUA
			&& $this->lastSetSourceKey !== null
			&& isset($this->encryptedSourceFiles[$this->lastSetSourceKey])
		) {
			return $this->handleEncryptedImportInUaMode($pageNumber, $this->lastSetSourceKey);
		}

		try {
			$pageId = $this->fpdiImportPage($pageNumber, $box, $groupXObject);
		} catch (CrossReferenceException $e) {
			if ($this->PDFUA && $e->getCode() === CrossReferenceException::ENCRYPTED) {
				// Late-detected encryption — vendor parser threw during page parse
				// rather than during setSourceFile(). Flag the active source and
				// reuse the same Tier 0 path.
				if ($this->lastSetSourceKey !== null) {
					$this->encryptedSourceFiles[$this->lastSetSourceKey] = true;
				}
				return $this->handleEncryptedImportInUaMode($pageNumber, $this->lastSetSourceKey);
			}
			throw $e;
		}

		$this->importedPages[$pageId]['externalLinks'] = $this->getImportedExternalPageLinks($pageNumber);

		return $pageId;
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
