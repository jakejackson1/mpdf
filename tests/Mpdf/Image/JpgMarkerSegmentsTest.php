<?php

namespace Mpdf\Image;

/**
 * A JPEG's metadata lives in its marker segments. Searching the whole file for the string that
 * introduces one finds it just as readily in a comment, or among the compressed samples.
 */
class JpgMarkerSegmentsTest extends \Mpdf\BaseMpdfTest
{

	use BuildsImageFixtures;

	public function testTheDensityComesFromTheJfifSegmentAndNotACommentThatSaysJfif()
	{
		// Version 1.2, units 3, and a density of 100 - none of which this image is
		$image = $this->withComment("JFIF\0\x01\x02\x03\x00\x64\x00\x64");

		$this->assertSame(300, $image['set-dpi']);
	}

	public function testAProfileComesFromTheApp2SegmentsAndNotACommentThatSaysIccProfile()
	{
		$image = $this->withComment("ICC_PROFILE\0\x01\x01" . $this->iccProfile(300));

		$this->assertFalse($image['icc']);
	}

	/**
	 * A profile too big for one segment is split across several, each numbered, and the numbering is
	 * what puts it back together - the segments themselves need not be in order
	 */
	public function testASplitProfileIsReassembledInSequenceOrder()
	{
		$profile = $this->iccProfile(150000);
		$chunks = str_split($profile, 60000);
		$segments = '';

		foreach (array_reverse($chunks, true) as $i => $chunk) {
			$payload = "ICC_PROFILE\0" . chr($i + 1) . chr(count($chunks)) . $chunk;
			$segments .= "\xFF\xE2" . pack('n', strlen($payload) + 2) . $payload;
		}

		$image = $this->render($this->splice($segments));

		$this->assertSame($profile, $image['icc']);
	}

	public function testTheDimensionsStillComeOffTheFrameHeader()
	{
		$image = $this->render(file_get_contents($this->source()));

		$this->assertSame(292, $image['w']);
		$this->assertSame(83, $image['h']);
	}

	/**
	 * T.871 makes the segment 16 bytes plus a thumbnail. Seven bytes is the size and the identifier and
	 * nothing after; read on past its end and the size field of the segment behind it passes for units
	 * of 0, which would take away the 300 dpi this image really has
	 */
	public function testAJfifSegmentTooShortToHoldADensityIsSkipped()
	{
		$image = $this->render($this->splice("\xFF\xE0" . pack('n', 7) . "JFIF\0"));

		$this->assertSame(300, $image['set-dpi']);
	}

	/**
	 * T.81 B.2.2 makes a frame header 8 bytes plus 3 a component; one cut short has no dimensions to read
	 */
	public function testAFrameHeaderTooShortToHoldTheDimensionsIsAnError()
	{
		$data = file_get_contents($this->source());
		$sof = strpos($data, "\xFF\xC0");
		$data = substr_replace($data, pack('n', 4), $sof + 2, 2);

		$this->mpdf->showImageErrors = true;

		$this->expectException(\Mpdf\MpdfImageException::class);
		$this->expectExceptionMessage('Error parsing JPG header');

		$this->render($data);
	}

	private function source()
	{
		return __DIR__ . '/../../data/img/bayeux2.jpg';
	}

	private function withComment($text)
	{
		return $this->render($this->splice("\xFF\xFE" . pack('n', strlen($text) + 2) . $text));
	}

	private function splice($segments)
	{
		return $this->afterSoi(file_get_contents($this->source()), $segments);
	}

	private function render($data)
	{
		$this->mpdf->WriteHTML('<img src="data:image/jpeg;base64,' . base64_encode($data) . '">');

		return reset($this->mpdf->images);
	}

}
