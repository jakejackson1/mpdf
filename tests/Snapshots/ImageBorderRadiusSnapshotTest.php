<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class ImageBorderRadiusSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'image-border-radius';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			table.grid { border-collapse: collapse; }
			table.grid td { width: 60mm; height: 40mm; padding: 0; text-align: center; vertical-align: middle; border: 0.2mm solid #c0c0c0; }
			table.grid td p { margin: 3mm 0 0 0; font-size: 8pt; color: #606060; }
			table.ratios td { background-color: #ccc; }

			div.box { float: left; width: 50mm; margin: 0 4mm 6mm 0; padding: 3mm; text-align: center; }
			div.box p { margin: 3mm 0 0 0; font-size: 8pt; color: #606060; }

			img.sample { width: 30mm; }

			h2 { margin-top: 5mm; }
			p.caption { margin: 0 0 4mm 0; color: #606060; }
		</style>

		<h1>mPDF</h1>
		<h2>border-radius on an image</h2>

		<p class="caption">The picture is clipped to the curve, a background fills inside it and a border follows it.</p>

		<table class="grid">
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="border-radius: 4mm"><p>border-radius: 4mm</p></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="border-radius: 50%"><p>border-radius: 50%</p></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="border-radius: 8mm 0"><p>border-radius: 8mm 0</p></td>
			</tr>
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="border: 1mm solid #c00; border-radius: 5mm"><p>solid border</p></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="padding: 2mm; background-color: #ffd; border: 0.5mm solid #333; border-radius: 6mm"><p>padding and background</p></td>
				<td><img class="sample" src="img/bayeux2.jpg" style="border: 0.6mm dashed #00c; border-radius: 10mm / 4mm"><p>dashed, 10mm / 4mm</p></td>
			</tr>
			<tr>
				<td><img class="sample" src="img/bayeux2.jpg" style="transform: rotate(15deg); border-radius: 4mm"><p>transform: rotate(15deg)</p></td>
				<td><img src="img/bayeux2.jpg" rotate="90" style="height: 30mm; border-radius: 3mm"><p>rotate="90"</p></td>
				<td><img class="sample" src="img/demo.svg" style="border: 0.5mm solid #080; border-radius: 4mm"><p>svg with border</p></td>
			</tr>
		</table>

		<h2>Floated</h2>

		<p><img class="sample" src="img/bayeux2.jpg" style="float: left; margin: 1mm 4mm 2mm 0; border: 1mm solid #08c; border-radius: 6mm">
			<img class="sample" src="img/bayeux2.jpg" style="float: right; margin: 1mm 0 2mm 4mm; padding: 2mm; background-color: #fed; border-radius: 50%">
			Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore
			magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo
			consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.
			Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>

		<h2 style="page-break-before: always">In a container</h2>

		<div class="box" style="background-color: #dfe">
			<img class="sample" src="img/bayeux2.jpg" style="border-radius: 4mm">
			<p>solid background</p>
		</div>
		<div class="box" style="background-image: linear-gradient(#fdd, #ddf)">
			<img class="sample" src="img/bayeux2.jpg" style="border-radius: 4mm">
			<p>gradient background</p>
		</div>
		<div class="box" style="background-image: linear-gradient(#fdd, #ddf); border: 0.5mm solid #333; border-radius: 6mm">
			<img class="sample" src="img/bayeux2.jpg" style="border: 0.5mm solid #333; border-radius: 4mm">
			<p>rounded gradient container</p>
		</div>
		<div class="box" style="background-image: url(img/bg.jpg); border-radius: 5mm">
			<img class="sample" src="img/bayeux2.jpg" style="border-radius: 4mm">
			<p>rounded background image</p>
		</div>
		<div class="box" style="background-color: #ffe; padding: 2mm">
			<div style="background-image: url(img/bg.jpg); border-radius: 5mm; padding: 3mm">
				<img class="sample" src="img/bayeux2.jpg" style="border-radius: 4mm">
				<p>same, in a solid container</p>
			</div>
		</div>
		<div class="box" style="background-color: #eef; border-radius: 6mm">
			<div style="background-image: url(img/bg.jpg); border: 0.5mm solid #446; border-radius: 4mm; padding: 3mm">
				<img class="sample" src="img/bayeux2.jpg" style="border: 0.5mm solid #fff; border-radius: 3mm">
				<p>rounded within rounded</p>
			</div>
		</div>
		<div style="clear: both"></div>

		<h2>Other ratios</h2>

		<p class="caption">The first row draws each picture at its own ratio. The second forces a size nothing like it: a
			wide picture into a square, a square one into 16:9, and a 16:9 one into a portrait.</p>

		<table class="grid ratios">
			<tr>
				<td><img src="img/tiger.jpg" style="width: 30mm; border-radius: 50%"><p>1:1, border-radius: 50%</p></td>
				<td><img src="img/ratio-16x9.png" style="width: 48mm; border: 0.5mm solid #333; border-radius: 5mm"><p>16:9, bordered</p></td>
				<td><img src="img/ratio-9x16.png" style="height: 32mm; border-radius: 50%"><p>9:16, border-radius: 50%</p></td>
			</tr>
			<tr>
				<td><img src="img/bayeux2.jpg" style="width: 30mm; height: 30mm; border-radius: 50%"><p>7:2 forced square</p></td>
				<td><img src="img/tiger.jpg" style="width: 48mm; height: 27mm; border: 0.5mm solid #333; border-radius: 6mm"><p>1:1 forced 16:9, bordered</p></td>
				<td><img src="img/ratio-16x9.png" width="20mm" height="34mm" style="border: 0.5mm solid #333; border-radius: 4mm 12mm"><p>16:9 forced portrait</p></td>
			</tr>
		</table>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->SetBasePath(__DIR__ . '/../data');

		$this->mpdf->WriteHTML($html);
	}
}
