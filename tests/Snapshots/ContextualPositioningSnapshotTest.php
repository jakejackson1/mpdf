<?php

namespace Snapshots;

/**
 * Renders the sequences that exercise GPOS Lookup Types 7 and 8 - a context, and a context with a backtrack
 * and lookahead around it - each handing a position within the match to another lookup.
 *
 * Type 8 Format 1 lists the glyphs of every rule: Noto Sans Takri's `kern` reaches a pair adjustment that
 * way, pulling the I matra across the KA only when the anusvara closes the cluster. Type 7 Format 1 does the
 * same without the chain: Noto Sans Gurmukhi UI's `dist` nudges the AU matra + addak ligature where a TTA and
 * an EE matra follow it. Type 7 Format 3 matches each position against a Coverage table instead, and no font
 * of the 701 surveyed for GravityPDF/mpdf#80 has one, so its fixture is written by hand - a plain context
 * covering A then B, shifting the B 400 units left.
 *
 * Positioning moves glyphs rather than replacing them, so what changes here is the offsets between the
 * operands of each TJ, not the operands themselves.
 *
 * @group snapshot
 */
class ContextualPositioningSnapshotTest extends Snapshot
{
	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'contextualpositioning';
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
				'takrigpos' => [
					'R' => 'NotoSansTakri-GPOS81-Subset.ttf',
					'useOTL' => 0xFF,
				],
				'gurmukhigpos' => [
					'R' => 'NotoSansGurmukhiUI-GPOS71-Subset.ttf',
					'useOTL' => 0xFF,
				],
				'context73' => [
					'R' => 'NotoSans-GPOS73-Synthetic.ttf',
					'useOTL' => 0xFF,
				],
			],
			'default_font' => 'takrigpos',
			'default_font_size' => 30,
		]);

		// Type 8 Format 1: the cluster the chain matches, then the matra on its own, which it does not
		$this->mpdf->WriteHTML('<div>&#x116AE;&#x1168A;&#x116AB; &#x116AE;</div>');

		// Type 7 Format 1: the context, then the same letters without the matra it begins with
		$this->mpdf->WriteHTML(
			'<div style="font-family: gurmukhigpos">&#x0A2C;&#x0A4C;&#x0A71;&#x0A1F;&#x0A47;'
			. ' &#x0A2C;&#x0A1F;&#x0A47;</div>'
		);

		// Type 7 Format 3: both Bs move, and neither the B after a C nor the B before an A does
		$this->mpdf->WriteHTML('<div style="font-family: context73">ABAB CB BA</div>');
	}
}
