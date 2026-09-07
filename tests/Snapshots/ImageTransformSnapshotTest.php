<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class ImageTransformSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'image-transform';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			table.grid { border-collapse: collapse; }
			table.grid td { width: 60mm; height: 36mm; padding: 0; text-align: center; vertical-align: middle; border: 0.2mm solid #c0c0c0; }

			img.sample { width: 30mm; }

			p.caption { margin: 0 0 6mm 0; color: #606060; }
		</style>

		<h1>mPDF</h1>
		<h2>transform on an image</h2>

		<p class="caption">Every cell holds the same picture. The middle column is drawn untransformed,
			so each row shows what the transform either side of it did to it.</p>

		<table class="grid">
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: scaleX(1.6)"></td>
				<td><img class="sample" src="img/bayeux2.jpg"></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: scaleY(1.6)"></td>
			</tr>
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: scaleX(0.5)"></td>
				<td><img class="sample" src="img/bayeux2.jpg"></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: scaleY(0.5)"></td>
			</tr>
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: scale(1.6)"></td>
				<td><img class="sample" src="img/bayeux2.jpg"></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: scale(1.6, 0.5)"></td>
			</tr>
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: translateX(8mm)"></td>
				<td><img class="sample" src="img/bayeux2.jpg"></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: translateY(8mm)"></td>
			</tr>
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: rotate(20deg)"></td>
				<td><img class="sample" src="img/bayeux2.jpg"></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: skewX(20deg)"></td>
			</tr>
		</table>
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->SetBasePath(__DIR__ . '/../data');

		$this->mpdf->WriteHTML($html);
	}
}
