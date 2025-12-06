<?php

namespace Mpdf\Css;

use Mpdf\Color\ColorConverter;
use Mpdf\Mpdf;
use Mpdf\SizeConverter;

class ShadowParserTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @var \Mpdf\Css\ShadowParser
	 */
	private $shadowParser;

	private $mpdf;
	private $sizeConverter;
	private $colorConverter;

	protected function setUp(): void
	{
		$this->mpdf = $this->getMockBuilder(Mpdf::class)
			->disableOriginalConstructor()
			->getMock();

		$this->mpdf->FontSize = 12;
		$this->mpdf->PDFAXwarnings = [];
		$this->mpdf->blklvl = 1;
		$this->mpdf->blk = [['inner_width' => 500]];

		$this->sizeConverter = $this->getMockBuilder(SizeConverter::class)
			->disableOriginalConstructor()
			->getMock();

		$this->sizeConverter->method('convert')
			->willReturnCallback(function ($val) {
				return $val;
			});

		$this->colorConverter = $this->getMockBuilder(ColorConverter::class)
			->disableOriginalConstructor()
			->getMock();

		$this->colorConverter->method('convert')
			->willReturnCallback(function ($val) {
				return $val;
			});

		$this->shadowParser = new ShadowParser($this->mpdf, $this->sizeConverter, $this->colorConverter);
	}

	public function testNormalizeShadowColors()
	{
		$input = '1px 1px 1px rgba(0, 0, 0, 0.5), 2px 2px #fff';
		$expected = '1px 1px 1px rgba(0*0*0*0.5), 2px 2px #fff';
		$this->assertEquals($expected, $this->shadowParser->normalizeShadowColors($input));
	}

	public function testParseBoxShadow()
	{
		$input = '10px 10px 5px #888888';
		$res = $this->shadowParser->parseBoxShadow($input);

		$this->assertIsArray($res);
		$this->assertCount(1, $res);
		$this->assertEquals('10px', $res[0]['x']);
		$this->assertEquals('10px', $res[0]['y']);
		$this->assertEquals('5px', $res[0]['blur']);
		$this->assertEquals('#888888', $res[0]['col']);
	}

	public function testParseBoxShadowInset()
	{
		$input = 'inset 5px 5px 5px #000';
		$res = $this->shadowParser->parseBoxShadow($input);

		$this->assertTrue($res[0]['inset']);
		$this->assertEquals('5px', $res[0]['x']);
	}

	public function testParseTextShadow()
	{
		$input = '2px 2px #ff0000';
		$res = $this->shadowParser->parseTextShadow($input);

		$this->assertIsArray($res);
		$this->assertEquals('2px', $res[0]['x']);
		$this->assertEquals('2px', $res[0]['y']);
		$this->assertEquals('#ff0000', $res[0]['col']);
	}

	public function testParseMultipleShadows()
	{
		$input = '1px 1px #000, 2px 2px #fff';
		$res = $this->shadowParser->parseBoxShadow($input);

		$this->assertCount(2, $res);
		// Note: parseBoxShadow unshifts, so order might be reversed in the array result
		// Logic: array_unshift($sh, $boxShadow).
		// explode returns [shadow1, shadow2].
		// process shadow1 -> unshift -> [shadow1]
		// process shadow2 -> unshift -> [shadow2, shadow1]
		
		$this->assertEquals('2px', $res[0]['x']);
		$this->assertEquals('1px', $res[1]['x']);
	}
}
