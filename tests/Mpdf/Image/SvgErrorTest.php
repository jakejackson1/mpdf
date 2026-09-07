<?php

namespace Mpdf\Image;

use Mpdf\Mpdf;

class SvgErrorTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const NAMESPACE_ATTRIBUTE = 'xmlns="http://www.w3.org/2000/svg"';

	/**
	 * An attribute value with no quotes around it. Well formed in HTML, not in XML, and the parser
	 * gives up on the <svg> element it is written on.
	 */
	private function unreadableSvg()
	{
		return '<svg width="40mm" height="20mm" class=narrow ' . self::NAMESPACE_ATTRIBUTE . '>'
			. '<circle cx="20" cy="20" r="15" fill="red" /></svg>';
	}

	private function render($svg, $mpdf = null)
	{
		$mpdf = $mpdf ?: new Mpdf(['mode' => 'c']);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<p>Before the picture</p>' . $svg . '<p>After the picture</p>');

		return $mpdf->Output('', 'S');
	}

	/**
	 * An SVG is drawn as a form XObject
	 */
	private function assertPictureDrawn($pdf)
	{
		$this->assertStringContainsString('/Subtype /Form', $pdf);
	}

	/**
	 * The <svg> element carries the picture's dimensions. When the parser could not read as far as
	 * it, nothing set them, and mPDF went on to divide by a width of zero on the way to the page.
	 */
	public function testAnSvgTheParserCannotReadDoesNotBringTheDocumentDown()
	{
		$pdf = $this->render($this->unreadableSvg());

		$this->assertStringContainsString('%PDF-', $pdf);
		$this->assertStringContainsString('Before the picture', $pdf);
		$this->assertStringContainsString('After the picture', $pdf);
	}

	public function testItRaisesNothingOfItsOwn()
	{
		$raised = [];
		set_error_handler(function ($no, $message) use (&$raised) {
			$raised[] = $message;

			return true;
		});

		try {
			$this->render($this->unreadableSvg());
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $raised);
	}

	public function testItIsReportedAsAnImageErrorLikeAnyOtherPictureThatCannotBeRead()
	{
		$mpdf = new Mpdf(['mode' => 'c']);
		$mpdf->showImageErrors = true;

		$this->expectException(\Mpdf\MpdfImageException::class);
		$this->expectExceptionMessage('Error parsing SVG file');

		$this->render($this->unreadableSvg(), $mpdf);
	}

	public function testAWellFormedSvgIsStillDrawn()
	{
		$svg = '<svg width="40mm" height="20mm" ' . self::NAMESPACE_ATTRIBUTE . '>'
			. '<circle cx="20" cy="20" r="15" fill="red" /></svg>';

		$this->assertPictureDrawn($this->render($svg));
	}

	/**
	 * The parser is fed the document in one piece and is not told it is the last, so it never comes
	 * to complain about the element that was left open - and the picture is drawn as it always was
	 */
	public function testAnSvgWithAnElementLeftOpenIsStillDrawn()
	{
		$svg = '<svg width="40mm" height="20mm" ' . self::NAMESPACE_ATTRIBUTE . '>'
			. '<circle cx="20" cy="20" r="15" fill="red"></svg>';

		$this->assertPictureDrawn($this->render($svg));
	}

	public function testAnSvgWithNoWidthOrHeightIsStillDrawn()
	{
		$svg = '<svg ' . self::NAMESPACE_ATTRIBUTE . '><circle cx="20" cy="20" r="15" fill="red" /></svg>';

		$this->assertPictureDrawn($this->render($svg));
	}

	public function testAnSvgWhoseViewBoxIsAllZeroesIsStillDrawn()
	{
		$svg = '<svg viewBox="0 0 0 0" ' . self::NAMESPACE_ATTRIBUTE . '>'
			. '<circle cx="20" cy="20" r="15" fill="red" /></svg>';

		$this->assertPictureDrawn($this->render($svg));
	}

	public function testAnSvgWithNothingToDrawInItIsStillNotAnError()
	{
		$mpdf = new Mpdf(['mode' => 'c']);
		$mpdf->showImageErrors = true;

		$pdf = $this->render('<svg width="40mm" height="20mm" ' . self::NAMESPACE_ATTRIBUTE . '></svg>', $mpdf);

		$this->assertPictureDrawn($pdf);
	}

}
