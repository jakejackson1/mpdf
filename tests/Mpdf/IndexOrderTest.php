<?php

namespace Mpdf;

/**
 * Index entries that collate the same are listed in the order they were made, whatever PHP sorts with
 */
class IndexOrderTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	use PageStreams;

	/**
	 * Twelve capitalised terms, then the same twelve in lower case: pairs that collate the same, made far enough
	 * apart for the sort PHP used before 8.0 to turn some of them round
	 */
	public function testEntriesThatCollateTheSameKeepTheirOrder()
	{
		$html = '';
		for ($i = 1; $i <= 12; $i++) {
			$html .= sprintf('<p>Item %1$d</p><indexentry content="Term%1$02d" />', $i);
		}
		for ($i = 1; $i <= 12; $i++) {
			$html .= sprintf('<p>Item %1$d again</p><indexentry content="term%1$02d" />', $i);
		}

		$streams = $this->pages($this->render($html . '<pagebreak /><indexinsert />'));
		preg_match_all('/\(((?:T|t)erm\d+)/', end($streams), $listed);

		$expected = [];
		for ($i = 1; $i <= 12; $i++) {
			$expected[] = sprintf('Term%02d', $i);
			$expected[] = sprintf('term%02d', $i);
		}
		$this->assertSame($expected, $listed[1]);
	}
}
