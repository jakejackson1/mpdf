<?php

namespace Mpdf\PdfX;

use Mpdf\Mpdf;

/**
 * External PDF/X-4 preflight test. veraPDF ships NO PDF/X profile, so this cannot use
 * the veraPDF harness the UA-1 / PDF/A plans rely on. Instead it shells out to a real
 * PDF/X validator (e.g. callas pdfToolbox CLI, or Ghostscript with a PDF/X-4 check
 * profile) named by the PDFX_PREFLIGHT_BIN environment variable.
 *
 * The whole test is skipped when PDFX_PREFLIGHT_BIN is unset, and it lives in the
 * "pdfx-preflight" group which phpunit.xml excludes by default (mirroring the
 * "snapshot" group), so it never runs — or fails — in the standard CI job. Opt in with:
 *
 *     PDFX_PREFLIGHT_BIN=/path/to/validator vendor/bin/phpunit --group=pdfx-preflight
 *
 * @group pdfx-preflight
 */
class PdfX4PreflightTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var string
	 */
	private $tempDir;

	/**
	 * @var string|false Path to the external preflight binary, or false when unset.
	 */
	private $bin;

	protected function set_up()
	{
		$this->tempDir = __DIR__ . '/../../../tmp';
		$this->bin = getenv('PDFX_PREFLIGHT_BIN');
	}

	/**
	 * Render a representative X-4 document (transparency + layer + raster) to a file.
	 *
	 * @return string Absolute path to the written PDF.
	 */
	private function writeX4Document()
	{
		$pngPath = $this->tempDir . '/pdfx4_preflight_' . getmypid() . '.png';
		if (function_exists('imagecreatetruecolor')) {
			$im = imagecreatetruecolor(16, 16);
			imagesavealpha($im, true);
			imagealphablending($im, false);
			$col = imagecolorallocatealpha($im, 220, 40, 40, 63);
			imagefilledrectangle($im, 0, 0, 15, 15, $col);
			imagepng($im, $pngPath);
			imagedestroy($im);
		}

		$html = file_get_contents(__DIR__ . '/../../data/html/pdfx4-acceptance.html');
		$html = str_replace('__ALPHA_IMAGE__', is_file($pngPath) ? $pngPath : '', $html);

		$mpdf = new Mpdf(['PDFX' => '4', 'PDFXauto' => true, 'tempDir' => $this->tempDir]);
		$mpdf->WriteHtml($html);

		$pdfPath = $this->tempDir . '/pdfx4_preflight_' . getmypid() . '.pdf';
		$mpdf->Output($pdfPath, 'F');

		if (is_file($pngPath)) {
			@unlink($pngPath);
		}

		return $pdfPath;
	}

	public function testX4DocumentPassesExternalPreflight()
	{
		if (!$this->bin) {
			$this->markTestSkipped('PDFX_PREFLIGHT_BIN not set; skipping external PDF/X-4 preflight.');
		}

		$pdfPath = $this->writeX4Document();
		$this->assertFileExists($pdfPath);

		$cmd = escapeshellarg($this->bin) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
		$output = [];
		$exitCode = 0;
		exec($cmd, $output, $exitCode);
		$report = implode("\n", $output);

		@unlink($pdfPath);

		$this->assertSame(
			0,
			$exitCode,
			"External PDF/X-4 preflight reported a failure (exit $exitCode):\n" . $report
		);
	}

}
