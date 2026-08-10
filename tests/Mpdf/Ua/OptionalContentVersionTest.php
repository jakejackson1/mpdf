<?php

namespace Mpdf\Ua;

use Mpdf\Mpdf;

/**
 * Audit E22 — SetVisibility()/BeginLayer() must not emit optional content under
 * PDF/A or PDF/X, and must preserve the declared base version for PDF/A, PDF/X
 * and PDF/UA (they share a single optional-content guard).
 */
class OptionalContentVersionTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	public function testSetVisibilityUnderPdfaKeepsVersionAndSkipsOcg()
	{
		$mpdf = new Mpdf();
		$mpdf->PDFA = true;
		$mpdf->PDFAauto = true;
		$declaredVersion = $mpdf->pdf_version;

		$mpdf->SetVisibility('printonly');

		$this->assertSame($declaredVersion, $mpdf->pdf_version, 'PDF/A base version must not be bumped for an OCG');
		$this->assertEmpty($mpdf->hasOC, 'no optional content group may be emitted under PDF/A');
		$this->assertSame('visible', $mpdf->visibility);
		$this->assertContains('Cannot set visibility to anything other than full when using PDFA or PDFX', $mpdf->PDFAXwarnings);
	}

	public function testSetVisibilityUnderPdfxKeepsVersionAndSkipsOcg()
	{
		$mpdf = new Mpdf();
		$mpdf->PDFX = true;
		$mpdf->PDFXauto = true;
		$declaredVersion = $mpdf->pdf_version;

		$mpdf->SetVisibility('hidden');

		$this->assertSame($declaredVersion, $mpdf->pdf_version, 'PDF/X base version must not be bumped for an OCG');
		$this->assertEmpty($mpdf->hasOC, 'no optional content group may be emitted under PDF/X');
		$this->assertContains('Cannot set visibility to anything other than full when using PDFA or PDFX', $mpdf->PDFAXwarnings);
	}

	public function testSetVisibilityUnderPdfuaPreservesPdf17()
	{
		$mpdf = new Mpdf(['PDFUA' => true]);

		$mpdf->SetVisibility('printonly');

		$this->assertSame('1.7', $mpdf->pdf_version, 'PDF/UA-1 base version (1.7) must not be downgraded by an OCG');
	}

	public function testSetVisibilityOnPlainPdfBumpsToPdf15()
	{
		$mpdf = new Mpdf();

		$mpdf->SetVisibility('printonly');

		$this->assertSame('1.5', $mpdf->pdf_version, 'plain PDF must advertise 1.5 once an OCG is used');
	}

	public function testBeginLayerUnderPdfaKeepsVersion()
	{
		$mpdf = new Mpdf();
		$mpdf->PDFA = true;
		$mpdf->PDFAauto = true;
		$declaredVersion = $mpdf->pdf_version;

		$mpdf->BeginLayer(1);

		$this->assertSame($declaredVersion, $mpdf->pdf_version, 'PDF/A base version must not be bumped for a layer');
		$this->assertContains('Cannot use layers when using PDFA or PDFX', $mpdf->PDFAXwarnings);
	}

}
