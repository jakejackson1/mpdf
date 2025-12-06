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
		unset( $this->shadowParser, $this->mpdf );
		parent::tear_down();
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
		$this->assertEqualsWithDelta(2.646, $res[0]['x'], 0.001);
		$this->assertEqualsWithDelta(2.646, $res[0]['y'], 0.001);
		$this->assertEqualsWithDelta(1.323, $res[0]['blur'], 0.001);

		$warnings = [];
		$this->assertEquals($this->colorConverter->convert('#888888', $warnings), $res[0]['col']);
	}

	public function testParseBoxShadowInset()
	{
		$input = 'inset 5px 5px 5px #000';
		$res = $this->shadowParser->parseBoxShadow($input);

		$this->assertTrue($res[0]['inset']);
		$this->assertEqualsWithDelta(1.323, $res[0]['x'], 0.001);
	}

	public function testParseTextShadow()
	{
		$input = '2px 2px #ff0000';
		$res = $this->shadowParser->parseTextShadow($input);

		$this->assertIsArray($res);
		$this->assertEqualsWithDelta(0.529, $res[0]['x'], 0.001);
		$this->assertEqualsWithDelta(0.529, $res[0]['y'], 0.001);
		$this->assertEqualsWithDelta(0.529, $res[0]['y'], 0.001);
		
		$warnings = [];
		$this->assertEquals($this->colorConverter->convert('#ff0000', $warnings), $res[0]['col']);
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
		
		$this->assertEqualsWithDelta(0.529, $res[0]['x'], 0.001);
		$this->assertEqualsWithDelta(0.265, $res[1]['x'], 0.001);
	}
}
