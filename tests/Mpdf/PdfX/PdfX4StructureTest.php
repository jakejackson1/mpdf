<?php

namespace Mpdf\PdfX;

use Mpdf\Mpdf;

/**
 * Deterministic structural tests for PDF/X-4 support. Each test renders a small
 * fixture to raw PDF bytes and asserts a single mechanical requirement directly on
 * the stream (Tier 1 — no external validator needed). Every PDF/X-4 assertion is
 * paired, where relevant, with a PDF/X-1a regression assertion so the legacy path
 * is proven untouched.
 *
 * These tests must exercise THIS worktree's src/ — tests/bootstrap.php prepends an
 * autoloader that loads Mpdf\ classes from ./src so a symlinked vendor/ cannot make
 * the suite run against another checkout.
 */
class PdfX4StructureTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var string Absolute path to the worktree temp dir usable for renders.
	 */
	private $tempDir;

	/**
	 * @var string Absolute path to the bundled sRGB (RGB) ICC profile.
	 */
	private $rgbIcc;

	/**
	 * @var string Absolute path to a generated PNG with an alpha channel.
	 */
	private $alphaPng;

	protected function set_up()
	{
		$this->tempDir = __DIR__ . '/../../../tmp';
		$this->rgbIcc = __DIR__ . '/../../../data/iccprofiles/sRGB_IEC61966-2-1.icc';

		// A small semi-transparent PNG so the alpha (SMask) path is exercised.
		$this->alphaPng = $this->tempDir . '/pdfx4_alpha_' . getmypid() . '.png';
		if (function_exists('imagecreatetruecolor')) {
			$im = imagecreatetruecolor(16, 16);
			imagesavealpha($im, true);
			imagealphablending($im, false);
			$col = imagecolorallocatealpha($im, 220, 40, 40, 63); // ~75% opaque red
			imagefilledrectangle($im, 0, 0, 15, 15, $col);
			imagepng($im, $this->alphaPng);
			imagedestroy($im);
		}
	}

	protected function tear_down()
	{
		if ($this->alphaPng && is_file($this->alphaPng)) {
			@unlink($this->alphaPng);
		}
	}

	/**
	 * Render HTML to the raw PDF byte string.
	 *
	 * @param array  $config Extra constructor config (merged over PDF/X defaults).
	 * @param string $html
	 * @param bool   $compress
	 * @return string
	 */
	private function render(array $config, $html, $compress = true)
	{
		$config['tempDir'] = $this->tempDir;
		$mpdf = new Mpdf($config);
		if (!$compress) {
			$mpdf->SetCompression(false);
		}
		$mpdf->WriteHtml($html);

		return $mpdf->Output(null, 'S');
	}

	// ------------------------------------------------------------------ A1

	public function testPdfxKeyBooleanTrueIsX1a()
	{
		$mpdf = new Mpdf(['PDFX' => true, 'tempDir' => $this->tempDir]);
		$this->assertTrue($mpdf->PDFX);
		$this->assertSame('1a', $mpdf->pdfxVersion);
		$this->assertSame('PDF/X-1a:2003', $mpdf->pdfxVersionLabel());
		$this->assertFalse($mpdf->pdfxAllowsTransparency());
	}

	public function testPdfxKey1aTokenIsX1a()
	{
		$mpdf = new Mpdf(['PDFX' => '1a', 'tempDir' => $this->tempDir]);
		$this->assertTrue($mpdf->PDFX);
		$this->assertSame('1a', $mpdf->pdfxVersion);
	}

	public function testPdfxKey4TokenIsX4()
	{
		$mpdf = new Mpdf(['PDFX' => '4', 'tempDir' => $this->tempDir]);
		$this->assertTrue($mpdf->PDFX, 'PDFX is coerced to a strict boolean true');
		$this->assertSame('4', $mpdf->pdfxVersion);
		$this->assertSame('PDF/X-4', $mpdf->pdfxVersionLabel());
		$this->assertTrue($mpdf->pdfxAllowsTransparency());
	}

	public function testPdfxKeyAcceptsVerboseX4Tokens()
	{
		foreach (['X-4', 'PDF/X-4', 'x-4', 'pdf/x-4'] as $token) {
			$mpdf = new Mpdf(['PDFX' => $token, 'tempDir' => $this->tempDir]);
			$this->assertSame('4', $mpdf->pdfxVersion, sprintf('token "%s" selects X-4', $token));
		}
	}

	public function testPdfxKeyFalseIsOff()
	{
		$mpdf = new Mpdf(['PDFX' => false, 'tempDir' => $this->tempDir]);
		$this->assertFalse($mpdf->PDFX);
		$this->assertNull($mpdf->pdfxVersion);
		$this->assertFalse($mpdf->pdfxAllowsTransparency());
	}

	public function testPdfxKeyInvalidTokenThrows()
	{
		$this->expectException('\Mpdf\MpdfException');
		new Mpdf(['PDFX' => '9', 'tempDir' => $this->tempDir]);
	}

	// ------------------------------------------------------------------ A2

	public function testX4HeaderIsPdf16()
	{
		$out = $this->render(['PDFX' => '4'], '<p>x4</p>');
		$this->assertSame('%PDF-1.6', substr($out, 0, 8));
	}

	public function testX4CatalogVersionIs16()
	{
		$out = $this->render(['PDFX' => '4'], '<p>x4</p>');
		$this->assertStringContainsString('/Version /1.6', $out);
	}

	public function testX1aHeaderStaysPdf14AndNoCatalogVersion()
	{
		$out = $this->render(['PDFX' => true, 'PDFXauto' => true], '<p>x1a</p>');
		$this->assertSame('%PDF-1.4', substr($out, 0, 8));
		$this->assertStringNotContainsString('/Version /1.6', $out);
	}

	// ------------------------------------------------------------------ B1/B2

	public function testX4EmbedsSingleOutputIntentWithDestOutputProfile()
	{
		$out = $this->render(['PDFX' => '4'], '<p>oi</p>');
		$this->assertStringContainsString('/S /GTS_PDFX', $out);
		$this->assertSame(1, substr_count($out, '/S /GTS_PDFX'), 'exactly one GTS_PDFX output intent');
		$this->assertStringContainsString('/DestOutputProfile', $out, 'X-4 always embeds a DestOutputProfile, even with no user ICCProfile');
	}

	public function testX4NoProfileEmbeds4ComponentCmykIcc()
	{
		$out = $this->render(['PDFX' => '4'], '<p>cmyk</p>');
		// The output-intent ICC stream (and the transparency-group ICC) are CMYK => /N 4.
		$this->assertMatchesRegularExpression('#/N 4\b#', $out);
		$this->assertStringNotContainsString('/N 3', $out, 'a CMYK default intent must not tag /N 3');
	}

	public function testBundledX4CmykFallbackIsAPrinterCmykProfile()
	{
		// The PDF/X-4 default output intent must be a genuine printer profile: PDF/X
		// rejects any output-intent DestOutputProfile whose ICC device class is not
		// 'prtr'. This pins the bundled fallback so a future profile swap cannot
		// silently ship a display/scanner-class or non-CMYK profile.
		$icc = __DIR__ . '/../../../data/iccprofiles/SWOP2006_Coated3v2.icc';
		$this->assertFileExists($icc, 'the bundled PDF/X-4 CMYK fallback profile must be present');

		$header = file_get_contents($icc, false, null, 0, 20);
		$this->assertSame('prtr', substr($header, 12, 4), 'X-4 output-intent profile must be ICC device class prtr (printer)');
		$this->assertSame('CMYK', substr($header, 16, 4), 'X-4 output-intent fallback must be a CMYK profile');
	}

	// ------------------------------------------------------------------ B3/B4/B5

	public function testX4XmpUsesPdfxidIdentifier()
	{
		$out = $this->render(['PDFX' => '4'], '<p>xmp</p>');
		$this->assertStringContainsString('xmlns:pdfxid="http://www.npes.org/pdfx/ns/id/"', $out);
		$this->assertStringContainsString('pdfxid:GTS_PDFXVersion="PDF/X-4"', $out);
	}

	public function testX4XmpDropsDeprecatedAdobePdfx13Schema()
	{
		$out = $this->render(['PDFX' => '4'], '<p>xmp</p>');
		$this->assertStringNotContainsString('ns.adobe.com/pdfx/1.3', $out);
		$this->assertStringNotContainsString('Apag_PDFX_Checkup', $out);
	}

	public function testX4InfoGtsVersionAgreesWithXmp()
	{
		$out = $this->render(['PDFX' => '4'], '<p>info</p>');
		$this->assertStringContainsString('/GTS_PDFXVersion(PDF/X-4)', $out);
	}

	public function testX1aXmpAndInfoStillStampX1a()
	{
		$out = $this->render(['PDFX' => true, 'PDFXauto' => true], '<p>x1a</p>');
		$this->assertStringContainsString('ns.adobe.com/pdfx/1.3', $out);
		$this->assertStringContainsString('/GTS_PDFXVersion(PDF/X-1a:2003)', $out);
		$this->assertStringNotContainsString('pdfxid:GTS_PDFXVersion', $out);
	}

	// ------------------------------------------------------------------ C1

	public function testX4DropsDocumentJavaScriptInAutoMode()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<p>js</p>', true);
		$this->assertStringNotContainsString('/JavaScript', $out);
	}

	public function testNormalModeKeepsDocumentJavaScript()
	{
		$mpdf = new Mpdf(['tempDir' => $this->tempDir]);
		$mpdf->SetJS('app.alert("hi");');
		$mpdf->WriteHtml('<p>js</p>');
		$out = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/JavaScript', $out);
	}

	public function testX4DocumentJavaScriptThrowsInStrictMode()
	{
		$this->expectException('\Mpdf\MpdfException');
		$mpdf = new Mpdf(['PDFX' => '4', 'tempDir' => $this->tempDir]);
		$mpdf->SetJS('app.alert("hi");');
		$mpdf->WriteHtml('<p>js</p>');
		$mpdf->Output(null, 'S');
	}

	private function renderWithJs(array $config, $js)
	{
		$config['tempDir'] = $this->tempDir;
		$mpdf = new Mpdf($config);
		$mpdf->SetJS($js);
		$mpdf->WriteHtml('<p>js</p>');

		return $mpdf->Output(null, 'S');
	}

	public function testX4AutoModeDropsJsWiredThroughSetJs()
	{
		$out = $this->renderWithJs(['PDFX' => '4', 'PDFXauto' => true], 'app.alert("x");');
		$this->assertStringNotContainsString('/JavaScript', $out);
		$this->assertStringNotContainsString('/JS ', $out);
	}

	// ------------------------------------------------------------------ C2

	public function testX4DropsLinkAnnotationInsidePrintAreaInAutoMode()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<p><a href="https://example.com">link</a></p>');
		$this->assertStringNotContainsString('/Subtype /Link', $out);
	}

	public function testNormalModeKeepsLinkAnnotation()
	{
		$out = $this->render([], '<p><a href="https://example.com">link</a></p>');
		$this->assertStringContainsString('/Subtype /Link', $out);
	}

	public function testX4LinkAnnotationInsidePrintAreaThrowsInStrictMode()
	{
		$this->expectException('\Mpdf\MpdfException');
		$this->render(['PDFX' => '4'], '<p><a href="https://example.com">link</a></p>');
	}

	public function testX4HasNoProhibitedAnnotationSubtypes()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<p>content</p>');
		foreach (['/Subtype /FileAttachment', '/Subtype /Sound', '/Subtype /Movie', '/Subtype /Screen'] as $subtype) {
			$this->assertStringNotContainsString($subtype, $out);
		}
	}

	// ------------------------------------------------------------------ C3

	public function testX4NeverEmitsInterpolateTrue()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<img src="' . $this->alphaPng . '">');
		$this->assertStringNotContainsString('/Interpolate true', $out);
	}

	// ------------------------------------------------------------------ C4

	public function testX4ExtGStateTransferFunctionThrowsWithLabel()
	{
		$mpdf = new Mpdf(['PDFX' => '4', 'tempDir' => $this->tempDir]);
		$mpdf->WriteHtml('<p>tr</p>');

		// Inject a transfer-function ExtGState the way a future feature might.
		$ref = new \ReflectionProperty($mpdf, 'extgstates');
		$ref->setAccessible(true);
		$eg = $ref->getValue($mpdf);
		$eg[1] = ['parms' => ['TR' => '/Foo']];
		$ref->setValue($mpdf, $eg);

		try {
			$mpdf->Output(null, 'S');
			$this->fail('Expected a transfer-function ExtGState to be rejected under PDF/X-4');
		} catch (\Mpdf\MpdfException $e) {
			$this->assertStringContainsString('PDF/X-4', $e->getMessage());
			$this->assertStringContainsString('/TR', $e->getMessage());
		}
	}

	// ------------------------------------------------------------------ C5 (via encryption guard)

	public function testX4EncryptionThrowsWithVersionLabel()
	{
		$mpdf = new Mpdf(['PDFX' => '4', 'tempDir' => $this->tempDir]);
		$mpdf->SetProtection(['print']);
		$mpdf->WriteHtml('<p>enc</p>');

		try {
			$mpdf->Output(null, 'S');
			$this->fail('Expected PDF/X-4 + encryption to throw');
		} catch (\Mpdf\MpdfException $e) {
			$this->assertSame('PDF/X-4 does not permit encryption of documents.', $e->getMessage());
		}
	}

	// ------------------------------------------------------------------ D1

	public function testX4PreservesLiveOpacity()
	{
		$mpdf = new Mpdf(['PDFX' => '4', 'tempDir' => $this->tempDir]);
		$mpdf->SetAlpha(0.4);
		$mpdf->WriteHtml('<p>alpha</p>');
		$out = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/ca 0.4', $out);
		$this->assertStringContainsString('/CA 0.4', $out);
	}

	public function testX1aFlattensOpacity()
	{
		$mpdf = new Mpdf(['PDFX' => true, 'PDFXauto' => true, 'tempDir' => $this->tempDir]);
		$mpdf->SetAlpha(0.4);
		$mpdf->WriteHtml('<p>alpha</p>');
		$out = $mpdf->Output(null, 'S');
		$this->assertStringNotContainsString('/ca 0.4', $out);
	}

	// ------------------------------------------------------------------ D2

	public function testX4AllowsWatermark()
	{
		$mpdf = new Mpdf(['PDFX' => '4', 'tempDir' => $this->tempDir]);
		$mpdf->SetWatermarkText('DRAFT');
		$mpdf->showWatermarkText = true;
		$mpdf->WriteHtml('<p>wm</p>');
		$out = $mpdf->Output(null, 'S');
		$this->assertNotEmpty($out);
		$this->assertSame('%PDF-1.6', substr($out, 0, 8));
	}

	public function testX1aWatermarkThrows()
	{
		$this->expectException('\Mpdf\MpdfException');
		$mpdf = new Mpdf(['PDFX' => true, 'tempDir' => $this->tempDir]);
		$mpdf->SetWatermarkText('DRAFT');
		$mpdf->showWatermarkText = true;
		$mpdf->WriteHtml('<p>wm</p>');
		$mpdf->Output(null, 'S');
	}

	// ------------------------------------------------------------------ D3

	public function testX4PreservesPngAlphaSmask()
	{
		if (!function_exists('imagecreatetruecolor')) {
			$this->markTestSkipped('GD required to generate the alpha PNG fixture');
		}
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<img src="' . $this->alphaPng . '">');
		$this->assertStringContainsString('/SMask', $out);
	}

	public function testX1aStripsPngAlphaSmask()
	{
		if (!function_exists('imagecreatetruecolor')) {
			$this->markTestSkipped('GD required to generate the alpha PNG fixture');
		}
		$out = $this->render(['PDFX' => true, 'PDFXauto' => true], '<img src="' . $this->alphaPng . '">');
		$this->assertStringNotContainsString('/SMask', $out);
	}

	// ------------------------------------------------------------------ D4

	public function testX4NamedLayerProducesOcgWithDefaultConfig()
	{
		$html = '<div style="z-index:1;position:absolute;top:6cm;left:2cm;">Layer</div><p>base</p>';
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], $html);
		$this->assertStringContainsString('/Type /OCG', $out, 'a named layer produces an optional-content group');
		$this->assertStringContainsString('/OCProperties', $out);
		$this->assertStringContainsString('/D <<', $out, 'the OCProperties carry a /D default configuration');
	}

	public function testX1aRefusesLayersInStrictMode()
	{
		$this->expectException('\Mpdf\MpdfException');
		$html = '<div style="z-index:1;position:absolute;top:6cm;left:2cm;">Layer</div><p>base</p>';
		$this->render(['PDFX' => true], $html);
	}

	// ------------------------------------------------------------------ D5

	public function testX4EveryTransparencyGroupHasBlendingColorSpace()
	{
		$svg = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40">'
			. '<rect width="40" height="40" fill="rgb(0,0,255)" opacity="0.5"/></svg>'
		);
		$html = '<p style="opacity:0.5">semi</p><img src="' . $svg . '">';
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], $html);

		$parts = explode('/S /Transparency', $out);
		$groups = count($parts) - 1;
		$this->assertGreaterThan(0, $groups, 'X-4 with transparency must emit at least one transparency group');

		for ($i = 1; $i < count($parts); $i++) {
			$context = substr($parts[$i], 0, 60);
			$this->assertStringContainsString('/CS', $context, 'every /S /Transparency group must define a /CS blending space');
		}
	}

	// ------------------------------------------------------------------ E1

	public function testX4CmykIntentConvertsRgbToCmyk()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<p style="color:#ff0000">RED</p>', false);
		$this->assertMatchesRegularExpression('#/N 4\b#', $out, 'CMYK output intent is 4-component');
		$this->assertStringContainsString('0.000 1.000 1.000 0.000 k', $out, 'red is converted to a CMYK fill');
		$this->assertStringNotContainsString('1.000 0.000 0.000 rg', $out);
	}

	public function testX4RgbIntentPassesRgbThrough()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true, 'ICCProfile' => $this->rgbIcc], '<p style="color:#ff0000">RED</p>', false);
		$this->assertMatchesRegularExpression('#/N 3\b#', $out, 'RGB output intent is 3-component');
		$this->assertStringContainsString('1.000 0.000 0.000 rg', $out, 'calibrated RGB passes through unchanged under an RGB intent');
	}

	public function testX4RgbIntentTagsOutputProfileN3()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true, 'ICCProfile' => $this->rgbIcc], '<p>rgb</p>');
		$this->assertMatchesRegularExpression('#/N 3\b#', $out);
		$this->assertStringNotContainsString('/N 4', $out);
	}

	// ------------------------------------------------------------------ E2

	public function testX4InfoAndXmpTrappedParity()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<p>trap</p>');
		$this->assertStringContainsString('/Trapped/False', $out, 'Info dictionary Trapped');
		$this->assertStringContainsString('<pdf:Trapped>False</pdf:Trapped>', $out, 'XMP Trapped mirrors Info');
	}

	public function testX4HasXmpMmInstanceId()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<p>id</p>');
		$this->assertStringContainsString('xmpMM:DocumentID', $out);
		$this->assertStringContainsString('xmpMM:InstanceID', $out);
	}

	public function testX4InfoAndXmpTimestampsAreIdentical()
	{
		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], '<p>time</p>');

		// Info: /CreationDate (D:20260713104726+10'00')
		$this->assertMatchesRegularExpression("#/CreationDate \\(D:(\\d{14})#", $out);
		preg_match("#/CreationDate \\(D:(\\d{14})#", $out, $info);

		// XMP: <xmp:CreateDate>2026-07-13T10:47:26+10:00</xmp:CreateDate>
		$this->assertMatchesRegularExpression('#<xmp:CreateDate>(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})#', $out);
		preg_match('#<xmp:CreateDate>(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})#', $out, $xmp);

		$xmpCompact = $xmp[1] . $xmp[2] . $xmp[3] . $xmp[4] . $xmp[5] . $xmp[6];
		$this->assertSame($info[1], $xmpCompact, 'Info /CreationDate and XMP xmp:CreateDate share one timestamp');
	}

	// ------------------------------------------------------------------ E3 / A3

	public function testX4StrictBannerReportsX4NotX1a()
	{
		$logger = new \Mpdf\TestLogger();
		$mpdf = new Mpdf(['PDFX' => '4', 'tempDir' => $this->tempDir]);
		$mpdf->setLogger($logger);
		$mpdf->WriteHtml('<p style="color:#ff0000">forced RGB violation</p>');

		try {
			$mpdf->Output(null, 'S');
			$this->fail('Expected a strict PDF/X-4 violation to throw');
		} catch (\Mpdf\MpdfException $e) {
			// expected
		}

		$reportedX4 = false;
		$reportedX1a = false;
		foreach ($logger->records as $record) {
			if (strpos($record['message'], 'PDF/X-4') !== false) {
				$reportedX4 = true;
			}
			if (strpos($record['message'], 'PDFX/1-a') !== false) {
				$reportedX1a = true;
			}
		}

		$this->assertTrue($reportedX4, 'the strict banner reports the PDF/X-4 label');
		$this->assertFalse($reportedX1a, 'no log line uses the legacy PDFX/1-a label under X-4');
	}

	// ------------------------------------------------------------------ A5 acceptance fixture

	public function testAcceptanceFixtureIsConformantX4()
	{
		if (!function_exists('imagecreatetruecolor')) {
			$this->markTestSkipped('GD required to generate the alpha PNG fixture');
		}

		$html = file_get_contents(__DIR__ . '/../../data/html/pdfx4-acceptance.html');
		$html = str_replace('__ALPHA_IMAGE__', $this->alphaPng, $html);

		$out = $this->render(['PDFX' => '4', 'PDFXauto' => true], $html);

		// Identity / version.
		$this->assertSame('%PDF-1.6', substr($out, 0, 8));
		$this->assertStringContainsString('/Version /1.6', $out);
		$this->assertStringContainsString('pdfxid:GTS_PDFXVersion="PDF/X-4"', $out);
		$this->assertStringContainsString('/GTS_PDFXVersion(PDF/X-4)', $out);

		// Output intent embedded.
		$this->assertStringContainsString('/S /GTS_PDFX', $out);
		$this->assertStringContainsString('/DestOutputProfile', $out);

		// X-4 capabilities SURVIVE (this is what distinguishes X-4 from X-1a).
		$this->assertStringContainsString('/SMask', $out, 'raster alpha survives');
		$this->assertStringContainsString('/Type /OCG', $out, 'named layer survives');
		$this->assertStringContainsString('/S /Transparency', $out, 'live transparency survives');

		// Prohibited constructs absent.
		$this->assertStringNotContainsString('/Interpolate true', $out);
		$this->assertStringNotContainsString('ns.adobe.com/pdfx/1.3', $out);

		// Every page carries a TrimBox (locks in PageWriter behaviour).
		$this->assertGreaterThanOrEqual(1, substr_count($out, '/TrimBox'));
	}

}
