<?php

namespace Mpdf;

/**
 * An index entry lists its pages singly, as a pair "a, b" or, for three or more in a row, as a run "a-b", wherever
 * in the list they fall
 */
class IndexPageRunsTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	use PageStreams;

	/**
	 * The pages a term is on, how the index should list them, and the page each listed reference links to
	 */
	public function pageLists()
	{
		return [
			'one page' => [[2], '2', [2]],
			'a pair then a page' => [[1, 2, 4], '1, 2, 4', [1, 2, 4]],
			'a page then a run' => [[1, 3, 4, 5], '1, 3-5', [1, 3]],
			'a run then a page' => [[1, 2, 3, 5], '1-3, 5', [1, 5]],
			'two runs' => [[1, 2, 3, 5, 6, 7], '1-3, 5-7', [1, 5]],
		];
	}

	/**
	 * A page for each number up to the last of $pages, "term" indexed on those in $pages, then the index
	 */
	private function document(array $pages, $links)
	{
		$html = '';
		for ($p = 1; $p <= max($pages); $p++) {
			$html .= '<p>Page ' . $p . '</p>' . (in_array($p, $pages) ? '<indexentry content="term" />' : '') . '<pagebreak />';
		}

		return $this->render($html . '<indexinsert links="' . $links . '" />');
	}

	/**
	 * @dataProvider pageLists
	 */
	public function testTheEntryListsItsPagesInRuns(array $pages, $listed)
	{
		$streams = $this->pages($this->document($pages, 'off'));

		$this->assertIndexLists($listed, 'term', end($streams));
	}

	/**
	 * @dataProvider pageLists
	 */
	public function testEachReferenceLinksToTheFirstPageItNames(array $pages, $listed, array $linked)
	{
		$pdf = $this->document($pages, 'on');
		$streams = $this->pages($pdf);
		$this->assertIndexLists($listed, 'term', end($streams));

		$objects = $this->pageObjects($pdf);
		$annotations = $this->annotations($pdf);
		preg_match_all('/\/Dest \[(\d+) 0 R/', end($annotations), $destinations);
		$targets = [];
		foreach ($destinations[1] as $object) {
			$targets[] = array_search($object, $objects) + 1;
		}
		$this->assertSame($linked, $targets, 'Each reference should link to the first page it names');
	}
}
