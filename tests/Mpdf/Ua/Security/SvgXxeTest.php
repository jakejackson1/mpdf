<?php

namespace Mpdf\Ua\Security;

use Mpdf\Ua\PdfUaTestCase;
use Mpdf\Image\Svg;

/**
 * Regression suite for UA1 audit finding M-3 — `Svg::extractAccessibleMetadata`
 * passed `LIBXML_NOENT` to `simplexml_load_string`, which expands character
 * AND external entities. Combined with a `<!DOCTYPE … SYSTEM "file://…">`
 * preamble it produced a classic XXE on PHP < 8.0 (where libxml has external
 * entity resolution enabled by default).
 *
 * The fix drops `LIBXML_NOENT`, adds `LIBXML_NONET`, and on PHP < 8.0 also
 * disables the libxml external-entity loader. `mergeStyles()` is hardened
 * the same way for consistency.
 *
 * extractAccessibleMetadata() is not called with a DOCTYPE through the
 * documented entry path (`ImageSVG()` strips it via regex), but the method
 * is `public` — third-party integrations that bypass `ImageSVG()` reach it
 * directly, so this is a live defence-in-depth concern.
 *
 * @group pdfua
 * @group security
 */
class SvgXxeTest extends PdfUaTestCase
{

	private function newSvgWithoutConstructor()
	{
		$ref = new \ReflectionClass(Svg::class);
		return $ref->newInstanceWithoutConstructor();
	}

	public function testFileSystemEntityIsNotResolved()
	{
		$svg = $this->newSvgWithoutConstructor();

		$payload = '<?xml version="1.0"?>'
			. '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/hosts">]>'
			. '<svg xmlns="http://www.w3.org/2000/svg"><title>&xxe;</title></svg>';

		$result = $svg->extractAccessibleMetadata($payload);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('title', $result);
		$title = (string) $result['title'];

		// The hosts file invariably contains "localhost" or "127.0.0.1"; if
		// either appears the entity was resolved and the parser leaked file
		// content into the accessible name.
		$this->assertStringNotContainsStringIgnoringCase('localhost', $title);
		$this->assertStringNotContainsString('127.0.0.1', $title);
	}

	public function testHttpEntityIsNotFetched()
	{
		$svg = $this->newSvgWithoutConstructor();
		$payload = '<?xml version="1.0"?>'
			. '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "http://example.invalid/secret">]>'
			. '<svg xmlns="http://www.w3.org/2000/svg"><title>&xxe;</title></svg>';

		// Should not throw, should not block on network.
		$start = microtime(true);
		$result = $svg->extractAccessibleMetadata($payload);
		$elapsed = microtime(true) - $start;

		$this->assertLessThan(5.0, $elapsed, 'Parse must not block on network entity resolution.');
		$this->assertIsArray($result);
	}

	public function testCharacterEntitiesStillFunctionInBodyText()
	{
		// Dropping LIBXML_NOENT means &amp;, &#233; etc. must still resolve in
		// element text via simplexml's default behaviour. Ensure regression-free.
		$svg = $this->newSvgWithoutConstructor();
		$payload = '<svg xmlns="http://www.w3.org/2000/svg">'
			. '<title>foo &amp; bar</title>'
			. '<desc>caf&#233;</desc>'
			. '</svg>';
		$result = $svg->extractAccessibleMetadata($payload);
		$this->assertSame('foo & bar', $result['title']);
		$this->assertSame('caf' . "\xC3\xA9", $result['desc']);
	}

	public function testWellFormedSvgStillProducesTitle()
	{
		$svg = $this->newSvgWithoutConstructor();
		$payload = '<svg xmlns="http://www.w3.org/2000/svg">'
			. '<title>Company logo</title>'
			. '<desc>Blue square</desc>'
			. '</svg>';
		$result = $svg->extractAccessibleMetadata($payload);
		$this->assertSame('Company logo', $result['title']);
		$this->assertSame('Blue square', $result['desc']);
	}
}
