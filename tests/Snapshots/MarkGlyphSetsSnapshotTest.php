<?php

namespace Snapshots;

/**
 * Renders the sequences that exercise GSUB lookups flagged UseMarkFilteringSet.
 *
 * The subset's mark filtering set is {U+0DCA, U+0DD2, U+0DD3}, and two ligature lookups are filtered by it:
 * U+0DBB with each of those three, and U+0DCF + U+0DCA. Getting the filtering wrong in either direction
 * silently drops those ligatures back to base + floating mark, which no other test would notice.
 *
 * @group snapshot
 */
class MarkGlyphSetsSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'markglyphsets';
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
		$this->mpdf = new \Mpdf\Mpdf([
			'fontDir' => [__DIR__ . '/../data/ttf'],
			'fontdata' => [
				'sinhalasubset' => [
					'R' => 'NotoSansSinhala-Subset.ttf',
					'useOTL' => 0xFF,
				],
			],
			'default_font' => 'sinhalasubset',
			'default_font_size' => 30,
		]);

		$this->mpdf->WriteHTML(
			'<div>&#x0DBB;&#x0DD2; &#x0DBB;&#x0DD3; &#x0DBB;&#x0DCA; &#x0DCF;&#x0DCA;</div>'
			. '<div>&#x0DC1;&#x0DCA;&#x200D;&#x0DBB;&#x0DD3; &#x0DBD;&#x0D82;&#x0D9A;&#x0DCF;</div>'
			. '<div>&#x0DC3;&#x0DD2;&#x0D82;&#x0DC4;&#x0DBD; &#x0D85;&#x0D9A;&#x0DD4;&#x0DBB;&#x0DD4;</div>'
		);
	}
}
