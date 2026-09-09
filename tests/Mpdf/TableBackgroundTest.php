<?php

namespace Mpdf;

class TableBackgroundTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	use PageStreams;

	const RED = '1.000 0.000 0.000 rg';

	/**
	 * Every rectangle filled red, in order, including any drawn more than once
	 */
	private function redRectangles($pdf)
	{
		preg_match_all('/' . preg_quote(self::RED, '/') . '\n([\d.\- ]+) re f/', $pdf, $matches);

		return $matches[1];
	}

	private function document($before, $after)
	{
		return str_repeat('<p>Before</p>', $before)
			. '<table><tr><td>Normal table cell</td></tr></table>'
			. '<div style="page-break-inside: avoid">'
			. '<table><tr><td bgcolor="red">Red table cell</td></tr></table>'
			. str_repeat('<p>After</p>', $after)
			. '</div>';
	}

	/**
	 * The measuring pass of a page-break-inside:avoid block used to paint the table background on the page
	 * the block started on, and leave it there when the block moved. See mpdf/mpdf#570.
	 */
	public function testATableBackgroundInsideAnAvoidBlockThatMovesIsDrawnOnceOnThePageItMovedTo()
	{
		$pdf = $this->render($this->document(20, 10));
		$pages = $this->pages($pdf);

		$this->assertCount(1, $this->redRectangles($pdf));
		$this->assertCount(2, $pages);
		$this->assertStringNotContainsString(self::RED, $pages[0]);
		$this->assertStringContainsString(self::RED, $pages[1]);
		$this->assertStringContainsString('Red table cell', $pages[1]);
	}

	/**
	 * The block fits where it is, so there is no move to leave anything behind - the background
	 * still has to be drawn
	 */
	public function testATableBackgroundInsideAnAvoidBlockThatStaysPutIsStillDrawn()
	{
		$this->assertCount(1, $this->redRectangles($this->render($this->document(2, 2))));
	}

	public function testATableBackgroundOutsideAnyAvoidBlockIsUnaffected()
	{
		$html = str_repeat('<p>Before</p>', 20)
			. '<table><tr><td bgcolor="red">Red table cell</td></tr></table>';

		$this->assertCount(1, $this->redRectangles($this->render($html)));
	}

	/**
	 * Each table writes a placeholder for its backgrounds to be spliced in behind. A table with no
	 * background of its own left the placeholder in the page, and the next table's backgrounds went
	 * behind that one as well as its own.
	 */
	public function testTablesWithNoBackgroundDoNotLeaveTheNextOnePaintedAgainForEachOfThem()
	{
		$html = str_repeat('<table><tr><td>Plain table cell</td></tr></table>', 5)
			. '<table><tr><td bgcolor="red">Red table cell</td></tr></table>';

		$this->assertCount(1, $this->redRectangles($this->render($html)));
	}

	/**
	 * Painting the same rectangle twice is invisible while it is opaque; a translucent one comes out
	 * darker than it was asked to be
	 */
	public function testATranslucentBackgroundIsNotDarkenedByBeingPaintedTwice()
	{
		$table = '<table style="background: rgba(255, 0, 0, 0.35)"><tr><td>Translucent table cell</td></tr></table>';

		$alone = $this->render($table);
		$preceded = $this->render('<table><tr><td>Plain table cell</td></tr></table>' . $table);

		$this->assertSame(
			preg_match_all('/ re f/', $alone),
			preg_match_all('/ re f/', $preceded)
		);
	}

	/**
	 * The table with the background comes first here, so its placeholder was always taken out before
	 * the plain table wrote one - this way round has never been wrong
	 */
	public function testATableWithNoBackgroundAfterAColouredOneIsStillUnaffected()
	{
		$html = '<table><tr><td bgcolor="red">Red table cell</td></tr></table>'
			. '<table><tr><td>Plain table cell</td></tr></table>';

		$this->assertCount(1, $this->redRectangles($this->render($html)));
	}

}
