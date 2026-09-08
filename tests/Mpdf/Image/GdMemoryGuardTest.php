<?php

namespace Mpdf\Image;

use Mpdf\MpdfImageException;

/**
 * Every path that hands GD a file refuses one whose header claims more than memory_limit leaves,
 * reporting it the way it reports any other image GD could not read. Each fixture is a real image whose
 * header has been rewritten to claim 60000 by 60000: 14 GB as a truecolor GD image, or 3.6 GB as the
 * palette image a GIF decodes to, against the 1 GB the tests allow themselves
 */
class GdMemoryGuardTest extends \Mpdf\BaseMpdfTest
{

	use BuildsImageFixtures;

	private $limit;

	protected function set_up()
	{
		parent::set_up();

		$this->limit = ini_get('memory_limit');
		ini_set('memory_limit', '1G');
		$this->mpdf->showImageErrors = true;
	}

	protected function tear_down()
	{
		ini_set('memory_limit', $this->limit);

		parent::tear_down();
	}

	/**
	 * A PNG with an alpha channel goes through GD for every document, with no setting to turn on
	 */
	public function testAPngIsRefusedBeforeItsAlphaChannelIsRead()
	{
		$png = $this->fromGd('imagepng', true);
		$png = substr_replace($png, pack('NN', 60000, 60000), 16, 8); // IHDR, PNG 11.2.2

		$this->expectException(MpdfImageException::class);
		$this->expectExceptionMessage('Error creating GD image from PNG file');

		$this->render($png, 'image/png');
	}

	public function testAGifIsRefused()
	{
		$gif = $this->fromGd('imagegif');
		$gif = substr_replace($gif, pack('vv', 60000, 60000), 6, 4); // Logical screen, GIF89a 18

		$this->expectException(MpdfImageException::class);
		$this->expectExceptionMessage('Error creating GD image file from GIF image');

		$this->render($gif, 'image/gif');
	}

	/**
	 * A WebP frame header holds 14 bits a dimension, so the most it can claim is 16383 by 16383: a GB and
	 * a bit, which is over what the limit leaves once the test itself is loaded
	 */
	public function testAWebpIsRefused()
	{
		$webp = file_get_contents(__DIR__ . '/../../data/img/tiger.webp');
		$webp = substr_replace($webp, pack('vv', 16383, 16383), 26, 4); // VP8 frame header, RFC 6386 9.1

		$this->expectException(MpdfImageException::class);
		$this->expectExceptionMessage('Error creating GD image from WEBP image');

		$this->render($webp, 'image/webp');
	}

	/**
	 * A JPEG is embedded as it is unless a colour space restriction sends it through GD to be rewritten
	 */
	public function testAJpegIsRefusedBeforeItIsConvertedToGreyscale()
	{
		$jpg = $this->withFrameDimensions($this->fromGd('imagejpeg'), 60000, 60000);

		$this->mpdf->restrictColorSpace = 1;

		$this->expectException(MpdfImageException::class);
		$this->expectExceptionMessage('Error parsing or converting JPG image');

		$this->render($jpg, 'image/jpeg');
	}

	/**
	 * Anything mPDF has no reader of its own for is handed to GD to identify: a WBMP here, which is two zero
	 * bytes and then each dimension as a multi-byte integer, seven bits at a time, WAP-190 6.1
	 */
	public function testAnImageOfAnUnknownTypeIsRefused()
	{
		$wbmp = "\0\0" . "\x83\xD4\x60" . "\x83\xD4\x60" . str_repeat("\xFF", 8); // 60000 is 3 << 14 | 84 << 7 | 96

		$this->expectException(MpdfImageException::class);
		$this->expectExceptionMessage('Error parsing image file - image type not recognised');

		$this->render($wbmp, 'image/vnd.wap.wbmp');
	}

	/**
	 * The same image at the size it really is goes through, so the refusals above are the guard and not the
	 * rewritten headers breaking the files
	 */
	public function testAnImageThatFitsIsRead()
	{
		$this->render($this->fromGd('imagepng', true), 'image/png');
		$this->render($this->fromGd('imagegif'), 'image/gif');

		$this->assertCount(3, $this->mpdf->images, 'The PNG, the soft mask made from its alpha channel, and the GIF');
	}

	/**
	 * A 4 by 4 image in whichever format GD writes with this function
	 */
	private function fromGd($writer, $alpha = false)
	{
		$image = imagecreatetruecolor(4, 4);

		if ($alpha) {
			imagesavealpha($image, true);
			imagefill($image, 0, 0, imagecolorallocatealpha($image, 200, 30, 30, 64));
		}

		ob_start();
		$writer($image);

		return ob_get_clean();
	}

	private function render($data, $mime)
	{
		$this->mpdf->WriteHTML('<img src="data:' . $mime . ';base64,' . base64_encode($data) . '">');
	}

}
