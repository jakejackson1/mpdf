<?php

namespace Snapshots;

/**
 * Active form fields inside a kept-together block, on a block that stays and one that moves: a text field, a
 * password, a text area, a select, checkboxes, radio buttons and the submit and reset buttons, in a form of their
 * own. Each appears once, on the page its block ends up on.
 *
 * @group snapshot
 */
class PageBreakAvoidFormsSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'page-break-avoid-forms';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.kept { page-break-inside: avoid; border: 0.3mm dashed #808080; padding: 3mm; margin-bottom: 4mm; }
			table.fields { width: 100%; margin-bottom: 2mm; }
			table.fields td { padding: 1mm 2mm 1mm 0; vertical-align: top; }
			table.fields td.label { width: 30mm; font-weight: bold; }
			input, select, textarea { border: 0.2mm solid #404040; }
		</style>

		<h1>mPDF</h1>
		<h2>Form fields in a kept block</h2>

		<p>Two blocks below are kept together, each holding a form with every kind of field. The first fits
			where it is; the second does not and moves to the next page whole, and its fields with it. Every field
			appears once, on the page its block ends up on, with the value it was given.</p>

		<?php foreach (['stays' => 0, 'moves' => 8] as $label => $filler) { ?>
			<?php for ($i = 0; $i < $filler; $i++) { ?>
				<p>Filler line <?= $i + 1 ?> before the block that <?= $label ?>.</p>
			<?php } ?>

			<div class="kept">
				<p>This block <?= $label ?>. It is on page {PAGENO}.</p>
				<form action="https://example.com/<?= $label ?>" method="post">
					<table class="fields">
						<tr>
							<td class="label">Text</td>
							<td><input type="text" name="text-<?= $label ?>" value="In the block that <?= $label ?>" size="40" /></td>
						</tr>
						<tr>
							<td class="label">Password</td>
							<td><input type="password" name="password-<?= $label ?>" value="secret" size="20" /></td>
						</tr>
						<tr>
							<td class="label">Text area</td>
							<td><textarea name="area-<?= $label ?>" rows="3" cols="50">Three rows of text area, in the block that <?= $label ?>.</textarea></td>
						</tr>
						<tr>
							<td class="label">Select</td>
							<td>
								<select name="select-<?= $label ?>">
									<option value="1">First option</option>
									<option value="2" selected="selected">Second option, selected</option>
									<option value="3">Third option</option>
								</select>
							</td>
						</tr>
						<tr>
							<td class="label">Checkboxes</td>
							<td>
								<input type="checkbox" name="check-a-<?= $label ?>" value="a" checked="checked" /> Checked
								<input type="checkbox" name="check-b-<?= $label ?>" value="b" /> Not checked
							</td>
						</tr>
						<tr>
							<td class="label">Radio</td>
							<td>
								<input type="radio" name="radio-<?= $label ?>" value="yes" checked="checked" /> Yes
								<input type="radio" name="radio-<?= $label ?>" value="no" /> No
							</td>
						</tr>
						<tr>
							<td class="label">Buttons</td>
							<td>
								<input type="submit" name="submit-<?= $label ?>" value="Submit" />
								<input type="reset" name="reset-<?= $label ?>" value="Reset" />
							</td>
						</tr>
					</table>
				</form>
				<?php for ($i = 0; $i < 4; $i++) { ?>
					<p>Block text, line <?= $i + 1 ?>.</p>
				<?php } ?>
			</div>
		<?php } ?>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->useActiveForms = true;
		$this->mpdf->WriteHTML($html);
	}
}
