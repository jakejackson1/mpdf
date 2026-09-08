<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class ExifOrientationSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'exif-orientation';
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
		$rows = [[1, 2], [3, 4], [5, 6], [7, 8]];

		ob_start();
		?>
		<style>
			table.samples { border-collapse: collapse; }
			table.samples td { padding: 3mm; border: 0.2mm solid #000000; text-align: center; }
			td.label { font-weight: bold; width: 14mm; }

			img.sample { width: 40mm; }
		</style>

		<h1>mPDF</h1>
		<h2>Exif Orientation</h2>

		<p>Every sample below holds the same picture, stored a different way up, with the Exif
			<code>Orientation</code> tag that says which. With <code>useImageExifOrientation</code> on, all
			eight have to be drawn the same way up as each other and as the browser draws them: twice as
			wide as they are tall, red top left, green top right, blue bottom right, yellow bottom left.</p>

		<p>The box each is drawn in has to turn with it. Every sample is given a width of 40mm and no
			height, so the height comes off the corrected image, and a sample that was not corrected would
			be drawn twice as tall as the others rather than half as tall.</p>

		<table class="samples">
			<?php foreach ($rows as $row) { ?>
				<tr>
					<?php foreach ($row as $orientation) { ?>
						<td class="label"><?php echo $orientation; ?></td>
						<td><img class="sample" src="img/exif-orientation-<?php echo $orientation; ?>.jpg"></td>
					<?php } ?>
				</tr>
			<?php } ?>
		</table>

		<h3>An image with no Exif at all</h3>
		<p>Nothing to correct, so it is embedded exactly as it was stored.</p>

		<table class="samples">
			<tr>
				<td class="label">none</td>
				<td><img class="sample" src="img/exif-orientation-none.jpg"></td>
			</tr>
		</table>
		<?php
		$html = ob_get_clean();

		/*
		 * These fixtures are four saturated flat colours meeting at hard edges, which is the worst case
		 * there is for the chroma subsampling any quality below 90 uses. The quality is raised here so the
		 * snapshot shows which way up the samples are rather than what JPEG does to a colour boundary.
		 */
		$this->mpdf = new \Mpdf\Mpdf(['useImageExifOrientation' => true, 'imageJpegQuality' => 95]);
		$this->mpdf->SetBasePath(__DIR__ . '/../data');

		$this->mpdf->WriteHTML($html);
	}
}
