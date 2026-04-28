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

	// ========================= List struct tests =========================

	/**
	 * <ul> produces /S /L struct element in the PDF output.
	 *
	 * ISO 32000-1:2008 §14.8 Table 333 — L (List) grouping element.
	 */
	public function testUlProducesLStruct()
	{
		$output = $this->getOutput($this->makeMpdf(), '<ul><li>Item</li></ul>');
		$this->assertStringContainsString('/S /L', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <ol> produces /S /L struct element in the PDF output.
	 *
	 * ISO 32000-1:2008 §14.8 Table 333 — L is used for both ordered and unordered lists.
	 */
	public function testOlProducesLStruct()
	{
		$output = $this->getOutput($this->makeMpdf(), '<ol><li>Item</li></ol>');
		$this->assertStringContainsString('/S /L', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <li> produces /S /LI with an /S /LBody child struct element.
	 *
	 * Tagged PDF Best Practice Guide §4.2.3 — LI must contain LBody for the
	 * item content. The content BDC is emitted as LBody, not LI.
	 */
	public function testLiProducesLiWithLblAndLBody()
	{
		$output = $this->getOutput($this->makeMpdf(), '<ul><li>Item text</li></ul>');
		$this->assertStringContainsString('/S /LI', $output);
		$this->assertStringContainsString('/S /LBody', $output);
		// Content BDC should be emitted as LBody (not LI).
		$this->assertStringContainsString('/LBody <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Nested <ul> produces outer L > LI > LBody > L (inner) > LI > LBody.
	 *
	 * The struct stack handles nesting automatically — no special-case code needed.
	 * ISO 32000-1:2008 §14.8 Table 333 — nested list elements.
	 */
	public function testNestedListNestsCorrectly()
	{
		$html = '<ul><li>Outer<ul><li>Inner</li></ul></li></ul>';
		$output = $this->getOutput($this->makeMpdf(), $html);
		// Both inner and outer L, LI, LBody must appear.
		$this->assertStringContainsString('/S /L', $output);
		$this->assertStringContainsString('/S /LI', $output);
		$this->assertStringContainsString('/S /LBody', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <dl><dt>X</dt><dd>Y</dd></dl> produces L > LI > (Lbl + LBody) structure.
	 *
	 * Tagged PDF Best Practice Guide §4.2.3 — DL → L, DT → Lbl, DD → LBody;
	 * an implicit LI wraps each Lbl+LBody pair.
	 */
	public function testDtDdProducesImplicitLi()
	{
		$html = '<dl><dt>Term</dt><dd>Definition</dd></dl>';
		$output = $this->getOutput($this->makeMpdf(), $html);
		$this->assertStringContainsString('/S /L', $output);
		$this->assertStringContainsString('/S /LI', $output);
		$this->assertStringContainsString('/S /Lbl', $output);
		$this->assertStringContainsString('/S /LBody', $output);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= Table struct tests =========================

	/**
	 * <table> produces /S /Table struct element in the PDF output.
	 *
	 * ISO 32000-1:2008 §14.8 Table 333 — Table grouping element.
	 */
	public function testTableProducesTableStruct()
	{
		$html = '<table><tr><td>Cell</td></tr></table>';
		$output = $this->getOutput($this->makeMpdf(), $html);
		$this->assertStringContainsString('/S /Table', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <tr> produces /S /TR struct element in the PDF output.
	 *
	 * ISO 32000-1:2008 §14.8 Table 333 — TR block-level table element.
	 */
	public function testTrProducesTrStruct()
	{
		$html = '<table><tr><td>Cell</td></tr></table>';
		$output = $this->getOutput($this->makeMpdf(), $html);
		$this->assertStringContainsString('/S /TR', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <td> produces /S /TD struct element and a TD BDC in the content stream.
	 *
	 * ISO 32000-1:2008 §14.8 Table 333 — TD table element.
	 */
	public function testTdProducesTdStruct()
	{
		$html = '<table><tr><td>Cell content</td></tr></table>';
		$output = $this->getOutput($this->makeMpdf(), $html);
		$this->assertStringContainsString('/S /TD', $output);
		$this->assertStringContainsString('/TD <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <th> produces /S /TH struct element with a /Scope attribute.
	 *
	 * ISO 32000-1:2008 §14.8 Table 333 — TH table header element.
	 * ISO 32000-1:2008 Table 349 — /Scope attribute (Column, Row, Both).
	 */
	public function testThProducesThStruct()
	{
		$html = '<table><tr><th>Header</th><td>Cell</td></tr></table>';
		$output = $this->getOutput($this->makeMpdf(), $html);
		$this->assertStringContainsString('/S /TH', $output);
		// TH carries a /Scope attribute (Column is the default).
		$this->assertStringContainsString('/Scope', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <td headers="h1 h2"> produces /A <</O /Table /Headers [/h1 /h2]>> on the TD.
	 *
	 * ISO 32000-1:2008 Table 349 — /Headers attribute (array of name objects).
	 * ISO 14289-1:2014 §7.5 — Matterhorn 09-004/09-005: complex header associations.
	 */
	public function testTdHeadersAttributeMapsToStructElement()
	{
		$html = '<table>'
			. '<tr><th id="col1">Col 1</th><th id="col2">Col 2</th></tr>'
			. '<tr><td headers="col1 col2">Data</td></tr>'
			. '</table>';
		$output = $this->getOutput($this->makeMpdf(), $html);
		// The TD struct element must have a /Table attribute object with /Headers.
		$this->assertStringContainsString('/O /Table', $output);
		$this->assertStringContainsString('/Headers', $output);
		// The referenced IDs must appear as name objects in the array.
		$this->assertStringContainsString('/col1', $output);
		$this->assertStringContainsString('/col2', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * A table whose rows span a page break produces TD struct elements and balanced
	 * BDC/EMC pairs across multiple pages.
	 *
	 * Per §A14: multi-page MCR handling is managed by StructureTree::addContent()
	 * which records the /StructParents integer on each MCR. This test verifies the
	 * document renders without exception and BDC/EMC operators are balanced even
	 * when a table's cells appear on multiple pages.
	 *
	 * ISO 32000-1:2008 §14.7.4.4 Table 324 — MCR dict: /Type /MCR /Pg N 0 R /MCID n.
	 * ISO 14289-1 Appendix A14 — multi-page struct element /K arrays use MCR dicts.
	 */
	public function testTableRowAcrossPageBreakMcrDicts()
	{
		$mpdf = $this->makeMpdf();
		// Build a table with many rows to force a page break.
		$rows = '';
		for ($i = 0; $i < 60; $i++) {
			$rows .= '<tr><td>Row ' . $i . ' content that is long enough</td></tr>';
		}
		$html = '<table>' . $rows . '</table>';
		$output = $this->getOutput($mpdf, $html);
		// TD struct elements must appear (one per cell).
		$this->assertStringContainsString('/S /TD', $output);
		// BDC/EMC operators must be balanced across all pages.
		$this->assertBdcEmcBalanced($output);
		// The document must have multiple pages (table spans page break).
		$this->assertGreaterThan(1, $mpdf->page, 'Table must span more than one page');
	}

	// ========================= Cross-page MCR dict tests =========================

	/**
	 * A paragraph spanning a page break must produce MCR dicts (not bare integers)
	 * in the struct element's /K array.
	 *
	 * Matterhorn Protocol 1.1 condition 01-006 — untagged real content. When a
	 * single /S /P struct element has glyphs on two or more pages, its /K array
	 * must contain MCR dicts of the form /Type /MCR /Pg N 0 R /MCID n (one per
	 * page portion). Bare integer /K values are only valid for single-page content
	 * (ISO 32000-1:2008 §14.7.4.4).
	 *
	 * FAILS: The current implementation emits a bare integer /K for cross-page
	 * paragraphs because finishFlowingBlock() calls addContent() once (on the
	 * first page) and the StructureWriter singleSimpleMcid path collapses it
	 * to a bare MCID integer. Multi-page paragraphs need addContent() called once
	 * per page portion so that each page's struct parents entry is populated and
	 * StructureWriter can emit full MCR dicts.
	 *
	 * See plan §A9 (priority test list) and §A14 (MCR dict requirements).
	 *
	 * ISO 32000-1:2008 §14.7.4.4 Table 324 — MCR dict: /Type /MCR /Pg N 0 R /MCID n.
	 * Matterhorn Protocol 1.1 §01-006 — untagged real content.
	 *
	 * @group pdfua
	 */
	public function testParagraphSplitAcrossPageBreakMcrDicts()
	{
		$mpdf = $this->makeMpdf();
		// A single very long paragraph forces mPDF to split it across multiple pages.
		$html = '<p>' . str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 500) . '</p>';
		$output = $this->getOutput($mpdf, $html);

		// Document must span more than one page.
		$this->assertGreaterThan(1, $mpdf->page, 'Paragraph must span more than one page');
		// The output must contain /Type /MCR dicts to link the struct element to each page.
		$this->assertStringContainsString(
			'/Type /MCR',
			$output,
			'Cross-page paragraph must produce MCR dicts (/Type /MCR) in struct element /K array'
		);
		// There must be at least two distinct /Pg N 0 R references (one per page).
		preg_match_all('/\/Pg (\d+) 0 R/', $output, $pgMatches);
		$distinctPages = array_unique($pgMatches[1]);
		$this->assertGreaterThanOrEqual(
			2,
			count($distinctPages),
			'MCR dicts must reference at least two distinct page objects for a cross-page paragraph'
		);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= Figure BBox tests =========================

	/**
	 * A Figure struct element must carry a /BBox attribute in a /Layout attr object.
	 *
	 * ISO 32000-1:2008 Table 344 (Layout attribute owner) — /BBox is required for
	 * any Figure appearing in its entirety on a single page so that assistive
	 * technology can locate it within the page coordinate system.
	 * Matterhorn Protocol 1.1 condition 13-008 — Figure /BBox missing.
	 * Plan §A4 — BBox emitted via open('Figure', ['Alt' => ..., 'BBox' => [...]]).
	 *
	 * The BBox array is [llx lly urx ury] in default user space units (pt).
	 *
	 * @group pdfua
	 */
	public function testFigureStructElementHasBBox()
	{
		$mpdf = $this->makeMpdf();
		$imgPath = __DIR__ . '/../../data/img/issue1609.png';
		if (!file_exists($imgPath)) {
			$this->markTestSkipped('Test image not available: ' . $imgPath);
		}
		$output = $this->getOutput(
			$mpdf,
			'<img src="' . $imgPath . '" alt="Test caption" width="100" height="50">'
		);
		// The Figure struct element must carry a /Layout attribute object.
		$this->assertStringContainsString('/O /Layout', $output);
		// The /BBox key must appear inside the attribute object.
		$this->assertStringContainsString('/BBox', $output);
		// The BBox must be a four-element array of numbers.
		$this->assertMatchesRegularExpression(
			'/\/BBox \[[\d\.\- ]+\]/',
			$output,
			'Figure /BBox must be a four-element number array [llx lly urx ury]'
		);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= List Lbl+LBody tests =========================

	/**
	 * Each list item must produce both Lbl (bullet/marker) and LBody (content)
	 * child struct elements inside the LI struct element.
	 *
	 * Tagged PDF Best Practice Guide §4.2.3 — LI must contain Lbl for the list
	 * marker and LBody for the item content. Two list items must therefore produce
	 * two /S /LI elements, each containing at least one /S /Lbl child.
	 *
	 * Matterhorn Protocol 1.1 condition 21-001 — list numbering attribute missing,
	 * which indicates incomplete list structure tagging.
	 * Plan §A9 (priority test list) — testLiStructureHasLblAndLBody.
	 *
	 * Implementation: Li::open() opens a Lbl struct element as a child of LI,
	 * stores a reference to it in blk['pdfua_li_lbl_elem'], and immediately closes
	 * it (deferred-render pattern). When printobjectbuffer() later renders the
	 * 'listmarker' object, it calls StructureTree::addContentForElement() with the
	 * stored reference to attach the MCID to Lbl and wraps the marker drawing
	 * commands in a Lbl BDC/EMC. Li::open() then opens LBody as the content
	 * container. Both Lbl and LBody are direct children of LI in the struct tree.
	 *
	 * Deliberate limitation: position:inside markers, list-style-type:none, and
	 * CSS image markers do not produce a Lbl element. _setListMarker() sets
	 * $mpdf->listitem to a non-empty array only for position:outside text/symbol
	 * markers (disc, circle, square, ordered counters, U+ symbols), so Li::open()
	 * skips Lbl for the other cases to avoid empty struct elements. This is a known
	 * gap — Matterhorn 21-001 coverage is limited to the position:outside path.
	 *
	 * @group pdfua
	 */
	public function testLiStructureHasLblAndLBody()
	{
		$output = $this->getOutput($this->makeMpdf(), '<ul><li>First</li><li>Second</li></ul>');
		// Two LI struct elements (one per list item).
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($output, '/S /LI'),
			'Each <li> must produce a /S /LI struct element'
		);
		// Each LI must have an LBody child (content wrapper).
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($output, '/S /LBody'),
			'Each <li> must produce a /S /LBody child struct element'
		);
		// Each LI must also have a Lbl child (marker/bullet wrapper).
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($output, '/S /Lbl'),
			'Each <li> must produce a /S /Lbl child struct element for the list marker'
		);
		$this->assertBdcEmcBalanced($output);
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
