<?php

namespace Mpdf;

/**
 * A table of contents in front of a document is written last and moved into place, which shifts every page after
 * it (@see Mpdf::MovePages). What was registered against a page before the shift has to end up where the page did
 */
class TableOfContentsShiftTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	use PageStreams;

	/**
	 * A contents of one page, and one long enough to run onto a second
	 */
	public function contentsLengths()
	{
		return [
			'one page' => [0, 1],
			'two pages' => [80, 2],
		];
	}

	/**
	 * A page that prints its number, with a contents entry, an index entry and a text field named after $label,
	 * and $more on it
	 */
	private function page($label, $more = '')
	{
		return '<p>Page ' . $label . ' printed as {PAGENO}</p>'
			. '<tocentry content="' . $label . '" /><indexentry content="' . $label . '" />'
			. '<p><input type="text" name="field' . $label . '" value="' . $label . '" /></p>' . $more . '<pagebreak />';
	}

	/**
	 * A contents of A, B and $padding more entries in front of page A and page B, then $index. The contents takes
	 * $tocPages, so A and B print as the pages after it
	 */
	private function document($padding, $tocPages, $index = '<indexinsert />', $contents = '<tocpagebreak />')
	{
		$html = $contents . $this->page('A')
			. $this->page('B', str_repeat('<tocentry content="Padding" />', $padding)) . $index;

		$pdf = $this->render($html, ['useActiveForms' => true]);
		$pages = $this->pages($pdf);
		$this->assertCount($tocPages + 3, $pages, 'The contents should take ' . $tocPages . ' page(s)');
		$this->assertTextCount(1, 'Page A printed as ' . ($tocPages + 1), $pages[$tocPages]);
		$this->assertTextCount(1, 'Page B printed as ' . ($tocPages + 2), $pages[$tocPages + 1]);

		return [$pdf, $pages];
	}

	/**
	 * @dataProvider contentsLengths
	 */
	public function testTheIndexListsThePagesTheEntriesEndUpOn($padding, $tocPages)
	{
		list(, $pages) = $this->document($padding, $tocPages);

		$this->assertIndexLists($tocPages + 1, 'A', end($pages));
		$this->assertIndexLists($tocPages + 2, 'B', end($pages));
	}

	/**
	 * @dataProvider contentsLengths
	 */
	public function testAFormFieldStaysOnThePageItWasWrittenOn($padding, $tocPages)
	{
		list($pdf) = $this->document($padding, $tocPages);

		$annotations = $this->annotations($pdf);
		$this->assertOnlyOnPage($tocPages, 1, '/T (fieldA)', $annotations, 'field A');
		$this->assertOnlyOnPage($tocPages + 1, 1, '/T (fieldB)', $annotations, 'field B');
	}

	/**
	 * The index's links are moved with the pages, like every other link, so they must not be shifted ahead of time
	 * the way the numbers are
	 *
	 * @dataProvider contentsLengths
	 */
	public function testALinkedIndexEntryPointsAtThePageItLists($padding, $tocPages)
	{
		list($pdf) = $this->document($padding, $tocPages, '<indexinsert links="on" />');

		$objects = $this->pageObjects($pdf);
		$annotations = $this->annotations($pdf);
		$links = end($annotations);
		$this->assertSame(1, substr_count($links, '/Dest [' . $objects[$tocPages] . ' 0 R'), 'A should link to the page it lists');
		$this->assertSame(1, substr_count($links, '/Dest [' . $objects[$tocPages + 1] . ' 0 R'), 'B should link to the page it lists');
	}

	/**
	 * A linked contents line links its title and its number to the page it names; the padding entries are on page
	 * B, so every one of their lines links there too
	 *
	 * @dataProvider contentsLengths
	 */
	public function testALinkedContentsLinePointsAtThePageItNames($padding, $tocPages)
	{
		list($pdf) = $this->document($padding, $tocPages, '<indexinsert />', '<tocpagebreak links="on" />');

		$objects = $this->pageObjects($pdf);
		$links = implode('', array_slice($this->annotations($pdf), 0, $tocPages));
		$this->assertSame(2, substr_count($links, '/Dest [' . $objects[$tocPages] . ' 0 R'), 'A should link to the page it names');
		$this->assertSame(2 * (1 + $padding), substr_count($links, '/Dest [' . $objects[$tocPages + 1] . ' 0 R'), 'B and the padding should link to the page they name');
	}

	/**
	 * @dataProvider contentsLengths
	 */
	public function testAnIndexInColumnsIsNumberedTheSame($padding, $tocPages)
	{
		list(, $pages) = $this->document($padding, $tocPages, '<columns column-count="2" /><indexinsert />');

		$this->assertIndexLists($tocPages + 1, 'A', end($pages));
		$this->assertIndexLists($tocPages + 2, 'B', end($pages));
	}

	/**
	 * A second contents, named, in front of page B: the pages after it are moved once more
	 */
	public function testASecondContentsMovesThePagesAfterItAgain()
	{
		$pdf = $this->render('<tocpagebreak />' . $this->page('A')
			. '<tocpagebreak name="second" />' . $this->page('B', '<tocentry content="B" name="second" />') . '<indexinsert />');
		$pages = $this->pages($pdf);

		$this->assertCount(5, $pages);
		$this->assertTextCount(1, 'Page A printed as 2', $pages[1]);
		$this->assertTextCount(1, 'Page B printed as 4', $pages[3]);
		$this->assertIndexLists(2, 'A', end($pages));
		$this->assertIndexLists(4, 'B', end($pages));
	}
}
