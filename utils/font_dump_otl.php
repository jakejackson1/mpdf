<?php

/**
 * Renders a readable report of a font's OpenType layout tables as a PDF.
 *
 *   php utils/font_dump_otl.php <family> [<style>] [<script> <language>]
 *
 *   php utils/font_dump_otl.php khmeros                  # what scripts and features the font offers
 *   php utils/font_dump_otl.php dejavusans '' latn DFLT  # every lookup of one script and language
 *
 * Also runs over the web, taking the same four values from the query string.
 *
 * Without a script and language it lists what the font offers, each script linking to its own detail
 * report. With them it walks every GSUB and GPOS lookup of that script, showing the glyphs each rule
 * matches and what it substitutes or moves.
 */

namespace Mpdf;

use Mpdf\Fonts\FontCache;

require_once __DIR__ . '/../vendor/autoload.php';

$cli = PHP_SAPI === 'cli';
$argument = function ($position, $name, $default = '') use ($cli) {
	global $argv;

	if ($cli) {
		return isset($argv[$position]) ? $argv[$position] : $default;
	}

	return isset($_REQUEST[$name]) ? $_REQUEST[$name] : $default;
};

$family = strtolower($argument(1, 'family'));
$style = strtoupper($argument(2, 'style'));
$script = $argument(3, 'script');
$language = $argument(4, 'lang');

if (!$family) {
	fwrite(STDERR, "Usage: php utils/font_dump_otl.php <family> [<style>] [<script> <language>]\n");
	exit(1);
}

if ($style === 'IB') {
	$style = 'BI';
}

// Script and language system tags are four bytes, space padded
$script = $script ? str_pad($script, 4, ' ') : '';
$language = $language ? str_pad($language, 4, ' ') : '';

$mpdf = new Mpdf();
$mpdf->simpleTables = true;

// Resolves the font file, generates its metrics cache if it is not already there, and leaves
// everything the dump needs in CurrentFont. Taking the file from there rather than resolving it again
// means the report is of the font the renderer would have used.
$mpdf->SetFont($family, $style);
$font = $mpdf->CurrentFont;

$features = [];
if ($script && $language) {
	foreach (['GSUBFeatures', 'GPOSFeatures'] as $table) {
		if (isset($font[$table][$script][$language]) && is_array($font[$table][$script][$language])) {
			foreach ($font[$table][$script][$language] as $tag => $unused) {
				$features[] = '"' . $tag . '" 0';
			}
		}
	}
}

// Every glyph in the report is rendered with the font's own features switched off, so that what is
// shown is the glyph the rule names rather than the glyph the shaper would have chosen
$featureSettings = implode(', ', $features);

$css = file_get_contents(__DIR__ . '/data/font_dump_otl.css');
$css = str_replace(['{{family}}', '{{featureSettings}}'], [$family, $featureSettings], $css);

$title = '<h1 style="text-align:center;">' . strtoupper($family . $style) . '</h1>';
if ($script && $language) {
	$title .= '<h2 style="text-align:center;">' . $script . ' ' . $language . '</h2>';
}

$mpdf->WriteHTML('<style>' . $css . '</style><body>' . $title);
$mpdf->debugfonts = false;

$dump = new OtlDump($mpdf, new FontCache(new Cache($mpdf->tempDir . '/ttfontdata')), 'win');

// The summary links every script and language system it lists to its own detail report, and only
// this script knows which terms name the font
$dump->detailReportQuery = ['family' => $family, 'style' => $style];

try {
	$dump->getMetrics(
		$font['ttffile'],
		$font['fontkey'],
		$font['TTCfontID'],
		$mpdf->debugfonts,
		in_array($family, $mpdf->BMPonly, true),
		$font['useOTL'],
		$script && $language ? 'detail' : 'summary',
		$script,
		$language
	);
} catch (MpdfException $e) {
	// Naming a script or language the font does not carry is the usual mistake, and the message says
	// what it does carry. A stack trace would bury that.
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}

$mpdf->Output();
