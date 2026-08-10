<?php

namespace Mpdf\Ua;

/**
 * Tests for ISO 14289-1:2014 §7.4.2 rule 1 — heading-order enforcement.
 *
 * The first heading in the document must be H1, and descending heading
 * sequences must not skip intervening levels (e.g. H1 → H3 is invalid).
 * BlockTag::open() applies auto-clamp (PDFUAauto=true) or throws
 * MpdfException (PDFUAauto=false / strict mode).
 *
 * All tests render small HTML snippets and assert on the raw PDF bytes
 * (compress=false from PdfUaTestCase::makeMpdf()).
 *
 * @group pdfua
 */
class HeadingSequenceTest extends PdfUaTestCase
{

	/**
	 * When the first heading in the document is <h2>, auto-clamp promotes it to H1.
	 *
	 * ISO 14289-1:2014 §7.4.2 rule 1 — H1 shall be the first heading used.
	 * In PDFUAauto mode the violation is corrected silently; in strict mode it throws.
	 */
	public function testFirstHeadingPromotedToH1WhenH2Comes()
	{
		$mpdf   = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<h2>Only heading</h2>');

		// The struct element must be H1 (promoted from H2).
		$this->assertStringContainsString('/S /H1', $output);
		// H2 must NOT appear — the clamp replaced it entirely.
		$this->assertStringNotContainsString('/S /H2', $output);

		// A warning must have been recorded.
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('7.4.2', $warnings[0]);
	}

	/**
	 * When a sequence jumps from H1 to H3, auto-clamp reduces H3 to H2.
	 *
	 * ISO 14289-1:2014 §7.4.2 rule 1 — descending sequences shall proceed
	 * in strict numerical order without skipping levels.
	 */
	public function testSkippedLevelPromotedToValidLevel()
	{
		$mpdf   = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<h1>Title</h1><h3>Skipped sub</h3>');

		// H1 must be present unchanged.
		$this->assertStringContainsString('/S /H1', $output);
		// H3 must be clamped to H2.
		$this->assertStringContainsString('/S /H2', $output);
		// The original H3 must NOT appear.
		$this->assertStringNotContainsString('/S /H3', $output);

		// Exactly one warning for the skipped level.
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('7.4.2', $warnings[0]);
	}

	/**
	 * A valid H1 → H2 sequence must not be altered and must produce no warnings.
	 *
	 * ISO 14289-1:2014 §7.4.2 rule 1 — a conforming ascending / sequential
	 * sequence requires no clamping.
	 */
	public function testValidSequenceNotChanged()
	{
		$mpdf   = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<h1>Title</h1><h2>Section</h2>');

		$this->assertStringContainsString('/S /H1', $output);
		$this->assertStringContainsString('/S /H2', $output);

		// No heading-sequence warnings should have been recorded.
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertCount(0, $warnings);
	}

	/**
	 * Multiple heading jumps each trigger a single clamp and warning.
	 *
	 * H1 → H4 skips H2 and H3; auto-clamp assigns H2.
	 * H2 → H5 then skips H3 and H4; auto-clamp assigns H3.
	 */
	public function testMultipleSkipsEachClampedIndependently()
	{
		$mpdf   = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<h1>A</h1><h4>B</h4><h5>C</h5>');

		// H4 clamped to H2, H5 clamped to H3.
		$this->assertStringContainsString('/S /H1', $output);
		$this->assertStringContainsString('/S /H2', $output);
		$this->assertStringContainsString('/S /H3', $output);
		$this->assertStringNotContainsString('/S /H4', $output);
		$this->assertStringNotContainsString('/S /H5', $output);

		// Two warnings: one per skipped level event.
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertCount(2, $warnings);
	}

	/**
	 * Ascending heading sequences (going back up) are always valid.
	 *
	 * A sequence H1 → H2 → H1 is permitted — re-use of a higher-level
	 * heading resets the allowed next level; the key rule is only about
	 * descending sequences not skipping.
	 */
	public function testAscendingAfterDescendingIsValid()
	{
		$mpdf   = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<h1>A</h1><h2>B</h2><h1>C</h1>');

		$this->assertStringContainsString('/S /H1', $output);
		$this->assertStringContainsString('/S /H2', $output);

		// No heading-sequence warnings.
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertCount(0, $warnings);
	}

	/**
	 * Strict mode throws MpdfException when the first heading is not H1.
	 *
	 * PDFUAauto=false (default) makes BlockTag::open() throw instead of clamping.
	 */
	public function testStrictModeThrowsOnNonH1First()
	{
		$this->expectException('\Mpdf\MpdfException');
		$this->expectExceptionMessageMatches('/7\.4\.2/');

		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);
		$this->getOutput($mpdf, '<h2>No H1 before me</h2>');
	}

	/**
	 * Strict mode throws MpdfException when a descending sequence skips a level.
	 *
	 * H1 → H3 is invalid; PDFUAauto=false must throw, not clamp silently.
	 */
	public function testStrictModeThrowsOnSkippedLevel()
	{
		$this->expectException('\Mpdf\MpdfException');
		$this->expectExceptionMessageMatches('/7\.4\.2/');

		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);
		$this->getOutput($mpdf, '<h1>Title</h1><h3>Skipped</h3>');
	}
}
