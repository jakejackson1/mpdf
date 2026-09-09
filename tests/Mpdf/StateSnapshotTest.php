<?php

namespace Mpdf;

/**
 * Mpdf::getStateSnapshot() and restoreStateSnapshot() carry the table of contents look-ahead and the measuring
 * pass of a page-break-inside:avoid block
 */
class StateSnapshotTest extends BaseMpdfTest
{

	/**
	 * CurrentFont is a reference into the font table. Restoring a snapshot taken in one font while another was
	 * selected must bind it to the restored selection: SetFont() does not while the family, style and size
	 * already match, and the glyphs Write() registered afterwards went to the other font's subset
	 */
	public function testRestoringASnapshotRebindsTheCurrentFont()
	{
		$this->mpdf = new Mpdf(['mode' => 'utf-8', 'default_font' => 'dejavusans']);
		$this->mpdf->WriteHTML('<p>Before</p>');
		$snapshot = $this->mpdf->getStateSnapshot();

		$this->mpdf->SetFont('dejavusans', 'B');
		$this->mpdf->restoreStateSnapshot($snapshot);
		$this->mpdf->Write(5, "\xCE\xA9");

		$this->assertSame('dejavusans', $this->mpdf->FontFamily . $this->mpdf->FontStyle);
		$this->assertArrayHasKey(937, $this->mpdf->fonts['dejavusans']['subset']);
	}
}
