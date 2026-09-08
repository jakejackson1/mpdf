<?php

namespace Mpdf;

class ZeroFontSizeTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const ARABIC = 'مرحبا بالعالم';

	private function contentStream($html)
	{
		$mpdf = new Mpdf(['mode' => 'utf-8']);
		$mpdf->compress = false;
		$mpdf->WriteHTML($html);
		$pdf = $mpdf->Output('', 'S');

		$start = strpos($pdf, 'stream');
		$end = strpos($pdf, 'endstream');

		return substr($pdf, $start + 6, $end - $start - 6);
	}

	/**
	 * Character and word spacing are written to the content stream as a per mille of the font size,
	 * so a font size of zero used to end the document with a division by zero on PHP 8. See
	 * mpdf/mpdf#1888.
	 */
	public function testShapedTextAtAZeroFontSizeIsWritten()
	{
		$stream = $this->contentStream('<p style="font-family: dejavusans; font-size: 0">' . self::ARABIC . '</p>');

		$this->assertStringContainsString('0.000 Tf', $stream);
		$this->assertStringContainsString('TJ', $stream);
	}

	/**
	 * The same division sets the inter-word adjustment of justified text that is spaced out
	 */
	public function testJustifiedSpacedTextAtAZeroFontSizeIsWritten()
	{
		$stream = $this->contentStream(
			'<p style="font-family: dejavusans; font-size: 0; text-align: justify; letter-spacing: 1px">'
			. str_repeat('hello world ', 30)
			. '</p>'
		);

		$this->assertStringContainsString('0.000 Tf', $stream);
		$this->assertStringContainsString(') 0(', $stream);
	}

	public function testAZeroFontSizeWritesNoInfinityIntoTheStream()
	{
		$stream = $this->contentStream('<p style="font-family: dejavusans; font-size: 0">' . self::ARABIC . '</p>');

		$this->assertStringNotContainsString('INF', $stream);
		$this->assertStringNotContainsString('NAN', $stream);
	}

	public function testShapedTextAtANormalFontSizeIsStillWritten()
	{
		$stream = $this->contentStream(
			'<p style="font-family: dejavusans; font-size: 12pt; letter-spacing: 1px">' . self::ARABIC . '</p>'
		);

		$this->assertStringContainsString('12.000 Tf', $stream);
		$this->assertStringContainsString('TJ', $stream);
	}

}
