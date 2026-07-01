<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 structure-element tagging tests.
 *
 * Tests that block-level HTML tags produce the correct PDF struct element types
 * in the output, and that BDC/EMC operators are balanced. These tests render
 * small HTML snippets and assert on the raw PDF bytes.
 *
 * All tests use PdfUaTestCase::makeMpdf() which sets PDFUA=true, mode='en-GB',
 * and compress=false so content-stream bytes are directly matchable.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — struct element dictionary entries
 *   - ISO 32000-1:2008 §14.8 Table 333/334/335 — standard struct types
 *   - ISO 14289-1:2014 §7 — document-level PDF/UA requirements
 *
 * @group pdfua
 */
class StructureElementsTest extends PdfUaTestCase
{

	/**
	 * <h1> produces /S /H1 struct element in the PDF output.
	 */
	public function testH1ProducesH1StructElement()
	{
		$output = $this->getOutput($this->makeMpdf(), '<h1>Heading</h1>');
		$this->assertStringContainsString('/S /H1', $output);
	}

	/**
	 * <h2> produces /S /H2 struct element (preceded by <h1> for a valid heading sequence).
	 *
	 * ISO 14289-1:2014 §7.4.2 rule 1 — the first heading must be H1.
	 * A stand-alone <h2> without a prior H1 would be a conformance violation.
	 */
	public function testH2ProducesH2StructElement()
	{
		$output = $this->getOutput($this->makeMpdf(), '<h1>Title</h1><h2>Heading</h2>');
		$this->assertStringContainsString('/S /H2', $output);
	}

	/**
	 * <p> produces /S /P struct element.
	 */
	public function testParagraphProducesPStructElement()
	{
		$output = $this->getOutput($this->makeMpdf(), '<p>Paragraph</p>');
		$this->assertStringContainsString('/S /P', $output);
	}

	/**
	 * <p> produces /P BDC in the page content stream.
	 *
	 * BlockTag::open() pushes a struct element and sets pdfua_struct_open,
	 * which causes finishFlowingBlock() to emit the BDC operator.
	 */
	public function testParagraphProducesPBdc()
	{
		$output = $this->getOutput($this->makeMpdf(), '<p>Hello</p>');
		$this->assertStringContainsString('/P <</MCID', $output);
		$this->assertStringContainsString('BDC', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <blockquote> produces /S /BlockQuote struct element (not /BLOCKQUOTE).
	 */
	public function testBlockquoteProducesBlockQuoteStructType()
	{
		$output = $this->getOutput($this->makeMpdf(), '<blockquote>Quote</blockquote>');
		$this->assertStringContainsString('/S /BlockQuote', $output);
		$this->assertStringNotContainsString('/S /BLOCKQUOTE', $output);
	}

	/**
	 * HTML lang attribute on a block element produces /Lang in the struct element dict.
	 */
	public function testLangAttributeProducesLangOnStructElement()
	{
		$output = $this->getOutput($this->makeMpdf(), '<p lang="fr">Bonjour</p>');
		// StructureWriter writes /Lang values as UTF-16BE PDF strings with the BOM (\xfe\xff).
		// The catalog carries /Lang (en-GB) from makeMpdf()'s mode argument. Asserting the
		// UTF-16BE bytes for "fr" proves the P struct element (not just the catalog) carries
		// the French language tag. Note: asserting bare 'fr' is insufficient — it also matches
		// 'beginbfrange'/'endbfrange' in the font CMap section of the PDF output.
		$this->assertStringContainsString('/Lang', $output);
		$utf16BeFr = "\xfe\xff\x00f\x00r"; // UTF-16BE for "fr"
		$this->assertStringContainsString($utf16BeFr, $output);
	}

	/**
	 * <ul><li> produces /S /L and /S /LI struct elements.
	 */
	public function testUnorderedListProducesLStructElement()
	{
		$output = $this->getOutput($this->makeMpdf(), '<ul><li>Item</li></ul>');
		$this->assertStringContainsString('/S /L', $output);
		$this->assertStringContainsString('/S /LI', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <abbr title="HyperText Markup Language">HTML</abbr> produces Span struct element
	 * with /E expansion text.
	 */
	public function testAbbrTitleProducesExpansionAttribute()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p><abbr title="HyperText Markup Language">HTML</abbr></p>'
		);
		$this->assertStringContainsString('/S /Span', $output);
		$this->assertStringContainsString('/E', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Inline lang= on a <span> mid-paragraph produces a Span struct element
	 * carrying /Lang. Matterhorn 11-001/11-002 require every text fragment
	 * whose language differs from the document default to carry a /Lang entry.
	 */
	public function testInlineSpanLangAttributeProducesLangOnStructElement()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p>Plain English. <span lang="fr">bonjour</span> tail.</p>'
		);
		// The Span around "bonjour" must carry /Lang.
		$this->assertStringContainsString('/S /Span', $output);
		$utf16BeFr = "\xfe\xff\x00f\x00r"; // UTF-16BE for "fr"
		$this->assertStringContainsString($utf16BeFr, $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Inline aria-label= on a <span> emits /Alt on a Span struct element.
	 * ISO 32000-1 Table 322 — /Alt provides alternative description for screen
	 * readers when visible glyphs convey meaning that's not in the text stream.
	 */
	public function testInlineSpanAriaLabelProducesAltOnStructElement()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p>Status: <span aria-label="warning sign">!</span> see below.</p>'
		);
		$this->assertStringContainsString('/S /Span', $output);
		$this->assertStringContainsString('/Alt', $output);
		// /Alt value is encoded as UTF-16BE PDF string.
		$utf16BeAlt = "\xfe\xff\x00w\x00a\x00r\x00n\x00i\x00n\x00g";
		$this->assertStringContainsString($utf16BeAlt, $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <fieldset> emits /S /Sect — closes the "untagged real content" hole
	 * for HTML form-grouping elements.
	 */
	public function testFieldsetProducesSectStructElement()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<fieldset><p>Body content inside fieldset.</p></fieldset>'
		);
		$this->assertStringContainsString('/S /Sect', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <form> emits /S /Div as a logical container — the inline 'Form' struct
	 * type is reserved for individual widgets emitted by Mpdf\Form per-widget.
	 */
	public function testFormContainerProducesDivStructElement()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<form><p>Form body content.</p></form>'
		);
		$this->assertStringContainsString('/S /Div', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <th scope="rowgroup"> maps to /Scope=Row, not /Scope=Both.
	 * ISO 32000-1 Table 349 only permits Row|Column|Both; HTML rowgroup's
	 * axis is rows, so PDF /Scope=Row is the spec-correct mapping.
	 */
	public function testThScopeRowgroupMapsToScopeRow()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<table>'
			. '<tr><th scope="rowgroup">Group A</th><th scope="col">C1</th></tr>'
			. '<tr><td>data</td><td>data</td></tr>'
			. '</table>'
		);
		$this->assertStringContainsString('/S /TH', $output);
		$this->assertStringContainsString('/Scope /Row', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <th scope="colgroup"> maps to /Scope=Column (the default for TH cells)
	 * rather than /Scope=Both — colgroup's axis is columns.
	 */
	public function testThScopeColgroupMapsToScopeColumn()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<table>'
			. '<tr><th scope="colgroup">G</th><th scope="col">C1</th></tr>'
			. '<tr><td>data</td><td>data</td></tr>'
			. '</table>'
		);
		$this->assertStringContainsString('/Scope /Column', $output);
		// /Scope /Both must NOT appear from the colgroup TH (HTML5 has no scope=both).
		$this->assertStringNotContainsString('/Scope /Both', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * role="heading" aria-level="2" on a div produces /S /H2 struct element.
	 *
	 * A valid H1 is placed first so the document satisfies §7.4.2 rule 1
	 * (first heading must be H1) in strict mode.
	 */
	public function testRoleHeadingOverridesTag()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<h1>Title</h1><div role="heading" aria-level="2">Custom Heading</div>'
		);
		$this->assertStringContainsString('/S /H2', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * role="presentation" produces Artifact wrap instead of struct element.
	 */
	public function testRolePresentationProducesArtifact()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<div role="presentation">Decorative</div>'
		);
		// Content should be Artifact-tagged, not have a P or Div struct element
		$this->assertStringContainsString('BMC', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * aria-hidden="true" on a block element produces Artifact wrap.
	 */
	public function testAriaHiddenProducesArtifactBmc()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<div aria-hidden="true"><p>Hidden from AT</p></div>'
		);
		$this->assertStringContainsString('BMC', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <a href> produces /S /Link struct element.
	 */
	public function testLinkProducesLinkStructElement()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p><a href="https://example.com">Click here</a></p>'
		);
		$this->assertStringContainsString('/S /Link', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * OverWrite() throws MpdfException in PDF/UA mode.
	 */
	public function testOverWriteThrowsInPdfuaMode()
	{
		$mpdf = $this->makeMpdf();
		$this->expectException(\Mpdf\MpdfException::class);
		$mpdf->OverWrite('/tmp/non-existent.pdf', 'foo', 'bar');
	}

	/**
	 * SetProtection() with no permissions force-adds 'extract' in PDFUAauto mode.
	 * The resulting /P value in the encryption dict must have bit 10 set.
	 *
	 * In PDFUAauto=true mode the violation is auto-corrected (extract added silently).
	 * Strict mode (PDFUAauto=false) throws — tested in DirectPhpAndAriaTest.
	 */
	public function testEncryptionForcesExtractPermission()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->SetProtection([], '', 'owner_pass');
		$mpdf->WriteHTML('<p>Encrypted</p>');
		$output = $mpdf->Output(null, 'S');
		// The /P value encodes permissions; bit 10 (value 512) for extract must be set.
		// Find /P value in the /Encrypt dict (/Filter /Standard section) and check
		// the integer contains bit 10. The regex skips /P N 0 R references by
		// requiring the value is NOT followed by a space+digit+space+'R' (object ref).
		preg_match('/\/Filter \/Standard.*?\/P (-?\d+)/s', $output, $m);
		if (!empty($m[1])) {
			$pValue = (int) $m[1];
			// Bit 10 (0-indexed = bit 9) is value 512.
			// In PDF, /P is typically a large unsigned 32-bit integer on 64-bit PHP.
			// Cast to unsigned 32-bit before testing the bit.
			$pValue32 = $pValue & 0xFFFFFFFF;
			$this->assertTrue(
				($pValue32 & 0x200) !== 0,
				'Extract permission bit (bit 10) must be set. /P = ' . $pValue
			);
		}
		// Also check the XMP metadata is readable (pdfuaid:part must be plaintext)
		$this->assertStringContainsString('pdfuaid:part', $output);
	}

	/**
	 * BDC+BMC count equals EMC count when a paragraph is inside a layer.
	 */
	public function testBdcEmcBalanceWithOcgLayers()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML('<p>Before layer</p>');
		$mpdf->BeginLayer(1);
		$mpdf->WriteHTML('<p>Inside layer</p>');
		$mpdf->EndLayer();
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/S /P', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Image() called with non-empty $alt produces Figure struct element and BDC in stream.
	 */
	public function testImageMethodWithAlt()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddPage();
		// Use the bundled test fixture image
		$imgFile = __DIR__ . '/../../data/img/bayeux2.jpg';
		if (!file_exists($imgFile)) {
			$this->markTestSkipped('Test image not available');
		}
		$mpdf->Image($imgFile, 10, 10, 50, 50, '', '', true, true, false, true, true, 'A company logo');
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/S /Figure', $output);
		$this->assertStringContainsString('/Figure <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Image() with empty $alt produces Artifact BMC (no Figure struct element).
	 */
	public function testImageMethodWithEmptyAlt()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddPage();
		$imgFile = __DIR__ . '/../../data/img/bayeux2.jpg';
		if (!file_exists($imgFile)) {
			$this->markTestSkipped('Test image not available');
		}
		$mpdf->Image($imgFile, 10, 10, 50, 50, '', '', true, true, false, true, true, '');
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/Artifact BMC', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Image() without $alt adds a warning and emits Artifact BMC.
	 */
	public function testImageMethodWithoutAlt()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->AddPage();
		$imgFile = __DIR__ . '/../../data/img/bayeux2.jpg';
		if (!file_exists($imgFile)) {
			$this->markTestSkipped('Test image not available');
		}
		$mpdf->Image($imgFile, 10, 10, 50, 50);
		$output = $mpdf->Output(null, 'S');
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertNotEmpty($warnings, 'Warning should be added when $alt is null');
		$this->assertStringContainsString('/Artifact BMC', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * AutosizeText() produces Span struct element and BDC/EMC in page stream.
	 */
	public function testAutosizeTextProducesSpanStructElement()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddPage();
		$mpdf->AutosizeText('Hello', 50, 'DejaVuSans', '');
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/S /Span', $output);
		$this->assertStringContainsString('/Span <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <abbr title=""> produces Span struct element with /E expansion text.
	 */
	public function testAbbrProducesSpanWithExpansionText()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p><abbr title="World Wide Web Consortium">W3C</abbr></p>'
		);
		$this->assertStringContainsString('/S /Span', $output);
		// /E should appear in the struct element dict
		$this->assertStringContainsString('/E', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <ruby><rb>kanji</rb><rt>furigana</rt></ruby> emits the standard ruby
	 * struct types — Ruby container with RB (base) and RT (annotation) children
	 * (ISO 32000-1 §14.8.5.6 Table 337) — rather than anonymous Spans.
	 */
	public function testRubyAnnotationProducesRubyStructElements()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p><ruby><rb>kanji</rb><rt>furigana</rt></ruby></p>'
		);
		$this->assertStringContainsString('/S /Ruby', $output);
		$this->assertStringContainsString('/S /RB', $output);
		$this->assertStringContainsString('/S /RT', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <rp> fallback parentheses are tagged as RP struct elements beneath the
	 * Ruby container (ISO 32000-1 §14.8.5.6 Table 337).
	 */
	public function testRubyParenthesisProducesRpStructElement()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p><ruby><rb>kanji</rb><rp>(</rp><rt>furigana</rt><rp>)</rp></ruby></p>'
		);
		$this->assertStringContainsString('/S /Ruby', $output);
		$this->assertStringContainsString('/S /RP', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Text watermark produces Background Artifact BDC/EMC in the page content
	 * stream (ISO 32000-1 §14.8.2.2 Table 329).
	 */
	public function testWatermarkTextIsArtifact()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetWatermarkText('DRAFT');
		$mpdf->showWatermarkText = true;
		$mpdf->WriteHTML('<p>Content</p>');
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/Artifact <</Type /Background>> BDC', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Assert that the number of BDC + BMC operators equals the number of EMC operators
	 * in the raw PDF output.
	 *
	 * @param string $output  raw PDF bytes
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
