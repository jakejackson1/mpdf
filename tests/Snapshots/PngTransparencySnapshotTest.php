<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class PngTransparencySnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'png-transparency';
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
			table.plates { border-collapse: collapse; margin-bottom: 6mm; }
			table.plates td { padding: 4mm; border: 0.2mm solid #000000; }

			td.dark { background-color: #14386b; }
			td.light { background-color: #f0d878; }
			td.white { background-color: #ffffff; }

			img.sample { width: 40mm; }
		</style>

		<h1>mPDF</h1>
		<h2>PNG tRNS transparency</h2>

		<p>Each sample is eight pixels square. The left half is the one colour the image's
			<code>tRNS</code> chunk names as transparent, and the right half is an opaque colour the
			other sample does not use. So over every plate the left half has to be the plate showing
			through, and the right half has to be the sample's own colour - grey for the greyscale
			image, blue for the truecolour one - whatever the plate behind it.</p>

		<h3>Greyscale, colour type 0</h3>
		<p>One transparent sample, black. The half that stays is mid grey.</p>

		<table class="plates">
			<tr>
				<td class="dark"><img class="sample" src="img/greyscale-trns.png"></td>
				<td class="light"><img class="sample" src="img/greyscale-trns.png"></td>
				<td class="white"><img class="sample" src="img/greyscale-trns.png"></td>
			</tr>
		</table>

		<h3>Truecolour, colour type 2</h3>
		<p>Three transparent samples, red. The half that stays is blue.</p>

		<table class="plates">
			<tr>
				<td class="dark"><img class="sample" src="img/truecolour-trns.png"></td>
				<td class="light"><img class="sample" src="img/truecolour-trns.png"></td>
				<td class="white"><img class="sample" src="img/truecolour-trns.png"></td>
			</tr>
		</table>
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->SetBasePath(__DIR__ . '/../data');

		$this->mpdf->WriteHTML($html);
	}
}
