<?php

namespace Mpdf\Ua;

/**
 * veraPDF conformance gate for PDF/UA-1 output.
 *
 * Each test method generates a PDF document, writes it to a temp file, invokes
 * the veraPDF CLI validator via proc_open, parses the JSON report, and asserts
 * that the document is compliant with the ua1 flavour.
 *
 * The entire class is skipped when the VERAPDF_BIN environment variable is
 * empty or points to a non-executable path — mirroring the Snapshot skip
 * pattern used in tests/Snapshots/Snapshot.php for imagick/ghostscript.
 *
 * Spec references:
 *   - ISO 14289-1:2014 (PDF/UA-1) — the standard being validated
 *   - Matterhorn Protocol 1.1 — machine-readable failure conditions used by veraPDF
 *
 * Run locally:
 *   VERAPDF_BIN=/path/to/verapdf vendor/bin/phpunit --group=verapdf
 *
 * CI runs this via the dedicated `verapdf` job in .github/workflows/tests.yml.
 *
 * @group verapdf
 * @see PdfUaTestCase  base class supplying makeMpdf() and getOutput()
 */
class VeraPdfConformanceTest extends PdfUaTestCase
{
	/**
	 * Path to the veraPDF CLI binary, resolved once in set_up().
	 *
	 * Empty string when the binary is unavailable; set_up() calls
	 * markTestSkipped() in that case so the property is never used.
	 *
	 * @var string
	 */
	private $veraPdfBin = '';

	/**
	 * Resolve the veraPDF binary path and skip the entire class when unavailable.
	 *
	 * Reads VERAPDF_BIN from the environment. If the env var is unset or the
	 * binary is not executable, the test is skipped with a helpful message.
	 * This mirrors the Imagick skip check in Snapshot::testSnapshot().
	 *
	 * @return void
	 */
	protected function set_up()
	{
		parent::set_up();

		$bin = (string) getenv('VERAPDF_BIN');

		if ($bin === '') {
			$this->markTestSkipped(
				'veraPDF conformance tests require the VERAPDF_BIN environment variable. '
				. 'Set it to the path of the veraPDF CLI binary and re-run: '
				. 'VERAPDF_BIN=/path/to/verapdf vendor/bin/phpunit --group=verapdf'
			);
		}

		if (!is_file($bin) || !is_executable($bin)) {
			$this->markTestSkipped(
				'VERAPDF_BIN is set but "' . $bin . '" is not an executable file. '
				. 'See https://docs.verapdf.org/install/ for installation instructions.'
			);
		}

		$this->veraPdfBin = $bin;
	}

	// =====================================================================
	// Test methods
	// =====================================================================

	/**
	 * Test that a minimal document with a heading and paragraph passes ua1.
	 *
	 * The simplest document that exercises the full PDF/UA-1 pipeline:
	 * XMP metadata, MarkInfo, StructTreeRoot, a single H1 and P struct element,
	 * PDF 1.7 version, DisplayDocTitle, and /Lang.
	 *
	 * @return void
	 */
	public function testMinimalDocumentPassesUa1()
	{
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, '<h1>Title</h1><p>Body paragraph text.</p>');
		$this->assertVeraPdfCompliant($pdf, 'minimal document');
	}

	/**
	 * Test that a document with all three list types passes ua1.
	 *
	 * Exercises UL -> L/LI/LBody, OL -> L/LI/LBody (with /ListNumbering), and
	 * DL -> L/(implicit LI)/Lbl+LBody struct elements (Matterhorn 16-xxx).
	 *
	 * @return void
	 */
	public function testDocumentWithListsPassesUa1()
	{
		$html = '<h1>Lists</h1>'
			. '<ul><li>Item one</li><li>Item two</li></ul>'
			. '<ol><li>First</li><li>Second</li><li>Third</li></ol>'
			. '<dl><dt>Term</dt><dd>Definition text</dd><dt>Term 2</dt><dd>Definition 2</dd></dl>';

		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with lists');
	}

	/**
	 * Test that a document with a complex table passes ua1.
	 *
	 * Exercises Table/THead/TBody/TR/TH/TD struct elements, /Scope attribute
	 * on TH cells (Matterhorn 09-004), and the /Headers attribute for explicit
	 * header associations via the headers= HTML attribute (Matterhorn 09-005).
	 *
	 * @return void
	 */
	public function testDocumentWithTablesPassesUa1()
	{
		$html = '<h1>Table test</h1>'
			. '<table>'
			. '<thead><tr><th id="col1">Column A</th><th id="col2">Column B</th></tr></thead>'
			. '<tbody>'
			. '<tr><td headers="col1">A1</td><td headers="col2">B1</td></tr>'
			. '<tr><td headers="col1">A2</td><td headers="col2">B2</td></tr>'
			. '</tbody>'
			. '</table>';

		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with tables');
	}

	/**
	 * Test that a document with images (descriptive and decorative) passes ua1.
	 *
	 * A descriptive image (non-empty alt) must produce a Figure struct element
	 * with /Alt. A decorative image (empty alt) must be wrapped as /Artifact.
	 * Matterhorn 02-004 fires when a Figure has no /Alt.
	 *
	 * Uses a small base64-encoded PNG to avoid filesystem dependency.
	 *
	 * @return void
	 */
	public function testDocumentWithImagesPassesUa1()
	{
		// A 1x1 red pixel PNG, base64-encoded (no external file dependency needed).
		$png = 'data:image/png;base64,'
			. 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
			. 'z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

		$html = '<h1>Image test</h1>'
			. '<img src="' . $png . '" alt="A descriptive red pixel" width="20" height="20">'
			. '<p>A paragraph after the image.</p>'
			. '<img src="' . $png . '" alt="" width="20" height="20">';

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with images');
	}

	/**
	 * Test that a document with hyperlinks passes ua1.
	 *
	 * A Link struct element must have an Object Reference (OBJR) kid pointing
	 * to the link annotation, and the annotation must carry /F 28 and /Contents
	 * (Matterhorn 02-003).
	 *
	 * @return void
	 */
	public function testDocumentWithLinksPassesUa1()
	{
		$html = '<h1>Link test</h1>'
			. '<p>Visit <a href="https://example.com">Example Domain</a> for more.</p>'
			. '<p>Another link: <a href="https://www.w3.org/TR/WCAG21/">WCAG 2.1</a></p>';

		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with links');
	}

	/**
	 * Test that a document with an HTML image map passes ua1.
	 *
	 * Each <area> with an href produces a Link annotation; each Link annotation
	 * is wired to a Link struct element via OBJR + /StructParent. The Link
	 * struct elements carry /Alt populated from the area's alt attribute,
	 * satisfying Matterhorn 28-002. ISO 32000-1 §12.5.6.5 (Link annotation),
	 * §14.7.4.4.2 Table 338 (OBJR), §14.8 Table 335 (Link struct element).
	 *
	 * The fixture exercises rect, circle, and poly shapes plus the
	 * deferred-emit path (the <map> is after the host <img> in source order).
	 *
	 * @return void
	 */
	public function testDocumentWithImageMapPassesUa1()
	{
		$html = $this->loadExampleFixture('imagemap');
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with image map');
	}

	/**
	 * Test that a document with OTL-ligatured text passes ua1.
	 *
	 * DejaVuSerif ligates "fi", "ffi", and "ffl". Each ligature glyph cluster
	 * must be wrapped with /Span <</ActualText <FEFF...>>> BDC...EMC so the
	 * character sequence is recoverable (Matterhorn 24-001). See plan §A6.
	 *
	 * @return void
	 */
	public function testDocumentWithLigaturesPassesUa1()
	{
		// "fine office difficulty" contains fi (fine), ffi (office), ffl (difficulty)
		$html = '<h1>Ligature test</h1>'
			. '<p style="font-family: DejaVuSerif;">fine office difficulty</p>';

		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with ligatures');
	}

	/**
	 * Test that an encrypted document with SetProtection passes ua1.
	 *
	 * PDF/UA-1 §7.6 requires that the accessibility-permission bit (bit 10,
	 * "extract text and graphics") remains set when encryption is applied.
	 * The XMP stream must also remain unencrypted (Identity crypt filter).
	 * Matterhorn 07-001 fires when bit 10 is cleared.
	 *
	 * PDFUAauto=true is used so mPDF auto-corrects the permission bits if needed,
	 * matching the intent of example64_protected_document.php (plan §"Test Inputs").
	 *
	 * @return void
	 */
	public function testEncryptedDocumentPassesUa1()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// Pass 'copy' and 'print' — mPDF must automatically keep bit 10 (extract)
		// set when PDFUA=true, regardless of the requested permission list.
		$mpdf->SetProtection(['copy', 'print']);
		$pdf = $this->getOutput($mpdf, '<h1>Protected</h1><p>Encrypted PDF/UA-1 document.</p>');
		$this->assertVeraPdfCompliant($pdf, 'encrypted document');
	}

	/**
	 * Test that an FPDI Tier 1 import (untagged source) passes ua1.
	 *
	 * An untagged source PDF is imported via SetPageTemplate(). The
	 * FpdiStructMerger Tier 1 path wraps the Form XObject Do operator as
	 * /Artifact <</Type /Layout>> BDC...EMC (Matterhorn 01-007).
	 *
	 * The source fixture is generated inline (rather than read from
	 * tests/data/pdfs/) so the source PDF's fonts are guaranteed to be
	 * embedded — PDF/UA-1 §7.21.4.1 requires every font used for rendering to
	 * be embedded, and FPDI inherits font references from the source verbatim.
	 *
	 * @return void
	 */
	public function testFpdiTier1ImportPassesUa1()
	{
		$sourceFixture = $this->makeUntaggedSourceFixture();

		$mpdf = $this->makeMpdf(['enableImports' => true, 'PDFUAauto' => true]);
		$mpdf->setSourceFile($sourceFixture);
		$pageId = $mpdf->importPage(1);
		// Import a page from the untagged source as a background template.
		// FpdiStructMerger Tier 1: wraps the Do operator as /Artifact BDC...EMC.
		$mpdf->SetPageTemplate($pageId);
		$pdf = $this->getOutput($mpdf, '<h1>Imported Page</h1><p>Content over imported background.</p>');
		@unlink($sourceFixture);
		$this->assertVeraPdfCompliant($pdf, 'FPDI Tier 1 import');
	}

	/**
	 * Generate an untagged source PDF whose fonts are embedded.
	 *
	 * Used by testFpdiTier1ImportPassesUa1. Produces the file under sys_get_temp_dir()
	 * so the test does not depend on a checked-in fixture whose font-embedding state
	 * we cannot easily guarantee.
	 *
	 * @return string  absolute path to the generated source PDF
	 */
	private function makeUntaggedSourceFixture()
	{
		// mode => 'utf-8' + a TrueType font (DejaVuSansCondensed is bundled in
		// ttfonts/) ensures the source PDF embeds its font subset rather than
		// referencing a core PDF font, which would fail PDF/UA-1 §7.21.4.1.
		$source = new \Mpdf\Mpdf(['mode' => 'utf-8', 'default_font' => 'DejaVuSansCondensed']);
		$source->WriteHTML('<p>Untagged source page generated for FPDI Tier 1 import testing.</p>');
		$path = tempnam(sys_get_temp_dir(), 'mpdf_ua_fpdi_source_') . '.pdf';
		file_put_contents($path, $source->Output('', 'S'));
		return $path;
	}

	/**
	 * Test that a document with AcroForm widget annotations passes ua1.
	 *
	 * Form widgets must be tagged as Form struct elements with OBJR kids and
	 * /StructParent on their annotation dicts (Matterhorn 02-xxx).
	 *
	 * useActiveForms must be set to true before WriteHTML() for mPDF to render
	 * <input>/<select> as real AcroForm widget annotations rather than skipping
	 * them as plain text. Without it the document is widget-free and the test
	 * would assert nothing meaningful about form tagging.
	 *
	 * @return void
	 */
	public function testDocumentWithFormWidgetsPassesUa1()
	{
		$html = '<h1>Form test</h1>'
			. '<p>Please fill in the form below.</p>'
			. '<form>'
			. '<input type="text" name="fname" value="">'
			. '<input type="checkbox" name="agree" value="1">'
			. '<select name="choice">'
			. '<option value="a">Option A</option>'
			. '<option value="b">Option B</option>'
			. '</select>'
			. '</form>';

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// useActiveForms=true is required for mPDF to emit AcroForm widget annotations
		// from <input>/<select> tags. Without it the tags are silently ignored.
		$mpdf->useActiveForms = true;
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with form widgets');
	}

	/**
	 * Test that a multi-page document with hierarchical headings passes ua1.
	 *
	 * Exercises the heading-level tracking (Matterhorn 14-003), struct tree
	 * across page boundaries, /StructParents on every page dict, and ParentTree
	 * NumTree cross-referencing.
	 *
	 * @return void
	 */
	public function testMultiPageDocumentPassesUa1()
	{
		$html = '<h1>Chapter One</h1>'
			. '<p>First paragraph of chapter one with sufficient text to fill a section.</p>'
			. '<h2>Section 1.1</h2>'
			. '<p>Content of section 1.1.</p>'
			. '<h2>Section 1.2</h2>'
			. '<p>Content of section 1.2.</p>'
			. '<h1>Chapter Two</h1>'
			. '<p>First paragraph of chapter two.</p>'
			. '<h2>Section 2.1</h2>'
			. '<p>Content of section 2.1.</p>';

		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'multi-page document');
	}

	/**
	 * Test that a document with abbr elements passes ua1.
	 *
	 * Abbreviations must produce Span struct elements with /E (expansion text)
	 * from the title attribute. This exercises the Abbr tag handler's ARIA wiring
	 * and AriaIdResolver.
	 *
	 * @return void
	 */
	public function testDocumentWithAbbreviationsPassesUa1()
	{
		$html = '<h1>Abbreviations</h1>'
			. '<p>The <abbr title="World Wide Web Consortium">W3C</abbr> develops web standards.</p>'
			. '<p>PDF stands for <abbr title="Portable Document Format">PDF</abbr>.</p>';

		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'document with abbreviations');
	}

	// =====================================================================
	// mpdf-examples coverage tests
	//
	// One test per example listed in the PDF/UA-1 plan §"Test Inputs"
	// (plans/ua-1-support-plan.md:21-42). Each test loads the example's
	// HTML from a fixture, generates a PDF with PDFUA=true, and asserts
	// veraPDF ua1 conformance.
	//
	// Fixtures live in tests/data/html/pdfua-examples/. To regenerate
	// from the upstream mpdf-examples repo, see the README in that directory.
	// =====================================================================

	/**
	 * mpdf-examples: example01_basic.php — H1–H6, P, A, DIV, BLOCKQUOTE,
	 * ADDRESS, PRE, HR, OL/UL/DL, TABLE/THEAD/TH. Plan §"Test Inputs" line 27.
	 *
	 * @return void
	 */
	public function testExample01BasicPassesUa1()
	{
		$html = $this->loadExampleFixture('example01_basic');
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example01_basic.php');
	}

	/**
	 * mpdf-examples: example04_images.php — GIF/JPG/CMYK/PNG/BMP/WMF/SVG
	 * images (replaced with data URI placeholders); opacity; rotation; alt text.
	 * Plan §"Test Inputs" line 28.
	 *
	 * External asset files (tiger.gif, tiger.jpg, etc.) are not available in
	 * the test fixture tree. All <img src="assets/..."> references are replaced
	 * with 1x1 red-pixel PNG data URIs in the fixture file. The fixture retains
	 * the structural image-format variety (multiple rows, opacity, rotation) with
	 * meaningful alt text on descriptive images and empty alt on decorative ones.
	 *
	 * @return void
	 */
	public function testExample04ImagesPassesUa1()
	{
		$html = $this->loadExampleFixture('example04_images');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example04_images.php');
	}

	/**
	 * mpdf-examples: example05_tables.php — Simple tables; THEAD/TFOOT/TH;
	 * cell backgrounds; H3 inside table cell. Plan §"Test Inputs" line 29.
	 *
	 * @return void
	 */
	public function testExample05TablesPassesUa1()
	{
		$html = $this->loadExampleFixture('example05_tables');
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example05_tables.php');
	}

	/**
	 * mpdf-examples: example06_tables_nested.php — Nested tables (Table-in-TD).
	 * Plan §"Test Inputs" line 30.
	 *
	 * The fixture retains the CSS styles and nested table structure from the
	 * original. The assets/bg.jpg background-image reference is omitted because
	 * background images do not carry struct semantics and its absence does not
	 * affect the tagging behaviour being tested.
	 *
	 * @return void
	 */
	public function testExample06TablesNestedPassesUa1()
	{
		$html = $this->loadExampleFixture('example06_tables_nested');
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example06_tables_nested.php');
	}

	/**
	 * mpdf-examples: example07_tables_borders.php — Complex collapsed/separate
	 * table borders. Plan §"Test Inputs" line 31.
	 *
	 * @return void
	 */
	public function testExample07TablesBordersPassesUa1()
	{
		$html = $this->loadExampleFixture('example07_tables_borders');
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example07_tables_borders.php');
	}

	/**
	 * mpdf-examples: example08_lists.php — OL/UL with roman, decimal, alpha,
	 * disc markers; deeply nested lists. Plan §"Test Inputs" line 32.
	 *
	 * The original example also renders an Arabic-indic ordered list using the
	 * xbriyaz font. That list is omitted here because xbriyaz is not bundled
	 * with the mPDF test installation and would trigger a font-load error rather
	 * than exercising the list tagging behaviour.
	 *
	 * @return void
	 */
	public function testExample08ListsPassesUa1()
	{
		$html = $this->loadExampleFixture('example08_lists');
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example08_lists.php');
	}

	/**
	 * mpdf-examples: example10_floating_and_fixed_position_elements.php —
	 * Float and fixed-position rendering; both default to Artifact in PDFUA mode.
	 * Plan §"Test Inputs" line 39.
	 *
	 * The floating image (assets/tiger.wmf) in the original is omitted. The
	 * fixture instead tests a text float — sufficient to exercise the Artifact
	 * wrapping path for floated and positioned elements.
	 *
	 * @return void
	 */
	public function testExample10FloatingAndFixedPassesUa1()
	{
		$html = $this->loadExampleFixture('example10_floating_and_fixed_position_elements');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example10_floating_and_fixed_position_elements.php');
	}

	/**
	 * mpdf-examples: example12_paging_html.php — HTML headers and footers via
	 * <htmlpageheader>/<setpageheader> (pagination artifacts). Plan §"Test Inputs" line 33.
	 *
	 * The fixture includes the <htmlpageheader>, <htmlpagefooter>, and <setpageheader>
	 * mPDF custom elements that are parsed by WriteHTML() so the header/footer
	 * definitions are self-contained in the HTML. The sunset.jpg image reference
	 * in the original header table is removed; only text-based headers are used.
	 *
	 * @return void
	 */
	public function testExample12PagingHtmlPassesUa1()
	{
		$html = $this->loadExampleFixture('example12_paging_html');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->mirrorMargins = true;
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example12_paging_html.php');
	}

	/**
	 * mpdf-examples: example14_page_numbers_ToC_Index_Bookmarks.php — Multi-page
	 * documents; ToC via <tocpagebreak>; page numbers as pagination artifacts.
	 * Plan §"Test Inputs" line 34.
	 *
	 * @return void
	 */
	public function testExample14TocAndBookmarksPassesUa1()
	{
		$html = $this->loadExampleFixture('example14_page_numbers_ToC_Index_Bookmarks');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->mirrorMargins = 1;
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example14_page_numbers_ToC_Index_Bookmarks.php');
	}

	/**
	 * mpdf-examples: example16_headers_method_2.php — Method-2 header/footer
	 * API via SetHTMLHeader()/SetHTMLFooter(). Plan §"Test Inputs" line 35.
	 *
	 * mode='c' is stripped per the plan note — PdfUaTestCase::makeMpdf() already
	 * uses embedded TrueType fonts. The purpose of this test is to verify that
	 * Method-2 headers/footers produce correct /Artifact pagination wrapping,
	 * NOT to re-test the core-font exception.
	 *
	 * The header and footer HTML reference assets/sunset.jpg which is not
	 * available in the test environment. Plain text headers are substituted.
	 *
	 * @return void
	 */
	public function testExample16HeadersMethod2PassesUa1()
	{
		$html = $this->loadExampleFixture('example16_headers_method_2');

		$mpdf = $this->makeMpdf([
			'PDFUAauto'     => true,
			'margin_header' => 10,
			'margin_footer' => 10,
		]);
		$mpdf->mirrorMargins = 1;

		$header = '<table width="100%" style="border-bottom: 1px solid #000000; font-family: serif; font-size: 9pt;"><tr>'
			. '<td width="50%">Left header {PAGENO}</td>'
			. '<td width="50%" style="text-align: right;"><b>Right header</b></td>'
			. '</tr></table>';

		$headerEven = '<table width="100%" style="border-bottom: 1px solid #000000; font-family: serif; font-size: 9pt;"><tr>'
			. '<td width="50%"><b>Outer header</b></td>'
			. '<td width="50%" style="text-align: right;">Inner header {PAGENO}</td>'
			. '</tr></table>';

		$footer = '<div align="center">Footer text</div>';

		$mpdf->SetHTMLHeader($header);
		$mpdf->SetHTMLHeader($headerEven, 'E');
		$mpdf->SetHTMLFooter($footer);
		$mpdf->SetHTMLFooter($footer, 'E');

		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example16_headers_method_2.php');
	}

	/**
	 * mpdf-examples: example22_columns.php — Multi-column layout; headings
	 * across page breaks. Plan §"Test Inputs" line 36.
	 *
	 * The original uses SetColumns() between multiple WriteHTML() calls. This
	 * test replicates that pattern: write intro HTML, switch to 3-column layout,
	 * write the body content. The fixture contains the lorem-ipsum body block.
	 *
	 * @return void
	 */
	public function testExample22ColumnsPassesUa1()
	{
		$html = $this->loadExampleFixture('example22_columns');

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);

		// Replicate the SetColumns() calls from the original example.
		$mpdf->SetColumns(3, 'J');
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example22_columns.php');
	}

	/**
	 * mpdf-examples: example26_RTL.php — RTL text (Hebrew/Arabic/Farsi);
	 * bidirectional layout; Unicode symbol characters.
	 * Plan §"Test Inputs" line 37.
	 *
	 * The original example uses the xbriyaz font for the mpdf_index_* CSS
	 * classes and the arabic-indic list. The xbriyaz font is not bundled with
	 * the mPDF test installation so those references are omitted in the fixture.
	 * The core RTL paragraphs (Hebrew, Arabic, Farsi) are retained.
	 *
	 * @return void
	 */
	public function testExample26RtlPassesUa1()
	{
		$html = $this->loadExampleFixture('example26_RTL');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example26_RTL.php');
	}

	/**
	 * mpdf-examples: example34_invoice_example.php — Real-world complex table
	 * (invoice rows, totals, colspan). Plan §"Test Inputs" line 38.
	 *
	 * The original also calls SetProtection(['print']), SetWatermarkText('Paid'),
	 * and showWatermarkText. Protection is omitted here (encryption conflicts with
	 * PDF/A in the PDFUA coexistence scenario; it is tested separately in
	 * testExample64ProtectedDocumentPassesUa1). Watermark text is omitted as it
	 * is decorative and would be an artifact, not affecting the tagging test.
	 *
	 * @return void
	 */
	public function testExample34InvoicePassesUa1()
	{
		$html = $this->loadExampleFixture('example34_invoice_example');
		$mpdf = $this->makeMpdf([
			'PDFUAauto'     => true,
			'margin_left'   => 20,
			'margin_right'  => 15,
			'margin_top'    => 48,
			'margin_bottom' => 25,
			'margin_header' => 10,
			'margin_footer' => 10,
		]);
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example34_invoice_example.php');
	}

	/**
	 * mpdf-examples: example36_annotations_and_attached_files.php — Sticky note
	 * annotations via <annotation> HTML tag; title2annots feature.
	 * Plan §"Test Inputs" line 40.
	 *
	 * The original also attaches a file via the annotation file= attribute and
	 * calls $mpdf->Annotation() directly with a file attachment. File attachments
	 * in annotations require allowAnnotationFiles=true and the attached file must
	 * exist on the server; the standalone Annotation() call with a non-existent
	 * assets/tiger.jpg is omitted to avoid a filesystem dependency. The sticky-
	 * note annotation tags in the HTML fixture exercise the /Note struct element
	 * and /StructParent annotation dict requirements.
	 *
	 * @return void
	 */
	public function testExample36AnnotationsPassesUa1()
	{
		$html = $this->loadExampleFixture('example36_annotations_and_attached_files');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->title2annots = true;
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example36_annotations_and_attached_files.php');
	}

	/**
	 * mpdf-examples: example39_PDFA_compliance.php — PDF/A + PDF/UA coexistence.
	 * Plan §"Test Inputs" line 41.
	 *
	 * Both PDFA=true and PDFUA=true are set. PDFAauto and PDFUAauto are also
	 * enabled so that auto-correction runs for both standards simultaneously.
	 * The XMP metadata stream must declare conformance to both ISO standards.
	 *
	 * @return void
	 */
	public function testExample39PdfaCompliancePassesUa1()
	{
		$html = $this->loadExampleFixture('example39_PDFA_compliance');
		$mpdf = $this->makeMpdf([
			'PDFA'      => true,
			'PDFAauto'  => true,
			'PDFUAauto' => true,
		]);
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example39_PDFA_compliance.php');
	}

	/**
	 * mpdf-examples: example64_protected_document.php — setProtection() + PDF/UA.
	 * Plan §"Test Inputs" line 42.
	 *
	 * mode='c' is stripped per the plan note — PdfUaTestCase::makeMpdf() already
	 * uses embedded TrueType fonts. PDFUAauto=true is used so mPDF auto-corrects
	 * the permission bits (bit 10 "extract for accessibility" must remain set).
	 * The purpose is to verify that setProtection() combined with PDFUA=true keeps
	 * the accessibility permission bit set and leaves the XMP stream unencrypted.
	 *
	 * @return void
	 */
	public function testExample64ProtectedDocumentPassesUa1()
	{
		$html = $this->loadExampleFixture('example64_protected_document');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// Re-enable FlateDecode compression for this single test. PdfUaTestCase
		// disables compression so other tests can grep operators in the raw
		// content stream. This test does not inspect the stream — it just hands
		// the bytes to veraPDF — and combining RC4 encryption with an
		// uncompressed multi-line content stream triggers a veraPDF parsing
		// quirk where /Length-bounded bytes after RC4 are mis-segmented and
		// every additional line of tagged text is reported as untagged real
		// content (§7.1 test 3). The same source PDF passes when compressed,
		// pdftotext extracts the text correctly, and the decrypted bytes are
		// byte-identical to the unencrypted stream — so the validation failure
		// is not an mPDF tagging defect. Re-enabling compression here matches
		// real-world usage (production PDFs are virtually always compressed)
		// while preserving the test's intent: verifying that SetProtection()
		// keeps bit 10 set and leaves the XMP stream unencrypted.
		$mpdf->compress = true;
		// Replicate the setProtection() call from example64_protected_document.php.
		// mPDF must automatically keep bit 10 (extract for accessibility) set when
		// PDFUA=true regardless of the requested permission list (Matterhorn 07-001).
		$mpdf->SetProtection([]);
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example64_protected_document.php');
	}

	/**
	 * mpdf-examples: example03_backgrounds_and_borders.php — CSS backgrounds,
	 * linear and radial gradients, rounded borders.
	 *
	 * PDFUAauto=true so the body-level background gradient and the gradient/
	 * background-color decorations are wrapped as /Artifact (Matterhorn 01-007).
	 *
	 * @return void
	 */
	public function testExample03BackgroundsAndBordersPassesUa1()
	{
		$html = $this->loadExampleFixture('example03_backgrounds_and_borders');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example03_backgrounds_and_borders.php');
	}

	/**
	 * mpdf-examples: example09_forms.php — comprehensive AcroForm widgets:
	 * textarea, select, radio, checkbox, text input, password, submit/button/reset.
	 *
	 * useActiveForms=true is required to emit AcroForm widget annotations for the
	 * input/select/textarea elements. Each widget annotation must carry
	 * /StructParent and have an OBJR kid in the corresponding Form struct elem
	 * (Matterhorn 02-003 / 19-003).
	 *
	 * @return void
	 */
	public function testExample09FormsPassesUa1()
	{
		$html = $this->loadExampleFixture('example09_forms');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->useActiveForms = true;
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example09_forms.php');
	}

	/**
	 * mpdf-examples: example11_overflow_auto.php — fixed-position block element
	 * with overflow:auto autofit; long body wrapped in <div style="position:fixed">
	 * to force shrink-to-fit on a single page.
	 *
	 * Tests that fixed-position content does not break the struct tree — children
	 * of the fixed block are still real content (P/H1/H4/etc.) when alt text is
	 * present, not artifact (Matterhorn 01-007).
	 *
	 * @return void
	 */
	public function testExample11OverflowAutoPassesUa1()
	{
		$html = $this->loadExampleFixture('example11_overflow_auto');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example11_overflow_auto.php');
	}

	/**
	 * mpdf-examples: example18_headers_method_4.php — Method-4 header/footer API
	 * via inline <!--mpdf ... mpdf--> comments holding <htmlpageheader> and
	 * <sethtmlpageheader> directives.
	 *
	 * Tests that headers defined via Method-4 (HTML comment block) emit pagination
	 * artifacts on every page (Matterhorn 01-007) and that the <pagebreak> with
	 * odd-/even-header switching does not introduce untagged real content.
	 *
	 * @return void
	 */
	public function testExample18HeadersMethod4PassesUa1()
	{
		$html = $this->loadExampleFixture('example18_headers_method_4');
		$mpdf = $this->makeMpdf([
			'PDFUAauto'     => true,
			'margin_left'   => 32,
			'margin_right'  => 25,
			'margin_top'    => 47,
			'margin_bottom' => 47,
			'margin_header' => 10,
			'margin_footer' => 10,
		]);
		$mpdf->mirrorMargins = 1;
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example18_headers_method_4.php');
	}

	/**
	 * mpdf-examples: example19_page_sizes.php — multiple page sizes within one
	 * document: A4 portrait, A4-L, A5-L, Letter, custom 150mm square, custom inches.
	 *
	 * Each <pagebreak sheet-size="..."> changes the page size. Every page dict must
	 * carry /StructParents (Matterhorn 01-005) regardless of sheet size; this test
	 * exercises the page-dict-emission path under variable /MediaBox values.
	 *
	 * @return void
	 */
	public function testExample19PageSizesPassesUa1()
	{
		$html = $this->loadExampleFixture('example19_page_sizes');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example19_page_sizes.php');
	}

	/**
	 * mpdf-examples: example20_justify.php — text justification with mixed inline
	 * styles, justified table cells with explicit /Headers associations.
	 *
	 * Exercises the justification path (J/Tj operator interaction with
	 * letter-spacing and word-spacing) and verifies that justified runs still
	 * produce parsable text content (Matterhorn 09-001/24-001 spacing).
	 *
	 * @return void
	 */
	public function testExample20JustifyPassesUa1()
	{
		$html = $this->loadExampleFixture('example20_justify');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example20_justify.php');
	}

	/**
	 * mpdf-examples: example21_hyphenation.php — automatic hyphenation in a
	 * multi-column layout (CSS hyphens: auto + SetColumns(4,'J')).
	 *
	 * Hyphens inserted by the Hyphenator must be reachable as text via the
	 * struct tree — Matterhorn 24-001 (ActualText) and the soft-hyphen
	 * Cmap-coverage path (Matterhorn 09-006).
	 *
	 * @return void
	 */
	public function testExample21HyphenationPassesUa1()
	{
		$html = $this->loadExampleFixture('example21_hyphenation');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->SetColumns(4, 'J');
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example21_hyphenation.php');
	}

	/**
	 * mpdf-examples: example23_orientation.php — mid-document orientation switch
	 * via <pagebreak orientation="L"> / orientation="P".
	 *
	 * Each orientation transition must propagate /Tabs /S, /StructParents, and
	 * /Lang on the new page dict (Matterhorn 09-001/04-001/01-005).
	 *
	 * @return void
	 */
	public function testExample23OrientationPassesUa1()
	{
		$html = $this->loadExampleFixture('example23_orientation');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->mirrorMargins = 1;
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example23_orientation.php');
	}

	/**
	 * mpdf-examples: example24_orientation_2.php — orientation switch with a
	 * landscape table (Table/THead/TR/TH/TD with explicit /Headers).
	 *
	 * Verifies that the struct tree across an orientation transition keeps the
	 * Table struct intact and that TD cells in the landscape page resolve their
	 * /Headers references to the THead row's TH /ID values (Matterhorn 09-005).
	 *
	 * @return void
	 */
	public function testExample24Orientation2PassesUa1()
	{
		$html = $this->loadExampleFixture('example24_orientation_2');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->displayDefaultOrientation = true;
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example24_orientation_2.php');
	}

	/**
	 * mpdf-examples: example38_dot_tab.php — <dottab> directive emits a row of
	 * leader dots between two text runs (a menu-style price layout).
	 *
	 * Each <dottab> renders as a sequence of dots in the content stream; those
	 * dots are decorative and must be wrapped as /Artifact, not real text content
	 * (Matterhorn 01-007). Verifies the dottab artifact wrapping path.
	 *
	 * @return void
	 */
	public function testExample38DotTabPassesUa1()
	{
		$html = $this->loadExampleFixture('example38_dot_tab');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example38_dot_tab.php');
	}

	/**
	 * mpdf-examples: example02_CSS_styles.php — basic CSS styling demo:
	 * H1–H5 with custom borders/colours/font-variant, paragraph with kerning,
	 * .breadcrumb class with right-aligned small grey text.
	 *
	 * The original example loads assets/mpdfstyleA4.css which provides the
	 * shared site-wide stylesheet; the fixture inlines the relevant subset so
	 * no external file dependency is required.
	 *
	 * @return void
	 */
	public function testExample02CssStylesPassesUa1()
	{
		$html = $this->loadExampleFixture('example02_CSS_styles');
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example02_CSS_styles.php');
	}

	/**
	 * mpdf-examples: example35_watermarks.php — text watermark via
	 * SetWatermarkText() / showWatermarkText.
	 *
	 * Watermark text is decorative and must be wrapped as
	 * /Artifact <</Type /Background>> BDC…EMC by mPDF in PDFUA mode (Phase 4
	 * artifact wrapping path). The original also uses image watermarks; that
	 * variant is omitted here because it requires assets/tiger.wmf and the
	 * artifact wrapping logic is the same for both.
	 *
	 * @return void
	 */
	public function testExample35WatermarksPassesUa1()
	{
		$html = $this->loadExampleFixture('example35_watermarks');
		$mpdf = $this->makeMpdf(['PDFUAauto' => true, 'watermarkAngle' => 135]);
		$mpdf->SetWatermarkText('DRAFT');
		$mpdf->watermark_font = 'DejaVuSansCondensed';
		$mpdf->showWatermarkText = true;
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example35_watermarks.php');
	}

	/**
	 * mpdf-examples: example37_barcodes.php — comprehensive barcode rendering:
	 * EAN-13, ISBN, UPC-A, EAN-8, RM4SCC, POSTNET, CODE 128 B, CODE 39, QR-code.
	 *
	 * Each <barcode> element is tagged as a Figure struct element with
	 * Alt = "Barcode: <code>" (Phase 4 BarCode tag handler). veraPDF must accept
	 * the Figure tagging without flagging untagged real content (Matterhorn
	 * 02-004) or missing alt (Matterhorn 13-008).
	 *
	 * @return void
	 */
	public function testExample37BarcodesPassesUa1()
	{
		$html = $this->loadExampleFixture('example37_barcodes');
		$mpdf = $this->makeMpdf([
			'PDFUAauto'          => true,
			'margin_left'        => 20,
			'margin_right'       => 15,
			'margin_top'         => 25,
			'margin_bottom'      => 25,
			'showBarcodeNumbers' => false,
		]);
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example37_barcodes.php');
	}

	/**
	 * mpdf-examples: example66_custom_properties.php — XMP custom properties
	 * via the customProperties config + AddCustomProperty() runtime calls.
	 *
	 * Custom properties must be embedded in the XMP metadata stream (PDF/UA-1
	 * §7.1 / Matterhorn 06-001 Document Information Dictionary) without
	 * disrupting the pdfuaid:part conformance assertion. AddCustomProperty()
	 * must coexist with the constructor's customProperties array.
	 *
	 * @return void
	 */
	public function testExample66CustomPropertiesPassesUa1()
	{
		$html = $this->loadExampleFixture('example66_custom_properties');
		$mpdf = $this->makeMpdf([
			'customProperties' => [
				'property1'         => 'value of property 1',
				'property2'         => 'value of property 2',
				'rewritten_property' => 'value to rewrite',
			],
		]);
		$mpdf->AddCustomProperty('rewritten_property', 'rewritten_value');
		$mpdf->AddCustomProperty('property3', 'value of property 3');
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'example66_custom_properties.php');
	}

	/**
	 * Legacy form chrome with `useActiveForms=false` must pass veraPDF's
	 * UA-1 profile end-to-end. The fixture exercises every print_ob_*
	 * code path; Phase 2 of the legacy-form artifact-tagging plan wraps
	 * each in /Artifact BMC ... EMC so rule 7.1#3 (untagged real content)
	 * is satisfied even though no AcroForm widget annotations exist.
	 *
	 * Plan: .claude/plans/2026-04-30-ua1-legacy-form-artifact-tagging.md §5.
	 *
	 * @return void
	 */
	public function testLegacyFormsUseActiveFormsFalsePassesUa1()
	{
		$html = $this->loadExampleFixture('legacy_forms_useactiveformsfalse');
		// useActiveForms=false drives the legacy chrome path; PDFUAauto=true
		// because makeMpdf() leaves it strict by default and a missing-alt
		// or skipped-heading in the fixture would otherwise throw.
		$mpdf = $this->makeMpdf([
			'PDFUAauto'      => true,
			'useActiveForms' => false,
		]);
		$pdf = $this->getOutput($mpdf, $html);
		$this->assertVeraPdfCompliant($pdf, 'legacy_forms_useactiveformsfalse.html');
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Load an mpdf-example HTML fixture from tests/data/html/pdfua-examples/.
	 *
	 * The fixture must be a plain HTML file (no PHP tags). If the fixture file
	 * does not exist, the test is skipped with a clear message so missing fixtures
	 * are immediately visible rather than silently passing.
	 *
	 * @param  string $name  Fixture base name without extension (e.g. 'example01_basic')
	 * @return string
	 */
	private function loadExampleFixture($name)
	{
		$path = __DIR__ . '/../../data/html/pdfua-examples/' . $name . '.html';
		if (!is_file($path)) {
			$this->markTestSkipped('Fixture not found: ' . $path);
		}
		return file_get_contents($path);
	}

	/**
	 * Write PDF bytes to a temp file, run veraPDF, and assert ua1 compliance.
	 *
	 * The temp file is always unlinked after the assertion — even on failure —
	 * to avoid accumulating stale PDFs in sys_get_temp_dir().
	 *
	 * @param  string $pdfBytes  Raw PDF bytes from getOutput()
	 * @param  string $label     Human-readable description for failure messages
	 * @return void
	 */
	private function assertVeraPdfCompliant($pdfBytes, $label)
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'mpdf-ua1-');

		// tempnam() creates a 0-byte file with no extension. Rename to .pdf to
		// avoid edge cases with older veraPDF releases that inspect the extension.
		$pdfFile = $tmpFile . '.pdf';
		rename($tmpFile, $pdfFile);

		file_put_contents($pdfFile, $pdfBytes);

		$result = null;
		try {
			$result = $this->runVeraPdf($pdfFile);
		} finally {
			if (is_file($pdfFile)) {
				unlink($pdfFile);
			}
		}

		if (!$result['isCompliant']) {
			$errorSummary = implode("\n", $result['errors']);
			$this->fail(
				'veraPDF ua1 validation FAILED for "' . $label . "\".\n\n"
				. 'Failures (' . count($result['errors']) . "):\n" . $errorSummary
			);
		}

		// Document is compliant — register an assertion so PHPUnit counts it.
		$this->assertTrue(true);
	}

	/**
	 * Invoke the veraPDF CLI on a PDF file and parse the JSON report.
	 *
	 * Stderr is redirected to /dev/null in the shell command to prevent a
	 * proc_open deadlock: if veraPDF writes more than ~64 KB to stderr while
	 * this process blocks reading stdout, the child blocks on its stderr write
	 * and both processes deadlock. Discarding stderr in the shell eliminates the
	 * buffering problem without requiring concurrent pipe draining.
	 *
	 * CLI flags:
	 *   --flavour ua1   validate against PDF/UA-1
	 *   --format json   machine-readable JSON report
	 *
	 * The JSON report structure (veraPDF 1.26+):
	 *   report.jobs[0].validationResult[0].compliant  (bool)
	 *   report.jobs[0].validationResult[0].details.ruleSummaries[]
	 *     .specification, .clause, .testNumber, .description, .failedChecks (int)
	 *
	 * Note: in veraPDF 1.30+ `validationResult` is an array (one entry per
	 * profile that ran); we read element [0]. Older releases used a single
	 * object — `parseVeraPdfJson()` handles both shapes.
	 *
	 * @param  string $pdfPath  Absolute path to the PDF file to validate
	 * @return array  Keys: isCompliant (bool), errors (string[])
	 */
	private function runVeraPdf($pdfPath)
	{
		// 2>/dev/null: discard stderr to prevent proc_open deadlock when
		// veraPDF writes large amounts of rule-loading output to stderr.
		$cmd = escapeshellarg($this->veraPdfBin)
			. ' --flavour ua1 --format json '
			. escapeshellarg($pdfPath)
			. ' 2>/dev/null';

		$descriptors = [
			0 => ['pipe', 'r'],  // stdin (unused)
			1 => ['pipe', 'w'],  // stdout -> JSON report
			// stderr is discarded via shell redirect above, not via a pipe
		];

		$process = proc_open($cmd, $descriptors, $pipes);

		if (!is_resource($process)) {
			$this->fail('Failed to launch veraPDF process: ' . $cmd);
		}

		// Close stdin immediately — nothing to send.
		fclose($pipes[0]);

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		proc_close($process);

		return $this->parseVeraPdfJson((string) $stdout);
	}

	/**
	 * Parse the veraPDF JSON report and extract compliance status and errors.
	 *
	 * Handles the veraPDF 1.26/1.27 JSON schema:
	 *   report.jobs[0].validationResult.compliant  (bool)
	 *   report.jobs[0].validationResult.details.ruleSummaries[].failedChecks (int)
	 *
	 * Falls back gracefully when the schema differs: if the JSON cannot be
	 * decoded or the expected keys are absent, the test fails with a diagnostic
	 * showing the raw stdout so the schema mismatch can be fixed.
	 *
	 * @param  string $json  Raw stdout from the veraPDF process
	 * @return array  Keys: isCompliant (bool), errors (string[])
	 */
	private function parseVeraPdfJson($json)
	{
		if ($json === '') {
			$this->fail('veraPDF produced no JSON output. Is VERAPDF_BIN correct?');
		}

		$data = json_decode($json, true);

		if (!is_array($data)) {
			$this->fail(
				'veraPDF output is not valid JSON. Raw output:' . "\n" . substr($json, 0, 2000)
			);
		}

		// Navigate report.jobs[0].validationResult
		if (!isset($data['report'])
			|| !isset($data['report']['jobs'])
			|| !is_array($data['report']['jobs'])
			|| !isset($data['report']['jobs'][0])
			|| !isset($data['report']['jobs'][0]['validationResult'])
		) {
			$this->fail(
				'veraPDF JSON structure not recognised (expected report.jobs[0].validationResult). '
				. 'Raw output:' . "\n" . substr($json, 0, 2000)
			);
		}

		$validationResult = $data['report']['jobs'][0]['validationResult'];
		// veraPDF 1.30+ wraps validationResult as a single-element array (one
		// entry per profile that ran). Older releases used a direct object.
		// Normalise to the direct-object shape so downstream code is uniform.
		if (is_array($validationResult)
			&& isset($validationResult[0])
			&& is_array($validationResult[0])
			&& array_key_exists('compliant', $validationResult[0])) {
			$validationResult = $validationResult[0];
		}
		$isCompliant = !empty($validationResult['compliant']);

		$errors = [];

		if (!$isCompliant && isset($validationResult['details']['ruleSummaries'])) {
			foreach ($validationResult['details']['ruleSummaries'] as $rule) {
				if (isset($rule['failedChecks']) && (int) $rule['failedChecks'] > 0) {
					$spec        = isset($rule['specification']) ? $rule['specification'] : '';
					$clause      = isset($rule['clause']) ? $rule['clause'] : '';
					$testNum     = isset($rule['testNumber']) ? $rule['testNumber'] : '';
					$description = isset($rule['description']) ? $rule['description'] : '';
					$failed      = (int) $rule['failedChecks'];

					$errors[] = sprintf(
						'[%s §%s test %s] %s (%d failed check%s)',
						$spec,
						$clause,
						$testNum,
						$description,
						$failed,
						$failed === 1 ? '' : 's'
					);
				}
			}
		}

		return [
			'isCompliant' => $isCompliant,
			'errors'      => $errors,
		];
	}
}
