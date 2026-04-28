<?php

namespace Mpdf\Ua;

/**
 * Phase 4 PDF/UA-1 tests: sticky-note annotations, form widget annotations,
 * barcode Figure tagging, watermark Artifact tagging, and textcircle Span tagging.
 *
 * All tests render small HTML snippets against a PDFUA-enabled Mpdf instance
 * (compress=false so content-stream bytes are directly matchable without
 * FlateDecode decompression).
 *
 * Spec references:
 *   - ISO 14289-1:2014 §7.18 — annotation /Contents requirements
 *   - ISO 32000-1:2008 §14.7.4.4.2 Table 338 — OBJR dict
 *   - ISO 32000-1:2008 §14.8.2.2 — Artifact marking
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — StructElem /K entries
 *
 * @group pdfua
 */
class AnnotationsAndMiscTest extends PdfUaTestCase
{

	// ========================= Sticky-note Annotations =========================

	/**
	 * A sticky-note annotation produces /S /Note in the struct tree.
	 *
	 * The annotation's struct element must appear in the PDF output as a
	 * /Type /StructElem with /S /Note.
	 */
	public function testAnnotationProducesNoteStructElement()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p>Text<annotation content="Review this section" title="Editor"/></p>'
		);
		$this->assertStringContainsString('/S /Note', $output);
	}

	/**
	 * A sticky-note annotation has /StructParent (singular) on its dict.
	 *
	 * /StructParent (singular integer) on the annotation object associates it
	 * with the Note struct element via the ParentTree — distinct from the
	 * /StructParents (plural) on page dicts.
	 */
	public function testAnnotationHasStructParentKey()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p>Text<annotation content="A note" title="Author"/></p>'
		);
		$this->assertStringContainsString('/StructParent', $output);
	}

	/**
	 * A sticky-note annotation has /F 28 in PDFUA mode.
	 *
	 * ISO 14289-1:2014 §7.18 — annotations must have /F bit 3 (Print) and
	 * bit 2 (NoZoom) and bit 1 (NoRotate) set, collectively /F 28.
	 */
	public function testAnnotationHasFlagF28()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p>Text<annotation content="Flag test" title="Tester"/></p>'
		);
		$this->assertStringContainsString('/F 28', $output);
	}

	/**
	 * The Note struct element has an OBJR kid pointing at the annotation object.
	 *
	 * /Type /OBJR in the /K array of the Note struct element is how the struct
	 * tree references the annotation object — not an MCID-based content item.
	 */
	public function testAnnotationNoteStructElementHasObjr()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p>Text<annotation content="Objr test" title="Reviewer"/></p>'
		);
		$this->assertStringContainsString('/Type /OBJR', $output);
	}

	/**
	 * A file-attachment annotation also produces a Note struct element.
	 *
	 * ISO 14289-1:2014 §7.18 — all annotation subtypes (except hidden, outside
	 * CropBox, or Popup) must be in the structure tree. /FileAttachment is not in
	 * the exempt list; when allowAnnotationFiles=true the annotation is a real
	 * PDF object and must carry /StructParent and appear in the struct tree.
	 */
	public function testFileAttachmentAnnotationProducesNoteStructElement()
	{
		$mpdf = $this->makeMpdf(['allowAnnotationFiles' => true]);
		$mpdf->WriteHTML('<p>See attached</p>');
		// Use the API directly — the HTML tag is a thin wrapper around this call.
		$mpdf->Annotation(
			'Attached file',
			0,
			0,
			'Paperclip',
			'Reviewer',
			'',
			1,
			false,
			'',
			__DIR__ . '/../../data/img/issue1609.png'
		);
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/S /Note', $output);
		$this->assertStringContainsString('/StructParent', $output);
		$this->assertStringContainsString('/Type /OBJR', $output);
	}

	// ========================= Barcodes =========================

	/**
	 * A barcode produces /S /Figure in the struct tree.
	 *
	 * Barcodes encode meaningful data so they are tagged as Figure with Alt text,
	 * not as Artifact or decorative content.
	 */
	public function testBarcodeProducesFigureStructElement()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<barcode code="9780954224608" type="EAN13"/>'
		);
		$this->assertStringContainsString('/S /Figure', $output);
	}

	/**
	 * A barcode's Figure struct element carries /Alt.
	 *
	 * The Alt text is "Barcode: <code>" when no aria-label is present.
	 * The value is emitted as a UTF-16BE PDF string so we check for the
	 * /Alt key rather than the raw ASCII digits of the code.
	 */
	public function testBarcodeAltTextContainsCode()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<barcode code="9780954224608" type="EAN13"/>'
		);
		$this->assertStringContainsString('/Alt', $output);
		// "Barcode: 9780954224608" in UTF-16BE — spot-check the "Barcode" label bytes.
		// UTF-16BE for 'B' is 0x0042, for 'a' is 0x0061, etc.
		$this->assertStringContainsString("\x00B\x00a\x00r\x00c\x00o\x00d\x00e", $output);
	}

	/**
	 * A barcode emits /Figure BDC … EMC in the page content stream.
	 *
	 * The BDC/EMC pair must wrap the entire bar + text rendering block.
	 */
	public function testBarcodeBdcEmcWrapsRender()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<barcode code="9780954224608" type="EAN13"/>'
		);
		$this->assertStringContainsString('/Figure', $output);
		$this->assertStringContainsString('BDC', $output);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= Watermarks =========================

	/**
	 * A text watermark is tagged as an Artifact with /Type /Background.
	 *
	 * Watermarks are decorative repeating elements; they must be outside the
	 * logical structure and tagged with /Artifact so AT can ignore them.
	 * See plan §"Why /Type /Background" — Background is the correct Artifact
	 * /Type per ISO 32000-1 §14.8.2.2 Table 329 for decorative overlays.
	 */
	public function testWatermarkTextIsArtifact()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetWatermarkText('DRAFT');
		$mpdf->showWatermarkText = true;
		$output = $this->getOutput($mpdf, '<p>Body text</p>');
		$this->assertStringContainsString('/Artifact', $output);
		$this->assertStringContainsString('/Type /Background', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * An image watermark (in front, watermarkImgBehind=false) is tagged as Artifact
	 * with /Type /Background per ISO 32000-1 §14.8.2.2 Table 329.
	 */
	public function testWatermarkImageFrontIsArtifact()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetWatermarkImage(__DIR__ . '/../../data/img/issue1609.png');
		$mpdf->showWatermarkImage = true;
		$mpdf->watermarkImgBehind = false;
		$output = $this->getOutput($mpdf, '<p>Body text</p>');
		$this->assertStringContainsString('/Artifact', $output);
		$this->assertStringContainsString('/Type /Background', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * An image watermark (behind the page, watermarkImgBehind=true) is tagged as
	 * Artifact with /Type /Background per ISO 32000-1 §14.8.2.2 Table 329.
	 *
	 * The behind-watermark path injects content before the ___BACKGROUND___PATTERNS
	 * marker via preg_replace; BDC/EMC must be part of the injected string.
	 */
	public function testWatermarkImageBehindIsArtifact()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetWatermarkImage(__DIR__ . '/../../data/img/issue1609.png');
		$mpdf->showWatermarkImage = true;
		$mpdf->watermarkImgBehind = true;
		$output = $this->getOutput($mpdf, '<p>Body text</p>');
		$this->assertStringContainsString('/Artifact', $output);
		$this->assertStringContainsString('/Type /Background', $output);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= Active Form Fields =========================

	/**
	 * A text input form field produces /S /Form in the struct tree.
	 *
	 * Requires useActiveForms=true (the default is false; without it mPDF
	 * renders form fields as non-interactive placeholder text).
	 */
	public function testTextInputProducesFormStructElement()
	{
		$mpdf = $this->makeMpdf(['useActiveForms' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<form method="post"><input type="text" name="fname" title="First Name"/></form>'
		);
		$this->assertStringContainsString('/S /Form', $output);
	}

	/**
	 * A text input form field widget dict has /StructParent (singular).
	 *
	 * /StructParent (singular) on the widget annotation object associates it
	 * with the Form struct element via the ParentTree.
	 */
	public function testTextInputHasStructParentKey()
	{
		$mpdf = $this->makeMpdf(['useActiveForms' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<form method="post"><input type="text" name="fname" title="First Name"/></form>'
		);
		$this->assertStringContainsString('/StructParent', $output);
	}

	/**
	 * A text input form field's Form struct element has an OBJR kid.
	 *
	 * The widget annotation is referenced from the Form struct element via
	 * an OBJR (/Type /OBJR) dict — the same mechanism as sticky-note Notes.
	 */
	public function testTextInputFormStructElementHasObjr()
	{
		$mpdf = $this->makeMpdf(['useActiveForms' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<form method="post"><input type="text" name="fname" title="First Name"/></form>'
		);
		$this->assertStringContainsString('/Type /OBJR', $output);
	}

	/**
	 * Multiple widget annotations each produce their own /S /Form struct element.
	 *
	 * ISO 14289-1:2014 §7.18.4 — each individual widget annotation must be
	 * associated with its own Form struct element. Two text inputs in a form
	 * must therefore produce at least two /S /Form struct elements.
	 */
	public function testMultipleWidgetsProduceMultipleFormStructElements()
	{
		$mpdf = $this->makeMpdf(['useActiveForms' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<form method="post">'
			. '<input type="text" name="first" title="First Name"/>'
			. '<input type="text" name="last" title="Last Name"/>'
			. '</form>'
		);
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($output, '/S /Form'),
			'Each widget annotation must have its own /S /Form struct element'
		);
	}

	/**
	 * Each radio button kid widget in a group gets its own /S /Form struct element.
	 *
	 * ISO 14289-1:2014 §7.18.4 — each individual radio kid widget annotation
	 * must be associated with a Form struct element. In PDFUA mode, ZapfDingbats
	 * is suppressed (it is a non-embeddable core font) and appearance streams are
	 * drawn with paths, so radio buttons render without an exception.
	 */
	public function testRadioGroupKidsProduceFormStructElements()
	{
		$mpdf = $this->makeMpdf(['useActiveForms' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<form method="post">'
			. '<input type="radio" name="colour" value="red" title="Red"/>'
			. '<input type="radio" name="colour" value="blue" title="Blue"/>'
			. '</form>'
		);
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($output, '/S /Form'),
			'Each radio kid widget must have its own /S /Form struct element'
		);
	}

	// ========================= TextCircle =========================

	/**
	 * A textcircle element produces /S /Span in the struct tree.
	 *
	 * Circular text is real rendered text; it must be tagged as Span
	 * (not Figure or Artifact) so assistive technology can read it.
	 */
	public function testTextCircleProducesSpanStructElement()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<textcircle top-text="Top Label" r="20" style="font-size: 12pt"/>'
		);
		$this->assertStringContainsString('/S /Span', $output);
	}

	/**
	 * A textcircle element carries /ActualText on its Span struct element.
	 *
	 * The ActualText value is the concatenation of top-text, divider, and
	 * bottom-text so that copy-paste and AT read-out produce the full string.
	 * The value is stored as a UTF-16BE PDF string; we check for the /ActualText
	 * key rather than the raw text bytes.
	 */
	public function testTextCircleHasActualText()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<textcircle top-text="Hello" bottom-text="World" divider=" | " r="20" style="font-size: 12pt"/>'
		);
		$this->assertStringContainsString('/ActualText', $output);
	}

	// ========================= Widget /TU entry =========================

	/**
	 * A widget annotation for a form field must carry /TU (tooltip / user name).
	 *
	 * /TU provides a human-readable alternative to /T (partial field name) so
	 * that assistive technology can announce the field's purpose to the user.
	 * ISO 14289-1:2014 §7.18 — all widget annotations must have /TU.
	 * Matterhorn Protocol 1.1 condition 11-002 — /TU missing from widget dict.
	 * Plan §A9 (priority test list) — testWidgetAnnotationHasTuEntry.
	 *
	 * @group pdfua
	 */
	public function testWidgetAnnotationHasTuEntry()
	{
		$mpdf = $this->makeMpdf(['useActiveForms' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<form method="post"><input type="text" name="email" title="Email address"/></form>'
		);
		// /TU must appear in the annotation dict with the field's title value.
		$this->assertStringContainsString('/TU', $output);
		// The title value is written as a UTF-16BE PDF string with BOM (0xFEFF).
		// utf16BigEndianTextString() prepends the BOM then encodes each character as
		// two bytes big-endian: "Email address" → FEFF 0045 006D 0061 0069 006C ...
		// We spot-check the BOM + first word "Email" in raw binary to confirm the
		// value was encoded correctly (not written as plain ASCII).
		// Pattern: /TU <whitespace> ( <BOM><E><m><a><i><l> — matching Example in
		// testLangAttributeProducesLangOnStructElement which uses \xfe\xff\x00f\x00r.
		$this->assertStringContainsString(
			"/TU (\xfe\xff\x00E\x00m\x00a\x00i\x00l",
			$output,
			'/TU must be followed by a UTF-16BE string starting with BOM and "Email"'
		);
	}

	// ========================= Annotation StructParent round-trip =========================

	/**
	 * The /StructParent integer on an annotation dict must resolve through the
	 * ParentTree to a struct element whose /S is /Note.
	 *
	 * This test verifies the full bidirectional round-trip:
	 *   annotation dict /StructParent N → ParentTree key N → struct elem ref → /S /Note
	 *
	 * ISO 32000-1:2008 §14.7.4.4 — /StructParent (singular) on annotation dicts.
	 * ISO 32000-1:2008 §7.9.7 — NumTree format for the ParentTree.
	 * Plan §A9 (priority test list) — testAnnotationStructParentRoundTrip.
	 *
	 * @group pdfua
	 */
	public function testAnnotationStructParentRoundTrip()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p>Text<annotation content="Note text" title="Author"/></p>'
		);

		// Step 1: find /StructParent N on the annotation dict.
		$found = preg_match('/\/StructParent (\d+)/', $output, $spMatch);
		$this->assertSame(1, $found, '/StructParent must appear on the annotation dict');
		$spIndex = (int) $spMatch[1];

		// Step 2: find the ParentTree /Nums array and look up index $spIndex.
		// The NumTree leaf for annotation entries is: index REF (single ref, no array wrapper).
		// Pattern: key followed by "N 0 R" (not wrapped in []).
		$numsFound = preg_match('/\/Nums \[([^\]]+)\]/', $output, $numsMatch);
		$this->assertSame(1, $numsFound, '/Nums array must exist in the ParentTree');
		$numsContent = $numsMatch[1];

		// Find the value for our specific index in the NumTree.
		// Annotation entries: "N  M 0 R" (single ref); page entries: "N [...]".
		$entryPattern = '/' . $spIndex . '\s+(\d+)\s+0\s+R\b/';
		$entryFound = preg_match($entryPattern, $numsContent, $entryMatch);
		$this->assertSame(
			1,
			$entryFound,
			'ParentTree must have a single-ref entry at index ' . $spIndex . ' for the annotation'
		);
		$structElemObjNum = (int) $entryMatch[1];

		// Step 3: find the struct element object and confirm /S /Note.
		$objPattern = '/' . $structElemObjNum . '\s+0\s+obj\s*<<([^>]+(?:>[^>]+)*?)>>/';
		$objFound = preg_match($objPattern, $output, $objMatch);
		$this->assertSame(
			1,
			$objFound,
			'Struct element object ' . $structElemObjNum . ' must be present in the PDF output'
		);
		$this->assertStringContainsString(
			'/S /Note',
			$objMatch[0],
			'The struct element referenced by ParentTree[' . $spIndex . '] must have /S /Note'
		);
	}

	// ========================= Helpers =========================

	/**
	 * Count BDC, BMC, and EMC operators; assert they balance.
	 *
	 * @param  string $output  raw PDF bytes
	 * @return void
	 */
	private function assertBdcEmcBalanced($output)
	{
		$bdcCount = preg_match_all('/\bBDC\b/', $output);
		$bmcCount = preg_match_all('/\bBMC\b/', $output);
		$emcCount = preg_match_all('/\bEMC\b/', $output);
		$this->assertEquals(
			$bdcCount + $bmcCount,
			$emcCount,
			sprintf('BDC(%d)+BMC(%d) must equal EMC(%d)', $bdcCount, $bmcCount, $emcCount)
		);
	}
}
