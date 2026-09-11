<?php

namespace Snapshots;

/**
 * Renders the sequences that exercise GSUB Lookup Type 5 - a plain context, with no backtrack or lookahead
 * around it - in both of the formats that match one.
 *
 * Format 3 matches each input position against a Coverage table of its own. The Takri subset carries three
 * such subtables, counting 3/2, 3/2 and 2/1, and no subtable repeats a Coverage offset across its positions:
 * reading glyphCount and seqLookupCount the wrong way round, or matching every position against position 0's
 * Coverage, leaves every one of these sequences unsubstituted. The Saurashtra pair is the shape Takri lacks,
 * two positions each substituting onto the other. Format 2 matches each position against a class instead; the
 * music subset sorts five noteheads into five classes, so the same stem resolves five ways on context alone.
 *
 * @group snapshot
 */
class ContextualSubstitutionSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'contextualsubstitution';
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
		$this->mpdf = $this->createMpdf([
			'fontDir' => [__DIR__ . '/../data/ttf'],
			'fontdata' => [
				'takrisubset' => [
					'R' => 'NotoSansTakri-GSUB53-Subset.ttf',
					'useOTL' => 0xFF,
				],
				'saurashtrasubset' => [
					'R' => 'NotoSansSaurashtra-GSUB53-Subset.ttf',
					'useOTL' => 0xFF,
				],
				'musicsubset' => [
					'R' => 'NotoMusic-GSUB52-Subset.ttf',
					'useOTL' => 0xFF,
				],
			],
			'default_font' => 'takrisubset',
			'default_font_size' => 30,
		]);

		// Format 3, three positions: the first and last are substituted and the middle one only matched,
		// and a different letter at that middle position picks the other subtable
		$this->mpdf->WriteHTML('<div>&#x116AE;&#x1168A;&#x116AB; &#x116AE;&#x116A0;&#x116AB;</div>');

		// Format 3, two positions, against the same first glyph on its own
		$this->mpdf->WriteHTML('<div>&#x116AE;&#x1168A; &#x116AE;</div>');

		// Format 3, two positions substituting onto each other, against the pair in the order that is not
		// the context
		$this->mpdf->WriteHTML('<div style="font-family: saurashtrasubset">&#xA8B4;&#xA8C4; &#xA8C4;&#xA8B4;</div>');

		// Format 2, one class per position, and the stem with no note in front of it
		$this->mpdf->WriteHTML(
			'<div style="font-family: musicsubset">&#x1D148;&#x1D165; &#x1D144;&#x1D165; &#x1D146;&#x1D165;'
			. ' &#x1D143;&#x1D165; &#x1D1B9;&#x1D165; &#x1D165;</div>'
		);
	}
}
