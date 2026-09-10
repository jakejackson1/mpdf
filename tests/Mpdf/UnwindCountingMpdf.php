<?php

namespace Mpdf;

/**
 * Counts how often a document is put back to a snapshot: once for each page-break-inside:avoid block that ran onto
 * another page, and not at all for one that stayed
 */
class UnwindCountingMpdf extends Mpdf
{
	public $unwinds = 0;

	public function restoreStateSnapshot(array $snapshot)
	{
		parent::restoreStateSnapshot($snapshot); // the count is part of the snapshot, so it is kept after the restore
		$this->unwinds++;
	}
}
