<?php

namespace Mpdf\Image;

use Mpdf\Mpdf;

class PngTransparencyTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * Diagnostics PHP raised from inside ImageProcessor while the document was written
	 *
	 * @var string[]
	 */
	private $raised;

	protected function set_up()
	{
		parent::set_up();

		$this->raised = [];

		set_error_handler(function ($no, $message, $file) {
			if (false !== strpos($file, 'ImageProcessor.php')) {
				$this->raised[] = $message;
			}

			return true;
		});
	}

	protected function tear_down()
	{
		restore_error_handler();

		parent::tear_down();
	}

	private function render($file)
	{
		$mpdf = new Mpdf();
		$mpdf->WriteHTML('<img src="' . __DIR__ . '/../../data/img/' . $file . '" />');

		return $mpdf->Output('', 'S');
	}

	/**
	 * The alpha values the loop wrote, read back off the page
	 *
	 * The soft mask is an 8-bit greyscale image, and its stream is the IDAT of the PNG that GD wrote
	 * for it. Wrapping that back up as a PNG file hands the inflating and unfiltering to GD.
	 *
	 * @param string $pdf
	 *
	 * @return int[] One alpha per pixel, left to right and top to bottom
	 */
	private function maskOf($pdf)
	{
		if (!preg_match('~/SMask (\\d+) 0 R~', $pdf, $reference)) {
			$this->fail('the image carries no soft mask');
		}

		$pattern = '~(?:^|\\n)' . $reference[1] . ' 0 obj(.*?)stream\\r?\\n~s';
		if (!preg_match($pattern, $pdf, $mask, PREG_OFFSET_CAPTURE)) {
			$this->fail('the soft mask object named by /SMask is not in the document');
		}

		preg_match('~/Width (\\d+)~', $mask[1][0], $width);
		preg_match('~/Height (\\d+)~', $mask[1][0], $height);
		preg_match('~/Length (\\d+)~', $mask[1][0], $length);

		$idat = substr($pdf, $mask[0][1] + strlen($mask[0][0]), (int) $length[1]);

		$chunk = function ($type, $data) {
			return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
		};

		$png = "\x89PNG\r\n\x1a\n"
			. $chunk('IHDR', pack('NNCCCCC', $width[1], $height[1], 8, 0, 0, 0, 0))
			. $chunk('IDAT', $idat)
			. $chunk('IEND', '');

		$image = imagecreatefromstring($png);

		$alpha = [];
		for ($y = 0; $y < $height[1]; $y++) {
			for ($x = 0; $x < $width[1]; $x++) {
				$alpha[] = imagecolorat($image, $x, $y) & 0xFF;
			}
		}

		imagedestroy($image);

		return $alpha;
	}

	/**
	 * The left half of each fixture is the colour its tRNS chunk names, so the mask has to be
	 * transparent there and opaque over the right half
	 *
	 * @return int[]
	 */
	private function expectedMask()
	{
		return array_merge(...array_fill(0, 8, array_merge(array_fill(0, 4, 0), array_fill(0, 4, 255))));
	}

	/**
	 * A greyscale tRNS chunk holds one sample, so the three-sample truecolour comparison must not be
	 * reached for it. See mpdf/mpdf#1927.
	 */
	public function testAGreyscaleImageDoesNotReadTheSamplesItHasNot()
	{
		$pdf = $this->render('greyscale-trns.png');

		$this->assertSame([], $this->raised);
		$this->assertSame($this->expectedMask(), $this->maskOf($pdf), 'the image lost its transparency');
	}

	public function testATruecolourImageStillMasksItsTransparentColour()
	{
		$pdf = $this->render('truecolour-trns.png');

		$this->assertSame([], $this->raised);
		$this->assertSame($this->expectedMask(), $this->maskOf($pdf), 'the image lost its transparency');
	}

}
