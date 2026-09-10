<?php

namespace Mpdf;

/**
 * What a kept block registers as it is laid out, beyond its text: links and anchors, bookmarks, index and table of
 * contents entries, form fields, annotations, page numbers, patterns and images, and its own border and
 * background. Each is registered once, on the page the block ends up on, whether the block moves or stays; the
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
	 * $needle appears $count times in the string for page $page and not at all in the others
	 */
	private function assertOnlyOnPage($page, $count, $needle, array $strings, $what)
	{
		foreach ($strings as $i => $string) {
			$expected = $i === $page ? $count : 0;
			$this->assertSame($expected, substr_count($string, $needle), 'Page ' . ($i + 1) . " should carry $what $expected time(s)");
		}
	}

	/**
	 * The annotation objects listed by each page, as one string per page
	 */
	private function annotations($pdf)
	{
		$annotations = [];
		foreach ($this->pageObjects($pdf) as $i => $number) {
			$annotations[$i] = '';
			if (preg_match('/\/Annots \[([^\]]*)\]/', $this->object($pdf, $number), $list)) {
				preg_match_all('/(\d+) 0 R/', $list[1], $refs);
				foreach ($refs[1] as $ref) {
					$annotations[$i] .= $this->object($pdf, $ref);
				}
			}
		}

		return $annotations;
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

		$this->assertSame(1, preg_match_all('/\(Kept term\s+(\d+)\)/', end($pages), $listed), 'The index should list the term once');
		$this->assertSame((string) ($page + 1), $listed[1][0], 'The index should list the block\'s page');
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
	 * @dataProvider placements
	 */
	public function testAKeptBlockInsideAKeptBlockIsLaidOutWithIt($filler, $page)
	{
		list(, $pages) = $this->document($filler, $page, '<div style="page-break-inside: avoid"><p>Nested</p></div>');

		$this->assertOnlyOnPage($page, 1, '(Nested)', $pages, 'the nested block');
	}
}
