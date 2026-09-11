<?php

/**
 * Rewrites the font parser golden master from the current code:
 *
 *   composer fontcache:update <font> [<font> ...]
 *   composer fontcache:update all
 *
 * The fixtures are everything TTFontFile hands to Otl - read tests/Mpdf/Fonts/ParserGoldenMaster.php
 * before trusting a diff. A change here means a change in what gets shaped, so it wants explaining in
 * the pull request that causes it.
 */

require __DIR__ . '/../vendor/autoload.php';

$master = new Mpdf\Fonts\ParserGoldenMaster();
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
	fwrite(STDERR, "Usage: composer fontcache:update <font> [<font> ...] | all\n\nThe fonts are:\n  " . implode("\n  ", $fonts) . "\n");
	exit(1);
}

foreach ($names as $name) {
	$file = $master->update($name);

	if ($file === null) {
		printf("%s: no OTL tables, no fixture\n", $name);
		continue;
	}

	printf("%s: %s written, %d bytes\n", $name, realpath($file), filesize($file));
}
