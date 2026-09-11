<?php

namespace Snapshots;

/**
 * A preface numbered in lower-case roman, a table of contents that continues that numbering, chapters that carry
 * the count on in arabic, and appendices that restart at A in letters, with an index at the back. Terms recur
 * across the sections, so an index entry lists a roman, an arabic and a lettered page side by side: the roman
 * ones are in front of the contents and do not move, the arabic ones move and keep counting, and the lettered
 * ones move with the reset that numbers them. Every contents line and index entry links to the page it names
 *
 * @group snapshot
 */
class IndexPageNumberStylesSnapshotTest extends Snapshot
{
	use IndexedPages;

	public function getId()
	{
		return 'index-page-number-styles';
	}

	public function generatePdf()
	{
		$preface = [['scope', 'method'], ['method', 'sources']];
		$chapters = [['Copper', ['copper', 'ore', 'smelting']], ['Tin', ['tin', 'ore', 'alloy']], ['Bronze', ['bronze', 'alloy', 'casting']]];
		$appendices = ['A' => ['Ores by region', ['ore', 'sources']], 'B' => ['Casting methods', ['casting', 'method']]];

		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.mpdf_toc_level_0 { margin-bottom: 1mm; }
		</style>

		<h1>mPDF</h1>
		<h2>Three ways of numbering the pages, in one index</h2>

		<p>The preface and the contents are numbered in lower-case roman. The chapters carry the count on in
			arabic, and the appendices restart at A. The same terms recur across all three, so the index at the back
			lists them side by side, each as the page it ends up on once the contents is moved into place.</p>

		<?php foreach ($preface as $i => $terms) { ?>
			<?php if ($i > 0) { ?>
				<pagebreak />
			<?php } ?>
			<?= $this->heading('Preface ' . ($i + 1)) ?>
			<p>This is preface page <?= $i + 1 ?>, on page {PAGENO}.</p>
			<?= $this->covers($terms) ?>
		<?php } ?>

		<tocpagebreak links="on" toc-preHTML="&lt;h2&gt;Contents&lt;/h2&gt;" toc-pagenumstyle="i" pagenumstyle="1" />

		<?php foreach ($chapters as $i => list($title, $terms)) { ?>
			<?php if ($i > 0) { ?>
				<pagebreak />
			<?php } ?>
			<?= $this->heading('Chapter ' . ($i + 1) . ': ' . $title) ?>
			<p>This is chapter <?= $i + 1 ?>, on page {PAGENO}.</p>
			<?= $this->covers($terms) ?>
		<?php } ?>

		<pagebreak pagenumstyle="A" resetpagenum="1" />
		<?php foreach ($appendices as $letter => list($title, $terms)) { ?>
			<?php if ($letter !== 'A') { ?>
				<pagebreak />
			<?php } ?>
			<?= $this->heading("Appendix $letter: $title") ?>
			<p>This is appendix <?= $letter ?>, on page {PAGENO}.</p>
			<?= $this->covers($terms) ?>
		<?php } ?>

		<pagebreak />
		<h2>Index</h2>
		<indexinsert usedivletters="on" links="on" />
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->SetHTMLFooter('<div style="text-align: center;">Page {PAGENO}</div>');
		$this->mpdf->AddPageByArray(['pagenumstyle' => 'i', 'resetpagenum' => 1]);
		$this->mpdf->WriteHTML($html);
	}
}
