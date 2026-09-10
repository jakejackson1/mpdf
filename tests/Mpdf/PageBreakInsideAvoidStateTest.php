<?php

namespace Mpdf;

/**
 * What a kept block registers as it is laid out, beyond its text: links and anchors, bookmarks, index and table of
 * contents entries, form fields, annotations, page numbers, patterns and images, its own border and background,
 * and the lists, pictures and tables it holds. Each is registered once, on the page the block ends up on, whether the block moves or stays; the
 * measuring pass leaves nothing of it behind
 */
class PageBreakInsideAvoidStateTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	use PageStreams;

	const LINES = 8;
	const RED = '1.000 0.000 0.000 rg';

	public function placements()
	{
		return [
			'the block moves to page 2' => [22, 1],
			'the block stays on page 1' => [3, 0],
		];
	}

	/**
	 * $before, the filler, a kept block of $inner and eight lines, then $after. Asserts the block landed on
	 * page $page
	 */
	private function document($filler, $page, $inner, $before = '', $after = '', $config = [], $style = '')
	{
		$mpdf = $this->mpdf($config);
		$mpdf->WriteHTML($before . $this->filler($filler) . $this->keptBlock(self::LINES, $inner, $style) . $after);

		$pdf = $this->output($mpdf);
		$pages = $this->pages($pdf);
		$this->assertOnlyOnPage($page, self::LINES, '(Kept)', $pages, 'the block');

		return [$pdf, $pages];
	}

	/**
	 * @dataProvider placements
	 */
	public function testALinkIsAnnotatedOnceOnTheBlocksPage($filler, $page)
	{
		list($pdf) = $this->document($filler, $page, '<p><a href="https://example.com/kept">Link</a></p>');

		$this->assertOnlyOnPage($page, 1, '/URI (https://example.com/kept)', $this->annotations($pdf), 'the link');
	}

	/**
	 * @dataProvider placements
	 */
	public function testAnAnchorInsideTheBlockResolvesToTheBlocksPage($filler, $page)
	{
		list($pdf) = $this->document($filler, $page, '<p><a name="target">Target</a></p>', '<p><a href="#target">To the target</a></p>');

		$target = '/Dest [' . $this->pageObjects($pdf)[$page] . ' 0 R';
		$this->assertSame(1, substr_count($this->annotations($pdf)[0], $target), 'The link on page 1 should point at page ' . ($page + 1));
	}

	/**
	 * @dataProvider placements
	 */
	public function testABookmarkPointsAtTheBlocksPage($filler, $page)
	{
		list($pdf) = $this->document($filler, $page, '<bookmark content="Kept bookmark" />');

		$this->assertSame(1, substr_count($pdf, '/Title'));
		$this->assertSame(1, substr_count($pdf, '/Dest [' . $this->pageObjects($pdf)[$page] . ' 0 R'), 'The outline should point at page ' . ($page + 1));
	}

	/**
	 * @dataProvider placements
	 */
	public function testAnIndexEntryIsRegisteredOnceForTheBlocksPage($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page, '<indexentry content="Kept term" />', '', '<pagebreak /><indexinsert />');

		$this->assertIndexLists($page + 1, 'Kept term', end($pages));
	}

	/**
	 * The table of contents goes in front, so every page number is one higher
	 *
	 * @dataProvider placements
	 */
	public function testATableOfContentsEntryIsRegisteredOnceForTheBlocksPage($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page + 1, '<tocentry content="Kept entry" />', '<tocpagebreak />');

		$this->assertTextCount(1, 'Kept entry', $pages[0]);
		$this->assertTextCount(1, (string) ($page + 2), $pages[0], 'The entry should carry the block\'s page number');
	}

	/**
	 * @dataProvider placements
	 */
	public function testAFormFieldIsWrittenOnceOnTheBlocksPage($filler, $page)
	{
		list($pdf) = $this->document($filler, $page, '<p><input type="text" name="keptfield" value="Hello" /></p>', '', '', ['useActiveForms' => true]);

		$this->assertSame(1, substr_count($pdf, '/T (keptfield)'));
		$this->assertOnlyOnPage($page, 1, '/Subtype /Widget', $this->annotations($pdf), 'the field');
	}

	/**
	 * A radio group and a submit button keep bookkeeping beside the field: the group's kids, and the button's
	 * action. Neither may be written for a field the unwind put back
	 *
	 * @dataProvider placements
	 */
	public function testARadioGroupAndASubmitButtonAreWrittenOnceOnTheBlocksPage($filler, $page)
	{
		$inner = '<p><input type="radio" name="choice" value="yes" checked="checked" /> Yes <input type="radio" name="choice" value="no" /> No'
			. ' <input type="submit" name="send" value="Send" /></p>';

		list($pdf) = $this->document($filler, $page, $inner, '', '', ['useActiveForms' => true]);

		$this->assertOnlyOnPage($page, 3, '/Subtype /Widget', $this->annotations($pdf), 'a widget');
		$this->assertSame(1, preg_match_all('/\/Ff \d+\s*\/Kids \[([^\]]*)\]/', $pdf, $groups), 'The radio group should be written once');
		preg_match_all('/(\d+) 0 R/', $groups[1][0], $kids);
		$this->assertCount(2, $kids[1]);
		foreach ($kids[1] as $kid) {
			$this->assertStringContainsString('/Subtype /Widget', $this->object($pdf, $kid), "Kid $kid should be one of the buttons");
		}
		$this->assertSame(1, substr_count($pdf, '/S /SubmitForm'));
	}

	/**
	 * @dataProvider placements
	 */
	public function testAnAnnotationIsWrittenOnceOnTheBlocksPage($filler, $page)
	{
		list($pdf) = $this->document($filler, $page, '<annotation content="Kept note" />');

		$this->assertOnlyOnPage($page, 1, '/Subtype /Text', $this->annotations($pdf), 'the note');
	}

	/**
	 * @dataProvider placements
	 */
	public function testAPageNumberInsideTheBlockIsTheBlocksPage($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page, '<p>Kept on page {PAGENO}</p>');

		$this->assertOnlyOnPage($page, 1, '(Kept on page ' . ($page + 1) . ')', $pages, 'its page number');
	}

	/**
	 * A gradient registers a shading and a cell\'s background image a pattern and an XObject; only the page the
	 * block lands on paints them
	 *
	 * @dataProvider placements
	 */
	public function testAGradientAndABackgroundImageArePaintedOnceOnTheBlocksPage($filler, $page)
	{
		$inner = '<div style="background-image: linear-gradient(#ff0000, #0000ff)">Gradient</div>'
			. '<table><tr><td style="background-image: url(' . $this->backgroundImage() . ')">Cell</td></tr></table>';

		list($pdf, $pages) = $this->document($filler, $page, $inner);

		foreach (['/PatternType' => 2, '/ShadingType' => 1, '/Subtype /Image' => 1] as $object => $count) {
			$this->assertSame($count, substr_count($pdf, $object), "$object should be registered $count time(s)");
		}
		$this->assertOnlyOnPage($page, 1, ' sh', $pages, 'the gradient');
		$this->assertOnlyOnPage($page, 1, ' scn', $pages, 'the image');
	}

	/**
	 * The block\'s own border is stroked line by line as it is laid out, and its background painted when it closes
	 *
	 * @dataProvider placements
	 */
	public function testTheBlocksOwnBorderAndBackgroundArePaintedOnItsPage($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page, '', '', '', [], 'border: 1mm solid #000; background-color: red');

		$this->assertOnlyOnPage($page, 1, self::RED, $pages, 'the background');
		foreach ($pages as $i => $stream) {
			$this->assertSame($i === $page, (bool) preg_match('/^S$/m', $stream), 'Page ' . ($i + 1) . ($i === $page ? ' should' : ' should not') . ' carry the border');
		}
	}

	/**
	 * The list counters go back with everything else, so the numbers are what they would be had the block not
	 * moved, a start and a letter style included
	 *
	 * @dataProvider placements
	 */
	public function testAListInsideTheBlockKeepsItsNumbers($filler, $page)
	{
		$inner = '<ol start="3"><li>Three</li><li>Four</li><li>Five</li></ol>'
			. '<ol style="list-style-type: lower-alpha"><li>Alpha</li><li>Beta</li></ol>';

		list(, $pages) = $this->document($filler, $page, $inner);

		foreach (['3.', '4.', '5.', 'a.', 'b.'] as $marker) {
			$this->assertOnlyOnPage($page, 1, "($marker)", $pages, "the marker $marker");
		}
	}

	/**
	 * @dataProvider placements
	 */
	public function testAnImageInsideTheBlockIsDrawnOnce($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page, '<p><img src="' . $this->pngImage() . '" /></p>');

		foreach ($pages as $i => $stream) {
			$this->assertSame($i === $page ? 1 : 0, $this->images($stream), 'Page ' . ($i + 1) . ' should draw the image ' . ($i === $page ? 'once' : 'not at all'));
		}
	}

	/**
	 * @dataProvider placements
	 */
	public function testATableInsideTheBlockIsWrittenOnce($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page, '<table><tr><td>Row one</td></tr><tr><td>Row two</td></tr></table>');

		$this->assertOnlyOnPage($page, 1, '(Row one)', $pages, 'the first row');
		$this->assertOnlyOnPage($page, 1, '(Row two)', $pages, 'the second row');
	}

	/**
	 * @dataProvider placements
	 */
	public function testAKeptBlockInsideAKeptBlockIsLaidOutWithIt($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page, '<div style="page-break-inside: avoid"><p>Nested</p></div>');

		$this->assertOnlyOnPage($page, 1, '(Nested)', $pages, 'the nested block');
	}
}
