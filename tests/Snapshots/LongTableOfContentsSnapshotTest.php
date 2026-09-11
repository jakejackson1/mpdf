<?php

namespace Snapshots;

/**
 * A table of contents in front of the document that runs onto a second page, listing twelve chapters and their
 * sections, with an index at the back. The contents is written last and moved into place, shifting every page
 * after it by two; the page numbers in the body, in the contents and in the index agree, and every contents line
 * and index entry links to the page it names
 *
 * @group snapshot
 */
class LongTableOfContentsSnapshotTest extends Snapshot
{
	use IndexedPages;

	public function getId()
	{
		return 'long-table-of-contents';
	}

	public function generatePdf()
	{
		$chapters = ['Air', 'Bread', 'Clay', 'Dust', 'Earth', 'Fire', 'Glass', 'Honey', 'Ice', 'Jade', 'Kelp', 'Lime'];
		$sections = ['origin', 'form', 'use', 'trade', 'decay'];

		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			h3 { margin: 3mm 0 1mm 0; }
			div.mpdf_toc_level_0 { margin-top: 1mm; font-weight: bold; }
			div.mpdf_toc_level_1 { margin-left: 5mm; }
		</style>

		<h1>mPDF</h1>
		<h2>A contents that runs onto a second page</h2>

		<p>Twelve chapters of five sections each give the contents in front more lines than one page holds. It is
			written last and moved into place, so every page after it moves two along, and the index at the back
			lists each term on the page it ends up on.</p>

		<tocpagebreak links="on" toc-preHTML="&lt;h2&gt;Contents&lt;/h2&gt;" />

		<?php foreach ($chapters as $i => $chapter) { ?>
			<?php $name = strtolower($chapter); ?>
			<?= $this->heading('Chapter ' . ($i + 1) . ': ' . $chapter) ?>
			<p>This is chapter <?= $i + 1 ?>, on page {PAGENO}.</p>
			<?php foreach ($sections as $j => $section) { ?>
				<h3><tocentry content="<?= $i + 1 ?>.<?= $j + 1 ?> The <?= $section ?> of <?= $name ?>" level="1" />
					<?= $i + 1 ?>.<?= $j + 1 ?> The <?= $section ?> of <?= $name ?></h3>
				<p>A few words on the <?= $section ?> of <?= $name ?>.</p>
			<?php } ?>
			<?= $this->covers([$name, $sections[$i % 5]]) ?>
			<pagebreak />
		<?php } ?>

		<h2>Index</h2>
		<indexinsert usedivletters="on" links="on" />
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->SetHTMLFooter('<div style="text-align: center;">Page {PAGENO}</div>');
		$this->mpdf->WriteHTML($html);
	}
}
