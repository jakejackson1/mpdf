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

	/**
	 * GDEF 1.3 carries MarkGlyphSetsDef exactly as 1.2 does - it only appends an ItemVarStore after it - so a
	 * lookup with UseMarkFilteringSet must shape rather than throw "which GDEF does not define"
	 */
	public function testShapesAFontWhoseMarkGlyphSetsLiveInAVersion13Gdef()
	{
		$mpdf = new Mpdf([
			'fontDir' => [__DIR__ . '/../data/ttf'],
			'fontdata' => ['gdef13subset' => [
				'R' => 'NotoSansMono-GDEF13-Subset.ttf',
				'useOTL' => 0xFF,
			]],
			'default_font' => 'gdef13subset',
		]);

		$mpdf->WriteHTML('<p>a&#x0301;e&#x0308;i&#x0300;o&#x0302;u&#x0307;</p>');

		$this->assertStringStartsWith('%PDF-', $mpdf->Output('', Destination::STRING_RETURN));
	}

}
