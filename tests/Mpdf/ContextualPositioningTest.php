<?php

namespace Mpdf;

/**
 * GPOS Lookup Types 7 and 8 match a sequence of glyphs and then hand named positions within it to
 * other lookups, exactly as GSUB Types 5 and 6 do for substitution. Type 7 is a plain context;
 * Type 8 puts a backtrack and a lookahead around it. Format 1 lists the glyphs of each rule one by
 * one, Format 2 matches them against classes, Format 3 against a Coverage table per position.
 *
 * Positioning does not change the shaped text, so the seam TextRecordingMpdf uses cannot see any of
 * this. PositionRecordingMpdf reads the adjustments off the same OTLdata the drawing code is given.
 */
class ContextualPositioningTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	private function mpdf($fontkey, $file)
	{
		return new PositionRecordingMpdf([
			'fontDir' => [__DIR__ . '/../data/ttf'],
			'fontdata' => [$fontkey => [
				'R' => $file,
				'useOTL' => 0xFF,
			]],
			'default_font' => $fontkey,
		]);
	}

	/**
	 * Noto Sans Takri's `kern` reaches a pair adjustment through a chained context: the I matra is
	 * pulled across the KA that follows it only when the anusvara closes the cluster.
	 */
	public function testAppliesAGlyphListChainedContextPositioning()
	{
		$mpdf = $this->mpdf('takrigpos', 'NotoSansTakri-GPOS81-Subset.ttf');

		$mpdf->WriteHTML('<p>&#x116AE;&#x1168A;&#x116AB;</p>');

		$this->assertEquals(
			[[1 => ['XAdvanceL' => 107, 'XAdvanceR' => 107, 'XPlacement' => 107]]],
			$mpdf->drawnPositions
		);
	}

	/**
	 * The same matra on its own has nothing for the chain to match, and is left where it was.
	 */
	public function testLeavesAGlyphTheChainedContextDoesNotReach()
	{
		$mpdf = $this->mpdf('takrigpos', 'NotoSansTakri-GPOS81-Subset.ttf');

		$mpdf->WriteHTML('<p>&#x116AE;</p>');

		$this->assertSame([], $mpdf->drawnPositions);
	}

	/**
	 * Noto Sans Gurmukhi UI's `dist` nudges the AU matra + addak ligature by a single unit, but only
	 * where a TTA and an EE matra follow it - a plain context, with no backtrack or lookahead.
	 */
	public function testAppliesAGlyphListPlainContextPositioning()
	{
		$mpdf = $this->mpdf('gurmukhigpos', 'NotoSansGurmukhiUI-GPOS71-Subset.ttf');

		$mpdf->WriteHTML('<p>&#x0A2C;&#x0A4C;&#x0A71;&#x0A1F;&#x0A47;</p>');

		$this->assertEquals([[1 => ['XPlacement' => -1]]], $mpdf->drawnPositions);
	}

	/**
	 * Drop the matra the context begins with and nothing is adjusted, which is what tells the rule
	 * apart from the single positioning it delegates to.
	 */
	public function testLeavesAGlyphThePlainContextDoesNotReach()
	{
		$mpdf = $this->mpdf('gurmukhigpos', 'NotoSansGurmukhiUI-GPOS71-Subset.ttf');

		$mpdf->WriteHTML('<p>&#x0A2C;&#x0A1F;&#x0A47;</p>');

		$this->assertSame([], $mpdf->drawnPositions);
	}

	/**
	 * None of the 701 fonts surveyed for GravityPDF/mpdf#80 carries a Type 7 Format 3 subtable, so
	 * the fixture is written by hand: Noto Sans cut down to A, B and C, with a GPOS of two lookups -
	 * a single positioning that shifts B left by 400 units, and a plain context covering A then B
	 * that runs it at the second position. `dist` is the only feature, on both DFLT and latn.
	 */
	public function testAppliesACoverageBasedPlainContextPositioning()
	{
		$mpdf = $this->mpdf('context73', 'NotoSans-GPOS73-Synthetic.ttf');

		$mpdf->WriteHTML('<p>ABAB</p>');

		$this->assertEquals(
			[[
				1 => ['XPlacement' => -400],
				3 => ['XPlacement' => -400, 'XAdvanceL' => -400, 'XAdvanceR' => -400],
			]],
			$mpdf->drawnPositions
		);
	}

	/**
	 * The B is only moved where the context's first Coverage table matches what precedes it, so the
	 * same glyph after a C, and the same pair the other way round, stay put.
	 */
	public function testLeavesAGlyphTheCoverageBasedContextDoesNotReach()
	{
		$mpdf = $this->mpdf('context73', 'NotoSans-GPOS73-Synthetic.ttf');

		$mpdf->WriteHTML('<p>CB</p><p>BA</p>');

		$this->assertSame([], $mpdf->drawnPositions);
	}

}
