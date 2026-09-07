<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class EmptyTextAreaSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'empty-textarea';
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
			td { border: 0.2mm solid #808080; padding: 2mm; vertical-align: top; }
			p.note { color: #606060; }
		</style>

		<h1>mPDF</h1>
		<h2>An empty textarea</h2>

		<p class="note">Every field below is a textarea. The ones on the left are empty and the ones on
			the right hold a word, and an empty one has to be drawn the same size as a filled one rather
			than leaving a gap where a field should be.</p>

		<h3>Opening the document</h3>

		<textarea name="opening" cols="20" rows="2"></textarea>

		<h3>Two in a row</h3>

		<textarea name="first" cols="20" rows="2"></textarea>
		<textarea name="second" cols="20" rows="2">second</textarea>

		<h3>In a table</h3>

		<table>
			<tr>
				<td><textarea name="cell1" cols="16" rows="2"></textarea></td>
				<td><textarea name="cell2" cols="16" rows="2">cell</textarea></td>
			</tr>
			<tr>
				<td>An empty one on its own in the cell</td>
				<td>One holding a word</td>
			</tr>
		</table>

		<h3>Inside a pre</h3>

		<p class="note">The space that draws the field is kept without putting the parser into pre mode,
			so the indentation around it is left alone.</p>

		<pre>  indented
  before<textarea name="pre" cols="10" rows="1"></textarea>after
  indented</pre>
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->WriteHTML($html);
	}
}
