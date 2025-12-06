<?php

namespace Mpdf\Css;

use Mpdf\Mpdf;

class SelectorParserTest  extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	private $mpdf;
	private $parser;

	protected function setUp(): void
	{
		$this->mpdf = new Mpdf();
		
		$this->mpdf->allowedCSStags = 'DIV|P|SPAN|H1|H2|H3|H4|H5|H6|A';

		$this->parser = new SelectorParser($this->mpdf);
	}

	public function set_up()
	{
		parent::set_up();

		$this->mpdf = new Mpdf();

		$this->mpdf->allowedCSStags = 'DIV|P|SPAN|H1|H2|H3|H4|H5|H6|A';

		$this->parser = new SelectorParser($this->mpdf);
	}

	public function tear_down()
	{
		unset( $this->parser, $this->mpdf );
		parent::tear_down();
	}

	public function testParsePageSelector()
	{
		$tags = ['@PAGE'];
		$expected = '@PAGE';
		$this->assertEquals($expected, $this->parser->parsePageSelector($tags));
		$this->assertFalse((bool) $this->mpdf->mirrorMargins);

		$tags = ['@PAGE', ':LEFT'];
		$expected = '@PAGE>>PSEUDO>>LEFT';
		$this->assertEquals($expected, $this->parser->parsePageSelector($tags));
		$this->assertTrue($this->mpdf->mirrorMargins);

		$tags = ['@PAGE', 'Named'];
		$expected = '@PAGE>>NAMED>>Named';
		$this->assertEquals($expected, $this->parser->parsePageSelector($tags));
		$this->assertTrue((bool) $this->mpdf->mirrorMargins); // once on, it doesnt turn off
	}

	public function testParseSimpleSelector()
	{
		$this->assertEquals('CLASS>>foo', $this->parser->parseSimpleSelector(['.foo']));
		$this->assertEquals('ID>>bar', $this->parser->parseSimpleSelector(['#bar']));
		$this->assertEquals('DIV', $this->parser->parseSimpleSelector(['DIV']));
		$this->assertEquals('DIV>>CLASS>>foo', $this->parser->parseSimpleSelector(['DIV.foo']));
		$this->assertEquals('DIV>>ID>>bar', $this->parser->parseSimpleSelector(['DIV#bar']));
		$this->assertNull($this->parser->parseSimpleSelector(['']));
	}

	public function testParseCascadedSelector()
	{
		$tags = ['DIV', 'P'];
		$expected = ['DIV', 'P'];
		$this->assertEquals($expected, $this->parser->parseCascadedSelector($tags));

		$tags = ['DIV.foo', '#bar'];
		$expected = ['DIV>>CLASS>>foo', 'ID>>bar'];
		$this->assertEquals($expected, $this->parser->parseCascadedSelector($tags));
	}
}
