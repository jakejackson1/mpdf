<?php

namespace Snapshots;

/**
 * Floats and page-break-inside:avoid: a text column floated beside wrapped text in a block that moves whole;
 * floated images in a block that moves; a float still open when a block starts; a block a float makes taller
 * than a page, which breaks like any other; a floated block that is itself kept together; and a pair of
 * kept floated blocks with a border, a background, a picture and a float of their own. Before the fix
 * every float inside a moved block was written twice, the float opened before one was lost, and a floated
 * block split at the foot of the page whatever it asked for.
 *
 * @group snapshot
 */
class PageBreakAvoidFloatsSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'page-break-avoid-floats';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }

			div.together { page-break-inside: avoid; border: 0.3mm dashed #808080; padding: 3mm; margin-bottom: 4mm; }
			div.aside { float: left; width: 38%; background-color: #e8e8e8; padding: 2mm; margin: 0 4mm 2mm 0; }
			div.aside.right { float: right; margin: 0 0 2mm 4mm; }
			div.aside.kept { page-break-inside: avoid; border: 0.3mm dashed #808080; }
			div.aside.boxed { border: 0.6mm solid #204080; background-color: #dfe8f5; }
			div.aside.boxed img { float: left; width: 22mm; margin: 0 3mm 2mm 0; }
			div.aside.boxed div.inner { float: right; width: 40%; background-color: #ffffff; padding: 1mm; margin: 0 0 2mm 3mm; }
			div.clear { clear: both; }

			img.left { float: left; width: 45mm; margin: 0 4mm 2mm 0; }
			img.right { float: right; width: 45mm; margin: 0 0 2mm 4mm; }
		</style>

		<h1>mPDF</h1>
		<h2>Floats inside kept-together blocks</h2>

		<p>Each dashed block below is kept together. The first has a floated column of text beside wrapped
			text and does not fit on this page, so it moves whole; the wrapped text keeps its indent on the
			next page.</p>

		<?php for ($i = 0; $i < 24; $i++) { ?>
			<p>Filler line <?= $i + 1 ?>, here to push the first block over the page boundary.</p>
		<?php } ?>

		<div class="together">
			<div class="aside">
				<?php for ($i = 0; $i < 8; $i++) { ?>
					<p>Floated column, line <?= $i + 1 ?>.</p>
				<?php } ?>
			</div>
			<?php for ($i = 0; $i < 6; $i++) { ?>
				<p>Wrapped text beside the floated column, line <?= $i + 1 ?>. It starts to the right of the
					column and returns to the margin once the column ends.</p>
			<?php } ?>
			<div class="clear"></div>
			<p>After the column has been cleared, inside the same block.</p>
		</div>

		<?php for ($i = 0; $i < 10; $i++) { ?>
			<p>Filler line <?= $i + 1 ?>, before the block with the pictures.</p>
		<?php } ?>

		<div class="together">
			<img class="left" src="img/tiger.webp" />
			<img class="right" src="img/bayeux2.jpg" />
			<?php for ($i = 0; $i < 10; $i++) { ?>
				<p>Text between two floated pictures, line <?= $i + 1 ?>. The block moves whole to the next
					page and both pictures move with it, drawn once each.</p>
			<?php } ?>
			<div class="clear"></div>
		</div>

		<div class="aside right">
			<p>A column floated before the next block opens.</p>
			<p>It used to vanish with the measuring pass.</p>
		</div>

		<div class="together">
			<?php for ($i = 0; $i < 14; $i++) { ?>
				<p>A block that opens while the column above is still floating, line <?= $i + 1 ?>.</p>
			<?php } ?>
			<div class="clear"></div>
		</div>

		<div class="together">
			<div class="aside">
				<?php for ($i = 0; $i < 48; $i++) { ?>
					<p>A column taller than a page, line <?= $i + 1 ?>.</p>
				<?php } ?>
			</div>
			<p>This block cannot be kept together because its floated column is taller than a page, so it
				breaks like any other block. Nothing in it is written twice.</p>
			<div class="clear"></div>
			<p>The end of the tall block.</p>
		</div>

		<?php for ($i = 0; $i < 38; $i++) { ?>
			<p>Filler line <?= $i + 1 ?>, before the floated block that is itself kept together.</p>
		<?php } ?>

		<div class="aside kept">
			<?php for ($i = 0; $i < 8; $i++) { ?>
				<p>A floated block kept together, line <?= $i + 1 ?>.</p>
			<?php } ?>
		</div>
		<p>Text beside a floated block that does not fit on the page it starts on, so the block moves whole and
			this text moves with it.</p>
		<div class="clear"></div>

		<?php for ($i = 0; $i < 24; $i++) { ?>
			<p>Filler line <?= $i + 1 ?>, before the pair of kept floated blocks.</p>
		<?php } ?>

		<div class="aside kept boxed">
			<img src="img/tiger.webp" />
			<p>A kept floated block with a border, a background and a picture, line 1.</p>
			<p>Line 2.</p>
			<p>Line 3.</p>
			<div class="clear"></div>
			<p>Line 4, after the picture.</p>
		</div>
		<div class="aside right kept boxed">
			<div class="inner"><p>A float inside.</p></div>
			<p>A kept floated block on the right, with a float of its own, line 1.</p>
			<p>Line 2.</p>
			<p>Line 3.</p>
			<div class="clear"></div>
			<p>Line 4, after the inner float.</p>
		</div>
		<p>Text between two kept floated blocks that did not fit on the page they started on; both moved whole,
			with everything in them, and this text moved with them.</p>
		<div class="clear"></div>

		<p>After every block.</p>
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->SetBasePath(__DIR__ . '/../data');
		$this->mpdf->WriteHTML($html);
	}
}
