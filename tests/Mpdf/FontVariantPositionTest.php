<?php

namespace Mpdf;

class FontVariantPositionTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * FreeSerif carries the sups and subs GSUB features, so each keyword has to reach the text
	 * as a different glyph. Rendered in one document the subset ids are comparable.
	 */
	public function testKeywordsSelectDistinctGlyphs()
	{
		$mpdf = new Mpdf(['default_font' => 'freeserif']);
		$mpdf->compress = false;

		$mpdf->WriteHTML(
			'<span style="font-variant-position: super">1</span>'
			. '<span style="font-variant-position: sub">1</span>'
			. '<span style="font-variant-position: normal">1</span>'
		);

		$output = $mpdf->OutputBinaryData();
		$mpdf->cleanup();

		preg_match_all('/<([0-9a-fA-F]+)>\s*Tj/', $output, $matches);

		$this->assertCount(3, $matches[1], 'Expected one text-showing operator per span');

		list($super, $sub, $normal) = $matches[1];

		$this->assertSame('31', $normal, 'font-variant-position: normal must leave the digit alone');
		$this->assertNotSame($normal, $super, 'font-variant-position: super must substitute the glyph');
		$this->assertNotSame($normal, $sub, 'font-variant-position: sub must substitute the glyph');
		$this->assertNotSame($super, $sub, 'sups and subs must not resolve to the same glyph');
	}

}
