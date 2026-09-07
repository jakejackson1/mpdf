<?php

namespace Mpdf;

/**
 * Pruning of $floatDivs, the document-global list of every float the document has closed.
 *
 * GetFloatDivInfo() reads all of it every time a float is placed, so the list has to stay bounded or a
 * long document of floated rows costs O(n^2). What the pruning may not do is move anything on the page,
 * so most of this compares the rendered pages against Mpdf as it behaved before.
 */
class FloatDivTest extends BaseMpdfTest
{

	/**
	 * @dataProvider floatLayoutProvider
	 */
	public function testTheRenderedPagesAreUnchangedByPruning($html)
	{
		$pruned = new Mpdf(['mode' => 'c']);
		$pruned->WriteHTML($html);

		$unpruned = new UnprunedFloatsMpdf(['mode' => 'c']);
		$unpruned->WriteHTML($html);

		/* The page content streams, not Output(), which carries a timestamp and a random file ID. */
		$this->assertSame($unpruned->pages, $pruned->pages);
		$this->assertSame($unpruned->page, $pruned->page, 'Pruning should not change the page count.');

		$pruned->cleanup();
		$unpruned->cleanup();
	}

	public function floatLayoutProvider()
	{
		$lorem = 'Body copy long enough to wrap, so each line box has to negotiate with whatever floats are live where it lands. ';

		$rows = static function ($count, $row) {
			$html = '';
			for ($i = 0; $i < $count; $i++) {
				$html .= sprintf($row, $i, $i, $i);
			}

			return $html;
		};

		return [
			// The reported case: a report of floated columns, one cleared row per record.
			'grid of cleared rows' => [$rows(60, '<div style="clear: both;"><div style="float: left; width: 30%%;">left %d</div><div style="float: left; width: 30%%;">middle %d</div><div style="float: left; width: 30%%;">right %d</div></div>')],
			'rows cleared by a trailing div' => [$rows(60, '<div style="float: left; width: 45%%;">left %d</div><div style="float: right; width: 45%%;">right %d</div><div style="clear: both;">%d</div>')],
			'text flowing beside the floats' => [$rows(40, '<div style="float: left; width: 30%%;">left %d</div><div style="float: right; width: 30%%;">right %d</div><p>' . $lorem . '%d</p><div style="clear: both;"></div>')],
			'clear on one side only' => [$rows(40, '<div style="float: left; width: 30%%;">left %d</div><div style="float: right; width: 30%%;">right %d</div><div style="clear: left;">after %d</div>')],
			'floats nested inside floats' => [$rows(30, '<div style="clear: both;"><div style="float: left; width: 48%%;"><div style="float: left; width: 45%%;">inner %d</div><div style="float: right; width: 45%%;">inner %d</div><div style="clear: both;"></div></div><div style="float: right; width: 48%%;">outer %d</div></div>')],
			'floats that are never cleared' => [$rows(60, '<div style="float: left; width: 24%%;">box %d%d%d</div>')],
			'floats spanning a page break' => [$rows(30, '<div style="clear: both;"><div style="float: left; width: 45%%;">' . str_repeat($lorem, 4) . '%d</div><div style="float: right; width: 45%%;">%d %d</div></div>')],
			'floats followed by tables' => [$rows(40, '<div style="float: left; width: 30%%;">float %d</div><div style="clear: both;"></div><table><tr><td>cell %d</td><td>' . $lorem . '%d</td></tr></table>')],
		];
	}

	public function testTheRenderedPagesAreUnchangedWhenRowsAreWrittenOneCallAtATime()
	{
		/* How a report renderer streams: many WriteHTML() calls into one document. */
		$write = static function (Mpdf $mpdf) {
			$mpdf->WriteHTML('<div style="clear: both;">head</div>', HTMLParserMode::HTML_BODY, true, false);
			for ($i = 0; $i < 60; $i++) {
				$mpdf->WriteHTML(sprintf('<div style="clear: both;"><div style="float: left; width: 45%%;">left %d</div><div style="float: right; width: 45%%;">right %d</div></div>', $i, $i), HTMLParserMode::HTML_BODY, false, false);
			}
			$mpdf->WriteHTML('<div style="clear: both;">foot</div>', HTMLParserMode::HTML_BODY, false, true);
		};

		$pruned = new Mpdf(['mode' => 'c']);
		$write($pruned);

		$unpruned = new UnprunedFloatsMpdf(['mode' => 'c']);
		$write($unpruned);

		$this->assertSame($unpruned->pages, $pruned->pages);

		$pruned->cleanup();
		$unpruned->cleanup();
	}

	public function testOnlyTheRowBeingLaidOutIsKept()
	{
		$html = '';
		for ($i = 0; $i < 60; $i++) {
			$html .= sprintf('<div style="clear: both;"><div style="float: left; width: 30%%;">left %d</div><div style="float: left; width: 30%%;">middle %d</div><div style="float: left; width: 30%%;">right %d</div></div>', $i, $i, $i);
		}

		$this->mpdf->WriteHTML($html);

		/* Three columns, not 60 rows of them. Before pruning this was 180. */
		$this->assertCount(3, $this->mpdf->floatDivs);
	}

	public function testAFloatTheTextIsStillFlowingBesideIsKept()
	{
		$this->mpdf->WriteHTML('<div style="float: left; width: 40%;">sidebar</div><p>Body copy beside the float.</p>');

		$this->assertCount(1, $this->mpdf->floatDivs);
	}

	public function testAFloatIsDroppedOnlyOnceTheNextOneStartsBelowIt()
	{
		$below = ['side' => 'L', 'startpos' => 1010.0, 'endpos' => 1050.0, 'blockContext' => 2];
		$this->mpdf->addFloatDiv($below);

		/* Overlaps it, so both are live. */
		$this->mpdf->addFloatDiv(['side' => 'R', 'startpos' => 1020.0, 'endpos' => 1060.0, 'blockContext' => 2]);
		$this->assertCount(2, $this->mpdf->floatDivs);

		/* Starts below both, so both are behind the cursor for good. */
		$this->mpdf->addFloatDiv(['side' => 'L', 'startpos' => 1080.0, 'endpos' => 1090.0, 'blockContext' => 2]);
		$this->assertCount(1, $this->mpdf->floatDivs);
	}

	public function testAFloatInAnotherBlockFormattingContextIsKept()
	{
		/* ClearFloats() matches on blockContext alone, ignoring position, so another context is not ours to drop. */
		$this->mpdf->addFloatDiv(['side' => 'L', 'startpos' => 1010.0, 'endpos' => 1050.0, 'blockContext' => 2]);
		$this->mpdf->addFloatDiv(['side' => 'L', 'startpos' => 1080.0, 'endpos' => 1090.0, 'blockContext' => 3]);

		$this->assertCount(2, $this->mpdf->floatDivs);
	}

}
