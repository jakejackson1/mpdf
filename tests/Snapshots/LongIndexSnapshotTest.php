<?php

namespace Snapshots;

/**
 * An index that runs onto a second page: a hundred and twenty terms in two columns under their first letters,
 * behind a table of contents in front. Each term is on one or two of the twelve pages, and each is listed on the
 * page it ends up on once the contents is moved into place, and every contents line and index entry links to the
 * page it names
 *
 * @group snapshot
 */
class LongIndexSnapshotTest extends Snapshot
{
	use IndexedPages;

	public function getId()
	{
		return 'long-index';
	}

	public function generatePdf()
	{
		$terms = [
			'abbey', 'acre', 'alley', 'anvil', 'arch', 'attic', 'awning', 'balcony', 'barn', 'basin',
			'beam', 'bell', 'bench', 'brick', 'bridge', 'buttress', 'cabin', 'canal', 'cellar', 'chapel',
			'chimney', 'cloister', 'column', 'cottage', 'crypt', 'dome', 'door', 'drain', 'dyke', 'eave',
			'facade', 'fence', 'ferry', 'forge', 'fountain', 'gable', 'garret', 'gate', 'granary', 'gutter',
			'hall', 'harbour', 'hearth', 'hedge', 'hinge', 'inn', 'jetty', 'joist', 'keep', 'kiln',
			'kitchen', 'ladder', 'lane', 'lantern', 'lattice', 'ledge', 'lintel', 'lock', 'loft', 'manor',
			'mantel', 'mill', 'moat', 'mortar', 'nave', 'niche', 'oast', 'orchard', 'oriel', 'pantry',
			'parapet', 'pavement', 'pier', 'plaster', 'porch', 'quay', 'rafter', 'rail', 'roof', 'sconce',
			'rivet', 'shed', 'shutter', 'sill', 'slate', 'spire', 'stable', 'stair', 'steeple', 'stile',
			'tannery', 'terrace', 'thatch', 'tile', 'tower', 'transom', 'trellis', 'turret', 'vault', 'veranda',
			'wall', 'ward', 'weir', 'well', 'wharf', 'wicket', 'window', 'yard', 'abutment', 'annexe',
			'apse', 'bastion', 'belfry', 'bollard', 'causeway', 'cistern', 'coping', 'cornice', 'dovecote', 'gantry',
		];

		// Ten terms to a page, and every fourth term on the next page as well
		$pages = [];
		foreach ($terms as $i => $term) {
			$page = (int) ($i / 10);
			$pages[$page][] = $term;
			if ($i % 4 === 0 && isset($terms[$i + 10])) {
				$pages[$page + 1][] = $term;
			}
		}

		ob_start();
		?>
		<style>
			p { margin: 0 0 2mm 0; }
			div.mpdf_toc_level_0 { margin-bottom: 1mm; }
		</style>

		<h1>mPDF</h1>
		<h2>An index that runs onto a second page</h2>

		<p>Twelve pages follow, each naming the ten terms it covers, and every fourth term is carried onto the next
			page too. That is more index lines than a page holds, even in two columns. Each page listed is the one
			the term ends up on once the contents in front is moved into place.</p>

		<tocpagebreak links="on" toc-preHTML="&lt;h2&gt;Contents&lt;/h2&gt;" />

		<?php foreach ($pages as $p => $covered) { ?>
			<?= $this->heading('Page ' . ($p + 1) . ' of twelve') ?>
			<p>This is page <?= $p + 1 ?> of twelve, on page {PAGENO}.</p>
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
