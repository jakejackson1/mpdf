<?php

namespace Snapshots;

/**
 * The same two blocks with tables not allowed to shrink. A smaller table kept together then leaves the foot of the
 * first page blank, as it moves as soon as it does not fit the space left, and the tall block's table moves whole
 * to a page of its own instead of being shrunk onto the page its lines end on.
 *
 * @group snapshot
 */
class PageBreakAvoidEarlyBreakNoAutosizeSnapshotTest extends PageBreakAvoidEarlyBreakSnapshotTest
{
	public function getId()
	{
		return 'page-break-avoid-early-break-no-autosize';
	}

	/**
	 * Fewer rows than would be shrunk into the space left on page 1, were shrinking allowed
	 */
	protected function fittingRows()
	{
		return 16;
	}

	protected function config()
	{
		return ['shrink_tables_to_fit' => 1];
	}
}
