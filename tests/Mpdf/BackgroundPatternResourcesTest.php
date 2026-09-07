<?php

namespace Mpdf;

class BackgroundPatternResourcesTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const SVG = __DIR__ . '/../data/img/pattern-with-text.svg';

	/**
	 * The page draws no text of its own, so its core font is registered but never marked used
	 * and carries no 'used' key at all. Reading that key before checking 'fo' is a warning,
	 * which PHPUnit turns into a test error.
	 */
	public function testPatternIsWrittenWithAnUnusedCoreFont()
	{
		$output = $this->render();

		$this->assertStringContainsString(
			'/PatternType 1',
			$output,
			'Expected the repeating SVG background to be written as a tiling pattern'
		);
	}

	/**
	 * The pattern's resource dictionary still has to name the font its Form XObject draws with.
	 */
	public function testPatternResourcesNameTheSvgFont()
	{
		$output = $this->render();

		$this->assertSame(1, preg_match('#/PatternType 1.*?/Resources (\d+) 0 R#s', $output, $pattern));

		$this->assertMatchesRegularExpression(
			'#/Font\s*<<\s*/F\d+ [1-9]\d* 0 R#',
			$this->object($output, $pattern[1])
		);
	}

	/**
	 * @param  string $pdf
	 * @param  string $number
	 * @return string
	 */
	private function object($pdf, $number)
	{
		preg_match('/(?:^|\s)' . $number . ' 0 obj\s*(.*?)endobj/s', $pdf, $matches);

		return isset($matches[1]) ? $matches[1] : '';
	}

	/**
	 * @return string
	 */
	private function render()
	{
		$mpdf = new Mpdf(['mode' => 'c']);
		$mpdf->compress = false;

		$mpdf->WriteHTML(
			'<div style="background-image: url(\'' . self::SVG . '\');'
			. ' background-repeat: repeat; height: 40mm"></div>'
		);

		$output = $mpdf->OutputBinaryData();
		$mpdf->cleanup();

		return $output;
	}

}
