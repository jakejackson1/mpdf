<?php

namespace Snapshots;

/**
 * GravityPDF/mpdf#61: a kept-together block over a table kept together, which moves whole to a fresh page now
 * that the blank its measuring pass left at the foot of the first page is discounted, and one taller than a page.
 *
 * @group snapshot
 */
class PageBreakAvoidEarlyBreakSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'page-break-avoid-early-break';
	}

	/**
	 * Rows in the table of the block that fits a page: a table kept together is shrunk into the space left on its
	 * page before it moves, so more than that allows
	 */
	protected function fittingRows()
	{
		return 20;
	}

	protected function config()
	{
		return [];
	}

	public function generatePdf()
	{
		$blocks = [
			[$this->fittingRows(), 'fits', 'This block fits a page, so it moves to the next one whole.'],
			[30, 'is too tall', 'This block is taller than a page, so it is split where it stands rather than moved.'],
		];
		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.kept { page-break-inside: avoid; border: 0.3mm dashed #808080; padding: 3mm; margin-bottom: 4mm; }
			table.rows { border-collapse: collapse; width: 100%; page-break-inside: avoid; }
			table.rows td { border: 0.2mm solid #404040; padding: 1.5mm; }
		</style>

		<h1>mPDF</h1>
		<h2>A kept block over a table kept together</h2>

		<p>Each dashed block below is kept together, and so is the table inside it. The table cannot split, so when
			the block is measured it jumps whole to the next page and leaves the foot of this page blank. That blank
			strip is not part of the block.</p>

		<?php for ($i = 0; $i < 8; $i++) { ?>
			<p>Filler line <?= $i + 1 ?>, so that the first block starts well down the page.</p>
		<?php } ?>

		<?php foreach ($blocks as list($rows, $label, $sentence)) { ?>
			<div class="kept">
				<?php for ($i = 0; $i < 8; $i++) { ?>
					<p>Kept line <?= $i + 1 ?>. <?= $sentence ?></p>
				<?php } ?>
				<table class="rows">
					<?php for ($i = 1; $i <= $rows; $i++) { ?>
						<tr><td>Row <?= $i ?> of the table that <?= $label ?></td></tr>
					<?php } ?>
				</table>
			</div>
		<?php } ?>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf($this->config());
		$this->mpdf->WriteHTML($html);
	}
}
