<?php

namespace Mpdf\Css;

use Mpdf\Css\SpecificityCalculator;
use PHPUnit\Framework\TestCase;

class SpecificityCalculatorTest extends TestCase
{
	/**
	 * @dataProvider specificityProvider
	 */
	public function testCalculate($selector, $expected)
	{
		$calculator = new SpecificityCalculator();
		$this->assertEquals($expected, $calculator->calculate($selector));
	}

	public function specificityProvider()
	{
		return [
			// Level 3 - Basic selectors
			['*', [0, 0, 0]],
			['div', [0, 0, 1]],
			['div p', [0, 0, 2]],
			['.class', [0, 1, 0]],
			['div.class', [0, 1, 1]],
			['#id', [1, 0, 0]],
			['div#id', [1, 0, 1]],
			['div.class#id', [1, 1, 1]],
			['a:hover', [0, 1, 1]],
			['a::before', [0, 0, 2]],
			['input[type="text"]', [0, 1, 1]],
			['div p.class', [0, 1, 2]],
			['#id .class', [1, 1, 0]],

			// W3C Level 3 Examples
			['LI', [0, 0, 1]],
			['UL LI', [0, 0, 2]],
			['UL OL+LI', [0, 0, 3]],
			['H1 + *[REL=up]', [0, 1, 1]],
			['UL OL LI.red', [0, 1, 3]],
			['LI.red.level', [0, 2, 1]],
			['#x34y', [1, 0, 0]],
			['#s12:not(FOO)', [1, 0, 1]],

			// Level 4 - :is() pseudo-class
			[':is(em, #foo)', [1, 0, 0]], // max of [0,0,1] and [1,0,0]
			[':is(.class)', [0, 1, 0]],
			[':is(div, .class, #id)', [1, 0, 0]], // max is #id
			['div:is(.a, .b)', [0, 1, 1]],
			['.foo :is(.bar, #baz)', [1, 1, 0]],

			// Level 4 - :where() pseudo-class (always zero)
			[':where(#id)', [0, 0, 0]],
			[':where(div, .class, #id)', [0, 0, 0]],
			['.qux:where(em, #foo#bar#baz)', [0, 1, 0]],
			['div:where(.ignored)', [0, 0, 1]],

			// Level 4 - :not() with multiple selectors
			[':not(em, strong#foo)', [0, 0, 0]],
			[':not(.class)', [0, 1, 0]],
			[':not(div)', [0, 0, 1]],
			['div:not(.excluded)', [0, 1, 1]],

			// Level 4 - :nth-child() with selector list
			[':nth-child(2n+1)', [0, 1, 0]],

			// Nested pseudo-classes
			[':not(:is(.class))', [0, 1, 0]],
			[':is(:where(#id))', [0, 0, 0]],
			[':where(:is(:not(#id)))', [0, 0, 0]],

			// Attribute selectors - all types
			['[href]', [0, 1, 0]],
			['[type="text"]', [0, 1, 0]],
			['[class~="active"]', [0, 1, 0]],
			['[lang|="en"]', [0, 1, 0]],
			['[href^="https://"]', [0, 1, 0]],
			['[href$=".pdf"]', [0, 1, 0]],
			['[title*="hello"]', [0, 1, 0]],
			['a[href][target]', [0, 2, 1]],
			['input[type="text"]', [0, 1, 1]], // case-insensitive modifier
			['[data-value="test"]', [0, 1, 0]], // case-sensitive modifier

			// Combined attribute + pseudo-class
			['a[href]:hover', [0, 2, 1]],
			['input[type="text"]:focus', [0, 2, 1]],
			[':not([disabled])', [0, 1, 0]],
			[':is(a[href], button[type])', [0, 1, 1]],

			// Deep cascading
			['body > main section article p', [0, 0, 5]],
			['#app .container > .row .col-md-6 .content', [1, 4, 0]],
			['html body div.page section#main article.post', [1, 2, 5]],
			['nav > ul > li:nth-child(2) > a.active:hover', [0, 3, 4]],
			[':not(div):is(section, article) > p.text', [0, 1, 3]],
			['#header nav:not(.mobile) > ul li > a:hover', [1, 2, 4]],

			// Deep cascading with attributes
			['form input[type="text"][required]', [0, 2, 2]],
			['nav > ul > li > a[href^="https://"]', [0, 1, 4]],
			['#sidebar .widget[data-type="recent"] > ul li', [1, 2, 2]],
			['article[lang|="en"] > p.intro', [0, 2, 2]],
			[':not([disabled]):is(input, button)[type="submit"]', [0, 2, 1]],
			['body > main section[aria-label] div.card[data-id^="post"]', [0, 3, 4]],
			['#nav ul li:nth-child(odd) > a[href$=".html"]:hover', [1, 3, 3]],
			['.form-group input[type][required]:not([disabled]):focus', [0, 5, 1]],

			// Stress tests
			['div div div div div div div div div', [0, 0, 9]],
			['.a .b .c .d .e .f .g .h .i', [0, 9, 0]],
			['#a #b #c #d #e', [5, 0, 0]],
			[':is(:is(:is(:is(.class))))', [0, 1, 0]],
		];
	}

	public function testCompare()
	{
		$calculator = new SpecificityCalculator();
		$this->assertGreaterThan(0, $calculator->compare([1, 0, 0], [0, 1, 0]));
		$this->assertLessThan(0, $calculator->compare([0, 1, 0], [1, 0, 0]));
		$this->assertEquals(0, $calculator->compare([0, 1, 0], [0, 1, 0]));
	}
}
