<?php

namespace Mpdf\Ua;

/**
 * Phase 5 hard-violation tests.
 *
 * Each violation has two paths governed by $PDFUAauto:
 *   - PDFUAauto=true   → mPDF auto-corrects and records a warning via
 *                        UaState::addWarning(). The document is still emitted
 *                        and remains PDF/UA-1 conformant.
 *   - PDFUAauto=false  → mPDF throws \Mpdf\MpdfException because the intent
 *                        cannot be guessed safely (e.g. is this image
 *                        decorative or a content image without alt text?).
 *
 * Spec references appear inline next to each test method.
 *
 * @group pdfua
 * @see PdfUaTestCase  base class supplying makeMpdf() and getOutput()
 */
class ValidationTest extends PdfUaTestCase
{

	// ========================= <img> alt attribute =========================

	/**
	 * ISO 14289-1:2014 §7.3 / Matterhorn 13-004 — every non-decorative image
	 * must carry /Alt. With PDFUAauto=true a missing alt is treated as
	 * decorative and a warning is recorded.
	 *
	 * @return void
	 */
	public function testImageMissingAltAddsWarning()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
		$this->getOutput($mpdf, '<p>Before <img src="' . $png . '" width="20" height="20"> after.</p>');

		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertNotEmpty($warnings, 'A warning must be recorded when alt is missing in PDFUAauto mode');

		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'missing alt') !== false || stripos($w, 'decorative') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Warning text must reference the missing alt / decorative treatment');
	}

	/**
	 * Same condition, strict mode — must throw because intent cannot be guessed.
	 *
	 * @return void
	 */
	public function testImageMissingAltThrowsWhenStrict()
	{
		$mpdf = $this->makeMpdf();
		// PDFUAauto defaults to false (strict).
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/missing the alt attribute/');
		$this->getOutput($mpdf, '<p>Before <img src="' . $png . '" width="20" height="20"> after.</p>');
	}

	/**
	 * Empty alt is the documented "decorative" marker — must NOT throw, and
	 * SHOULD NOT add a missing-alt warning.
	 *
	 * @return void
	 */
	public function testImageEmptyAltAcceptedAsDecorative()
	{
		$mpdf = $this->makeMpdf();
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
		$out = $this->getOutput($mpdf, '<p>Before <img src="' . $png . '" alt="" width="20" height="20"> after.</p>');
		$this->assertNotEmpty($out, 'Output must be produced when alt is explicitly empty');

		foreach ($mpdf->getPdfUaWarnings() as $w) {
			$this->assertStringNotContainsString(
				'missing alt',
				strtolower($w),
				'No missing-alt warning when alt="" is explicit'
			);
		}
	}

	// ========================= SetJS / <script> =========================

	/**
	 * ISO 14289-1:2014 §7.17 / Matterhorn 17-001 — document-level JavaScript
	 * is not permitted. PDFUAauto=true records a warning and silently drops
	 * the script.
	 *
	 * @return void
	 */
	public function testJavaScriptEmbedAddsWarning()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->SetJS('app.alert("hi");');
		// Output to flush the warning system; the JS itself must be skipped.
		$this->getOutput($mpdf, '<h1>Hello</h1>');

		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'javascript') !== false || stripos($w, 'SetJS') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'SetJS in PDFUAauto mode must record a warning');
	}

	/**
	 * Same condition, strict mode — must throw immediately when SetJS is called.
	 *
	 * @return void
	 */
	public function testJavaScriptEmbedThrowsWhenStrict()
	{
		$mpdf = $this->makeMpdf();

		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/SetJS|JavaScript/i');
		$mpdf->SetJS('app.alert("hi");');
	}

	// ========================= OverWrite() =========================

	/**
	 * OverWrite() does binary string replacement on a finished PDF. The
	 * structure tree references object numbers and byte offsets that the
	 * replacement cannot maintain, so PDF/UA-1 mode rejects the call
	 * unconditionally — neither strict nor auto mode can produce a
	 * conformant result.
	 *
	 * @return void
	 */
	public function testOverWriteThrowsInPdfuaMode()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->WriteHTML('<h1>Hello</h1>');

		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/OverWrite/');
		// File path does not need to exist — the PDFUA guard fires before
		// any I/O happens (see Mpdf::OverWrite).
		$mpdf->OverWrite('/tmp/does_not_matter.pdf', 'foo', 'bar', 'S', 'out');
	}

	// ========================= Heading sequence =========================

	/**
	 * ISO 14289-1:2014 §7.4.2 / Matterhorn 14-003 — heading sequence must
	 * not skip a level. PDFUAauto clamps and warns; strict mode throws.
	 *
	 * @return void
	 */
	public function testHeadingLevelSkipAddsWarningInAutoMode()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$this->getOutput($mpdf, '<h1>A</h1><h3>B</h3>');

		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'heading sequence') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Skipping H2 must record a heading-sequence warning');
	}

	/**
	 * Same condition, strict mode — must throw immediately.
	 *
	 * @return void
	 */
	public function testHeadingLevelSkipThrowsInStrictMode()
	{
		$mpdf = $this->makeMpdf();

		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/heading sequence/');
		$this->getOutput($mpdf, '<h1>A</h1><h3>B</h3>');
	}

	// ========================= /Lang catalog =========================

	/**
	 * ISO 14289-1:2014 §7.2 / Matterhorn 04-001 — /Lang must appear on the
	 * document catalog. When neither currentLang nor default_lang is set,
	 * PDFUAauto defaults to en-US with a warning; strict mode throws.
	 *
	 * @return void
	 */
	public function testMissingLangThrowsInStrictMode()
	{
		// Construct without 'mode' (the language) — bypass makeMpdf which
		// hard-codes mode='en-GB'. useActiveForms=true is required because
		// PDFUA on without it now throws at construction (see Mpdf::__construct).
		$mpdf = new \Mpdf\Mpdf(['PDFUA' => true, 'title' => 'Test', 'useActiveForms' => true]);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<h1>Hello</h1>');

		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/Lang/');
		$mpdf->Output('', 'S');
	}

	/**
	 * Same condition with PDFUAauto=true — defaults to en-US and warns.
	 *
	 * @return void
	 */
	public function testMissingLangFallsBackInAutoMode()
	{
		$mpdf = new \Mpdf\Mpdf(['PDFUA' => true, 'PDFUAauto' => true, 'title' => 'Test', 'useActiveForms' => true]);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<h1>Hello</h1>');
		$out = $mpdf->Output('', 'S');

		$this->assertStringContainsString('/Lang (en-US)', $out, 'PDFUAauto must fall back to en-US');

		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'lang') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'A /Lang fallback warning must be recorded');
	}

	// ========================= Encryption permission bit 10 =========================

	/**
	 * ISO 14289-1:2014 §7.6 / Matterhorn 07-001 — when encryption is
	 * applied, bit 10 ("extract text and graphics for accessibility") must
	 * remain set so AT can read the content. mPDF auto-corrects in
	 * PDFUAauto mode (the test below also verifies the correction sticks).
	 *
	 * @return void
	 */
	public function testEncryptionExtractBitPreservedInAutoMode()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// 'copy' and 'print' deliberately omit 'extract' — mPDF must add it
		// back automatically in PDFUAauto mode.
		$mpdf->SetProtection(['copy', 'print']);
		$out = $this->getOutput($mpdf, '<h1>Hello</h1>');

		$this->assertNotEmpty($out, 'Output must still be produced when permissions need correction');
		// Locate the encryption dict and read its /P field. Other /P keys in
		// the document refer to struct-element parents (`/P N 0 R`); the
		// encryption /P is always a bare integer next to /Filter /Standard.
		$found = preg_match('/\/Filter \/Standard.*?\/P (-?\d+)/s', $out, $m);
		$this->assertSame(1, $found, 'Encryption dict with /P must be present in the PDF');
		$p = (int) $m[1];
		// Bit 10 (decimal 512) is the "extract text and graphics for
		// accessibility" flag (ISO 32000-1 §7.6.3.2 Table 22). PDF stores
		// /P as a signed 32-bit integer; the bit check is unaffected by the
		// sign because PHP's bitwise AND uses the two's-complement value.
		$this->assertNotSame(0, $p & (1 << 9), '/P field must keep bit 10 set after PDFUAauto correction');
	}

	/**
	 * Strict mode rejects the SetProtection() call when 'extract' is
	 * missing because we cannot silently change a security setting the
	 * caller asked for.
	 *
	 * @return void
	 */
	public function testEncryptionExtractBitMissingThrowsInStrictMode()
	{
		$mpdf = $this->makeMpdf();

		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/extract/i');
		$mpdf->SetProtection(['copy', 'print']);
	}

	// ========================= Smoke tests =========================

	/**
	 * Render every PDF/UA-1 example fixture in PDFUAauto mode and assert that
	 * none of them throw a PHP exception during generation.
	 *
	 * This is a PHP-level smoke test only: PDFUAauto converts conformance
	 * violations to warnings, so the test passes even when an example would
	 * fail veraPDF — that gate is covered separately by VeraPdfConformanceTest.
	 *
	 * @return void
	 */
	public function testAllExamplesRenderWithoutExceptions()
	{
		$fixtureDir = __DIR__ . '/../../data/html/pdfua-examples';
		$fixtures = glob($fixtureDir . '/example*.html');
		$this->assertNotEmpty($fixtures, 'Fixture directory must contain example HTML files');

		foreach ($fixtures as $fixture) {
			$name = basename($fixture, '.html');
			try {
				// Each example gets its own Mpdf instance — state from one
				// example must not leak into the next.
				$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
				// example09_forms exercises AcroForm widget tagging; without
				// useActiveForms=true mPDF falls back to core fonts for the
				// widgets (Helvetica) which throws under PDFUA.
				if ($name === 'example09_forms') {
					$mpdf->useActiveForms = true;
				}
				$mpdf->WriteHTML(file_get_contents($fixture));
				$bytes = $mpdf->Output(null, 'S');
				$this->assertNotEmpty($bytes, $name . ' produced empty output');
			} catch (\Throwable $e) {
				$this->fail('Example "' . $name . '" threw ' . get_class($e) . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * Multiple violations in one document: each violation records its own
	 * warning entry, and the document still renders to non-empty output in
	 * PDFUAauto mode. This guards against warning-system state leaks between
	 * violation types (regression smoke for UaState::addWarning / getWarnings).
	 *
	 * @return void
	 */
	/**
	 * ISO 14289-1:2014 §7.21.4.1 / Matterhorn 09-006 — every font referenced
	 * for content (not just artifact) must provide a ToUnicode CMap so AT
	 * can recover the underlying character codes from glyph indices.
	 *
	 * @return void
	 */
	public function testFontSubsetToUnicodeCoverage()
	{
		$mpdf = $this->makeMpdf();
		$out = $this->getOutput($mpdf, '<h1>Hello</h1><p>Some text content.</p>');

		// Every /Type /Font dict in the output must reference a /ToUnicode
		// CMap. mPDF emits font dicts with /Subtype /TrueType, /Type0, or
		// /CIDFontType2 — only /Type0 wrappers and /TrueType base fonts need
		// /ToUnicode (CIDFontType2 is descendant inside /Type0 and shares
		// the wrapper's CMap), so we count `/Type /Font` dicts that are
		// /Subtype /Type0 or /Subtype /TrueType and assert each has
		// /ToUnicode.
		preg_match_all(
			'#<<[^<>]*?/Type\s*/Font\s*[^<>]*?/Subtype\s*/(?:Type0|TrueType)[^<>]*?>>#s',
			$out,
			$m
		);
		$this->assertNotEmpty($m[0], 'PDFUA output must contain at least one embedded font dict');
		foreach ($m[0] as $i => $dict) {
			$this->assertStringContainsString(
				'/ToUnicode',
				$dict,
				'Font dict #' . $i . ' must reference /ToUnicode CMap (Matterhorn 09-006)'
			);
		}
	}

	public function testMultipleViolationsAccumulateWarnings()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// Heading-level skip (H1 → H3) + image without alt + JS embed —
		// three independent violations chosen to exercise three distinct
		// addWarning() call sites.
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
		$mpdf->SetJS('app.alert("hi");');
		$bytes = $this->getOutput(
			$mpdf,
			'<h1>One</h1><h3>Three</h3>'
			. '<p>Image: <img src="' . $png . '" width="20" height="20"></p>'
		);

		$this->assertNotEmpty($bytes);

		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertGreaterThanOrEqual(
			3,
			count($warnings),
			'Each of the three violations should add at least one warning; got: ' . print_r($warnings, true)
		);
	}
}
