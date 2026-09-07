<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class SvgClassSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'svg-class';
	}

	private function circle()
	{
		return '<circle cx="50" cy="50" r="40" fill="#f0d878" stroke="#14386b" stroke-width="4" />';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			.framed { border: 1mm solid #d02020; padding: 2mm; }
			.wide { width: 38mm; }
			.narrow { width: 20mm; }
			.spaced { margin: 0 0 0 40mm; }

			p.caption { margin: 5mm 0 2mm 0; color: #606060; }
		</style>

		<h1>mPDF</h1>
		<h2>class on an embedded SVG</h2>

		<p class="caption">An embedded SVG is written out to a file and drawn as an image. The class it
			was given comes with it, so these rules reach the picture.</p>

		<p class="caption">No class - drawn at the size the SVG asks for</p>
		<p><svg width="100" height="100" viewBox="0 0 100 100"><?= $this->circle() ?></svg></p>

		<p class="caption">class="framed" - a border and padding around it</p>
		<p><svg class="framed" width="100" height="100" viewBox="0 0 100 100"><?= $this->circle() ?></svg></p>

		<p class="caption">class="wide" - a width</p>
		<p><svg class="wide" width="100" height="100" viewBox="0 0 100 100"><?= $this->circle() ?></svg></p>

		<p class="caption">class='narrow' - a width, in a single-quoted attribute</p>
		<p><svg class='narrow' width="100" height="100" viewBox="0 0 100 100"><?= $this->circle() ?></svg></p>

		<p class="caption">class="narrow spaced framed" - three of them at once</p>
		<p><svg class="narrow spaced framed" width="100" height="100" viewBox="0 0 100 100"><?= $this->circle() ?></svg></p>
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->WriteHTML($html);
	}
}
