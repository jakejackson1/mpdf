<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 javascript:/vbscript: URL handling in <a href>.
 *
 * Strict mode (PDFUAauto=false): rejects the document with MpdfException
 * citing Matterhorn 17-001 / 28-002.
 *
 * Auto mode (PDFUAauto=true): strips the link entirely (no Link annotation,
 * no Link struct element, no /URI action), preserves the visible inner text,
 * preserves ARIA / lang as a Span struct element, and emits a single
 * warning per offending anchor.
 *
 * Defence in depth: MetadataWriter::writeAnnotations() also drops a /URI
 * action whose URI matches the policy regex, in case a third-party caller
 * (or FPDI-imported Link) reaches that branch with such a URI.
 *
 * Spec references:
 *   - ISO 14289-1:2014 §7.18 — interactive elements need accessible alternatives.
 *   - ISO 32000-1:2008 §12.6.4.7 — URI actions.
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — Link /Alt fallback.
 *   - Matterhorn Protocol 1.1 condition 17-001 — document-level JavaScript prohibited
 *     (spirit applied to link-level URI actions with no fallback).
 *   - Matterhorn Protocol 1.1 condition 28-002 — Link annotation lacking text alternative.
 *   - WCAG 2.1 §2.1.1 — javascript: links are not keyboard-equivalent in PDF readers.
 *
 * @group pdfua
 */
class JavascriptUrlHandlingTest extends PdfUaTestCase
{

	public function testJavascriptUrlThrowsInStrictMode()
	{
		$mpdf = $this->makeMpdf();
		try {
			$this->getOutput($mpdf, '<p><a href="javascript:alert(1)">x</a></p>');
			$this->fail('Expected MpdfException for javascript: href in strict mode.');
		} catch (\Mpdf\MpdfException $e) {
			$msg = $e->getMessage();
			$this->assertStringContainsString('17-001', $msg);
			$this->assertStringContainsString('28-002', $msg);
			$this->assertStringContainsString('javascript:alert(1)', $msg);
		}
	}

	public function testCaseInsensitiveJavascriptDetectionStrict()
	{
		foreach (['JAVASCRIPT:foo()', 'JavaScript:bar()', 'jAvAsCrIpT:baz()'] as $href) {
			$mpdf = $this->makeMpdf();
			try {
				$this->getOutput($mpdf, '<p><a href="' . $href . '">x</a></p>');
				$this->fail('Expected MpdfException for ' . $href);
			} catch (\Mpdf\MpdfException $e) {
				$this->assertStringContainsString('17-001', $e->getMessage());
			}
		}
	}

	/**
	 * Whitespace-prefixed schemes are caught by the UaPolicy regex directly.
	 * (At the integration level, mPDF's HTML parser normalises hrefs through
	 * GetFullPath() — when a `basepath` like `http://localhost/` is in scope,
	 * a leading-space `javascript:` is rewritten to
	 * `http://localhost/ javascript:foo()`, which is no longer a
	 * policy-blocked scheme. The end-to-end behaviour therefore depends on
	 * the document's basepath, but the policy unit must catch leading
	 * whitespace whenever a raw href reaches it — e.g. via Mpdf::Link()
	 * direct calls or a third-party PageLinks injection path.)
	 */
	public function testWhitespacePrefixedJavascriptCaughtByPolicy()
	{
		$this->assertTrue(\Mpdf\Ua\UaPolicy::isPolicyBlockedHref(' javascript:foo()'));
		$this->assertTrue(\Mpdf\Ua\UaPolicy::isPolicyBlockedHref("\tjavascript:foo()"));
		$this->assertTrue(\Mpdf\Ua\UaPolicy::isPolicyBlockedHref("\rjavascript:foo()"));
		$this->assertTrue(\Mpdf\Ua\UaPolicy::isPolicyBlockedHref("\njavascript:foo()"));
		$this->assertTrue(\Mpdf\Ua\UaPolicy::isPolicyBlockedHref('  javascript:foo()'));
	}

	public function testPolicyRegexCoversCaseAndWhitespaceVariants()
	{
		// Direct policy unit tests — these are the load-bearing assertions
		// for the regex; integration tests below exercise the dispatch.
		$blocked = [
			'javascript:alert(1)',
			'JAVASCRIPT:foo()',
			'JavaScript:bar()',
			'jAvAsCrIpT:baz()',
			'vbscript:msgbox(1)',
			'VBSCRIPT:foo()',
			' javascript:foo()',
			"\tjavascript:foo()",
			'javascript :foo()',
		];
		foreach ($blocked as $href) {
			$this->assertTrue(
				\Mpdf\Ua\UaPolicy::isPolicyBlockedHref($href),
				'Expected policy block for: ' . $href
			);
		}

		$allowed = [
			null, '', '#', '#fragment',
			'http://example.com',
			'https://example.com',
			'mailto:foo@example.com',
			'tel:+1234567890',
			'data:text/plain;base64,AAAA',
			'ftp://example.com',
			'file:///etc/hosts',
			'/local/path',
			'./relative.html',
			'page.html',
		];
		foreach ($allowed as $href) {
			$this->assertFalse(
				\Mpdf\Ua\UaPolicy::isPolicyBlockedHref($href),
				'Expected policy pass for: ' . var_export($href, true)
			);
		}
	}

	public function testVbscriptUrlAlsoBlockedStrict()
	{
		$mpdf = $this->makeMpdf();
		try {
			$this->getOutput($mpdf, '<p><a href="vbscript:msgbox(1)">x</a></p>');
			$this->fail('Expected MpdfException for vbscript: href in strict mode.');
		} catch (\Mpdf\MpdfException $e) {
			$this->assertStringContainsString('vbscript:msgbox(1)', $e->getMessage());
		}
	}

	public function testJavascriptUrlStrippedInAutoMode()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p><a href="javascript:alert(1)">click here</a></p>');

		// No throw. Visible text "click here" reaches the PDF — fonts may
		// subset it as glyphs, but a TJ for the runs must exist; we assert
		// the absence of the URI bytes anywhere in the document instead, which
		// is the load-bearing privacy / safety property of the strip.
		$this->assertStringNotContainsString('javascript:alert(1)', $output);
		$this->assertStringNotContainsString('javascript:', $output);
		// No URI action emitted.
		$this->assertStringNotContainsString('/S /URI', $output);
		// No Link struct element for this anchor (the surrounding <p> still
		// produces a P struct element, but no /S /Link).
		$this->assertStringNotContainsString('/S /Link', $output);
	}

	public function testJavascriptUrlEmitsExactlyOneWarning()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$this->getOutput($mpdf, '<p><a href="javascript:alert(1)">x</a></p>');
		$warnings = $mpdf->getPdfUaWarnings();

		$matching = [];
		foreach ($warnings as $w) {
			if (stripos($w, 'javascript:alert(1)') !== false) {
				$matching[] = $w;
			}
		}
		$this->assertCount(1, $matching, 'Expected exactly one warning citing the offending href.');
		$this->assertStringContainsString('stripped', $matching[0]);
	}

	public function testCaseInsensitiveJavascriptDetectionAuto()
	{
		foreach (['JAVASCRIPT:foo()', 'JavaScript:bar()', 'jAvAsCrIpT:baz()'] as $href) {
			$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
			$output = $this->getOutput($mpdf, '<p><a href="' . $href . '">x</a></p>');
			$this->assertStringNotContainsString($href, $output, 'Mixed-case ' . $href . ' must not appear in PDF');
			$this->assertStringNotContainsString('/S /URI', $output);
		}
	}

	public function testWhitespacePrefixedJavascriptDetectionAuto()
	{
		// At the integration level, mPDF's GetFullPath() may absorb leading
		// whitespace (when a basepath is in scope) before our handler sees the
		// href. In a clean test environment with no $_SERVER['HTTP_HOST'] /
		// basepath, the whitespace survives — auto mode strips the link and
		// no /URI action is emitted. (Direct UaPolicy unit coverage is in
		// testWhitespacePrefixedJavascriptCaughtByPolicy.)
		$savedHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
		unset($_SERVER['HTTP_HOST']);
		try {
			$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
			$output = $this->getOutput($mpdf, '<p><a href=" javascript:foo()">x</a></p>');
			$this->assertStringNotContainsString('javascript:foo()', $output);
			$this->assertStringNotContainsString('/S /URI', $output);
		} finally {
			if ($savedHost !== null) {
				$_SERVER['HTTP_HOST'] = $savedHost;
			}
		}
	}

	public function testVbscriptUrlAlsoBlockedAuto()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p><a href="vbscript:msgbox(1)">x</a></p>');
		$this->assertStringNotContainsString('vbscript:msgbox(1)', $output);
		$this->assertStringNotContainsString('vbscript:', $output);
		$this->assertStringNotContainsString('/S /URI', $output);
	}

	public function testInlineSegmentedAnchorEmitsOneWarning()
	{
		// <a href="javascript:..."><b>bold</b> plain <i>italic</i></a>
		// should emit a single warning, drop the link, render all three
		// inline segments as plain text. Mpdf's tag dispatcher creates a NEW
		// instance per open/close, so the warning must come from open(); we
		// confirm exactly one warning is recorded.
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p><a href="javascript:foo()"><b>bold</b> plain <i>italic</i></a></p>';
		$output = $this->getOutput($mpdf, $html);

		$warnings = $mpdf->getPdfUaWarnings();
		$matching = 0;
		foreach ($warnings as $w) {
			if (stripos($w, 'javascript:foo()') !== false) {
				$matching++;
			}
		}
		$this->assertSame(1, $matching, 'Expected exactly one warning for one stripped anchor.');

		$this->assertStringNotContainsString('javascript:foo()', $output);
		$this->assertStringNotContainsString('/S /URI', $output);
		$this->assertStringNotContainsString('/S /Link', $output);
	}

	public function testStrippedAnchorPreservesAriaLabel()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="javascript:foo()" aria-label="Run">x</a></p>'
		);

		// No Link struct element.
		$this->assertStringNotContainsString('/S /Link', $output);
		// A Span struct element exists (with /Alt encoding "Run" in UTF-16BE).
		$this->assertStringContainsString('/S /Span', $output);
		// /Alt = "Run" → BOM + "R\0u\0n" → \xFE\xFF\x00R\x00u\x00n
		$this->assertStringContainsString("\xFE\xFF\x00R\x00u\x00n", $output);
	}

	public function testStrippedAnchorPreservesLang()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="javascript:foo()" lang="fr">bonjour</a></p>'
		);

		$this->assertStringNotContainsString('/S /Link', $output);
		$this->assertStringContainsString('/S /Span', $output);
		// /Lang values are emitted as UTF-16BE PDF text strings (with BOM).
		// "fr" → \xFE\xFF + \x00f\x00r.
		$utf16BeFr = "\xFE\xFF\x00f\x00r";
		$this->assertStringContainsString($utf16BeFr, $output);
	}

	public function testStrippedAnchorWithoutAriaProducesNoExtraSpan()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput(
			$mpdf,
			'<p>Before <a href="javascript:foo()">plain</a> after.</p>'
		);

		// No Link, no extra Span — text flows inline under the parent P.
		$this->assertStringNotContainsString('/S /Link', $output);
		// A Span with /Alt or /Lang would appear in the structure tree; this
		// anchor has neither, so no Span struct is emitted.
		$this->assertStringNotContainsString('/S /Span', $output);
	}

	public function testMailtoUrlNotBlocked()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="mailto:foo@example.com">contact</a></p>'
		);
		$this->assertStringContainsString('/S /Link', $output);
		$this->assertStringContainsString('/S /URI', $output);
		// The actual URI bytes appear in the /URI action.
		$this->assertStringContainsString('mailto:foo@example.com', $output);
	}

	public function testTelUrlNotBlocked()
	{
		// `tel:` is permitted by the policy. mPDF's pre-existing href dispatch
		// at Mpdf.php:17147 routes "no-dot" hrefs through the internal-link
		// path (treating them as named anchors), so the resulting annotation
		// is an internal Dest rather than a /URI action — but the salient
		// property under test is "no throw, Link struct still produced, no
		// policy strip". The legacy routing decision is out of scope.
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="tel:+1234567890">call</a></p>'
		);
		$this->assertStringContainsString('/S /Link', $output);
		// Confirm no policy-blocked-scheme warning was recorded.
		foreach ($mpdf->getPdfUaWarnings() as $w) {
			$this->assertStringNotContainsString('tel:', $w);
		}
	}

	public function testHttpsUrlUnchanged()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="https://example.com">ok</a></p>'
		);
		$this->assertStringContainsString('/S /Link', $output);
		$this->assertStringContainsString('/S /URI', $output);
		$this->assertStringContainsString('https://example.com', $output);
	}

	public function testDataUriNotBlocked()
	{
		// data: URIs are explicitly out of scope of the policy — they must NOT
		// trigger the strict-mode throw or the auto-mode strip. Document
		// generation must succeed and no PDF/UA warning must be recorded for
		// the data: URL.
		//
		// (mPDF's pre-existing href dispatch at Mpdf.php:17147 may route this
		// href through the internal-link path because the literal contains
		// no `.`; the routing decision is unrelated to the policy.)
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="data:text/plain;base64,SGVsbG8=">embedded</a></p>'
		);
		$this->assertStringContainsString('/S /Link', $output);
		foreach ($mpdf->getPdfUaWarnings() as $w) {
			$this->assertStringNotContainsString('data:', $w);
		}
	}

	public function testInternalAnchorNotBlocked()
	{
		// `#fragment` is internal navigation, never a URI action.
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="#section">jump</a></p>'
		);
		// Internal anchors do NOT emit /S /URI; they emit /Dest navigation.
		// We just assert the document generates and a Link struct element is
		// produced. (No throw is the assertion.)
		$this->assertStringContainsString('/S /Link', $output);
	}

	public function testAnchorWithoutHrefStillFollowsExistingPath()
	{
		// <a name="anchor"> takes the bookmark path, no Link involvement.
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<p>Before <a name="anchor">marker</a> after.</p>'
		);
		// No struct element of type Link or Span is emitted by Tag/A for
		// the bookmark path.
		$this->assertStringNotContainsString('/S /Link', $output);
	}

	public function testThirdPartyJavascriptUriCaughtByAnnotationGuard()
	{
		// Simulate a third-party caller (or imported PDF) injecting a Link
		// directly into Mpdf::Link() with a javascript: URI. Tag\A::open()
		// is bypassed so the strict-throw / auto-strip does NOT run. The
		// MetadataWriter guard must catch it.
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// Render normal content first so a page exists.
		$mpdf->WriteHTML('<p>preface</p>');
		// Inject a Link annotation with a policy-blocked URI directly.
		$mpdf->Link(10, 10, 100, 20, 'javascript:thirdParty(1)');
		$output = $mpdf->Output(null, 'S');

		// The /URI action must be stripped from the output bytes.
		$this->assertStringNotContainsString('javascript:thirdParty(1)', $output);
		$this->assertStringNotContainsString('/S /URI', $output);
		// A warning must be recorded by the writer-side guard.
		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'javascript:thirdParty(1)') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'MetadataWriter guard must record a warning for direct Link() injection.');
	}

	public function testJavascriptUrlUnchangedWhenPdfuaOff()
	{
		// Direct construction (no PDFUA), assert the legacy behaviour:
		// document generation must NOT throw and no PDF/UA-1 warning must
		// be recorded. (mPDF's pre-existing href dispatch at Mpdf.php:17147
		// routes hrefs without a dot through the internal-link path, so the
		// raw `javascript:alert(1)` literal does not survive into the PDF
		// output even without our filter — that legacy quirk is unrelated
		// to this feature. The load-bearing assertion is "no throw + no
		// PDFUA warning" since PDFUA is off.)
		$mpdf = new \Mpdf\Mpdf(['mode' => 'en-GB']);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<p><a href="javascript:alert(1)">x</a></p>');
		$output = $mpdf->Output(null, 'S');

		$this->assertNotEmpty($output);
		// PDF/UA warnings list belongs to the UaState — even when PDFUA is
		// off the accessor returns an empty array. Assert no policy warning
		// was recorded.
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertSame([], array_filter($warnings, function ($w) {
			return stripos($w, 'javascript') !== false;
		}));
	}
}
