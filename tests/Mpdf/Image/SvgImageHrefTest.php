<?php

namespace Mpdf\Image;

use Mpdf\Mpdf;

class SvgImageHrefTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const PICTURE = 'img/bayeux2.jpg';

	private function svgImage($href)
	{
		return '<svg width="292" height="83" viewBox="0 0 292 83">'
			. '<image xlink:href="' . $href . '" width="292" height="83" />'
			. '</svg>';
	}

	private function render($html, array $imageVars = [])
	{
		$mpdf = new Mpdf(['mode' => 'c']);
		$mpdf->SetBasePath(__DIR__ . '/../../data/');
		$mpdf->compress = false;
		$mpdf->showImageErrors = true;

		foreach ($imageVars as $name => $data) {
			$mpdf->imageVars[$name] = $data;
		}

		$mpdf->WriteHTML($html);

		return $mpdf->Output('', 'S');
	}

	private function embeddedImages($pdf)
	{
		return substr_count($pdf, '/Subtype /Image');
	}

	/**
	 * Everywhere else that resolves an image path keeps the path as written in $orig_srcpath and
	 * resolves a copy, and getImage() looks the picture up under both. svgImage() had the test the
	 * other way round, so a file path went in with nothing recorded against it and missed the entry
	 * an img tag had already made. See mpdf/mpdf#1382.
	 */
	public function testAPictureReachedByBothAnImgAndAnSvgImageIsEmbeddedOnce()
	{
		$pdf = $this->render('<img src="' . self::PICTURE . '" width="100" />' . $this->svgImage(self::PICTURE));

		$this->assertSame(1, $this->embeddedImages($pdf));
	}

	public function testAPictureReachedBySeveralSvgImagesIsEmbeddedOnce()
	{
		$pdf = $this->render(str_repeat($this->svgImage(self::PICTURE), 3));

		$this->assertSame(1, $this->embeddedImages($pdf));
	}

	public function testAnImageVariableIsStillDrawn()
	{
		$pdf = $this->render(
			$this->svgImage('var:picture'),
			['picture' => file_get_contents(__DIR__ . '/../../data/' . self::PICTURE)]
		);

		$this->assertSame(1, $this->embeddedImages($pdf));
	}

	public function testAPathRelativeToTheBasePathIsStillDrawn()
	{
		$this->assertSame(1, $this->embeddedImages($this->render($this->svgImage(self::PICTURE))));
	}

	public function testAnAbsolutePathIsStillDrawn()
	{
		$href = __DIR__ . '/../../data/' . self::PICTURE;

		$this->assertSame(1, $this->embeddedImages($this->render($this->svgImage($href))));
	}

	public function testAnImageVariableThatIsNotSetStillRaises()
	{
		$this->expectException(\Mpdf\MpdfImageException::class);
		$this->expectExceptionMessage('Unknown image variable');

		$this->render($this->svgImage('var:nothing'));
	}

	public function testAFileThatIsNotThereStillRaises()
	{
		$this->expectException(\Mpdf\MpdfImageException::class);
		$this->expectExceptionMessage('Could not find image file');

		$this->render($this->svgImage('img/not-a-file.jpg'));
	}

}
