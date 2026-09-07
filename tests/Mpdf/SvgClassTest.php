<?php

namespace Mpdf;

class SvgClassTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	protected function set_up()
	{
		parent::set_up();

		$this->mpdf = new Mpdf(['mode' => 'c']);
	}

	protected function tear_down()
	{
		parent::tear_down();

		$this->mpdf->cleanup();
	}

	private function svg($attributes)
	{
		return '<svg ' . $attributes . ' width="100" height="100">'
			. '<circle cx="50" cy="50" r="40" fill="#ffff00" />'
			. '</svg>';
	}

	/**
	 * The class the img element AdjustHTML() replaced the SVG with was given, or '' for none
	 */
	private function convertedClass($svg)
	{
		$matches = [];
		if (!preg_match('/<img src="[^"]*"(?: class="([^"]*)")? \/>/', $this->mpdf->AdjustHTML($svg), $matches)) {
			return null;
		}

		return isset($matches[1]) ? $matches[1] : '';
	}

	/**
	 * An embedded SVG is written out to a file and replaced by an img element pointing at it, which
	 * used to drop everything the svg tag was styled by. See mpdf/mpdf#1404.
	 */
	public function quotingProvider()
	{
		return [
			'double quoted' => ['class="framed wide"', 'framed wide'],
			'single quoted' => ["class='framed wide'", 'framed wide'],
			'unquoted'      => ['class=framed', 'framed'],
			'spaced out'    => ['class = "framed"', 'framed'],
		];
	}

	/**
	 * @dataProvider quotingProvider
	 */
	public function testTheClassIsCarriedOverToTheImg($attribute, $expected)
	{
		$this->assertSame($expected, $this->convertedClass($this->svg($attribute)));
	}

	public function testAnSvgWithNoClassBecomesAnImgWithNone()
	{
		$this->assertSame('', $this->convertedClass($this->svg('id="plain"')));
	}

	/**
	 * Only the svg tag's own class is wanted - the shapes inside it are drawn by the SVG reader and
	 * have nothing to do with the img element
	 */
	public function testAClassOnSomethingInsideTheSvgIsNotTaken()
	{
		$svg = '<svg width="100" height="100"><circle class="inner" cx="50" cy="50" r="40" /></svg>';

		$this->assertSame('', $this->convertedClass($svg));
	}

	public function testTheImgIsStillWrittenWhenTheClassIsEmpty()
	{
		$this->assertSame('', $this->convertedClass($this->svg('class=""')));
	}

	/**
	 * The point of carrying it over: a selector written for the svg reaches the img
	 */
	public function testAStyleRuleWrittenForTheClassReachesTheImage()
	{
		$this->mpdf->compress = false;
		$this->mpdf->WriteHTML(
			'<style>.framed { border: 2px solid #ff0000 }</style><p>' . $this->svg('class="framed"') . '</p>'
		);

		$this->assertStringContainsString('1.000 0.000 0.000 RG', $this->mpdf->Output('', 'S'));
	}

}
