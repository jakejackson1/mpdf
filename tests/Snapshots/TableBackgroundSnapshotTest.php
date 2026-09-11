<?php

namespace Snapshots;

/**
 * Table backgrounds: translucent pairs with a plain table in front, which used to be painted twice; a table inside a
 * kept-together block that moves, whose background used to be left at the foot of the page the block left
 * (mpdf/mpdf#570); and a table that breaks across a page, painted once on each part.
 *
 * @group snapshot
 */
class TableBackgroundSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'table-background';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			p { margin: 0 0 3mm 0; }

			table.panel { border-collapse: collapse; width: 100%; margin-bottom: 4mm; }
			table.panel td { padding: 3mm; border: 0.2mm solid #404040; }

			table.red { background: rgba(208, 32, 32, 0.35); }
			table.blue { background: rgba(32, 64, 192, 0.35); }

			td.tint { background: rgba(32, 128, 32, 0.35); }

			div.kept { page-break-inside: avoid; border: 0.3mm dashed #808080; padding: 3mm; margin-bottom: 4mm; }
		</style>

		<h1>mPDF</h1>
		<h2>Table backgrounds</h2>

		<p>Each pair below is the same colour written twice. The second of each pair has a
			table with no background of its own in front of it, which used to leave the pair's second
			half painted on top of itself and so darker than the first.</p>

		<table class="panel red">
			<tr><td>Translucent red, with nothing in front of it</td></tr>
		</table>

		<table class="panel">
			<tr><td>A table with no background of its own</td></tr>
		</table>

		<table class="panel red">
			<tr><td>Translucent red again, and the same shade as the first</td></tr>
		</table>

		<table class="panel">
			<tr><td>Another table with no background of its own</td></tr>
		</table>

		<table class="panel blue">
			<tr><td>Translucent blue, one table with no background behind it</td></tr>
		</table>

		<table class="panel">
			<tr><td>A third table with no background of its own</td></tr>
		</table>

		<table class="panel">
			<tr><td class="tint">A translucent cell rather than a translucent table</td></tr>
		</table>

		<p>The dashed block below is kept together and does not fit on this page, so it moves whole to the
			next. Its red table used to leave its background behind at the foot of this page, where the table in
			front of it had left a place for backgrounds to land.</p>

		<?php for ($i = 0; $i < 8; $i++) { ?>
			<p>Filler line <?= $i + 1 ?> before the block that moves.</p>
		<?php } ?>

		<div class="kept">
			<p>A kept block that moves, with a red table inside it.</p>
			<table class="panel red">
				<?php for ($i = 0; $i < 4; $i++) { ?>
					<tr><td>Translucent red inside the moved block, row <?= $i + 1 ?></td></tr>
				<?php } ?>
			</table>
			<p>After the table, inside the block.</p>
		</div>

		<p>The blue table below is not kept together and breaks across the page; each part is painted
			once, the same shade.</p>

		<table class="panel blue">
			<?php for ($i = 0; $i < 36; $i++) { ?>
				<tr><td>Translucent blue across a page break, row <?= $i + 1 ?></td></tr>
			<?php } ?>
		</table>

		<p>After the table that broke across the page.</p>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->WriteHTML($html);
	}
}
