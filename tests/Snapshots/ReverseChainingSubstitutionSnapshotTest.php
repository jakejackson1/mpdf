<?php

namespace Snapshots;

/**
 * Renders the runs that exercise GSUB Lookup Type 8, the reverse chaining contextual single substitution -
 * the one lookup applied from the last glyph back to the first.
 *
 * Noto Sans Coptic decides the form of each supralinear stroke with three lookups: a Type 6 catches an
 * overline written straight after a capital, a second Type 6 carries that along the run through its
 * backtrack, and a Type 8 carries it the other way, so an overline whose following overline is already
 * `.cap` becomes `.cap` too. The first line below is the only one the Type 8 can reach, and is drawn with
 * the short overline of a lowercase letter unless the run is walked backwards.
 *
 * @group snapshot
 */
class ReverseChainingSubstitutionSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'reversechainingsubstitution';
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
				'copticsubset' => [
					'R' => 'NotoSansCoptic-GSUB81-Subset.ttf',
					'useOTL' => 0xFF,
				],
			],
			'default_font' => 'copticsubset',
			'default_font_size' => 30,
		]);

		// The capital comes second, so only the reverse pass can reach the first overline
		$this->mpdf->WriteHTML('<div>&#x03E3;&#x0305;&#x03E2;&#x0305;</div>');

		// The same pair the other way round, which the chaining lookups carry forward on their own
		$this->mpdf->WriteHTML('<div>&#x03E2;&#x0305;&#x03E3;&#x0305;</div>');

		// No capital to start the run, so no overline takes the taller form
		$this->mpdf->WriteHTML('<div>&#x03E3;&#x0305;&#x03E3;&#x0305;</div>');
	}
}
