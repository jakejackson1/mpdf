<?php

namespace Mpdf;

/**
 * GSUB Lookup Type 8 is the only lookup applied from the last glyph back to the first. It replaces
 * exactly one glyph per match, and each match reads a lookahead the pass has already been over and
 * a backtrack it has not - so a run of matches propagates leftwards, which no forward walk can
 * reproduce.
 *
 * The fixture is Noto Sans Coptic's supralinear stroke. Three `ccmp` lookups decide which overlines
 * take the taller `.cap` form: a Type 6 catches an overline written straight after a capital, a
 * second Type 6 carries that along the run through its backtrack, and a Type 8 carries it the other
 * way - any overline whose following overline is already `.cap` becomes `.cap` itself. Only the last
 * of the three can reach an overline written before the capital, so the first test below fails
 * outright if the lookup is walked forwards.
 */
class ReverseChainingSubstitutionTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * The Coptic codepoints the fixture covers, as UTF-8. Spelt out in bytes because the \u{}
	 * escape needs PHP 7.0 and this suite still runs on 5.6.
	 */
	const SHEI = "\xCF\xA3";         // U+03E3 COPTIC SMALL LETTER SHEI

	const SHEI_CAPITAL = "\xCF\xA2"; // U+03E2 COPTIC CAPITAL LETTER SHEI

	const OVERLINE = "\xCC\x85";     // U+0305 COMBINING OVERLINE

	/**
	 * The font draws the overline at the width of the letter beneath it, and none of those variants
	 * has a codepoint of its own, so they are mapped into the Private Use Area in the order the
	 * subset introduces them.
	 */
	const OVERLINE_XLARGE = "\xEE\x80\x83";      // U+E003 uni0305.xlarge

	const OVERLINE_XLARGE_CAP = "\xEE\x80\x88";  // U+E008 uni0305.xlarge.cap

	const OVERLINE_XXLARGE_CAP = "\xEE\x80\x89"; // U+E009 uni0305.xxlarge.cap

	private function coptic()
	{
		return new TextRecordingMpdf([
			'fontDir' => [__DIR__ . '/../data/ttf'],
			'fontdata' => ['copticsubset' => [
				'R' => 'NotoSansCoptic-GSUB81-Subset.ttf',
				'useOTL' => 0xFF,
			]],
			'default_font' => 'copticsubset',
		]);
	}

	/**
	 * The capital comes second, so the only lookup that can reach the first overline is the reverse
	 * one, reading the `.cap` the pass has already put on the second.
	 */
	public function testCarriesASubstitutionBackwardsAlongTheRun()
	{
		$mpdf = $this->coptic();

		$mpdf->WriteHTML('<p>&#x03E3;&#x0305;&#x03E2;&#x0305;</p>');

		$this->assertSame(
			[self::SHEI . self::OVERLINE_XLARGE_CAP . self::SHEI_CAPITAL . self::OVERLINE_XXLARGE_CAP],
			$mpdf->drawnText
		);
	}

	/**
	 * The same run written the other way round is the chaining lookups' own job, and must still be
	 * done - the reverse pass is an addition to them, not a replacement.
	 */
	public function testCarriesASubstitutionForwardsAlongTheRun()
	{
		$mpdf = $this->coptic();

		$mpdf->WriteHTML('<p>&#x03E2;&#x0305;&#x03E3;&#x0305;</p>');

		$this->assertSame(
			[self::SHEI_CAPITAL . self::OVERLINE_XXLARGE_CAP . self::SHEI . self::OVERLINE_XLARGE_CAP],
			$mpdf->drawnText
		);
	}

	/**
	 * With no capital to start it, nothing in the run is `.cap` for the reverse pass to read, and
	 * both overlines keep the form the width lookup gave them.
	 */
	public function testLeavesARunThatNoCapitalStarts()
	{
		$mpdf = $this->coptic();

		$mpdf->WriteHTML('<p>&#x03E3;&#x0305;&#x03E3;&#x0305;</p>');

		$this->assertSame(
			[self::SHEI . self::OVERLINE_XLARGE . self::SHEI . self::OVERLINE_XLARGE],
			$mpdf->drawnText
		);
	}

}
