<?php

namespace Snapshots;

/**
 * Everything the measuring pass of a page-break-inside:avoid block used to leave behind, on one document:
 * the watermark and footer of the page a block moves off, the @page rules of that page, the backgrounds of
 * blocks that stay on it and of tables inside the moved block, a float inside a moved block, an enclosing
 * border, and a table caption kept with its table. The watermark names its page, and each block that moves
 * sets the next page's text as it opens: the page it left must still carry its own.
 *
 * @group snapshot
 */
class PageBreakAvoidStateSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'page-break-avoid-state';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			@page { background-color: #dfe8f5; footer: html_std; }
			@page :first { background-color: #fbe9e9; footer: html_first; }

			p { margin: 0 0 2mm 0; }

			div.card { page-break-inside: avoid; margin-bottom: 4mm; border: 0.2mm solid #808080; }
			div.card div.head { background-color: #c8c8c8; padding: 2mm; font-weight: bold; }
			div.card p { padding: 0 2mm; }
			div.card table.panel { margin: 0 2mm 2mm; }

			table.panel { border-collapse: collapse; width: 100%; margin-bottom: 4mm; }
			table.panel td { padding: 2mm; border: 0.2mm solid #404040; }
			td.red { background-color: #d02020; color: #ffffff; }
			td.blue { background-color: #2040c0; color: #ffffff; }

			div.frame { border: 0.8mm solid #202020; padding: 3mm; margin-bottom: 4mm; }
			div.signatures { page-break-inside: avoid; }
			div.signatures div.left { float: left; width: 45%; border-top: 0.3mm solid #000; }
			div.signatures div.right { float: right; width: 45%; border-top: 0.3mm solid #000; }
			div.clear { clear: both; }

			table.kept { page-break-inside: avoid; border-collapse: collapse; width: 100%; }
			table.kept td { border: 0.2mm solid #404040; padding: 2mm; }
			table.kept caption { font-weight: bold; text-align: left; }
		</style>

		<htmlpagefooter name="first">First page footer</htmlpagefooter>
		<htmlpagefooter name="std">Page {PAGENO} footer</htmlpagefooter>

		<watermarktext content="Page one" />

		<h1>mPDF</h1>
		<h2>What a measured block must not leave behind</h2>

		<p>Every card below is kept together. The first page has its own background colour and footer, and
			one watermark naming it, all of which stay as they are when the cards that do not fit here move on
			(the first card to move sets the next page's watermark as it opens). The coloured panels in the last
			card move with it, and the plain table in front of the cards, which is what leaves a place on this
			page for table backgrounds to land, gets nothing behind it.</p>

		<table class="panel">
			<tr><td>A table before the cards, with no background of its own</td></tr>
		</table>

		<?php for ($i = 1; $i <= 7; $i++) { ?>
			<div class="card">
				<div class="head">Card <?= $i ?></div>
				<?php if ($i === 5) { ?>
					<watermarktext content="Page two" />
				<?php } ?>
				<?php if ($i === 7) { ?>
					<table class="panel"><tr><td class="red">Red panel, inside the last card</td></tr></table>
					<table class="panel"><tr><td class="blue">Blue panel, inside the last card</td></tr></table>
				<?php } ?>
				<?php for ($j = 0; $j < 4; $j++) { ?>
					<p>Card text, line <?= $j + 1 ?>. Enough of it that the last cards have to move to page 2 whole.</p>
				<?php } ?>
			</div>
		<?php } ?>

		<div class="frame">
			<p>A framed section. Its border runs to the foot of this page and on from the top of the next,
				around a signature block that is kept together and moves as one.</p>
			<?php for ($i = 0; $i < 20; $i++) { ?>
				<p>Framed text, line <?= $i + 1 ?>.</p>
			<?php } ?>
			<div class="signatures">
				<watermarktext content="Page three" />
				<p>Signed on behalf of both parties:</p>
				<div class="left">Me</div>
				<div class="right">You</div>
				<div class="clear"></div>
				<?php for ($i = 0; $i < 6; $i++) { ?>
					<p>Signature block text, line <?= $i + 1 ?>.</p>
				<?php } ?>
			</div>
			<p>After the signature block.</p>
		</div>

		<?php for ($i = 0; $i < 15; $i++) { ?>
			<p>Filler before the table, line <?= $i + 1 ?>.</p>
		<?php } ?>

		<table class="kept">
			<caption>A caption kept with its table</caption>
			<tr><td>The table does not fit on this page, so it moves. With keep-with-table on, the caption moves with it.</td></tr>
			<?php for ($i = 0; $i < 12; $i++) { ?>
				<tr><td>Row <?= $i + 1 ?></td></tr>
			<?php } ?>
		</table>

		<watermarktext content="Page four" />
		<p>After the table.</p>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->use_kwt = true;
		$this->mpdf->showWatermarkText = true;
		$this->mpdf->WriteHTML($html);
	}
}
