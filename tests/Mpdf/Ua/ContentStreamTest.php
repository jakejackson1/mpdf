<?php

namespace Mpdf\Ua;

/**
 * Phase 3 PDF/UA-1 content stream BDC/EMC tagging tests.
 *
 * Verifies that marked-content operators (BDC, BMC, EMC) are emitted correctly
 * into PDF page content streams. Tests cover:
 *   - MarkedContentHelper infrastructure (begin/end/getDepth)
 *   - Header and footer artifact wrapping
 *   - Image BDC/BMC based on alt attribute
 *   - BDC/EMC balance assertion at document close
 *
 * All tests disable content-stream compression ($mpdf->compress = false, set by
 * PdfUaTestCase::makeMpdf()) so operator assertions can match raw bytes without
 * decompressing FlateDecode streams.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.6 — BMC/BDC/EMC marked-content operators
 *   - ISO 32000-1:2008 §14.7.4.4 — MCID in BDC property dicts
 *   - ISO 32000-1:2008 §14.8.2.2 — Artifact content sequences
 *   - ISO 14289-1:2014 §7.1 — MarkInfo; Marked=true
 *
 * @group pdfua
 */
class ContentStreamTest extends PdfUaTestCase
{

	// ========================= §3a Helper tests =========================

	/**
	 * Calling begin('P', 5) must write "/P <</MCID 5>> BDC" to the page content stream.
	 *
	 * ISO 32000-1:2008 §14.7.4.4 — MCID is the integer the ParentTree
	 * cross-references; the property dict key is /MCID.
	 */
	public function testHelperWritesBdcToPageStream()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML('<p>test</p>');
		// Force page state (state 2 = within page) so BaseWriter::write() routes
		// to the page buffer. We call begin() directly after WriteHTML which has
		// already opened a page.
		$mpdf->getPdfUaMarkedContentHelper()->begin('P', 5);
		// Balance the depth so _enddoc() does not throw or warn
		$mpdf->getPdfUaMarkedContentHelper()->end();
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/P <</MCID 5>> BDC', $output);
	}

	/**
	 * Calling end() must write "EMC" to the page content stream.
	 *
	 * ISO 32000-1:2008 §14.6 — EMC closes the most-recently-opened BDC or BMC.
	 */
	public function testHelperWritesEmcToPageStream()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML('<p>test</p>');
		// Open first so depth > 0 (end() is a no-op at depth=0)
		$mpdf->getPdfUaMarkedContentHelper()->begin('P', 0);
		$mpdf->getPdfUaMarkedContentHelper()->end();
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('EMC', $output);
	}

	/**
	 * begin() with $mcid === -1 must write "/Artifact BMC" (no property dict).
	 *
	 * ISO 32000-1:2008 §14.6 — BMC is used for property-less sequences (no dict);
	 * BDC requires a property dict. Artifact content uses BMC per §14.8.2.2.
	 */
	public function testArtifactHelperWritesBmcNoDictToPageStream()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML('<p>test</p>');
		$mpdf->getPdfUaMarkedContentHelper()->begin('Artifact', -1);
		// Immediately close to keep balance (Output() calls _enddoc which checks depth).
		$mpdf->getPdfUaMarkedContentHelper()->end();
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/Artifact BMC', $output);
		// Must NOT have a property dict on the BMC
		$this->assertStringNotContainsString('/Artifact <<', $output);
	}

	/**
	 * getDepth() must track begin/end nesting correctly and end() at depth=0 must be a no-op.
	 *
	 * ISO 32000-1:2008 §14.6 — BDC/EMC pairs must be balanced.
	 */
	public function testHelperTracksDepth()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML('<p>x</p>');

		$helper = $mpdf->getPdfUaMarkedContentHelper();

		$this->assertSame(0, $helper->getDepth());

		$helper->begin('P', 0);
		$this->assertSame(1, $helper->getDepth());

		$helper->begin('Span', 1);
		$this->assertSame(2, $helper->getDepth());

		$helper->end();
		$this->assertSame(1, $helper->getDepth());

		$helper->end();
		$this->assertSame(0, $helper->getDepth());

		// Extra end() at depth 0 must be a no-op — depth stays at 0
		$helper->end();
		$this->assertSame(0, $helper->getDepth());

		// Depth is at 0 — Output() will not throw.
		$mpdf->Output(null, 'S');
	}

	/**
	 * end() when depth is already 0 must NOT write EMC and must NOT go negative.
	 *
	 * ISO 32000-1:2008 §14.6 — a stray EMC would corrupt the operator stack.
	 */
	public function testEndMarkedContentWhenDepthIsZeroIsNoop()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML('<p>x</p>');

		$helper = $mpdf->getPdfUaMarkedContentHelper();
		$this->assertSame(0, $helper->getDepth());

		// Call end() with depth=0 — must not write EMC, must not make depth negative
		$helper->end();

		$this->assertSame(0, $helper->getDepth());

		$output = $mpdf->Output(null, 'S');
		// The page stream should not contain an extra stray EMC unmatched to a BDC.
		// We verify by checking balance: count BDC+BMC occurrences emitted by PDFUA code
		// against EMC occurrences. With depth=0 call, there should be no mismatch.
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= Header artifact tests =========================

	/**
	 * A document with an HTML header must produce a Pagination/Header artifact BDC
	 * in the page stream (not BMC — a property dict is required for /Type /Pagination).
	 *
	 * ISO 32000-1:2008 §14.8.2.2 — Artifact subtypes (Pagination, Layout, Page, Background).
	 * Matterhorn Protocol 1.1 §13-004 — running headers/footers must be Artifacts.
	 */
	public function testHeaderArtifactUsesBdcNotBmc()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetHTMLHeader('<p>Header text</p>');
		$output = $this->getOutput($mpdf, '<p>Body text</p>');
		$this->assertStringContainsString('/Artifact <</Type /Pagination /Subtype /Header>> BDC', $output);
	}

	/**
	 * A document with an HTML footer must produce a Pagination/Footer artifact BDC.
	 *
	 * ISO 32000-1:2008 §14.8.2.2 — Pagination artifact subtype Footer.
	 */
	public function testFooterArtifactUsesBdcNotBmc()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetHTMLFooter('<p>Footer text</p>');
		$output = $this->getOutput($mpdf, '<p>Body text</p>');
		$this->assertStringContainsString('/Artifact <</Type /Pagination /Subtype /Footer>> BDC', $output);
	}

	/**
	 * Header content must appear between the BDC and EMC in the page stream.
	 *
	 * ISO 32000-1:2008 §14.8.2.2 — all content inside an Artifact sequence is
	 * treated as non-structure content by AT. The header HTML content must be
	 * enclosed within the /Pagination BDC … EMC wrapper.
	 */
	public function testHeaderContentIsArtifactWrapped()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetHTMLHeader('<p>My Header</p>');
		$output = $this->getOutput($mpdf, '<p>Body</p>');

		$bdcStr = '/Artifact <</Type /Pagination /Subtype /Header>> BDC';
		$bdcPos = strpos($output, $bdcStr);
		$this->assertNotFalse($bdcPos, 'Header BDC must appear in page stream');

		// Search for EMC after the BDC position
		$afterBdc = substr($output, $bdcPos + strlen($bdcStr));
		$emcPos = strpos($afterBdc, 'EMC');
		$this->assertNotFalse($emcPos, 'Header EMC must appear after BDC');
	}

	/**
	 * Footer content must appear between the BDC and EMC in the page stream.
	 *
	 * ISO 32000-1:2008 §14.8.2.2 — footer content is pagination artifact.
	 */
	public function testFooterContentIsArtifactWrapped()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetHTMLFooter('<p>My Footer</p>');
		$output = $this->getOutput($mpdf, '<p>Body</p>');

		$bdcStr = '/Artifact <</Type /Pagination /Subtype /Footer>> BDC';
		$bdcPos = strpos($output, $bdcStr);
		$this->assertNotFalse($bdcPos, 'Footer BDC must appear in page stream');

		$afterBdc = substr($output, $bdcPos + strlen($bdcStr));
		$emcPos = strpos($afterBdc, 'EMC');
		$this->assertNotFalse($emcPos, 'Footer EMC must appear after BDC');
	}

	// ========================= Image tagging tests =========================

	/**
	 * An image with alt="" (explicitly empty) must produce /Artifact BMC (no dict)
	 * — it is decorative. W3C convention: alt="" declares a decorative image.
	 *
	 * ISO 32000-1:2008 §14.8.2.2 — decorative images are Artifacts.
	 */
	public function testImageWithEmptyAltProducesArtifactBmc()
	{
		$mpdf = $this->makeMpdf();
		$imgPath = __DIR__ . '/../../data/img/issue1609.png';
		$html = '<p><img src="' . $imgPath . '" alt=""></p>';
		$output = $this->getOutput($mpdf, $html);
		$this->assertStringContainsString('/Artifact BMC', $output);
	}

	/**
	 * An image with no alt attribute must add a warning (treated as decorative).
	 *
	 * W3C convention: absent alt = unknown intent; treat as decorative and warn so
	 * the author can add appropriate alt text. ISO 14289-1:2014 §7.3.
	 */
	public function testImageMissingAltRecordsWarning()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$imgPath = __DIR__ . '/../../data/img/issue1609.png';
		$html = '<p><img src="' . $imgPath . '"></p>';
		$this->getOutput($mpdf, $html);
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertNotEmpty($warnings, 'Missing alt attribute must record a warning');
		$combined = implode(' ', $warnings);
		$this->assertStringContainsString('alt', $combined);
	}

	// ========================= Balance tests =========================

	/**
	 * BDC+BMC count must equal EMC count in a simple paragraph document.
	 *
	 * Scope limited to PDFUA-emitted operators (tagged struct types or /Artifact)
	 * to avoid counting Optional Content Group BDC operators that mPDF also uses
	 * (those use /OC and /ZI prefixes which are excluded by the regex).
	 *
	 * ISO 32000-1:2008 §14.6 — BDC/EMC pairs must be balanced within each content stream.
	 */
	public function testBdcEmcBalancedSimple()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>Hello</p>');
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * BDC+BMC count must equal EMC count in a document with headers and footers.
	 *
	 * This validates that the header/footer BDC/EMC splice pairs close correctly.
	 * ISO 32000-1:2008 §14.6 — every BDC must have exactly one matching EMC.
	 */
	public function testBdcEmcBalancedWithHeaders()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetHTMLHeader('<p>Running header</p>');
		$mpdf->SetHTMLFooter('<p>Running footer</p>');
		$output = $this->getOutput($mpdf, '<p>Body paragraph</p>');
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= Depth balance check tests =========================

	/**
	 * Unbalanced BDC/EMC depth > 0 at _enddoc() time with PDFUAauto=false must throw.
	 *
	 * ISO 32000-1:2008 §14.6 — unbalanced operators produce invalid PDF.
	 * Matterhorn Protocol 1.1 §02-001 — syntax errors invalidate PDF/UA.
	 */
	public function testUnbalancedMarkedContentDepthPositiveThrows()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);
		$mpdf->WriteHTML('<p>test</p>');
		// Manually open a BDC without closing it to simulate an unbalanced handler
		$mpdf->getPdfUaMarkedContentHelper()->begin('P', 0);
		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/Unbalanced marked content operators/');
		$mpdf->Output(null, 'S');
	}

	/**
	 * Unbalanced BDC/EMC depth > 0 at _enddoc() with PDFUAauto=true must warn, not throw.
	 *
	 * In auto mode, conformance violations that cannot be corrected produce a warning
	 * entry rather than an exception so the document can still be generated.
	 */
	public function testUnbalancedMarkedContentDepthPositiveWarns()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->WriteHTML('<p>test</p>');
		// Manually open a BDC without closing it
		$mpdf->getPdfUaMarkedContentHelper()->begin('P', 0);
		// Must not throw
		$mpdf->Output(null, 'S');
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertNotEmpty($warnings, 'Unbalanced depth must produce a warning in PDFUAauto=true mode');
		$combined = implode(' ', $warnings);
		$this->assertStringContainsString('Unbalanced', $combined);
	}

	// ========================= Helpers =========================

	/**
	 * Assert that the count of PDFUA-emitted BDC + BMC operators equals the count
	 * of EMC operators in the raw PDF bytes.
	 *
	 * Uses narrow regexes so OCG /OC and /ZI prefixed BDC operators — which mPDF
	 * also uses for Optional Content Groups — are not counted.
	 *
	 * @param  string $output  Raw PDF output bytes.
	 * @return void
	 */
	private function assertBdcEmcBalanced($output)
	{
		// Match /Artifact <</Type /Pagination ... >> BDC (pagination artifact with dict)
		preg_match_all('|/Artifact <</Type /Pagination[^>]*>> BDC|', $output, $paginationBdc);
		// Match /Artifact BMC (no dict — decorative content sentinel)
		preg_match_all('|/Artifact BMC\b|', $output, $artifactBmc);
		// Match struct-type BDC: /<Type> <</MCID N>> BDC (emitted by MarkedContentHelper::begin)
		preg_match_all('|/\w+ <</MCID \d+>> BDC\b|', $output, $structBdc);
		// Match EMC (all occurrences)
		preg_match_all('/\bEMC\b/', $output, $emcMatches);

		$opens = count($paginationBdc[0]) + count($artifactBmc[0]) + count($structBdc[0]);
		$closes = count($emcMatches[0]);

		$this->assertSame(
			$opens,
			$closes,
			sprintf(
				'BDC+BMC count (%d) must equal EMC count (%d). Pagination BDC: %d, Artifact BMC: %d, Struct BDC: %d, EMC: %d',
				$opens,
				$closes,
				count($paginationBdc[0]),
				count($artifactBmc[0]),
				count($structBdc[0]),
				$closes
			)
		);
	}
}
