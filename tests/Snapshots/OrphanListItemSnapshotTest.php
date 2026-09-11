<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class OrphanListItemSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'orphan-list-item';
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
			p.note { color: #606060; }
		</style>

		<h1>mPDF</h1>
		<h2>A list item with no list around it</h2>

		<p class="note">The lists come first, so what a marker is supposed to look like is on the page
			before the bare items below are asked to draw one of their own.</p>

		<h3>Items inside the list they belong to</h3>

		<ul><li>Inside a ul</li></ul>
		<ol><li>Inside an ol</li></ol>
		<ul style="list-style-type: square"><li>Inside a ul asking for a square</li></ul>

		<h3>The same items with nothing around them</h3>

		<p class="note">A bare item was never given a list style, a list image or a position, and it has
			to fall back on what a first level unordered list would have set. Nothing here resets the
			ordered counter either, which is why the decimal one carries on from the list above.</p>

		<li>Bare item, falling back on the marker a ul would have given it</li>
		<li style="list-style-type: decimal">Bare item asking for a decimal marker</li>
		<li style="list-style-type: square">Bare item asking for a square marker</li>
		<li style="list-style-type: none">Bare item turning its marker off</li>

		<h3>Several in a row</h3>

		<p class="note">Nothing opens or closes a list between these, so each one falls back on its own.</p>

		<li>First</li>
		<li>Second</li>
		<li>Third</li>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->WriteHTML($html);
	}
}
