<?php

namespace Mpdf\Ua;

/**
 * Tests for the PDF/UA-1 Tier 1 (untagged source) FPDI import path.
 *
 * An imported PDF page whose source carries no struct tree cannot be cloned
 * into the host structure tree (that is Tier 2). Historically FpdiTrait wrapped
 * such a page wholesale in /Artifact <</Type /Layout>> BDC … EMC — conformant
 * (Matterhorn 01-007: real content is marked as an artifact) but wholly
 * inaccessible with no signal, which the no-deferrals rule treats as a gap.
 *
 * UA1 audit E15 makes the behaviour signalled and, optionally, accessible:
 *   - auto mode  (PDFUAauto=true)  wraps as /Artifact AND records a
 *     getPdfUaWarnings() entry naming the source page;
 *   - strict mode (PDFUAauto=false) throws \Mpdf\MpdfException — the producer
 *     must supply a tagged source or an explicit /Alt;
 *   - either mode: an author-supplied /Alt via
 *     useImportedPage($id, ['alt' => …]) tags the whole page as a captioned
 *     Figure so it carries an accessible name instead of vanishing.
 *
 * The untagged source fixture is generated inline by a non-PDFUA mPDF instance
 * (no /StructTreeRoot ⇒ sourceIsTagged() returns false). An embedded TrueType
 * font is used so the imported Form XObject inherits an embedded font subset,
 * matching PDF/UA-1 §7.21.4.1.
 *
 * Spec references:
 *   - ISO 14289-1:2014 §7.1           — real content tagged or marked Artifact
 *   - ISO 14289-1:2014 §7.3           — Figure / /Alt
 *   - ISO 32000-1:2008 §14.7.4.4 T324 — MCR dict
 *   - Matterhorn Protocol 1.1 01-007  — real content not tagged or Artifact
 *   - Matterhorn Protocol 1.1 13-004  — Figure without /Alt
 *
 * @group pdfua
 */
class FpdiImportTest extends PdfUaTestCase
{

	/** @var string|null  path to an inline-generated untagged source PDF; deleted on tear_down() */
	private $untaggedPdf;

	protected function set_up()
	{
		parent::set_up();
		$this->untaggedPdf = null;
	}

	protected function tear_down()
	{
		parent::tear_down();
		if ($this->untaggedPdf !== null && file_exists($this->untaggedPdf)) {
			@unlink($this->untaggedPdf);
			$this->untaggedPdf = null;
		}
	}

	/**
	 * Generate an untagged source PDF on disk (no /StructTreeRoot).
	 *
	 * Built with a non-PDFUA mPDF instance so the output carries no structure
	 * tree; an embedded TrueType font (mode='utf-8' + DejaVuSansCondensed)
	 * keeps the imported Form XObject font-embedded.
	 *
	 * @return string  absolute path to the generated untagged PDF
	 */
	private function makeUntaggedPdf()
	{
		$src = new \Mpdf\Mpdf(['mode' => 'utf-8', 'default_font' => 'DejaVuSansCondensed']);
		$src->WriteHTML('<p>Untagged source page for FPDI Tier 1 import testing.</p>');
		$tmp = tempnam(sys_get_temp_dir(), 'mpdf_untagged_') . '.pdf';
		$src->Output($tmp, 'F');
		return $tmp;
	}

	/**
	 * Auto mode: an untagged import is wrapped as /Artifact AND a warning naming
	 * the source page is recorded (UA1 audit E15). The wrap is conformant; the
	 * warning is what makes the content loss non-silent.
	 */
	public function testUntaggedImportAutoModeWrapsArtifactAndWarnsNamingPage()
	{
		$this->untaggedPdf = $this->makeUntaggedPdf();

		$mpdf = $this->makeMpdf(['enableImports' => true, 'PDFUAauto' => true]);
		$mpdf->setSourceFile($this->untaggedPdf);
		$pageId = $mpdf->importPage(1);

		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId, 0, 0, 150, 100);
		$output = $mpdf->Output(null, 'S');

		$this->assertStringContainsString(
			'/Artifact <</Type /Layout>> BDC',
			$output,
			'Untagged import in auto mode must wrap the Do operator as an Artifact'
		);

		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		$basename = basename($this->untaggedPdf);
		foreach ($warnings as $w) {
			if (strpos($w, 'untagged') !== false
				&& strpos($w, 'page 1') !== false
				&& strpos($w, $basename) !== false
			) {
				$found = true;
				break;
			}
		}
		$this->assertTrue(
			$found,
			'getPdfUaWarnings() must record an untagged-import warning naming "page 1" of the source file'
		);
	}

	/**
	 * Strict mode: an untagged import with no author-supplied /Alt throws
	 * \Mpdf\MpdfException naming the source page and citing Matterhorn 01-007.
	 */
	public function testUntaggedImportStrictModeThrows()
	{
		$this->untaggedPdf = $this->makeUntaggedPdf();

		$mpdf = $this->makeMpdf(['enableImports' => true, 'PDFUAauto' => false]);
		$mpdf->setSourceFile($this->untaggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();

		try {
			$mpdf->useImportedPage($pageId, 0, 0, 150, 100);
			$this->fail('strict-mode useImportedPage() on an untagged source must throw');
		} catch (\Mpdf\MpdfException $e) {
			$this->assertStringContainsString('untagged', $e->getMessage());
			$this->assertStringContainsString('page 1', $e->getMessage());
			$this->assertStringContainsString(basename($this->untaggedPdf), $e->getMessage());
			$this->assertStringContainsString('Matterhorn 01-007', $e->getMessage());
		}
	}

	/**
	 * Author-supplied /Alt: useImportedPage($id, ['alt' => …]) tags the whole
	 * imported page as a Figure struct element carrying that /Alt — a named,
	 * accessible artifact — instead of an anonymous /Artifact. Works in strict
	 * mode because the accessible name satisfies the requirement without a throw.
	 */
	public function testUntaggedImportWithAuthorAltProducesNamedFigure()
	{
		$this->untaggedPdf = $this->makeUntaggedPdf();

		$alt  = 'Scanned invoice page rendered as an accessible figure';
		$mpdf = $this->makeMpdf(['enableImports' => true, 'PDFUAauto' => false]);
		$mpdf->setSourceFile($this->untaggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();

		// Array-form call carrying the accessible name — must not throw in strict mode.
		$mpdf->useImportedPage($pageId, ['x' => 0, 'y' => 0, 'width' => 150, 'alt' => $alt]);
		$output = $mpdf->Output(null, 'S');

		$this->assertStringContainsString(
			'/Figure <</MCID',
			$output,
			'An author-supplied /Alt must tag the imported page as a Figure with an MCID'
		);
		$this->assertStringNotContainsString(
			'/Artifact <</Type /Layout>> BDC',
			$output,
			'A named Figure must not also be wrapped as an anonymous Artifact'
		);

		// /Alt is written as a BOM-prefixed UTF-16BE PDF text string (BaseWriter::
		// utf16BigEndianTextString): (<FEFF><UTF-16BE bytes>). Rebuild that exact
		// token so the assertion proves the author's text reached the struct dict.
		$utf16    = "\xFE\xFF" . mb_convert_encoding($alt, 'UTF-16BE', 'UTF-8');
		$escaped  = strtr($utf16, [')' => '\\)', '(' => '\\(', '\\' => '\\\\', chr(13) => '\r']);
		$expected = '/Alt (' . $escaped . ')';
		$this->assertStringContainsString(
			$expected,
			$output,
			'The Figure struct element must carry the author-supplied /Alt text (UTF-16BE)'
		);
	}

	/**
	 * Regression guard: an empty-string alt is treated as "no accessible name"
	 * (decorative intent is not expressible for a whole imported page), so strict
	 * mode still throws rather than emitting a nameless Figure.
	 */
	public function testUntaggedImportEmptyAltStillThrowsInStrictMode()
	{
		$this->untaggedPdf = $this->makeUntaggedPdf();

		$mpdf = $this->makeMpdf(['enableImports' => true, 'PDFUAauto' => false]);
		$mpdf->setSourceFile($this->untaggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();

		$this->expectException(\Mpdf\MpdfException::class);
		$mpdf->useImportedPage($pageId, ['x' => 0, 'y' => 0, 'width' => 150, 'alt' => '   ']);
	}
}
