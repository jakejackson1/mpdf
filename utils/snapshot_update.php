<?php

/**
 * Rewrites the fixture of one or more snapshot tests from the current code:
 *
 *   composer snapshot:update <id> [<id> ...]
 *   composer snapshot:update all
 *
 * A fixture whose images go through GD (the exif-orientation and png-transparency documents) carries this machine's
 * GD, and every embedded font is deflated by its zlib; the rest carries nothing from here.
 */

require __DIR__ . '/../vendor/autoload.php';

$tests = [];
foreach (glob(__DIR__ . '/../tests/Snapshots/*SnapshotTest.php') as $file) {
	$class = 'Snapshots\\' . basename($file, '.php');
	$test = new $class('testSnapshot');
	$tests[$test->getId()] = $test;
}
ksort($tests);

$ids = array_slice($argv, 1);
if ($ids === ['all']) {
	$ids = array_keys($tests);
}

$unknown = array_diff($ids, array_keys($tests));
if ($unknown) {
	fwrite(STDERR, 'There is no snapshot called ' . implode(', ', $unknown) . ".\n\n");
}
if (!$ids || $unknown) {
	fwrite(STDERR, "Usage: composer snapshot:update <id> [<id> ...] | all\n\nThe snapshots are:\n  " . implode("\n  ", array_keys($tests)) . "\n");
	exit(1);
}

foreach ($ids as $id) {
	$file = $tests[$id]->updateSnapshot();
	printf("%s: %s written, %d bytes\n", $id, realpath($file), filesize($file));
}
