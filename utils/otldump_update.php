<?php

/**
 * Rewrites the OtlDump golden master from the current code:
 *
 *   composer otldump:update <font> [<font> ...]
 *   composer otldump:update all
 *
 * The fixtures are the dump's own report plus every diagnostic PHP raised producing it - read
 * tests/Mpdf/Fonts/OtlDumpGoldenMaster.php before trusting a diff.
 */

require __DIR__ . '/../vendor/autoload.php';

$master = new Mpdf\Fonts\OtlDumpGoldenMaster();
$fonts = array_keys($master->fonts());

$names = array_slice($argv, 1);
if ($names === ['all']) {
	$names = $fonts;
}

$unknown = array_diff($names, $fonts);
if ($unknown) {
	fwrite(STDERR, 'There is no font called ' . implode(', ', $unknown) . ".\n\n");
}
if (!$names || $unknown) {
	fwrite(STDERR, "Usage: composer otldump:update <font> [<font> ...] | all\n\nThe fonts are:\n  " . implode("\n  ", $fonts) . "\n");
	exit(1);
}

foreach ($names as $name) {
	$file = $master->update($name);
	printf("%s: %s written, %d bytes\n", $name, realpath($file), filesize($file));
}
