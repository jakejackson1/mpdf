<?php

namespace Mpdf;

/**
 * Floats and page-break-inside:avoid. A float inside the block used to switch the measuring discard off but
 * leave the block flagged, so it was written by both passes (mpdf/mpdf#785); a float opened just before the
 * block was lost outright. The float bookkeeping is now rolled back with the rest of the page state.
 */
class PageBreakInsideAvoidFloatTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	use PageStreams;

	const RED = '1.000 0.000 0.000 rg';

	private function kept($inner)
	{
		return '<div style="page-break-inside: avoid">' . $inner . '<div style="clear: both"></div></div><p>Tail</p>';
	}

	private function floatingText($side, $lines)
	{
		return '<div style="float: ' . $side . '; width: 40%">' . str_repeat('<p>Float</p>', $lines) . '</div>';
	}

	private function floatingImage($side)
	{
		return '<img src="' . $this->pngImage() . '" style="float: ' . $side . '; width: 30mm; height: 30mm" />';
	}

	private function floatedKept($lines, $side = 'left', $style = '', $inner = '')
	{
		return '<div style="float: ' . $side . '; width: 45%; page-break-inside: avoid;' . $style . '">' . $inner . str_repeat('<p>Kept</p>', $lines) . '</div>';
	}

	private function beside($lines)
	{
		return str_repeat('<p>Beside</p>', $lines) . '<div style="clear: both"></div>';
	}

	private function besideAndTail()
	{
		return $this->beside(2) . '<p>Tail</p>';
	}

	private function assertMovedWhole(array $pages, array $onPage2)
	{
		$this->assertCount(2, $pages);
		foreach ($onPage2 as $text => $count) {
			$this->assertTextCount(0, $text, $pages[0]);
			$this->assertTextCount($count, $text, $pages[1]);
		}
	}

	public function testAFloatInsideTheBlockIsLaidOutOnce()
	{
		$html = $this->filler(5) . $this->kept('<p>Signed</p>' . $this->floatingText('left', 1) . $this->floatingText('left', 1));

		$pages = $this->pages($this->render($html));

		$this->assertCount(1, $pages);
		foreach (['Filler' => 5, 'Signed' => 1, 'Float' => 2, 'Tail' => 1] as $text => $count) {
			$this->assertTextCount($count, $text, $pages[0]);
		}
	}

	public function testAFloatInsideTheBlockMovesWithIt()
	{
		$html = $this->filler(22) . $this->kept('<p>Head</p>' . $this->floatingText('left', 1) . $this->floatingText('right', 1) . str_repeat('<p>Kept</p>', 10));

		$this->assertMovedWhole($this->pages($this->render($html)), ['Head' => 1, 'Float' => 2, 'Kept' => 10, 'Tail' => 1]);
	}

	/**
	 * Text beside the float keeps its indent after the move; the float's end position on the enclosing
	 * blocks is part of what is rolled back
	 */
	public function testTextStillWrapsBesideTheFloatAfterTheMove()
	{
		$html = $this->filler(22) . $this->kept($this->floatingText('left', 3) . $this->beside(3) . str_repeat('<p>Kept</p>', 8));

		$pages = $this->pages($this->render($html));

		$this->assertCount(2, $pages);
		preg_match('/([\d.]+) [\d.]+ Td  \(Beside\)/', $pages[1], $beside);
		preg_match('/([\d.]+) [\d.]+ Td  \(Kept\)/', $pages[1], $kept);
		$this->assertGreaterThan((float) $kept[1], (float) $beside[1], 'Text beside the float should be indented past it');
	}

	/**
	 * The float alone does not fit on what is left of the page, so the whole block moves
	 */
	public function testAFloatTallerThanTheSpaceLeftMovesWithTheBlock()
	{
		$html = $this->filler(22) . $this->kept($this->floatingText('left', 20) . $this->beside(5) . '<p>Kept</p>');

		$this->assertMovedWhole($this->pages($this->render($html)), ['Float' => 20, 'Beside' => 5, 'Kept' => 1, 'Tail' => 1]);
	}

	/**
	 * A float taller than a page makes the block taller than a page, so it cannot be kept together and
	 * breaks like any other block. Nothing is written twice on the way
	 */
	public function testABlockAFloatMakesTallerThanAPageIsLaidOutOnce()
	{
		$html = $this->filler(5) . $this->kept($this->floatingText('left', 60) . $this->beside(5) . '<p>Kept</p>');

		$pages = $this->pages($this->render($html));
		$all = implode('', $pages);

		$this->assertGreaterThan(1, count($pages));
		$this->assertTextCount(5, 'Filler', $pages[0]);
		foreach (['Float' => 60, 'Beside' => 5, 'Kept' => 1, 'Tail' => 1] as $text => $count) {
			$this->assertTextCount($count, $text, $all);
		}
	}

	/**
	 * Floated images go through their own buffer, which is rolled back too
	 */
	public function testFloatedImagesMoveWithTheBlock()
	{
		$html = $this->filler(22) . $this->kept($this->floatingImage('left') . $this->floatingImage('right') . $this->beside(8) . str_repeat('<p>Kept</p>', 4));

		$pages = $this->pages($this->render($html));

		$this->assertMovedWhole($pages, ['Beside' => 8, 'Kept' => 4]);
		$this->assertSame(0, $this->images($pages[0]));
		$this->assertSame(2, $this->images($pages[1]));
	}

	public function testAFloatedImageInABlockThatStaysPutIsDrawnOnce()
	{
		$html = $this->filler(5) . $this->kept($this->floatingImage('left') . $this->beside(8) . '<p>Kept</p>');

		$pages = $this->pages($this->render($html));

		$this->assertCount(1, $pages);
		$this->assertSame(1, $this->images($pages[0]));
		$this->assertTextCount(8, 'Beside', $pages[0]);
	}

	/**
	 * A float still open when the block starts used to disappear with the measuring pass. Where mPDF puts
	 * it is the float engine's decision, so only that it is drawn once is asserted
	 */
	public function testAFloatOpenedBeforeTheBlockIsNotLost()
	{
		$html = $this->filler(28) . $this->floatingText('left', 6) . $this->kept(str_repeat('<p>Kept</p>', 10));

		$pages = $this->pages($this->render($html));

		$this->assertCount(2, $pages);
		$this->assertTextCount(6, 'Float', implode('', $pages));
		$this->assertTextCount(10, 'Kept', $pages[1]);
	}

	/**
	 * A floated block used to be excluded from keep-together and split at the foot of the page like any float.
	 * A float's close puts the position back to where it started, so a block being measured skips its own and
	 * the move is decided from where the float ended
	 */
	public function testAFloatedBlockIsKeptTogether()
	{
		$pages = $this->pages($this->render($this->filler(26) . $this->floatedKept(6) . $this->besideAndTail()));

		$this->assertMovedWhole($pages, ['Kept' => 6, 'Beside' => 2, 'Tail' => 1]);
		$this->assertTextCount(26, 'Filler', $pages[0]);
	}

	public function testAFloatedBlockThatFitsStaysPut()
	{
		$pages = $this->pages($this->render($this->filler(5) . $this->floatedKept(6) . $this->besideAndTail()));

		$this->assertCount(1, $pages);
		foreach (['Kept' => 6, 'Beside' => 2, 'Tail' => 1] as $text => $count) {
			$this->assertTextCount($count, $text, $pages[0]);
		}
	}

	public function testAFloatedBlockTallerThanAPageStillBreaks()
	{
		$pages = $this->pages($this->render($this->filler(26) . $this->floatedKept(40) . $this->besideAndTail()));

		$this->assertCount(3, $pages);
		$this->assertGreaterThan(0, substr_count($pages[0], '(Kept)'), 'A block that fits on no page starts where it is');
		$this->assertTextCount(40, 'Kept', implode('', $pages));
		$this->assertTextCount(1, 'Tail', $pages[2]);
	}

	public function testARightFloatedBlockIsKeptTogether()
	{
		$pages = $this->pages($this->render($this->filler(26) . $this->floatedKept(6, 'right') . $this->besideAndTail()));

		$this->assertMovedWhole($pages, ['Kept' => 6, 'Beside' => 2, 'Tail' => 1]);
	}

	public function testLeftAndRightFloatedBlocksBothMove()
	{
		$right = str_replace('<p>Kept</p>', '<p>Right</p>', $this->floatedKept(6, 'right'));

		$pages = $this->pages($this->render($this->filler(26) . $this->floatedKept(6) . $right . $this->besideAndTail()));

		$this->assertMovedWhole($pages, ['Kept' => 6, 'Right' => 6, 'Beside' => 2, 'Tail' => 1]);
	}

	/**
	 * Of two floated blocks, only the one that does not fit moves
	 */
	public function testAFloatedBlockThatFitsStaysWhileOneThatDoesNotMoves()
	{
		$right = str_replace('<p>Kept</p>', '<p>Right</p>', $this->floatedKept(12, 'right'));

		$pages = $this->pages($this->render($this->filler(22) . $this->floatedKept(3) . $right . $this->besideAndTail()));

		$this->assertCount(2, $pages);
		$this->assertTextCount(3, 'Kept', $pages[0]);
		$this->assertTextCount(0, 'Right', $pages[0]);
		$this->assertTextCount(12, 'Right', $pages[1]);
		$this->assertTextCount(1, 'Tail', $pages[1]);
	}

	/**
	 * The border and background are painted where the block ends up, and only there
	 */
	public function testAFloatedBlockWithABorderAndBackgroundMovesWhole()
	{
		$html = $this->filler(26) . $this->floatedKept(6, 'left', ' border: 1mm solid #000; background-color: red; padding: 2mm') . $this->besideAndTail();

		$pages = $this->pages($this->render($html));

		$this->assertMovedWhole($pages, ['Kept' => 6, 'Tail' => 1]);
		$this->assertSame(0, substr_count($pages[0], self::RED));
		$this->assertSame(1, substr_count($pages[1], self::RED));
		$this->assertDoesNotMatchRegularExpression('/^S$/m', $pages[0]);
		$this->assertMatchesRegularExpression('/^S$/m', $pages[1]);
	}

	public function testAFloatInsideAFloatedBlockMovesWithIt()
	{
		$inner = '<div style="float: left; width: 40%">' . str_repeat('<p>Inner</p>', 3) . '</div>';

		$pages = $this->pages($this->render($this->filler(26) . $this->floatedKept(6, 'left', '', $inner) . $this->besideAndTail()));

		$this->assertMovedWhole($pages, ['Inner' => 3, 'Kept' => 6, 'Beside' => 2, 'Tail' => 1]);
	}

	public function testAnImageAndATableInsideAFloatedBlockMoveWithIt()
	{
		$inner = '<img src="' . $this->pngImage() . '" style="width: 20mm; height: 20mm" /><table><tr><td bgcolor="red">Cell</td></tr></table>';

		$pages = $this->pages($this->render($this->filler(26) . $this->floatedKept(4, 'left', '', $inner) . $this->besideAndTail()));

		$this->assertMovedWhole($pages, ['Cell' => 1, 'Kept' => 4, 'Tail' => 1]);
		$this->assertSame(0, $this->images($pages[0]));
		$this->assertSame(1, $this->images($pages[1]));
		$this->assertSame(0, substr_count($pages[0], self::RED));
		$this->assertSame(1, substr_count($pages[1], self::RED));
	}

	/**
	 * The container's border runs to the foot of page 1 and continues on page 2 around the moved block
	 */
	public function testAFloatedBlockInsideABorderedContainerMovesWhole()
	{
		$html = '<div style="border: 1mm solid #000; padding: 2mm">' . $this->filler(25) . $this->floatedKept(6) . $this->besideAndTail() . '</div>';

		$pages = $this->pages($this->render($html));

		$this->assertMovedWhole($pages, ['Kept' => 6, 'Beside' => 2, 'Tail' => 1]);
		$this->assertMatchesRegularExpression('/^S$/m', $pages[0]);
		$this->assertMatchesRegularExpression('/^S$/m', $pages[1]);
	}

	/**
	 * Keep-together is off inside columns, and a float there is unaffected
	 */
	public function testAFloatInsideTheBlockInsideColumnsIsLaidOutOnce()
	{
		$html = '<columns column-count="2" />' . $this->filler(22) . $this->kept($this->floatingText('left', 3) . $this->beside(3) . '<p>Kept</p>');

		$pages = $this->pages($this->render($html));

		$this->assertCount(1, $pages);
		foreach (['Filler' => 22, 'Float' => 3, 'Beside' => 3, 'Kept' => 1, 'Tail' => 1] as $text => $count) {
			$this->assertTextCount($count, $text, $pages[0]);
		}
	}
}
