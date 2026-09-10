<?php

namespace Snapshots;

/**
 * A main table of contents in front of the document and a contents of its own in front of each of three parts,
 * with an index at the back. Every contents is written last and moved into place, each shifting the pages after
 * it once more; the page numbers in the body, in every contents and in the index agree, and every contents line
 * and index entry links to the page it names
 *
 * @group snapshot
 */
class MultipleTablesOfContentsSnapshotTest extends Snapshot
{
	use IndexedPages;

	public function getId()
	{
		return 'multiple-tables-of-contents';
	}

	public function generatePdf()
	{
		$parts = [
			'one' => ['Apple' => ['apple', 'orchard'], 'Banana' => ['banana', 'plantation'], 'Cherry' => ['cherry', 'orchard']],
			'two' => ['Dill' => ['dill', 'herb'], 'Endive' => ['endive', 'salad'], 'Fennel' => ['fennel', 'herb']],
			'three' => ['Garlic' => ['garlic', 'bulb'], 'Hazel' => ['hazel', 'orchard'], 'Iris' => ['iris', 'bulb']],
		];

		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.mpdf_toc_level_0 { margin-bottom: 1mm; }
		</style>

		<h1>mPDF</h1>
		<h2>A contents for the book and one for each part</h2>

		<p>The contents in front lists every chapter. Each of the three parts opens with a contents of its own that
			lists only that part's chapters. Every contents is written last and moved into place, so each shifts the
			pages after it, and the index at the back lists each term on the page it ends up on.</p>

		<tocpagebreak links="on" toc-preHTML="&lt;h2&gt;Contents&lt;/h2&gt;" />

		<?php $chapter = 0; ?>
		<?php foreach ($parts as $part => $chapters) { ?>
			<tocpagebreak name="part-<?= $part ?>" links="on" toc-preHTML="&lt;h2&gt;Part <?= $part ?>&lt;/h2&gt;" />
			<?php foreach ($chapters as $title => $terms) { ?>
				<?php $chapter++; ?>
				<?= $this->heading("Chapter $chapter: $title", ["part-$part"]) ?>
				<p>This is chapter <?= $chapter ?> of part <?= $part ?>, on page {PAGENO}.</p>
				<?= $this->covers($terms) ?>
				<pagebreak />
			<?php } ?>
		<?php } ?>

		<h2>Index</h2>
		<indexinsert usedivletters="on" links="on" />
		<?php
		$html = ob_get_clean();

		$this->mpdf = new \Mpdf\Mpdf();
		$this->mpdf->SetHTMLFooter('<div style="text-align: center;">Page {PAGENO}</div>');
		$this->mpdf->WriteHTML($html);
	}
}
