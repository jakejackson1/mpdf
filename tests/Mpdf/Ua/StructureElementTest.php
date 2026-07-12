<?php

namespace Mpdf\Ua;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pure unit tests for StructureElement::sanitiseIdForPdf() — the id
 * normalisation used for /ID and /Headers cross-references.
 *
 * No mPDF instantiation is required; the method under test is static.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §7.3.5 — name object production (# must be followed
 *     by exactly two hexadecimal digits)
 *
 * @group pdfua
 */
class StructureElementTest extends TestCase
{

	/**
	 * A PDF name (after the leading '/') is a sequence of unrestricted safe
	 * bytes and #xx escapes. Anything else — a bare '#', or a '#' followed by
	 * fewer than two hex digits — is a malformed name.
	 */
	private function assertValidPdfName($name)
	{
		$this->assertMatchesRegex(
			'/^(?:[a-z0-9_.\-]|#[0-9A-F]{2})*$/',
			$name,
			'sanitised id is not a valid PDF name production: ' . $name
		);
	}

	/**
	 * Yoast polyfills expose assertMatchesRegularExpression on newer PHPUnit
	 * and assertRegExp on older; wrap so this file runs on both.
	 */
	private function assertMatchesRegex($pattern, $value, $message = '')
	{
		if (method_exists($this, 'assertMatchesRegularExpression')) {
			$this->assertMatchesRegularExpression($pattern, $value, $message);
		} else {
			$this->assertRegExp($pattern, $value, $message);
		}
	}

	/**
	 * Short ids that fit under the byte cap pass through untouched (bar the
	 * A-Z fold) — no truncation, no hash suffix.
	 */
	public function testShortIdUnchanged()
	{
		$this->assertSame('header-1', StructureElement::sanitiseIdForPdf('Header-1'));
	}

	/**
	 * The E14 reproduction: 107 ASCII bytes followed by eight two-byte UTF-8
	 * characters. Each 'é' expands to two #xx tokens (#C3#A9), pushing the
	 * output well past the 127-byte cap. A blind substr(…, 108) cut lands
	 * one byte into the first '#C3' token and yields a bare '#'; the fixed
	 * token-aware cut must stop on a complete token.
	 */
	public function testLongNonAsciiIdEndsOnCompleteToken()
	{
		$id  = str_repeat('a', 107) . str_repeat("\xC3\xA9", 8); // "é" in UTF-8
		$out = StructureElement::sanitiseIdForPdf($id);

		$this->assertValidPdfName($out);
		// suffix is the #2D-joined sha1 head; the byte before it must complete
		// a token, i.e. the name must not contain a '#' with < 2 hex digits.
		$this->assertStringEndsWith('#2D' . substr(sha1($id), 0, 16), $out);
	}

	/**
	 * Every alignment of the truncation offset against a #xx token boundary
	 * must produce a valid name. Sweeping the ASCII-prefix length around the
	 * 108-byte cut point exercises the case where the offset would otherwise
	 * split a multibyte escape.
	 */
	public function testTruncationNeverSplitsEscapeAtAnyAlignment()
	{
		for ($ascii = 100; $ascii <= 115; $ascii++) {
			$id  = str_repeat('a', $ascii) . str_repeat("\xC3\xA9", 12);
			$out = StructureElement::sanitiseIdForPdf($id);
			$this->assertValidPdfName($out);
			$this->assertLessThanOrEqual(127, strlen($out), "id length $ascii overflowed the cap");
		}
	}

	/**
	 * A wholly non-ASCII overlong id (every byte escaped) still truncates on a
	 * token boundary rather than mid-escape.
	 */
	public function testAllNonAsciiIdIsValidName()
	{
		$id  = str_repeat("\xE2\x9C\x93", 60); // U+2713 CHECK MARK ×60
		$out = StructureElement::sanitiseIdForPdf($id);

		$this->assertValidPdfName($out);
		$this->assertLessThanOrEqual(127, strlen($out));
	}

	/**
	 * Two distinct overlong inputs sharing a long common prefix must still map
	 * to distinct sanitised ids — the sha1 suffix disambiguates them.
	 */
	public function testOverlongInputsWithSharedPrefixStayDistinct()
	{
		$base = str_repeat('a', 130);
		$a    = StructureElement::sanitiseIdForPdf($base . 'x');
		$b    = StructureElement::sanitiseIdForPdf($base . 'y');

		$this->assertNotSame($a, $b);
		$this->assertValidPdfName($a);
		$this->assertValidPdfName($b);
	}
}
