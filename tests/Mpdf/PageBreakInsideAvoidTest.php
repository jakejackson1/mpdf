<?php

namespace Mpdf;

/**
 * A page-break-inside:avoid block is laid out once to measure it and, if that ran onto another page, thrown
 * away and laid out again from where it started. These pin down what the measuring pass must not leave behind.
 */
class PageBreakInsideAvoidTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	use PageStreams;

	const RED = '1.000 0.000 0.000 rg';
	const GREEN = '0.000 0.502 0.000 rg';
	const WHITE = '1.000 1.000 1.000 rg';

	/**
	 * Twenty-two filler paragraphs leave room for a few more but not for the block, which moves whole to page 2
	 */
	private function movingBlock()
	{
		return $this->filler(22) . '<div style="page-break-inside: avoid">' . str_repeat('<p>Kept</p>', 12) . '</div>';
	}

	/**
	 * mpdf/mpdf#533: the footer is written when the measuring pass reaches the end of the starting page, and
	 * that page is the one the unwind kept, so the watermark on it was drawn again when the block was laid
	 * out for real
	 */
	public function testAWatermarkIsDrawnOncePerPage()
	{
		$mpdf = $this->mpdf();
		$mpdf->setWatermarkText('TESTING');
		$mpdf->showWatermarkText = true;
		$mpdf->WriteHTML('<p>Outside</p><div style="page-break-inside:avoid">' . str_repeat('<p>Inside</p>', 100) . '</div>');

		$pages = $this->pages($this->output($mpdf));

		$this->assertCount(4, $pages);
		foreach ($pages as $i => $page) {
			$this->assertTextCount(1, 'TESTING', $page, 'Page ' . ($i + 1) . ' should carry one watermark');
		}
	}

	/**
	 * The watermark is drawn when a page is finished, with the text set at that moment. A block that sets the next
	 * page's text as it opens and then moves must not leave that text on the page it left
	 */
	public function testAWatermarkSetInsideAMovedBlockDoesNotReachThePageItLeft()
	{
		$mpdf = $this->mpdf();
		$mpdf->showWatermarkText = true;
		$mpdf->WriteHTML('<watermarktext content="Page one" />' . $this->filler(22) . $this->keptBlock(12, '<watermarktext content="Page two" />'));

		$pages = $this->pages($this->output($mpdf));

		$this->assertCount(2, $pages);
		$this->assertTextCount(1, 'Page one', $pages[0]);
		$this->assertTextCount(0, 'Page two', $pages[0]);
		$this->assertTextCount(0, 'Page one', $pages[1]);
		$this->assertTextCount(1, 'Page two', $pages[1]);
	}

	/**
	 * mpdf/mpdf#1131: the measuring pass starts page 2, which selects the @page rules for it, and the
	 * unwind did not put page 1's back. So page 1 was finished with page 2's footer and background
	 */
	public function testTheFirstPageKeepsItsOwnPageRulesWhenABlockMovesOffIt()
	{
		$mpdf = $this->mpdf();
		$mpdf->WriteHTML('<style>
			@page { background-color: green; footer: second; }
			@page :first { background-color: red; footer: first; }
			p { page-break-inside: avoid; }
		</style>');
		$mpdf->WriteHTML('<htmlpagefooter name="first">This is Footer 1</htmlpagefooter>');
		$mpdf->WriteHTML('<htmlpagefooter name="second">This is Footer 2</htmlpagefooter>');
		$mpdf->WriteHTML('<h1>Hello</h1>' . str_repeat('<p>' . str_repeat('Lorem ipsum dolor sit amet. ', 12) . '</p>', 15));

		$pages = $this->pages($this->output($mpdf));

		$this->assertCount(2, $pages);

		$this->assertSame(1, substr_count($pages[0], self::RED));
		$this->assertSame(0, substr_count($pages[0], self::GREEN));
		$this->assertTextCount(1, 'This is Footer 1', $pages[0]);
		$this->assertTextCount(0, 'This is Footer 2', $pages[0]);

		$this->assertSame(0, substr_count($pages[1], self::RED));
		$this->assertSame(1, substr_count($pages[1], self::GREEN));
		$this->assertTextCount(0, 'This is Footer 1', $pages[1]);
		$this->assertTextCount(1, 'This is Footer 2', $pages[1]);
	}

	/**
	 * mpdf/mpdf#1805: the measuring pass painted the @page background onto the starting page a second time,
	 * on top of the block backgrounds already collected for it, so every page but the last lost them
	 */
	public function testAPageBackgroundIsPaintedOnceAndUnderTheBlockBackgrounds()
	{
		$html = '<style>@page { background-color: white } .card { page-break-inside: avoid } .head { background-color: red }</style>';
		for ($i = 1; $i <= 14; $i++) {
			$html .= '<div class="card"><div class="head">Card ' . $i . '</div><p>' . str_repeat('Body text. ', 60) . '</p></div>';
		}

		$pages = $this->pages($this->render($html));

		$this->assertCount(3, $pages);
		foreach ($pages as $i => $page) {
			$this->assertSame(1, substr_count($page, self::WHITE), 'Page ' . ($i + 1) . ' should paint its background once');
			$this->assertGreaterThan(strrpos($page, self::WHITE), strpos($page, self::RED), 'Page ' . ($i + 1) . ' should paint the card heads over the page background');
		}
	}

	/**
	 * The backgrounds collected for the starting page before the block are flushed onto it by the measuring
	 * pass. Putting the page back as it was has to put them back too, or they would never be painted
	 */
	public function testABackgroundCollectedBeforeTheBlockIsStillPainted()
	{
		$pages = $this->pages($this->render('<div style="background-color: red">Red box</div>' . $this->movingBlock()));

		$this->assertCount(2, $pages);
		$this->assertSame(1, substr_count($pages[0], self::RED));
		$this->assertTextCount(0, 'Kept', $pages[0]);
		$this->assertTextCount(12, 'Kept', $pages[1]);
	}

	/**
	 * The border of a block enclosing the moved one is stroked down to the foot of the starting page during
	 * the measuring pass, and the block remembers having done so. Putting the page back as it was without
	 * also putting the block back would leave page 1 with no border at all
	 */
	public function testAnEnclosingBorderIsPaintedOnTheFirstPageAfterTheBlockMoves()
	{
		$html = '<div style="border: 1mm solid #000; padding: 2mm">' . $this->movingBlock() . '<p>Tail</p></div>';

		$pages = $this->pages($this->render($html));

		$this->assertCount(2, $pages);
		$this->assertMatchesRegularExpression('/^S$/m', $pages[0], 'Page 1 should carry the border down to its foot');
		$this->assertMatchesRegularExpression('/^S$/m', $pages[1]);
	}

	/**
	 * The snapshot is taken after the text already waiting in the block's parent is flushed, so that text stays
	 * where it was when the block moves rather than being carried onto the next page with it
	 */
	public function testTextBeforeTheBlockInTheSameParentStaysWhereItWas()
	{
		$html = $this->filler(22) . '<div>Intro text<div style="page-break-inside: avoid">' . str_repeat('<p>Kept</p>', 12) . '</div></div>';

		$pages = $this->pages($this->render($html));

		$this->assertCount(2, $pages);
		$this->assertTextCount(1, 'Intro text', $pages[0]);
		$this->assertTextCount(0, 'Intro text', $pages[1]);
		$this->assertTextCount(12, 'Kept', $pages[1]);
	}

	/**
	 * A list item that moves to the next page keeps its number after the page the measuring pass made is discarded
	 * (tests/Issues/Issue339Test covers the numbering of kept items that stay put)
	 */
	public function testAListItemThatMovesKeepsItsNumber()
	{
		$html = $this->filler(25) . '<ol><li>One</li><li>Two</li><li style="page-break-inside: avoid">Three' . str_repeat('<br />', 8) . 'End</li><li>Four</li></ol>';

		$pages = $this->pages($this->render($html));

		$this->assertCount(2, $pages);
		$this->assertTextCount(1, '2.', $pages[0]);
		$this->assertTextCount(0, '3.', $pages[0]);
		$this->assertTextCount(1, '3.', $pages[1]);
		$this->assertTextCount(1, '4.', $pages[1]);
	}

	/**
	 * mpdf/mpdf#1131 (comments): a header, footer or fixed-position block is buffered, not discarded, while
	 * being measured, so a kept-together paragraph inside one was written twice
	 */
	public function testAKeptTogetherBlockInsideAFixedPositionBlockIsWrittenOnce()
	{
		$html = '<div style="position: absolute; top: 100mm; left: 100mm"><p style="page-break-inside: avoid">Hello</p></div><p>Body</p>';

		$pages = $this->pages($this->render($html));

		$this->assertCount(1, $pages);
		$this->assertTextCount(1, 'Hello', $pages[0]);
	}

	public function testAKeptTogetherBlockInsideAFooterIsWrittenOnce()
	{
		$mpdf = $this->mpdf();
		$mpdf->SetHTMLFooter('<p style="page-break-inside: avoid">Footer para</p>');
		$mpdf->WriteHTML('<p>Body</p>');

		$pages = $this->pages($this->output($mpdf));

		$this->assertCount(1, $pages);
		$this->assertTextCount(1, 'Footer para', $pages[0]);
	}

	/**
	 * mpdf/mpdf#2075: a substituted character is spliced into the token stream as a span, and the text
	 * before it trimmed from the current token. The trim was lost on the token itself, so the second pass
	 * printed the whole text and then the span and the rest of it again. Text in a TrueType font is written
	 * as UTF-16
	 */
	public function testASubstitutedCharacterIsNotDuplicatedWhenTheBlockIsParsedAgain()
	{
		$html = '<style>body { font-family: ocrb }</style>' . $this->filler(5) . '<div style="page-break-inside: avoid"><p>HÄẞLICH</p></div>';

		$pdf = $this->render($html, ['mode' => 'utf-8', 'useSubstitutions' => true, 'backupSubsFont' => ['dejavusans']]);

		foreach (['HÄ' => 'before', 'LICH' => 'after'] as $text => $side) {
			$this->assertSame(1, substr_count($pdf, mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), "The letters $side the substituted one should be printed once");
		}
	}

	/**
	 * mpdf/mpdf#1801: page-break-after:avoid asks for room for one more line as tall as the block after it.
	 * A tall image could never have that, on any page, and each check pushed it on to yet another one
	 */
	public function testATallBlockWithPageBreakAfterAvoidDoesNotPushBlankPages()
	{
		$html = '<p>Before</p><div style="page-break-after: avoid"><img style="width: 501px" src="' . $this->pngImage() . '" /></div><p>After</p>';

		$pages = $this->pages($this->render($html));

		$this->assertCount(1, $pages);
		$this->assertSame(1, $this->images($pages[0]));
		$this->assertTextCount(1, 'Before', $pages[0]);
		$this->assertTextCount(1, 'After', $pages[0]);
	}

	/**
	 * The look-ahead still applies when a fresh page could satisfy it: a heading with only its own height
	 * left on the page moves to the next one
	 */
	public function testAHeadingWithPageBreakAfterAvoidStillMovesToKeepItsNextLine()
	{
		$html = $this->filler(38) . '<h3 style="page-break-after: avoid; margin: 0">Heading</h3><p>After</p>';

		$pages = $this->pages($this->render($html));

		$this->assertCount(2, $pages);
		$this->assertTextCount(0, 'Heading', $pages[0]);
		$this->assertTextCount(1, 'Heading', $pages[1]);
		$this->assertTextCount(1, 'After', $pages[1]);
	}
}
