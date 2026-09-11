<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class ExifOrientationGreyscaleSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'exif-orientation-greyscale';
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
			table.samples { border-collapse: collapse; }
			table.samples td { padding: 3mm; border: 0.2mm solid #000000; text-align: center; vertical-align: top; }
			td.label { font-weight: bold; width: 30mm; }
		</style>

		<h1>mPDF</h1>
		<h2>Exif Orientation of a greyscale JPEG</h2>

		<p>The sample is a one-channel JPEG stored portrait, a gradient that runs from dark down its left side to
			light down its right, with the Exif <code>Orientation</code> tag saying it is a quarter turn clockwise
			from the right way up. GD decodes it to three channels and cannot write it back as one, so the corrected
			image is embedded as the grey samples GD decoded, deflated, rather than re-encoded as a colour JPEG.</p>

		<p>Both samples below have to be drawn landscape, twice as wide as they are tall, dark along the top and
			light along the bottom, in a neutral grey with no colour cast. The first is given a width and no height,
			and the second a height and no width, so the missing dimension comes off the corrected image both ways
			round.</p>

		<table class="samples">
			<tr>
				<td class="label">width: 40mm</td>
				<td><img src="img/exif-orientation-6-gray.jpg" style="width: 40mm"></td>
			</tr>
			<tr>
				<td class="label">height: 20mm</td>
				<td><img src="img/exif-orientation-6-gray.jpg" style="height: 20mm"></td>
			</tr>
		</table>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf(['useImageExifOrientation' => true]);
		$this->mpdf->SetBasePath(__DIR__ . '/../data');

		$this->mpdf->WriteHTML($html);
	}
}
