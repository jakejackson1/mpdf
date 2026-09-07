<?php

namespace Mpdf\Css;

use Mpdf\Color\ColorConverter;
use Mpdf\Color\ColorModeConverter;
use Mpdf\Color\ColorSpaceRestrictor;
use Mpdf\Mpdf;
use Mpdf\SizeConverter;
use Psr\Log\NullLogger;

class ShadowParserTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	/**
	 * @var \Mpdf\Css\ShadowParser
	 */
	private $shadowParser;
	private $mpdf;

	public function set_up()
	{
		parent::set_up();

		$this->mpdf = new Mpdf();
		$logger = new NullLogger();
		$sizeConverter = new SizeConverter(96, 11, $this->mpdf, $logger);
		$colorModeConverter = new ColorModeConverter();
		$colorSpaceRestrictor = new ColorSpaceRestrictor($this->mpdf, $colorModeConverter);
		$colorConverter = new ColorConverter($this->mpdf, $colorModeConverter, $colorSpaceRestrictor);

		$this->shadowParser = new ShadowParser($this->mpdf, $sizeConverter, $colorConverter);
	}

	public function tear_down()
	{
		unset($this->shadowParser, $this->mpdf);
		parent::tear_down();
	}

	public function testNormalizeShadowColors()
	{
		$input = '1px 1px 1px rgba(0, 0, 0, 0.5), 2px 2px #fff';
		$expected = '1px 1px 1px rgba(0*0*0*0.5), 2px 2px #fff';
		$this->assertEquals($expected, $this->shadowParser->normalizeShadowColors($input));
	}

	public function testParseBoxShadowWithBasicShadow()
	{
		$this->mpdf->blk    = [0 => ['inner_width' => 100]];
		$this->mpdf->blklvl = 1;

		$result = $this->shadowParser->parseBoxShadow('2px 2px');
		$this->assertCount(1, $result);
		// 2px = 0.529 mm
		$this->assertEqualsWithDelta(0.529, $result[0]['x'], 0.001);
		$this->assertEqualsWithDelta(0.529, $result[0]['y'], 0.001);
		$this->assertEquals(0, $result[0]['blur']);
		$this->assertFalse($result[0]['inset']);
	}

	public function testParseBoxShadowWithBlurAndSpread()
	{
		$this->mpdf->blk    = [0 => ['inner_width' => 100]];
		$this->mpdf->blklvl = 1;

		$result = $this->shadowParser->parseBoxShadow('2px 2px 4px 1px #000');
		$this->assertCount(1, $result);
		$this->assertEqualsWithDelta(0.529, $result[0]['x'], 0.001);
		$this->assertEqualsWithDelta(0.529, $result[0]['y'], 0.001);
		$this->assertEqualsWithDelta(1.058, $result[0]['blur'], 0.001);
		$this->assertEqualsWithDelta(0.264, $result[0]['spread'], 0.001);
	}

	public function testParseBoxShadowWithInset()
	{
		$this->mpdf->blk    = [0 => ['inner_width' => 100]];
		$this->mpdf->blklvl = 1;

		$result = $this->shadowParser->parseBoxShadow('inset 5px 5px 10px #000');
		$this->assertCount(1, $result);
		$this->assertTrue($result[0]['inset']);
	}

	public function testParseBoxShadowWithMultipleShadows()
	{
		$this->mpdf->blk    = [0 => ['inner_width' => 100]];
		$this->mpdf->blklvl = 1;

		$result = $this->shadowParser->parseBoxShadow('2px 2px #000, 4px 4px #fff');
		$this->assertCount(2, $result);
	}

	public function testParseTextShadowWithBasicShadow()
	{
		$result = $this->shadowParser->parseTextShadow('1px 1px');
		$this->assertCount(1, $result);
		$this->assertEqualsWithDelta(0.264, $result[0]['x'], 0.001);
		$this->assertEqualsWithDelta(0.264, $result[0]['y'], 0.001);
		$this->assertEquals(0, $result[0]['blur']);
	}

	public function testParseTextShadowWithBlur()
	{
		$this->mpdf->blk = [];

		$result = $this->shadowParser->parseTextShadow('2px 2px 3px #000');
		$this->assertCount(1, $result);
		$this->assertEqualsWithDelta(0.793, $result[0]['blur'], 0.001);
	}

	public function testNormalizeShadowColorsTakesTheSpaceWithTheComma()
	{
		$input = '1px 1px 1px rgba(0, 0, 0, 0.5), 2px 2px rgb(1,2,3)';
		$expected = '1px 1px 1px rgba(0*0*0*0.5), 2px 2px rgb(1*2*3)';

		$this->assertSame($expected, $this->shadowParser->normalizeShadowColors($input));
	}

	public function testShadowComponentsMayBeSeparatedByAnyWhitespace()
	{
		$this->mpdf->blk    = [0 => ['inner_width' => 100]];
		$this->mpdf->blklvl = 1;

		$this->assertEquals(
			$this->shadowParser->parseBoxShadow('2px 2px 4px 1px #000'),
			$this->shadowParser->parseBoxShadow("2px  2px\t4px\n 1px   #000")
		);
	}

	public function testAColourFunctionNeedsNoSpaceAfterItsCommas()
	{
		$this->mpdf->blk    = [0 => ['inner_width' => 100]];
		$this->mpdf->blklvl = 1;

		$tight = $this->shadowParser->parseBoxShadow('2px 2px 4px rgba(255,0,0,0.5)');
		$fallback = $this->shadowParser->parseBoxShadow('2px 2px 4px');

		$this->assertEquals($this->shadowParser->parseBoxShadow('2px 2px 4px rgba(255, 0, 0, 0.5)'), $tight);
		$this->assertNotEquals($fallback[0]['col'], $tight[0]['col'], 'the colour fell back to the default');
	}

	/**
	 * Not that a percentage is valid CSS here, but it is the only length whose conversion can
	 * show that the containing block's width reaches the size converter at all
	 */
	public function testAPercentageIsMeasuredAgainstTheContainingBlock()
	{
		$this->mpdf->blk    = [0 => ['inner_width' => 100]];
		$this->mpdf->blklvl = 1;

		$result = $this->shadowParser->parseBoxShadow('10% 20%');

		$this->assertEqualsWithDelta(10.0, $result[0]['x'], 0.001);
		$this->assertEqualsWithDelta(20.0, $result[0]['y'], 0.001);
	}

	public function testTextShadowComponentsMayBeSeparatedByAnyWhitespace()
	{
		$this->assertEquals(
			$this->shadowParser->parseTextShadow('2px 2px 3px #000'),
			$this->shadowParser->parseTextShadow("2px  2px\t3px   #000")
		);
	}

	public function testATextShadowColourFunctionNeedsNoSpaceAfterItsCommas()
	{
		$tight = $this->shadowParser->parseTextShadow('2px 2px 3px rgba(255,0,0,0.5)');
		$fallback = $this->shadowParser->parseTextShadow('2px 2px 3px');

		$this->assertEquals($this->shadowParser->parseTextShadow('2px 2px 3px rgba(255, 0, 0, 0.5)'), $tight);
		$this->assertNotEquals($fallback[0]['col'], $tight[0]['col'], 'the colour fell back to the default');
	}

	/**
	 * The colour has to survive the whole way to the page, not just out of the parser
	 */
	public function testATightColourFunctionIsPaintedOnThePage()
	{
		$mpdf = new Mpdf();
		$mpdf->compress = false;
		$mpdf->WriteHTML('<div style="width: 40mm; height: 20mm; box-shadow: 5mm 5mm 2mm rgba(255,0,0,0.5)">x</div>');

		$this->assertStringContainsString('1.000 0.000 0.000 rg', $mpdf->Output('', 'S'));
	}
}
