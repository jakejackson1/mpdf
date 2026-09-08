<?php

namespace Mpdf\Image;

use Mpdf\Mpdf;

class ExifOrientationTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	use BuildsImageFixtures;

	/**
	 * The colours of the fixture the right way up, clockwise from the top left
	 */
	private static $canonical = [[255, 0, 0], [0, 176, 0], [255, 224, 0], [0, 0, 255]];

	/**
	 * The correction is off by default, so every test that expects one turns it on
	 */
	private static $on = ['useImageExifOrientation' => true];

	/**
	 * The middle of each quadrant, clockwise from the top left, as a fraction of the width and height
	 */
	private static $quadrants = [[0.25, 0.25], [0.75, 0.25], [0.75, 0.75], [0.25, 0.75]];

	private $mpdf;

	protected function tear_down()
	{
		parent::tear_down();

		if ($this->mpdf) {
			$this->mpdf->cleanup();
			$this->mpdf = null;
		}
	}

	public function orientationProvider()
	{
		return [[1], [2], [3], [4], [5], [6], [7], [8]];
	}

	/**
	 * Each fixture holds the same picture stored a different way up, tagged with the orientation that
	 * says so. Half are little-endian Exif and half big-endian, so the set proves both are read.
	 *
	 * @dataProvider orientationProvider
	 */
	public function testEveryOrientationIsShownTheSameWayUp($orientation)
	{
		$image = $this->render($this->fixture('exif-orientation-' . $orientation . '.jpg'), self::$on);

		$this->assertSame(40, $image['w'], 'Corrected width');
		$this->assertSame(20, $image['h'], 'Corrected height');
		$this->assertCorners(self::$canonical, $image['data']);
	}

	public function testAnImageWithNoExifAtAllIsPassedThroughUntouched()
	{
		$file = $this->fixture('exif-orientation-none.jpg');
		$image = $this->render($file, self::$on);

		$this->assertSame(file_get_contents($file), $image['data']);
	}

	public function testAnImageAlreadyTheRightWayUpIsNotReEncoded()
	{
		$file = $this->fixture('exif-orientation-1.jpg');
		$image = $this->render($file, self::$on);

		$this->assertSame(file_get_contents($file), $image['data']);
	}

	public function testAnImageIsUsedAsStoredUntilTheSettingIsTurnedOn()
	{
		$file = $this->fixture('exif-orientation-6.jpg');
		$image = $this->render($file);

		$this->assertSame(20, $image['w']);
		$this->assertSame(40, $image['h']);
		$this->assertSame(file_get_contents($file), $image['data']);
	}

	public function testAColourProfileSurvivesTheRotation()
	{
		$image = $this->render($this->fixture('exif-orientation-6-icc.jpg'), self::$on);

		$this->assertSame(40, $image['w']);
		$this->assertNotFalse($image['icc']);
		$this->assertSame('acsp', substr($image['icc'], 36, 4));
		$this->assertSame(132, strlen($image['icc']));
	}

	public function testTheReEncodeHonoursTheConfiguredJpegQuality()
	{
		$file = $this->fixture('exif-orientation-6.jpg');

		$low = $this->render($file, self::$on + ['imageJpegQuality' => 20]);
		$high = $this->render($file, self::$on + ['imageJpegQuality' => 100]);

		$this->assertLessThan(strlen($high['data']), strlen($low['data']));
	}

	public function reEncodePathProvider()
	{
		return [
			'a quarter turn, which GD does into a copy' => [6],
			'a half turn, which GD does in place' => [3],
		];
	}

	/**
	 * GD writes a JFIF segment of its own, at its default of 96 dpi, in place of the one it read
	 *
	 * @dataProvider reEncodePathProvider
	 */
	public function testTheDensityOfTheOriginalSurvivesTheReEncode($orientation)
	{
		$data = $this->withJfifDensity(file_get_contents($this->fixture('exif-orientation-' . $orientation . '.jpg')), 300);
		$image = $this->renderData($data, self::$on);

		$this->assertSame(40, $image['w'], 'Corrected');
		$this->assertSame(300, $image['set-dpi'], 'Density read off the original');

		if (function_exists('imageresolution')) { // PHP 7.2
			$this->assertSame(300, $this->jfifDensity($image['data']), 'Density written into the re-encoded segment');
		}
	}

	/**
	 * GD decodes everything but CMYK to RGB and cannot write a one-component JPEG, so a corrected greyscale
	 * image is embedded as the samples GD decoded, deflated, rather than as a JPEG with three channels.
	 * The fixture is dark down its left side as stored, which a quarter turn clockwise puts at the top
	 */
	public function testAGreyscaleImageIsCorrectedAndStaysGreyscale()
	{
		$data = $this->withJfifDensity(file_get_contents($this->fixture('exif-orientation-6-gray.jpg')), 300);

		$asStored = $this->renderData($data);
		$corrected = $this->renderData($data, self::$on);

		$this->assertSame('DeviceGray', $asStored['cs']);
		$this->assertSame('DCTDecode', $asStored['f']);

		$this->assertSame(40, $corrected['w']);
		$this->assertSame(20, $corrected['h']);
		$this->assertSame('DeviceGray', $corrected['cs']);
		$this->assertSame('FlateDecode', $corrected['f']);
		$this->assertSame(300, $corrected['set-dpi'], 'Density read off the original');
		$this->assertGreyCorners([61, 61, 180, 180], gzuncompress($corrected['data']), 40, 20);
	}

	/**
	 * PHP 8 refuses a quality outside -1 to 100 with an exception, not a warning, where PHP 7 let libgd
	 * clamp it. Clamping first keeps the two the same, and keeps the output buffer the re-encode is
	 * written into from being left open
	 */
	public function testAQualityOutsideGdsRangeIsClampedRatherThanRefused()
	{
		$file = $this->fixture('exif-orientation-6.jpg');
		$level = ob_get_level();

		$over = $this->render($file, self::$on + ['imageJpegQuality' => 150]);
		$under = $this->render($file, self::$on + ['imageJpegQuality' => -5]);

		$this->assertSame(40, $over['w']);
		$this->assertSame(40, $under['w']);
		$this->assertLessThan(strlen($over['data']), strlen($under['data']), '150 is written at 100, and -5 at GD\'s default');
		$this->assertSame($level, ob_get_level());
	}

	/**
	 * The dimensions GD would allocate for come off the frame header, so a few bytes can claim an image
	 * that takes gigabytes to decode. One that would not fit is left the way it is stored
	 */
	public function testAnImageTooBigForMemoryLimitIsLeftAsStored()
	{
		$data = $this->withFrameDimensions(file_get_contents($this->fixture('exif-orientation-6.jpg')), 60000, 60000);

		$limit = ini_get('memory_limit');
		ini_set('memory_limit', '1G'); // Well above what the suite uses, well below the 14 GB a copy of the image would take

		try {
			$image = $this->renderData($data, self::$on);
		} finally {
			ini_set('memory_limit', $limit);
		}

		$this->assertSame(60000, $image['w'], 'Read as stored');
		$this->assertSame($data, $image['data']);
	}

	/**
	 * The samples in this fixture are stored portrait, and the orientation tag is what makes it landscape
	 */
	public function testTheBoxTheImageIsDrawnInTurnsWithIt()
	{
		$corrected = $this->drawnBox(self::$on);
		$asStored = $this->drawnBox();

		$this->assertGreaterThan($corrected[1], $corrected[0], 'Drawn landscape once the orientation is read');
		$this->assertGreaterThan($asStored[0], $asStored[1], 'Drawn portrait when it is not');
	}

	/**
	 * The width and height, in points, of the box the image is scaled into
	 */
	private function drawnBox(array $config = [])
	{
		$this->mpdf = new Mpdf($config + ['mode' => 'c']);
		$this->mpdf->compress = false;
		$this->mpdf->WriteHTML('<img src="' . $this->fixture('exif-orientation-6.jpg') . '" style="width: 40mm">');

		$pdf = $this->mpdf->Output('', 'S');

		$this->assertSame(1, preg_match('/q ([\d.]+) 0 0 ([\d.]+) [\d.]+ [\d.]+ cm \/I\d+ Do Q/', $pdf, $m));

		return [(float) $m[1], (float) $m[2]];
	}

	private function fixture($name)
	{
		return __DIR__ . '/../../data/img/' . $name;
	}

	private function render($file, array $config = [])
	{
		$this->mpdf = new Mpdf($config + ['mode' => 'c']);
		$this->mpdf->WriteHTML('<img src="' . $file . '">');

		return reset($this->mpdf->images);
	}

	private function renderData($data, array $config = [])
	{
		return $this->render('data:image/jpeg;base64,' . base64_encode($data), $config);
	}

	/**
	 * Put a JFIF segment (ITU-T T.871 6.3) giving this density ahead of any the image carries, which is
	 * where mPDF reads the first one it finds
	 */
	private function withJfifDensity($data, $dpi)
	{
		$app0 = "JFIF\0" . "\x01\x01" . "\x01" . pack('n', $dpi) . pack('n', $dpi) . "\x00\x00"; // Version 1.1, units 1 (dpi), no thumbnail

		return $this->afterSoi($data, "\xFF\xE0" . pack('n', strlen($app0) + 2) . $app0);
	}

	/**
	 * The density, in dots per inch, of the first JFIF segment in a JPEG
	 */
	private function jfifDensity($data)
	{
		$this->assertSame(1, preg_match('/\xFF\xE0..JFIF\0..(.)(..)/s', $data, $m), 'Has a JFIF segment');
		$this->assertSame(1, ord($m[1]), 'In dots per inch');

		return unpack('n', $m[2])[1];
	}

	/**
	 * Read the four quadrants of a JPEG, clockwise from the top left
	 */
	private function assertCorners(array $expected, $data)
	{
		$image = imagecreatefromstring($data);
		$width = imagesx($image);
		$height = imagesy($image);

		foreach (self::$quadrants as $i => $point) {

			$rgb = imagecolorsforindex($image, imagecolorat($image, (int) ($width * $point[0]), (int) ($height * $point[1])));
			$actual = [$rgb['red'], $rgb['green'], $rgb['blue']];

			foreach ($expected[$i] as $channel => $value) {
				// JPEG is lossy, and 95 leaves ringing well inside a quadrant that is a solid colour
				$this->assertEqualsWithDelta($value, $actual[$channel], 32, sprintf('Quadrant %d, channel %d, got %s', $i, $channel, implode(',', $actual)));
			}
		}
	}

	/**
	 * Assert the four quadrants of a stream of 8-bit grey samples, clockwise from the top left
	 */
	private function assertGreyCorners(array $expected, $samples, $width, $height)
	{
		$this->assertSame($width * $height, strlen($samples), 'One byte a pixel');

		foreach (self::$quadrants as $i => $point) {
			$actual = ord($samples[(int) ($height * $point[1]) * $width + (int) ($width * $point[0])]);
			$this->assertEqualsWithDelta($expected[$i], $actual, 8, sprintf('Quadrant %d, got %d', $i, $actual));
		}
	}

}
