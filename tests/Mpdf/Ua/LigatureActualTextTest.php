<?php

namespace Mpdf\Ua;

/**
 * Tests for ligature ActualText wrapping (Matterhorn 24-001).
 *
 * Matterhorn 24-001 fires when a glyph produced by an OTL LookupType 4
 * (ligature) substitution has no 1:1 Unicode entry in the font's ToUnicode
 * CMap. The fix is to wrap each such glyph with:
 *
 *   /Span <</ActualText <FEFF…>>> BDC  …glyph…  EMC
 *
 * so that a conforming reader can extract the original Unicode text.
 *
 * All tests use DejaVuSerif with useOTL = 0xFF so the OTL engine is active,
 * and set $mpdf->compress = false (via makeMpdf()) so assertions can match
 * raw operator bytes in the uncompressed page content stream.
 *
 * ISO 32000-1:2008 §14.7.2 Table 322 — /ActualText attribute.
 * ISO 32000-1:2008 §14.6 — BDC/EMC marked content operators.
 * Matterhorn Protocol 1.1 condition 24-001.
 *
 * @group pdfua
 */
class LigatureActualTextTest extends PdfUaTestCase
{

	/**
	 * Font config with OTL enabled for DejaVuSerif.
	 *
	 * DejaVuSerif is bundled with mPDF and supports common ligatures (fi, ff,
	 * ffl). useOTL is not set in the default FontVariables registration, so it
	 * must be injected via the 'fontdata' config key.
	 *
	 * @return array  fontdata config array
	 */
	private function dejavuSerifWithOtl()
	{
		return [
			'dejavuserif' => [
				'R'      => 'DejaVuSerif.ttf',
				'B'      => 'DejaVuSerif-Bold.ttf',
				'I'      => 'DejaVuSerif-Italic.ttf',
				'BI'     => 'DejaVuSerif-BoldItalic.ttf',
				'useOTL' => 0xFF,
			],
		];
	}

	/**
	 * The fi ligature in 'find' must produce an ActualText wrapper.
	 *
	 * DejaVuSerif substitutes the f+i glyph pair into a single ligature CID.
	 * The wrapper must encode the original two codepoints (U+0066 U+0069) as
	 * UTF-16BE with BOM: FEFF00660069.
	 *
	 * ISO 32000-1 §14.7.2 — ActualText is a text string (UTF-16BE).
	 */
	public function testFiLigatureProducesActualText()
	{
		$mpdf = $this->makeMpdf(['fontdata' => $this->dejavuSerifWithOtl()]);
		$pdf  = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">find</p>');

		// FEFF = BOM; 0066 = f; 0069 = i
		$this->assertStringContainsString(
			'/Span <</ActualText <FEFF00660069>>>',
			$pdf,
			'fi ligature must be wrapped with /ActualText <FEFF00660069>'
		);
		$this->assertStringContainsString('BDC', $pdf);
		$this->assertStringContainsString('EMC', $pdf);
	}

	/**
	 * The ff ligature in 'office' must produce an ActualText wrapper.
	 *
	 * DejaVuSerif forms the ff ligature (U+FB00) before an fi ligature, so
	 * 'office' produces one ff ActualText wrapper (FEFF00660066).
	 *
	 * ISO 32000-1 §14.7.2 — ActualText preserves the original Unicode sequence.
	 */
	public function testFfLigatureProducesActualText()
	{
		$mpdf = $this->makeMpdf(['fontdata' => $this->dejavuSerifWithOtl()]);
		$pdf  = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">office</p>');

		// FEFF = BOM; 0066 = f; 0066 = f
		$this->assertStringContainsString(
			'/Span <</ActualText <FEFF00660066>>>',
			$pdf,
			'ff ligature in "office" must be wrapped with /ActualText <FEFF00660066>'
		);
	}

	/**
	 * The ffl ligature in 'ruffled' must produce an ActualText wrapper.
	 *
	 * For a three-component ligature (ffl → single glyph), ActualText must
	 * contain the full three-codepoint UTF-16BE sequence.
	 *
	 * DejaVuSerif forms 'ffl' as a single LookupType 4 substitution (unlike
	 * 'ffi' in words like 'difficult', where it forms 'ff' first then leaves
	 * 'i' separate). The word 'ruffled' is chosen because it contains 'ffl'
	 * and DejaVuSerif substitutes it in a single three-component ligature step.
	 *
	 * ISO 32000-1 §14.7.2 — multi-character ActualText for multi-source ligatures.
	 */
	public function testFflLigatureProducesActualText()
	{
		$mpdf = $this->makeMpdf(['fontdata' => $this->dejavuSerifWithOtl()]);
		$pdf  = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">ruffled</p>');

		// FEFF = BOM; 0066 = f; 0066 = f; 006C = l — ffl three-char ligature.
		$this->assertStringContainsString(
			'/Span <</ActualText <FEFF00660066006C>>>',
			$pdf,
			'ffl ligature in "ruffled" must be wrapped with /ActualText <FEFF00660066006C>'
		);
	}

	/**
	 * Plain ASCII text with no OTL ligature substitution produces no wrapper.
	 *
	 * The word 'hello' contains no ligature sequences for DejaVuSerif, so
	 * the content stream must contain no /Span /ActualText BDC operator.
	 */
	public function testNoLigatureNoWrapper()
	{
		$mpdf = $this->makeMpdf(['fontdata' => $this->dejavuSerifWithOtl()]);
		$pdf  = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">hello</p>');

		$this->assertStringNotContainsString(
			'/Span <</ActualText',
			$pdf,
			'Text with no ligatures must not produce an /ActualText wrapper'
		);
	}

	/**
	 * When toUnicodeCovers() returns true (font has synthetic multi-char CMap
	 * entry), the ActualText wrapper is omitted.
	 *
	 * This test exercises the skip path in LigatureActualTextWriter::toUnicodeCovers()
	 * by injecting a synthetic 'toUnicodeMultiChar' entry directly into the
	 * font before PDF generation begins. The injected entry maps the fi ligature
	 * glyph (U+FB01 = 64257) to the two-codepoint source array [102, 105].
	 *
	 * Since toUnicodeCovers() checks this map and returns true, the wrapper is
	 * skipped — the PDF must NOT contain /Span /ActualText for that ligature.
	 *
	 * ISO 32000-1 §9.10.3 — ToUnicode CMap; multi-char dstStrings.
	 * Matterhorn 24-001 — no wrapper needed when CMap already covers the glyph.
	 */
	public function testToUnicodeCoveredSkipsWrapper()
	{
		$mpdf = $this->makeMpdf(['fontdata' => $this->dejavuSerifWithOtl()]);

		// Load the font so CurrentFont is populated.
		$mpdf->SetFont('dejavuserif', '', 12);

		// Inject a synthetic toUnicodeMultiChar entry for U+FB01 (fi ligature).
		// U+FB01 = 64257. Source codepoints: f=102, i=105.
		// LigatureActualTextWriter::toUnicodeCovers() will find this and skip
		// the wrapper.
		$mpdf->CurrentFont['toUnicodeMultiChar'] = [
			64257 => [102, 105],
		];

		$pdf = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">find</p>');

		// The fi ligature should NOT be wrapped because toUnicodeCovers() found
		// a matching multi-char CMap entry for glyph U+FB01.
		$this->assertStringNotContainsString(
			'/Span <</ActualText <FEFF00660069>>>',
			$pdf,
			'When ToUnicode already covers the fi ligature, the wrapper must be skipped'
		);
	}

	/**
	 * The /Span /ActualText BDC falls inside the parent MCID-bearing struct range.
	 *
	 * The ActualText BDC wrapper does NOT create a new struct element — it is
	 * a property-dict BDC without MCID. The surrounding /P struct element's
	 * MCID-bearing BDC must precede the /Span BDC in the content stream, and
	 * the fi ActualText must appear nested inside that outer range.
	 *
	 * This test asserts the ordering using regex to locate:
	 *   - the outer MCID-bearing BDC (MCID keyword present)
	 *   - the /Span /ActualText BDC immediately following
	 *   - its closing EMC (the text "EMC" that appears after the span BDC)
	 *   - the final outer EMC that closes the struct element
	 *
	 * Using regex-based positions rather than fragile first/last strpos calls
	 * so that additional EMC occurrences elsewhere in the PDF do not skew the
	 * comparison.
	 */
	public function testLigatureSpanNestedInsideParentStructRange()
	{
		$mpdf = $this->makeMpdf(['fontdata' => $this->dejavuSerifWithOtl()]);
		$pdf  = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">find</p>');

		// Locate the outer struct BDC that contains MCID.
		// e.g. "/P <</MCID 0>> BDC" or "/Span <</MCID 1>> BDC"
		$outerBdcFound = preg_match('/MCID\s+\d+\s*>>+\s*BDC/', $pdf, $m, PREG_OFFSET_CAPTURE);
		$this->assertSame(1, $outerBdcFound, 'Outer MCID-bearing BDC must be present in the PDF');
		$outerBdcPos = $m[0][1];

		// Locate the /Span /ActualText BDC for the fi ligature.
		$spanBdcFound = preg_match(
			'/\/Span\s*<<\/ActualText\s*<FEFF[0-9A-F]+>\s*>>\s*BDC/',
			$pdf,
			$m2,
			PREG_OFFSET_CAPTURE
		);
		$this->assertSame(1, $spanBdcFound, '/Span /ActualText BDC must be present');
		$spanBdcPos = $m2[0][1];
		$spanBdcEnd = $spanBdcPos + strlen($m2[0][0]);

		// Locate the EMC that closes the Span — the first "EMC" that appears
		// after the /Span BDC operator.
		$spanEmcFound = preg_match('/EMC/', $pdf, $m3, PREG_OFFSET_CAPTURE, $spanBdcEnd);
		$this->assertSame(1, $spanEmcFound, 'EMC closing the /Span must appear after the /Span BDC');
		$spanEmcPos = $m3[0][1];
		$spanEmcEnd = $spanEmcPos + strlen($m3[0][0]);

		// Locate the outer struct closing EMC — the next EMC after the Span's EMC.
		$outerEmcFound = preg_match('/EMC/', $pdf, $m4, PREG_OFFSET_CAPTURE, $spanEmcEnd);
		$this->assertSame(1, $outerEmcFound, 'Outer struct closing EMC must appear after the Span EMC');
		$outerEmcPos = $m4[0][1];

		// Nesting order: outer BDC < span BDC < span EMC < outer EMC
		$this->assertLessThan(
			$spanBdcPos,
			$outerBdcPos,
			'Outer struct BDC must precede /Span BDC'
		);
		$this->assertLessThan(
			$spanEmcPos,
			$spanBdcPos,
			'/Span BDC must precede its closing EMC'
		);
		$this->assertLessThan(
			$outerEmcPos,
			$spanEmcPos,
			'/Span EMC must precede the outer struct closing EMC'
		);

		// The ActualText BDC must NOT contain MCID — it is a plain property-dict
		// BDC without struct element registration.
		$spanBdcSnippet = substr($pdf, $spanBdcPos, 80);
		$this->assertStringNotContainsString(
			'MCID',
			$spanBdcSnippet,
			'/Span /ActualText BDC must not contain MCID'
		);
	}

	/**
	 * The exemplar sentence "fine office difficulty" must produce ActualText wrappers
	 * for every OTL ligature DejaVuSerif forms from those words.
	 *
	 * Empirical analysis of DejaVuSerif OTL output for this sentence:
	 *   - "fine"       → fi ligature:  FEFF00660069
	 *   - "office"     → ff ligature:  FEFF00660066  (then separate 'i' via CMap)
	 *   - "difficulty" → ff ligature:  FEFF00660066  (then separate 'i' + 'culty')
	 *
	 * Note: the task brief mentioned ffi/ffl for this sentence, but empirical
	 * verification shows DejaVuSerif does NOT form an ffi ligature for "difficulty"
	 * (it forms ff then leaves 'i' for the CMap). The ffl ligature appears in
	 * "ruffled", which is already covered by testFflLigatureProducesActualText().
	 * This test asserts the actual OTL output for the exemplar sentence.
	 *
	 * Matterhorn Protocol 1.1 condition 24-001 — ligature glyph without ActualText.
	 * ISO 32000-1:2008 §14.7.2 Table 322 — /ActualText attribute.
	 *
	 * @group pdfua
	 */
	public function testDejaVuSerifLigatureSentenceProducesExpectedWrappers()
	{
		if (!file_exists(__DIR__ . '/../../../ttfonts/DejaVuSerif.ttf')) {
			$this->markTestSkipped('DejaVuSerif.ttf not found in ttfonts/');
		}
		$mpdf = $this->makeMpdf(['fontdata' => $this->dejavuSerifWithOtl()]);
		$pdf  = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">fine office difficulty</p>');

		preg_match_all('/\/Span <<\/ActualText <(FEFF[0-9A-F]+)>>>/', $pdf, $m);
		$wrappers = $m[1];

		// fi ligature (from "fine"): U+0066 U+0069.
		$this->assertContains(
			'FEFF00660069',
			$wrappers,
			'fi ligature in "fine" must produce /ActualText <FEFF00660069>'
		);

		// ff ligature (from "office"): U+0066 U+0066.
		$ffCount = count(array_keys($wrappers, 'FEFF00660066'));
		$this->assertGreaterThanOrEqual(
			1,
			$ffCount,
			'ff ligature in "office" must produce /ActualText <FEFF00660066>'
		);

		// Total wrapper count: fi (×1) + ff (×2, from "office" and "difficulty") = 3.
		$this->assertCount(
			3,
			$wrappers,
			'Exemplar sentence must produce exactly 3 ActualText wrappers (fi from "fine", ff from "office", ff from "difficulty")'
		);
	}

	/**
	 * ActualText wrappers must not appear when PDFUA mode is disabled.
	 *
	 * The PDFUA gate in applyGPOSpdf() initialises $ligActualTextWriter only
	 * when $this->PDFUA is truthy. When the document is not PDF/UA-1, no
	 * BDC/EMC operators of this form should be emitted, regardless of whether
	 * OTL forms ligatures.
	 *
	 * This test proves the gate is real: the same fi-ligature word 'find' with
	 * the same OTL-enabled font must produce zero /Span /ActualText wrappers
	 * in a non-PDFUA document.
	 */
	public function testNoWrapperWhenPdfuaDisabled()
	{
		// Construct Mpdf directly without PDFUA=true — same font config as the
		// positive tests so OTL ligature substitution still fires.
		$mpdf = new \Mpdf\Mpdf([
			'mode'     => 'en-GB',
			'fontdata' => $this->dejavuSerifWithOtl(),
		]);
		// Disable compression so content-stream bytes are directly matchable.
		$mpdf->compress = false;

		$pdf = $this->getOutput($mpdf, '<p style="font-family: dejavuserif;">find</p>');

		$this->assertStringNotContainsString(
			'/Span <</ActualText',
			$pdf,
			'ActualText wrappers must not appear when PDFUA mode is disabled'
		);
	}
}
