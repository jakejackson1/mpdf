<?php

namespace Snapshots;

/**
 * A kept-together block that stays on its page is laid out by its measuring pass alone, so that pass must lay it
 * out as the block would be without the property: two shapes rendered plain and then kept, which must match, and
 * a kept block over a table whose rows keep together, which moves whole.
 *
 * @group snapshot
 */
class PageBreakAvoidLayoutSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'page-break-avoid-layout';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			h3 { margin: 0 0 2mm 0; }
			div.kept { page-break-inside: avoid; }
			div.card { background-color: #ffe0a0; background-image: url('img/bg.jpg'); padding: 3mm; margin-bottom: 4mm; }
			div.card div.section { border: 0.3mm solid #404040; padding: 2mm; }
			div.card p { margin: 0; }
			h3.heading { background-color: #c8c8c8; padding: 1mm 2mm; }
			table.rows { border-collapse: collapse; width: 100%; margin-bottom: 4mm; }
			table.rows td { border: 0.2mm solid #404040; padding: 1.5mm; }
			div.tail { border: 0.3mm dashed #808080; padding: 3mm; }
			div.tail table.rows { margin: 0; }
		</style>

		<h1>mPDF</h1>
		<h2>What the measuring pass of a kept block lays out</h2>

		<p>A block kept together that fits where it is, is laid out by the pass that measured it. So that pass has to
			lay it out as the block would be without the property. Each shape below appears twice, plain and then
			kept, and the two must match.</p>

		<?php foreach (['plain' => '', 'kept' => 'kept'] as $label => $class) { ?>
			<div class="<?= $class ?>">
				<div class="card">
					<div class="section">
						<p>The <?= $label ?> card has a colour and a picture behind it. This section inside it has a border and no
							background of its own, so the picture shows through it.</p>
					</div>
				</div>
			</div>
		<?php } ?>

		<?php foreach (['plain' => '', 'kept' => 'kept'] as $label => $class) { ?>
			<div class="<?= $class ?>">
				<h3 class="heading">The <?= $label ?> heading, kept with its table</h3>
				<table class="rows">
					<tr><td>With keep-with-table on, the heading is held back until the table starts.</td></tr>
					<tr><td>Its background is painted with it then, by a different path from a plain heading's.</td></tr>
				</table>
			</div>
		<?php } ?>

		<?php for ($i = 0; $i < 2; $i++) { ?>
			<p>Filler line <?= $i + 1 ?>, so that the last block starts past the middle of the page.</p>
		<?php } ?>

		<div class="kept tail">
			<?php for ($i = 0; $i < 8; $i++) { ?>
				<p>Kept line <?= $i + 1 ?>. Every row of the table below keeps with the one before it, so the table cannot split.</p>
			<?php } ?>
			<table class="rows">
				<tr><td>Row 1</td></tr>
				<?php for ($i = 2; $i <= 24; $i++) { ?>
					<tr style="page-break-before: avoid"><td>Row <?= $i ?></td></tr>
				<?php } ?>
			</table>
		</div>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->SetBasePath(__DIR__ . '/../data');
		$this->mpdf->use_kwt = true;
		$this->mpdf->WriteHTML($html);
	}
}
