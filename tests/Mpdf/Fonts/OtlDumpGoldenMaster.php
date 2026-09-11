<?php

namespace Mpdf\Fonts;

use Mpdf\Cache;
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

	/**
	 * How much of a detail report to keep verbatim before falling back to size and hash. The dump
	 * writes long single lines, so this has to be counted in bytes.
	 */
	const DETAIL_BYTES = 8192;

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
			// Attributed to the method, not the line: a fixture keyed on line numbers churns on every
			// edit above it and says nothing about what changed
			$frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
			$where = isset($frame[1]['function'])
				? (isset($frame[1]['class']) ? $frame[1]['class'] . '::' . $frame[1]['function'] : $frame[1]['function'])
				: basename($file) . ':' . $line;
			$diagnostics[] = sprintf('%s in %s', $string, $where);
			return true;
		});

		$mpdf = new HtmlRecordingMpdf(['mode' => 'utf-8', 'tempDir' => $this->tmpDir]);

		try {
			$dump = new OtlDump($mpdf, new FontCache(new Cache($this->tmpDir . '/cache')), 'win');
			$dump->getMetrics(self::FONT_DIR . '/' . $name . '.ttf', $name, 0, false, false, 0xFF, 'summary');
			$report = implode("\n", $mpdf->recordedHtml);

			// Then one script in detail, which is the other half of the tool and the half that walks
			// every lookup. The font's own script sorts last, after DFLT and the Latin fallbacks.
			$scripts = array_keys($dump->GSUBScriptLang);
			if ($scripts) {
				$script = end($scripts);
				$languages = explode(' ', trim($dump->GSUBScriptLang[$script]));
				$mpdf->recordedHtml = [];
				$detail = new OtlDump($mpdf, new FontCache(new Cache($this->tmpDir . '/cache')), 'win');
				$header = sprintf("\n\n=== detail: script %s language %s ===\n", trim($script), trim($languages[0]));

				// Detail mode could never run before #81 - it read Mpdf properties that do not exist -
				// so it is still reached for the first time here on some fonts. A font it cannot get
				// through is recorded as such rather than failing the capture, so that the fixtures say
				// where it stands and a later fix shows up as a diff.
				try {
					$detail->getMetrics(
						self::FONT_DIR . '/' . $name . '.ttf',
						$name,
						0,
						false,
						false,
						0xFF,
						'detail',
						$script,
						str_pad($languages[0], 4, ' ')
					);
					$report .= $header . $this->abbreviate(implode("\n", $mpdf->recordedHtml));
				} catch (\Throwable $detailError) {
					$report .= $header . sprintf(
						"cannot be dumped in detail: %s: %s\n",
						get_class($detailError),
						str_replace(realpath(__DIR__ . '/../../..') . '/', '', $detailError->getMessage())
					);
				}
			}
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
	 * Detail mode walks every rule of every lookup, which for a full text font runs to megabytes - 15.7
	 * of them for Noto Sans. Recorded by size, hash and opening, so that any change is still caught
	 * while the fixture stays something a person can read. Regenerate and run the utility to see what
	 * moved.
	 */
	private function abbreviate($report)
	{
		if (strlen($report) <= self::DETAIL_BYTES) {
			return $report;
		}

		return sprintf(
			"%d bytes, sha256 %s\nfirst %d bytes:\n%s\n",
			strlen($report),
			hash('sha256', $report),
			self::DETAIL_BYTES,
			substr($report, 0, self::DETAIL_BYTES)
		);
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
