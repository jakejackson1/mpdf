<?php

namespace Mpdf\Ua;

use Mpdf\Output\Destination;

/**
 * Exercises the strict-vs-auto two-path contract for PDF/UA-1 mode guards.
 *
 * Strict mode (PDFUAauto=false) must throw MpdfException on a violation; auto
 * mode (PDFUAauto=true) must record a warning via getPdfUaWarnings() and
 * proceed without throwing.
 */
class PdfUaModeTest extends PdfUaTestCase
{

	/**
	 * OverWrite() cannot preserve the logical structure tree, so in strict
	 * PDF/UA-1 mode it must throw (audit E21).
	 */
	public function testOverWriteInStrictModeThrows()
	{
		$mpdf = $this->makeMpdf(['PDFUA' => true, 'PDFUAauto' => false]);

		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/PDF\/UA-1 mode/');

		// The strict guard fires before the input file is read, so the path
		// does not need to exist.
		$mpdf->OverWrite('nonexistent.pdf', 'foo', 'bar', Destination::STRING_RETURN);
	}

	/**
	 * In auto mode OverWrite() must warn that the accessibility guarantee is
	 * lost and still perform the overwrite rather than throwing (audit E21).
	 */
	public function testOverWriteInAutoModeWarnsAndReturns()
	{
		// Produce a valid PDF to overwrite.
		$source = $this->makeMpdf();
		$pdf = $this->getOutput($source, '<p>hello placeholder world</p>');

		$file = tempnam(sys_get_temp_dir(), 'mpdf_e21_') . '.pdf';
		file_put_contents($file, $pdf);

		try {
			$mpdf = $this->makeMpdf(['PDFUA' => true, 'PDFUAauto' => true]);
			$out = $mpdf->OverWrite($file, 'placeholder', 'replacement', Destination::STRING_RETURN);

			$this->assertNotEmpty($out, 'OverWrite() must still return PDF bytes in auto mode');
			$this->assertStringStartsWith('%PDF', $out);

			$warnings = $mpdf->getPdfUaWarnings();
			$this->assertNotEmpty($warnings, 'auto mode must record a PDF/UA warning');
			$joined = implode("\n", $warnings);
			$this->assertStringContainsString('OverWrite()', $joined);
			$this->assertStringContainsString('structure tree', $joined);
		} finally {
			@unlink($file);
		}
	}
}
