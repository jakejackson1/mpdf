<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class ShadowSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'shadow';
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
				width: 50mm;
				height: 14mm;
				margin: 0 0 10mm 6mm;
				padding: 2mm;
				border: 0.2mm solid #000000;
				background-color: #ffffff;
			}

			/* A declaration may be wrapped over as many lines as it takes */
			div.wrapped {
				box-shadow: 6mm
					6mm
					3mm
					#0000ff;
			}

			p.shadowed {
				font-size: 20pt;
				margin: 0 0 8mm 6mm;
			}

			p.wrapped {
				text-shadow: 1.5mm
					1.5mm
					#0000ff;
			}
		</style>

		<h1>mPDF</h1>
		<h2>box-shadow</h2>

		<div class="box" style="box-shadow: 6mm 6mm rgba(255,0,0,0.5)">rgba() written without spaces</div>
		<div class="box" style="box-shadow: 6mm 6mm rgba(255, 0, 0, 0.5)">rgba() written with spaces</div>
		<div class="box wrapped">wrapped over several lines</div>
		<div class="box" style="box-shadow: 5% 3% 2mm #008000">offsets as percentages of the containing block</div>
		<div class="box" style="box-shadow: 4mm 4mm 2mm 3mm #000080">blur and spread</div>
		<div class="box" style="box-shadow: inset 4mm 4mm 3mm #333333">inset</div>
		<div class="box" style="box-shadow: 5mm 5mm rgb(255,0,0), -5mm -5mm rgb(0,0,255)">two shadows at once</div>

		<pagebreak />

		<h2>text-shadow</h2>

		<p class="shadowed" style="text-shadow: 1.5mm 1.5mm rgba(255,0,0,0.6)">rgba() without spaces</p>
		<p class="shadowed" style="text-shadow: 1.5mm 1.5mm rgba(255, 0, 0, 0.6)">rgba() with spaces</p>
		<p class="shadowed wrapped">wrapped over several lines</p>
		<p class="shadowed" style="text-shadow: 1.5mm 1.5mm 0.8mm #008000">blurred</p>
		<p class="shadowed" style="text-shadow: 1.5mm 1.5mm rgb(255,0,0), -1.5mm -1.5mm rgb(0,0,255)">two shadows at once</p>
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->WriteHTML($html);
	}
}
