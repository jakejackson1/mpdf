<?php

namespace Mpdf\Fonts;

use Mpdf\HtmlRecordingMpdf;
use Mpdf\OtlDump;
use Mpdf\TTFontFile;

/**
 * Captures everything OtlDump reports about a font, plus every diagnostic PHP raised while it read it.
 *
 * OtlDump has no tests, and until #81 it was a second, independent parser of the same font tables as
 * TTFontFile - free to disagree with the one that renders. Collapsing it onto the shared parser needs
 * a witness, and this is it. The diagnostics are part of that witness on purpose: OtlDump raises
 * warnings on every font in the corpus that TTFontFile does not, and those are exactly the
 * disagreements the collapse is meant to resolve.
 */
class OtlDumpGoldenMaster
{

	const FIXTURE_DIR = __DIR__ . '/../../data/otldump';

	const FONT_DIR = __DIR__ . '/../../data/ttf';

	private $tmpDir;

	public function __construct($tmpDir = null)
	{
		$this->tmpDir = $tmpDir === null ? __DIR__ . '/../tmp/mpdf/otldump' : $tmpDir;
	}

	public function fonts()
	{
		$fonts = [];
		foreach (glob(self::FONT_DIR . '/*.ttf') as $file) {
			$name = basename($file, '.ttf');
			$fonts[$name] = [$name];
		}
		ksort($fonts);

		return $fonts;
	}

	public function fixtureFile($name)
	{
		return self::FIXTURE_DIR . '/' . $name . '.txt';
	}

	public function loadFixture($name)
	{
		return file_get_contents($this->fixtureFile($name));
	}

	/**
	 * @return string The report and the diagnostics raised producing it
	 */
	public function capture($name)
	{
		// TTFontFile.php and OtlDump.php both declare Mpdf\unicode_hex() behind function_exists, so a
		// diagnostic raised inside it is attributed to whichever file was loaded first. Load the one the
		// renderer always loads, so the fixture does not depend on test order. The collapse removes the
		// second declaration and with it the need for this.
		class_exists(TTFontFile::class);

		$diagnostics = [];
		set_error_handler(function ($number, $string, $file, $line) use (&$diagnostics) {
			$diagnostics[] = sprintf('%s @ %s:%d', $string, basename($file), $line);
			return true;
		});

		$mpdf = new HtmlRecordingMpdf(['mode' => 'utf-8', 'tempDir' => $this->tmpDir]);

		try {
			$dump = new OtlDump($mpdf);
			$dump->getMetrics(self::FONT_DIR . '/' . $name . '.ttf', $name, 0, false, false, false, 0xFF, 'summary');
			$report = implode("\n", $mpdf->recordedHtml);
		} catch (\Exception $e) {
			// The message carries the font's path as it was passed in, absolute and unresolved; a fixture
			// must carry nothing of the machine that made it
			$message = str_replace(realpath(__DIR__ . '/../../..') . '/', '', $e->getMessage());
			$report = sprintf('%s: %s', get_class($e), $message);
		}

		restore_error_handler();
		$mpdf->cleanup();

		$capture = $report . "\n";

		if ($diagnostics) {
			$capture .= "\n=== diagnostics raised while reading this font ===\n";
			foreach ($this->tally($diagnostics) as $line => $count) {
				$capture .= sprintf("%4dx %s\n", $count, $line);
			}
		}

		return $capture;
	}

	public function update($name)
	{
		if (!is_dir(self::FIXTURE_DIR)) {
			mkdir(self::FIXTURE_DIR, 0777, true);
		}

		$file = $this->fixtureFile($name);
		file_put_contents($file, $this->capture($name));

		return $file;
	}

	/**
	 * Counted rather than listed: the same warning fires once per glyph, and a count that moves is
	 * as informative as a list that would be thousands of identical lines
	 */
	private function tally($diagnostics)
	{
		$tally = [];
		foreach ($diagnostics as $line) {
			$tally[$line] = isset($tally[$line]) ? $tally[$line] + 1 : 1;
		}
		ksort($tally);

		return $tally;
	}
}
