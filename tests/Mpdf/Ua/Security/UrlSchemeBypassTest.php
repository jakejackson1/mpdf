<?php

namespace Mpdf\Ua\Security;

use Mpdf\Ua\PdfUaTestCase;
use Mpdf\Ua\UaPolicy;

/**
 * Regression suite for UA1 audit finding H-1 — bypasses against the URL scheme
 * blocklist. Each case here corresponds to a working PoC under /tmp/ua1-audit/.
 *
 * The bypass classes covered:
 *   - Unicode invisible prefixes (NBSP, ZWSP, ZWJ/ZWNJ, BOM).
 *   - HTML entity-encoded scheme bytes (&Tab;, &#x6A;, &#58;).
 *   - Percent-encoded scheme bytes (%6A, %20).
 *   - Schemes outside the original `(?:javascript|vbscript)` regex
 *     (`livescript:`, `mocha:`, `vbs:`, `view-source:`).
 *   - `data:` URLs whose MIME carries active content
 *     (text/html, application/x-javascript, image/svg+xml, ...).
 *   - <area href> reaching the same policy decision (was previously not
 *     gated by UaPolicy at all).
 *
 * These complement the existing 19 happy-case assertions in
 * Mpdf\Ua\JavascriptUrlHandlingTest.
 *
 * @group pdfua
 * @group security
 */
class UrlSchemeBypassTest extends PdfUaTestCase
{

	/**
	 * @dataProvider bypassedSchemeProvider
	 */
	public function testBypassedSchemeIsBlocked($href)
	{
		$this->assertTrue(
			UaPolicy::isPolicyBlockedHref($href),
			'Expected policy block for: ' . var_export($href, true)
		);
	}

	public function bypassedSchemeProvider()
	{
		return [
			// --- Unicode invisible prefixes ---
			'NBSP'             => ["\xC2\xA0javascript:alert(1.0)"],
			'NBSP-vbscript'    => ["\xC2\xA0vbscript:msgbox(1)"],
			'ZWSP'             => ["\xE2\x80\x8Bjavascript:alert(1.0)"],
			'ZWNJ'             => ["\xE2\x80\x8Cjavascript:alert(1.0)"],
			'ZWJ'              => ["\xE2\x80\x8Djavascript:alert(1.0)"],
			'BOM'              => ["\xEF\xBB\xBFjavascript:alert(1.0)"],
			'NULL'             => ["\x00javascript:alert(1.0)"],
			'multiple-mixed'   => ["\xC2\xA0\xE2\x80\x8B \tjavascript:alert(1.0)"],

			// --- HTML entity-encoded scheme bytes ---
			'tab-entity'       => ['&Tab;javascript:alert(1.0)'],
			'newline-entity'   => ['&NewLine;javascript:alert(1.0)'],
			'first-letter-hex' => ['&#x6A;avascript:alert(1.0)'],
			'first-letter-dec' => ['&#106;avascript:alert(1.0)'],
			'colon-entity'     => ['javascript&#58;alert(1.0)'],

			// --- Percent-encoded scheme bytes ---
			'percent-letter'   => ['%6Aavascript:alert(1.0)'],
			'percent-prefix'   => ['%20javascript:alert(1.0)'],

			// --- Bytes inside the scheme name itself ---
			'newline-in-scheme' => ["java\nscript:alert(1.0)"],
			'space-in-scheme'   => ['j a v a s c r i p t :alert(1.0)'],
			'tab-in-scheme'     => ["java\tscript:alert(1.0)"],
			'nul-in-scheme'     => ["java\x00script:alert(1.0)"],

			// --- Case folding (must survive a byte-safe A-Z→a-z fold, no
			//     locale-dependent strtolower(); the `I` bytes below are what a
			//     Turkish-locale strtolower() on PHP < 8 would have mangled) ---
			'upper-scheme'     => ['JAVASCRIPT:alert(1.0)'],
			'mixed-scheme'     => ['JaVaScRiPt:alert(1.0)'],
			'upper-vbscript'   => ['VBSCRIPT:msgbox(1)'],
			'upper-livescript' => ['LIVESCRIPT:alert(1.0)'],

			// --- Extra schemes ---
			'livescript'       => ['livescript:alert(1.0)'],
			'mocha'            => ['mocha:alert(1.0)'],
			'vbs'              => ['vbs:msgbox(1)'],
			'view-source'      => ['view-source:javascript:alert(1)'],

			// --- data: with active-content MIME ---
			'data-html'        => ['data:text/html,<script>alert(1)</script>'],
			'data-html-base64' => ['data:text/html;base64,PHNjcmlwdD4='],
			'data-x-js'        => ['data:application/x-javascript,alert(1)'],
			'data-js'          => ['data:application/javascript,alert(1)'],
			'data-xhtml'       => ['data:application/xhtml+xml,<x/>'],
			'data-svg'         => ['data:image/svg+xml,<svg></svg>'],
			'data-html-mixed'  => ['DATA:Text/HTML,foo'],
		];
	}

	/**
	 * Defence-in-depth: the case fold in UaPolicy::normaliseHref() must be
	 * byte-safe (strtr A-Z→a-z), not the locale-dependent strtolower(). Under a
	 * Turkish locale, PHP < 8's strtolower() mapped `I` to a dotless `ı`, which
	 * could stop `JAVASCRIPT:` matching the lowercase deny-list. Pin the locale
	 * to tr_TR (when available) and assert the uppercase scheme is still blocked.
	 */
	public function testUppercaseSchemeBlockedUnderTurkishLocale()
	{
		$saved = setlocale(LC_CTYPE, '0');
		$applied = setlocale(LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR', 'turkish');
		try {
			$this->assertTrue(UaPolicy::isPolicyBlockedHref('JAVASCRIPT:alert(1)'));
			$this->assertTrue(UaPolicy::isPolicyBlockedHref('VBSCRIPT:msgbox(1)'));
			$this->assertTrue(UaPolicy::isPolicyBlockedHref('LiveScript:alert(1)'));
		} finally {
			if ($applied !== false && $saved !== false) {
				setlocale(LC_CTYPE, $saved);
			}
		}
	}

	/**
	 * @dataProvider permittedSchemeProvider
	 */
	public function testPermittedHrefIsNotBlocked($href)
	{
		$this->assertFalse(
			UaPolicy::isPolicyBlockedHref($href),
			'Expected policy pass for: ' . var_export($href, true)
		);
	}

	public function permittedSchemeProvider()
	{
		return [
			'http'              => ['http://example.com/path'],
			'https'             => ['https://example.com/path?q=1#frag'],
			'mailto'            => ['mailto:foo@example.com'],
			'tel'               => ['tel:+1234567890'],
			'sms'               => ['sms:+1234567890'],
			'ftp'               => ['ftp://example.com/file'],
			'file'              => ['file:///etc/hosts'],
			'fragment'          => ['#section'],
			'relative'          => ['./page.html'],
			'data-png'          => ['data:image/png;base64,AAAA'],
			'data-jpeg'         => ['data:image/jpeg;base64,AAAA'],
			'data-text'         => ['data:text/plain;base64,SGVsbG8='],
			'data-css'          => ['data:text/css,body{}'],
			'fragment-with-js'  => ['https://safe.example.com/#javascript:fake'],
		];
	}

	/**
	 * End-to-end: confirm dangerous bytes never land in the PDF /URI sink for
	 * any bypass class. Strict-mode assertion: the document must throw.
	 *
	 * HTTP_HOST is unset for the duration of these tests because mPDF's
	 * GetFullPath() uses it to absorb leading whitespace into a basepath
	 * (rewriting `\xC2\xA0javascript:...` to `http://host/ javascript:...`),
	 * which would mask the bypass at parse time. The audit's threat model
	 * targets cases where no basepath is in scope.
	 */
	private function withCleanHttpHost(callable $body)
	{
		$savedHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
		unset($_SERVER['HTTP_HOST']);
		try {
			$body();
		} finally {
			if ($savedHost !== null) {
				$_SERVER['HTTP_HOST'] = $savedHost;
			}
		}
	}

	public function testNbspPrefixThrowsInStrictMode()
	{
		$this->withCleanHttpHost(function () {
			$this->expectException(\Mpdf\MpdfException::class);
			$mpdf = $this->makeMpdf();
			$this->getOutput(
				$mpdf,
				'<p><a href="' . "\xC2\xA0" . 'javascript:alert(1.0)">x</a></p>'
			);
		});
	}

	public function testEntityEncodedSchemeThrowsInStrictMode()
	{
		$this->withCleanHttpHost(function () {
			$this->expectException(\Mpdf\MpdfException::class);
			$mpdf = $this->makeMpdf();
			$this->getOutput(
				$mpdf,
				'<p><a href="&#x6A;avascript:alert(1.0)">x</a></p>'
			);
		});
	}

	public function testDataTextHtmlThrowsInStrictMode()
	{
		$this->withCleanHttpHost(function () {
			$this->expectException(\Mpdf\MpdfException::class);
			$mpdf = $this->makeMpdf();
			$this->getOutput(
				$mpdf,
				'<p><a href="data:text/html,<script>alert(1)</script>x.">click</a></p>'
			);
		});
	}

	public function testLivescriptThrowsInStrictMode()
	{
		$this->withCleanHttpHost(function () {
			$this->expectException(\Mpdf\MpdfException::class);
			$mpdf = $this->makeMpdf();
			$this->getOutput($mpdf, '<p><a href="livescript:alert(1.0)">x</a></p>');
		});
	}

	public function testNbspPrefixStrippedInAutoMode()
	{
		$savedHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
		unset($_SERVER['HTTP_HOST']);
		try {
			$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
			$output = $this->getOutput(
				$mpdf,
				'<p><a href="' . "\xC2\xA0" . 'javascript:alert(1.0)">x</a></p>'
			);
			$this->assertStringNotContainsString('javascript:alert(1.0)', $output);
			$this->assertStringNotContainsString('/S /URI', $output);
			$this->assertStringNotContainsString('/S /Link', $output);
		} finally {
			if ($savedHost !== null) {
				$_SERVER['HTTP_HOST'] = $savedHost;
			}
		}
	}

	/**
	 * Tag/Area used to skip UaPolicy entirely. Strict mode must now throw and
	 * auto mode must drop the area.
	 */
	public function testAreaJavascriptHrefThrowsInStrictMode()
	{
		$this->expectException(\Mpdf\MpdfException::class);
		$mpdf = $this->makeMpdf();
		$html = '<map name="m"><area shape="rect" coords="0,0,10,10" '
			. 'href="javascript:alert(1.0)" alt="bad"></map>'
			. '<img src="' . __DIR__ . '/../../../data/img/checkerboard.png" usemap="#m">';
		$this->getOutput($mpdf, $html);
	}

	public function testAreaJavascriptHrefStrippedInAutoMode()
	{
		$savedHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
		unset($_SERVER['HTTP_HOST']);
		try {
			$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
			$html = '<map name="m"><area shape="rect" coords="0,0,10,10" '
				. 'href="javascript:alert(1.0)" alt="bad"></map>'
				. '<p>after</p>';
			$output = $this->getOutput($mpdf, $html);
			$this->assertStringNotContainsString('javascript:alert(1.0)', $output);
			$this->assertStringNotContainsString('/S /URI', $output);

			$warnings = $mpdf->getPdfUaWarnings();
			$found = false;
			foreach ($warnings as $w) {
				if (stripos($w, 'javascript:alert(1.0)') !== false) {
					$found = true;
					break;
				}
			}
			$this->assertTrue($found, 'Expected a warning citing the stripped <area> href.');
		} finally {
			if ($savedHost !== null) {
				$_SERVER['HTTP_HOST'] = $savedHost;
			}
		}
	}
}
