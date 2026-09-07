<?php

namespace Mpdf;

class AttributeParsingTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	private function render($html)
	{
		$mpdf = new Mpdf();
		$mpdf->compress = false;
		$mpdf->SetBasePath(__DIR__ . '/../data/');
		$mpdf->WriteHTML($html);

		return $mpdf->Output('', 'S');
	}

	private function red($output)
	{
		return false !== strpos($output, '1.000 0.000 0.000 rg');
	}

	/**
	 * Core fonts write one glyph per pair of parentheses, spaced out and sometimes zero padded, so only the letters are kept
	 */
	private function drawnText($output)
	{
		$matches = [];
		preg_match_all('#\((.*?)\)\s*Tj#s', $output, $matches);

		return preg_replace('/[^A-Za-z]/', '', implode('', $matches[1]));
	}

	/**
	 * A closed <select> draws the text of whichever option is selected, then a dropdown marker
	 */
	private function selectedOption($output)
	{
		$drawn = $this->drawnText($output);

		return false !== strpos($drawn, 'One') ? 'One' : (false !== strpos($drawn, 'Two') ? 'Two' : $drawn);
	}

	private function pageSizes($output)
	{
		$matches = [];
		preg_match_all('#/MediaBox \[([^\]]*)\]#', $output, $matches);

		return array_values(array_unique($matches[1]));
	}

	private function select($selected)
	{
		return '<select><option value="1">One</option><option ' . $selected . ' value="2">Two</option></select>';
	}

	public function testABooleanAttributeNeedsNoValue()
	{
		$this->assertSame('Two', $this->selectedOption($this->render($this->select('selected'))));
	}

	public function testABooleanAttributeMayStillCarryAValue()
	{
		$this->assertSame('Two', $this->selectedOption($this->render($this->select('selected="selected"'))));
	}

	public function testTheFirstOptionWinsWhenNoneIsSelected()
	{
		$this->assertSame('One', $this->selectedOption($this->render($this->select(''))));
	}

	/**
	 * A hyphen is not a word character, so the step that quotes bare values never reached these
	 */
	public function testAnUnquotedValueOnAHyphenatedAttribute()
	{
		$sizes = $this->pageSizes($this->render('a<pagebreak sheet-size=A5 />b'));

		$this->assertCount(2, $sizes);
		$this->assertSame('0 0 419.530 595.280', $sizes[1]);
	}

	public function testAQuotedValueOnAHyphenatedAttribute()
	{
		$sizes = $this->pageSizes($this->render('a<pagebreak sheet-size="A5" />b'));

		$this->assertCount(2, $sizes);
		$this->assertSame('0 0 419.530 595.280', $sizes[1]);
	}

	public function testASingleQuotedValueMayHoldDoubleQuotes()
	{
		$output = $this->render('<div style=\'font-family: "times"; color: #ff0000\'>x</div>');

		$this->assertStringContainsString('1.000 0.000 0.000 rg', $output);
	}

	public function testADoubleQuotedValueMayHoldSingleQuotes()
	{
		$output = $this->render('<div style="font-family: \'times\'; color: #ff0000">x</div>');

		$this->assertStringContainsString('1.000 0.000 0.000 rg', $output);
	}


	/**
	 * HTML lets whitespace sit either side of the equals sign
	 */
	public function testWhitespaceMaySurroundTheEqualsSign()
	{
		$sizes = $this->pageSizes($this->render('a<pagebreak sheet-size = "A5" />b'));

		$this->assertCount(2, $sizes);
		$this->assertSame('0 0 419.530 595.280', $sizes[1]);
	}

	public function testWhitespaceMaySurroundTheEqualsSignOfABareValue()
	{
		$sizes = $this->pageSizes($this->render('a<pagebreak sheet-size = A5 />b'));

		$this->assertCount(2, $sizes);
		$this->assertSame('0 0 419.530 595.280', $sizes[1]);
	}

	/**
	 * Attribute names are letter-led, so the slash closing an empty tag is not read as one
	 */
	public function testTheSlashOfAnEmptyTagIsNotAnAttribute()
	{
		$sizes = $this->pageSizes($this->render('a<pagebreak sheet-size="A5"/>b'));

		$this->assertCount(2, $sizes);
		$this->assertSame('0 0 419.530 595.280', $sizes[1]);
	}

	public function testABooleanAttributeMayComeLast()
	{
		$html = '<select><option value="1">One</option><option value="2" selected>Two</option></select>';

		$this->assertSame('Two', $this->selectedOption($this->render($html)));
	}

	/**
	 * The name that follows a valueless attribute has to be read as a name, not as its value
	 */
	public function testABooleanAttributeMayStandBetweenTwoOthers()
	{
		$html = '<select><option value="1">One</option><option class="x" selected value="2">Two</option></select>';

		$this->assertSame('Two', $this->selectedOption($this->render($html)));
	}

	public function testAttributesMayBeSeparatedByNewlines()
	{
		$output = $this->render("<div\n\tclass=\"x\"\n\tstyle=\"color: #ff0000\">x</div>");

		$this->assertTrue($this->red($output));
	}

	public function testAnEmptyValueDoesNotSwallowTheNextAttribute()
	{
		$output = $this->render('<div class="" style="color: #ff0000">x</div>');

		$this->assertTrue($this->red($output));
	}

	public function testTheLastOfTwoIdenticalAttributesWins()
	{
		$output = $this->render('<div style="color: #00ff00" style="color: #ff0000">x</div>');

		$this->assertTrue($this->red($output));
	}

	public function testAnAttributeNameIsCaseInsensitive()
	{
		$output = $this->render('<div STYLE="color: #ff0000">x</div>');

		$this->assertTrue($this->red($output));
	}

	/**
	 * Both the class attribute and the selector it matches are uppercased before comparison
	 */
	public function testAClassIsMatchedWhateverItsCase()
	{
		$output = $this->render('<style>.red { color: #ff0000 }</style><div class="rEd">x</div>');

		$this->assertTrue($this->red($output));
	}

	/**
	 * <pagefooter> is one of the tags held back from the step that quotes bare values, so nothing
	 * but the attribute regex itself can read these
	 */
	public function testATagHeldBackFromTheQuotingStepStillReadsBareValues()
	{
		$html = '<pagefooter name=pf content-left=FOOT footer-style-left="color: #ff0000" />'
			. '<setpagefooter name=pf page=ALL value=on />body';

		$output = $this->render($html);

		$this->assertStringContainsString('FOOT', $this->drawnText($output));
		$this->assertTrue($this->red($output), 'the footer style was not applied');
	}

	/**
	 * Relative paths are rewritten in the tag text before the attributes are read back out of it
	 */
	public function testARelativeImagePathIsStillResolved()
	{
		$this->assertStringContainsString('/Subtype /Image', $this->render('<img src=img/bayeux2.jpg />'));
	}

}
