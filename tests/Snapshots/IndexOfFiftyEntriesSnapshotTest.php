<?php

namespace Snapshots;

/**
 * An index of fifty terms in two columns, grouped under their first letters, behind a table of contents in front.
 * Each term is on one, two or three of the ten pages, so entries list single pages, pairs and runs; each is the
 * page the term ends up on once the contents is moved into place, and every contents line and index entry links
 * to the page it names
 *
 * @group snapshot
 */
class IndexOfFiftyEntriesSnapshotTest extends Snapshot
{
	use IndexedPages;

	public function getId()
	{
		return 'index-of-fifty-entries';
	}

	public function generatePdf()
	{
		$terms = [
			'acorn', 'alder', 'ash', 'aspen', 'beech', 'birch', 'bramble', 'briar', 'cedar', 'cherry',
			'chestnut', 'cypress', 'dogwood', 'elder', 'elm', 'fir', 'gorse', 'hawthorn', 'hazel', 'heather',
			'holly', 'hornbeam', 'ivy', 'juniper', 'larch', 'laurel', 'lime', 'maple', 'moss', 'myrtle',
			'nettle', 'oak', 'olive', 'pine', 'plane', 'poplar', 'privet', 'reed', 'rowan', 'rush',
			'sedge', 'sloe', 'sorrel', 'spruce', 'sycamore', 'teasel', 'thistle', 'thorn', 'walnut', 'willow',
		];

		// Term $i is on page $i % 10, and every third term on the next one or two pages as well, the last few
		// wrapping round to the first pages
		$pages = [];
		foreach ($terms as $i => $term) {
			$span = 3 - $i % 3;
			for ($k = 0; $k < $span; $k++) {
				$pages[($i + $k) % 10][] = $term;
			}
		}

		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.mpdf_toc_level_0 { margin-bottom: 1mm; }
		</style>

		<h1>mPDF</h1>
		<h2>Fifty terms in two columns</h2>

		<p>Ten pages follow, each naming the terms it covers. Every term is on one, two or three of them, so the
			index at the back lists single pages, pairs and runs, in two columns under the first letters. Each page
			is the one the term ends up on once the contents in front is moved into place.</p>

		<tocpagebreak links="on" toc-preHTML="&lt;h2&gt;Contents&lt;/h2&gt;" />

		<?php foreach ($pages as $p => $covered) { ?>
			<?= $this->heading('Page ' . ($p + 1) . ' of ten') ?>
			<p>This is page <?= $p + 1 ?> of ten, on page {PAGENO}.</p>
			<?= $this->covers($covered) ?>
			<pagebreak />
		<?php } ?>

		<h2>Index</h2>
		<columns column-count="2" column-gap="5" />
		<indexinsert usedivletters="on" links="on" />
		<columns column-count="1" />
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf();
		$this->mpdf->SetHTMLFooter('<div style="text-align: center;">Page {PAGENO}</div>');
		$this->mpdf->WriteHTML($html);
	}
}
