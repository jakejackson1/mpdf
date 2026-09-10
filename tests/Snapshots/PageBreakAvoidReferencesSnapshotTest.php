<?php

namespace Snapshots;

/**
 * What a kept-together block registers beyond its text, on a block that moves and on one that stays: page
 * numbers, links and anchors, bookmarks, contents and index entries, a form field, an annotation, a gradient, a
 * cell background image and a nested kept block. The table of contents in front is written last and moved into
 * place, which shifts every page after it by one; the contents and the index at the back each list a block's page
 * once, as the page it ends up on, and each contents line and index entry links to that page
 *
 * @group snapshot
 */
class PageBreakAvoidReferencesSnapshotTest extends Snapshot
{
	public function getId()
	{
		return 'page-break-avoid-references';
	}

	public function generatePdf()
	{
		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.kept { page-break-inside: avoid; border: 0.3mm dashed #808080; padding: 3mm; margin-bottom: 4mm; }
			div.gradient { background-image: linear-gradient(#f4c7c7, #c7d4f4); padding: 2mm; margin-bottom: 2mm; }
			table.cells { border-collapse: collapse; width: 100%; margin-bottom: 2mm; }
			table.cells td { border: 0.2mm solid #404040; padding: 2mm; }
			td.picture { background-image: url(img/bg.jpg); }
			div.nested { page-break-inside: avoid; background-color: #eeeeee; padding: 2mm; }
			input { border: 0.2mm solid #404040; }
		</style>

		<tocpagebreak links="on" />
		<h1>mPDF</h1>
		<h2>What a kept block carries with it</h2>

		<p>Two blocks below are kept together. The first fits where it is; the second does not and moves to the
			next page. Each carries the same things: a page number, a link, an anchor and a link to it, a bookmark,
			a contents entry, an index entry, a text field, a note, a gradient, a cell with a picture in its
			background, and a kept block inside it. Each is registered once, for the page the block ends up on, as
			the contents in front and the index at the back show.</p>

		<?php foreach (['stays' => 1, 'moves' => 7] as $label => $filler) { ?>
			<?php for ($i = 0; $i < $filler; $i++) { ?>
				<p>Filler line <?= $i + 1 ?> before the block that <?= $label ?>.</p>
			<?php } ?>
			<p><a href="#anchor-<?= $label ?>">To the anchor in the block that <?= $label ?></a></p>

			<div class="kept">
				<bookmark content="The block that <?= $label ?>" />
				<tocentry content="The block that <?= $label ?>" />
				<indexentry content="Block that <?= $label ?>" />
				<annotation content="A note on the block that <?= $label ?>" />
				<p>This block <?= $label ?>. It is on page {PAGENO}.</p>
				<p><a href="https://example.com/<?= $label ?>">A link</a> and
					<a name="anchor-<?= $label ?>">the anchor</a> the link above points at.</p>
				<p><input type="text" name="field-<?= $label ?>" value="A field" size="20" /></p>
				<div class="gradient">A gradient behind this line.</div>
				<table class="cells">
					<tr><td class="picture">A picture tiled behind this cell.</td><td>A plain cell.</td></tr>
				</table>
				<div class="nested">A kept block inside the kept block.</div>
				<?php for ($i = 0; $i < 6; $i++) { ?>
					<p>Block text, line <?= $i + 1 ?>.</p>
				<?php } ?>
			</div>
		<?php } ?>

		<pagebreak />
		<h2>Index</h2>
		<indexinsert links="on" />
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->useActiveForms = true;
		$this->mpdf->SetBasePath(__DIR__ . '/../data');
		$this->mpdf->WriteHTML($html);
	}

}
