<?php

namespace Snapshots;

/**
 * The content a kept-together block moves with it, on a block that stays and one that moves: ordered lists in
 * several styles and with a start, an unordered list with nested levels, a picture in the text, and a table with
 * a header row. Every list number and marker is what it would be had the block not moved, and every picture and
 * row appears once, on the page the block ends up on.
 *
 * @group snapshot
 */
class PageBreakAvoidContentSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'page-break-avoid-content';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.kept { page-break-inside: avoid; border: 0.3mm dashed #808080; padding: 3mm; margin-bottom: 4mm; }
			ol, ul { margin: 0 0 2mm 0; }
			img { width: 30mm; vertical-align: middle; margin-right: 3mm; }
			table.rows { border-collapse: collapse; width: 100%; margin-bottom: 2mm; }
			table.rows th { background-color: #dfe8f5; }
			table.rows th, table.rows td { border: 0.2mm solid #404040; padding: 1.5mm; text-align: left; }
		</style>

		<h1>mPDF</h1>
		<h2>What a kept block moves with it</h2>

		<p>Two blocks below are kept together. The first fits where it is; the second does not and moves to the
			next page whole. Each holds the same content: numbered lists in four styles, one of them starting at
			three, a bulleted list with nested levels, a picture in the running text, and a table with a header row.
			The numbers and markers are what they would be had the block not moved, and every picture and row
			appears once.</p>

		<?php foreach (['stays' => 0, 'moves' => 2] as $label => $filler) { ?>
			<?php for ($i = 0; $i < $filler; $i++) { ?>
				<p>Filler line <?= $i + 1 ?> before the block that <?= $label ?>.</p>
			<?php } ?>

			<div class="kept">
				<p>This block <?= $label ?>. It is on page {PAGENO}.</p>

				<ol start="3">
					<li>Decimal, starting at three</li>
					<li>Four</li>
					<li>Five</li>
				</ol>
				<ol style="list-style-type: lower-alpha">
					<li>Lower alpha</li>
					<li>Second</li>
					<li>Third</li>
				</ol>
				<ol style="list-style-type: upper-roman">
					<li>Upper roman</li>
					<li>Second</li>
					<li>Third</li>
				</ol>
				<ol style="list-style-type: lower-greek">
					<li>Lower greek</li>
					<li>Second</li>
					<li>Third</li>
				</ol>
				<ul>
					<li>Disc
						<ul style="list-style-type: circle">
							<li>Circle, one level in
								<ul style="list-style-type: square">
									<li>Square, two levels in</li>
								</ul>
							</li>
							<li>Circle again</li>
						</ul>
					</li>
					<li>Disc again</li>
				</ul>

				<p><img src="img/bayeux2.jpg" />A picture in the running text of the block that <?= $label ?>.</p>

				<table class="rows">
					<thead>
						<tr><th>Row</th><th>In the block that <?= $label ?></th></tr>
					</thead>
					<tbody>
						<tr><td>1</td><td>First row</td></tr>
						<tr><td>2</td><td>Second row</td></tr>
						<tr><td>3</td><td>Third row</td></tr>
					</tbody>
				</table>

				<p>Last line of the block that <?= $label ?>.</p>
			</div>
		<?php } ?>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->SetBasePath(__DIR__ . '/../data');
		$this->mpdf->WriteHTML($html);
	}
}
