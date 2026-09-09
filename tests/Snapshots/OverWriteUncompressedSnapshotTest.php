<?php

namespace Snapshots;

/**
 * The same letter written without compression and overwritten by an instance that compresses, so the
 * replaced streams have to stay uncompressed rather than be deflated the way the instance would write them
 *
 * @group snapshot
 */
class OverWriteUncompressedSnapshotTest extends OverWriteSnapshotTest
{
	public function getId()
	{
		return 'overwrite-uncompressed';
	}

	protected function sourceCompressed()
	{
		return false;
	}
}
