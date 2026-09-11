<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class BackgroundSizeSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'background-size';
	}

	/**
	 * Generate a PDF document by initializing the Mpdf object on $this->mpdf and
	 * loading it with content
	 *
	 * @return   void
	 * @internal Don't call any $this->mpdf->Output*() method
	 */
	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			div.box {
				border: 0.2mm solid #000000;
				margin-bottom: 3mm;
				background-repeat: no-repeat;
				background-position: center center;
			}

			div.landscape { width: 70mm; height: 20mm; }
			div.portrait { width: 25mm; height: 45mm; }

			/* 292 x 83, so much wider than it is tall */
			div.wide { background-image: url('img/bayeux2.jpg'); }

			/* 100 x 116, so slightly taller than it is wide */
			div.tall { background-image: url('img/bg.jpg'); }

			div.cover { background-size: cover; }
			div.contain { background-size: contain; }
			div.stretched { background-size: 100% 100%; }
			div.narrow { background-size: 20mm auto; }
			div.short { background-size: auto 10mm; }
		</style>

		<h1>mPDF</h1>
		<h2>background-size</h2>

		<h3>cover</h3>
		<p>The image is scaled until it fills the box in both directions, keeping its aspect ratio, so
			whichever side runs out first is the one that ends up exactly the size of the box.</p>

		<div class="box landscape wide cover"></div>
		<div class="box landscape tall cover"></div>
		<div class="box portrait wide cover"></div>
		<div class="box portrait tall cover"></div>

		<h3>contain</h3>
		<p>The mirror image of cover: the image is scaled until it fits inside the box, so whichever side
			reaches the edge first is the one that ends up exactly the size of the box.</p>

		<div class="box landscape wide contain"></div>
		<div class="box landscape tall contain"></div>
		<div class="box portrait wide contain"></div>
		<div class="box portrait tall contain"></div>

		<h3>Lengths and percentages</h3>

		<div class="box landscape wide stretched"></div>
		<div class="box landscape tall narrow"></div>
		<div class="box portrait wide short"></div>

		<h3>In a footer</h3>
		<p>The footer scales its backgrounds through a second copy of the same code.</p>
		<?php
		$html = ob_get_clean();

		$box = 'border: 0.2mm solid #000000; margin-bottom: 2mm; background-repeat: no-repeat; background-size: cover;';

		$footer = '<div style="width: 30mm; height: 10mm; ' . $box . ' background-image: url(\'img/bayeux2.jpg\')"></div>'
			. '<div style="width: 12mm; height: 15mm; ' . $box . ' background-image: url(\'img/bg.jpg\')"></div>';

		$this->mpdf = $this->createMpdf(['margin_bottom' => 36, 'margin_footer' => 6]);
		$this->mpdf->SetBasePath(__DIR__ . '/../data');

		$this->mpdf->SetHTMLFooter($footer);
		$this->mpdf->WriteHTML($html);
	}
}
