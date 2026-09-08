<?php

namespace Mpdf;

use Mpdf\Fonts\FontCache;

class DictionaryLineBreakingTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const ZWSP = "\xE2\x80\x8B";

	/**
	 * Thai for "test", with and without the abbreviation full stop that used to insert U+200B on its own
	 */
	const THAI_WITH_FULL_STOP = 'ถ.ทดสอบ';

	const THAI = 'ทดสอบข้อความ';

	/**
	 * Repeated by the caller into a run long enough to wrap, so the marker has a line break to
	 * drive before it is taken back out
	 */
	const THAI_PHRASE = 'ทดสอบข้อความภาษาไทยสำหรับการตัดบรรทัด';

	const TIBETAN_PHRASE = 'བོད་སྐད་ཡིག་ཚོགས་';

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	protected function tear_down()
	{
		if ($this->mpdf) {
			$this->mpdf->cleanup();
			$this->mpdf = null;
		}

		parent::tear_down();
	}

	private function applyOtl($text, $useDictionaryLBR)
	{
		$tempDir = sys_get_temp_dir() . '/mpdf-dictionary-lbr-test';

		$this->mpdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => $tempDir, 'useDictionaryLBR' => $useDictionaryLBR]);
		$this->mpdf->SetFont('garuda');

		$otl = new Otl($this->mpdf, new FontCache(new Cache($tempDir . '/mpdf/ttfontdata')));

		return $otl->applyOTL($text, 0xFF);
	}

	public function testWordBoundariesAreMarkedWhenTheDictionaryIsInUse()
	{
		$this->assertSame(2, substr_count($this->applyOtl(self::THAI, true), self::ZWSP));
	}

	public function testAFullStopIsAWordBoundaryWhenTheDictionaryIsInUse()
	{
		$this->assertSame(2, substr_count($this->applyOtl(self::THAI_WITH_FULL_STOP, true), self::ZWSP));
	}

	/**
	 * Fonts such as TH Sarabun New have no glyph for U+200B, so a document that turns the dictionary
	 * off must not be given one. See mpdf/mpdf#1899 and mpdf/mpdf#1272.
	 */
	public function testNoWordBoundaryIsMarkedWhenTheDictionaryIsTurnedOff()
	{
		$this->assertSame(0, substr_count($this->applyOtl(self::THAI, false), self::ZWSP));
	}

	public function testAFullStopIsNoWordBoundaryWhenTheDictionaryIsTurnedOff()
	{
		$this->assertSame(0, substr_count($this->applyOtl(self::THAI_WITH_FULL_STOP, false), self::ZWSP));
	}

	/**
	 * The shaper still reorders and composes glyphs, but it must add nothing to the run
	 */
	public function testTurningTheDictionaryOffInsertsNothingAtAll()
	{
		$this->assertSame(
			mb_strlen(self::THAI_WITH_FULL_STOP),
			mb_strlen($this->applyOtl(self::THAI_WITH_FULL_STOP, false))
		);
	}

	/**
	 * @return string[] The text of each line as the drawing code received it
	 */
	private function draw($html, $config = [])
	{
		$mpdf = new TextRecordingMpdf(array_merge(['mode' => 'utf-8'], $config));
		$mpdf->WriteHTML($html);
		$mpdf->Output('', 'S');

		$drawn = $mpdf->drawnText;
		$mpdf->cleanup();

		return $drawn;
	}

	private function countMarkersDrawn($html, $config = [])
	{
		$markers = 0;
		foreach ($this->draw($html, $config) as $line) {
			$markers += substr_count($line, self::ZWSP);
		}

		return $markers;
	}

	/**
	 * The marker is a line-breaking instruction, not text. A font without a glyph for it draws a
	 * box, which is what mpdf/mpdf#1272 reported, so it must not reach the page whatever put it
	 * there - the dictionary at its default setting included.
	 */
	public function testTheDictionaryMarkerIsNotDrawn()
	{
		$html = '<p style="font-family: garuda">' . str_repeat(self::THAI_PHRASE, 6) . '</p>';

		$this->assertSame(0, $this->countMarkersDrawn($html));
	}

	public function testTheTibetanMarkerIsNotDrawn()
	{
		$html = '<p style="font-family: jomolhari">' . str_repeat(self::TIBETAN_PHRASE, 10) . '</p>';

		$this->assertSame(0, $this->countMarkersDrawn($html));
	}

	/**
	 * Authoring U+200B is what the settings documentation offers instead of the dictionary, so it
	 * draws a box in the same fonts and has to be discarded on the same terms
	 */
	public function testAMarkerWrittenByTheAuthorIsNotDrawn()
	{
		$html = '<p style="font-family: garuda">' . self::THAI . '&#x200b;' . self::THAI . '</p>';

		$this->assertSame(0, $this->countMarkersDrawn($html, ['useDictionaryLBR' => false]));
	}

	/**
	 * Discarding the marker before the line-breaking pass reads it would lose the boundaries
	 * altogether, and the two settings would then break in the same places
	 */
	public function testTheMarkerStillBreaksTheLineItWasInsertedInto()
	{
		$html = '<p style="font-family: garuda">' . str_repeat(self::THAI_PHRASE, 6) . '</p>';

		$this->assertNotSame($this->draw($html), $this->draw($html, ['useDictionaryLBR' => false]));
	}

}
