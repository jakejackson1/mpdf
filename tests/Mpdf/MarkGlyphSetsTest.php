<?php

namespace Mpdf;

use Mpdf\Output\Destination;

class MarkGlyphSetsTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * A GSUB lookup carrying UseMarkFilteringSet must shape rather than throw
	 */
	public function testShapesAFontWithMarkGlyphSets()
	{
		$mpdf = new Mpdf([
			'fontDir' => [__DIR__ . '/../data/ttf'],
			'fontdata' => ['sinhalasubset' => [
				'R' => 'NotoSansSinhala-Subset.ttf',
				'useOTL' => 0xFF,
			]],
			'default_font' => 'sinhalasubset',
		]);

		$mpdf->WriteHTML('<p>සිංහල අකුරු ශ්‍රී ලංකා</p>');

		$this->assertStringStartsWith('%PDF-', $mpdf->Output('', Destination::STRING_RETURN));
	}

}
