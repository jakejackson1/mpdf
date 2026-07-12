<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 tests for ARIA accessible-name resolution (audit E8).
 *
 * aria-labelledby / aria-describedby / aria-details resolve to an accessible
 * name built from the target element's real text. Before the E8 fix
 * AriaIdResolver::collectText() was a stub that, for an ordinary text target,
 * returned "" — and resolveAll() wrote that empty string as /Alt (\376\377),
 * a BOM-only empty string that, per ISO 32000-1 Table 322, REPLACES the
 * referring element's content for assistive technology and silently hides it.
 *
 * These tests assert both halves of the fix:
 *   1. a text target resolves to its actual text (/Alt = "The caption");
 *   2. a missing or empty target never emits an empty /Alt or /E — strict mode
 *      throws (Matterhorn 13-004 / 28-002), PDFUAauto mode warns.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — /Alt and /E on struct elements
 *   - ISO 14289-1:2014 (Matterhorn Protocol 1.1) — 13-004, 28-002
 *   - WAI-ARIA 1.1 §6.6 — aria-labelledby / aria-describedby ID references
 *
 * @group pdfua
 * @see PdfUaTestCase  base class supplying makeMpdf() and getOutput()
 */
class AriaNameResolutionTest extends PdfUaTestCase
{

	/**
	 * UTF-16BE-with-BOM encoding of a UTF-8 string, matching how StructureWriter
	 * serialises /Alt and /E values (ISO 32000-1 §7.9.2.2 text string, BOM \xfe\xff).
	 *
	 * @param  string $utf8
	 * @return string
	 */
	private function utf16Be($utf8)
	{
		return "\xfe\xff" . mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
	}

	/**
	 * A BOM-only empty /Alt is the exact content-hiding value E8 forbids.
	 *
	 * @return string
	 */
	private function emptyAltLiteral()
	{
		return '/Alt (' . "\xfe\xff" . ')';
	}

	/**
	 * aria-labelledby pointing at a plain text element resolves to that text.
	 *
	 * `<p id="cap">The caption</p><div aria-labelledby="cap">…</div>` must emit
	 * /Alt = "The caption" (UTF-16BE) on the div's struct element — not the empty
	 * BOM-only string that hid the div's content before the E8 fix.
	 *
	 * @return void
	 */
	public function testAriaLabelledbyResolvesToTargetText()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p id="cap">The caption</p>'
			. '<div aria-labelledby="cap">Region content here</div>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString(
			$this->utf16Be('The caption'),
			$output,
			'aria-labelledby must resolve to the target element\'s real text as /Alt.'
		);
		$this->assertStringNotContainsString(
			$this->emptyAltLiteral(),
			$output,
			'A resolved aria-labelledby must never leave a BOM-only empty /Alt.'
		);

		$warnings = implode(' ', $mpdf->getPdfUaWarnings());
		$this->assertStringNotContainsString('Unresolved ARIA reference', $warnings);
		$this->assertStringNotContainsString('resolved to empty text', $warnings);
	}

	/**
	 * aria-describedby pointing at a plain text element resolves to /E.
	 *
	 * @return void
	 */
	public function testAriaDescribedbyResolvesToTargetText()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p id="desc">Long description text</p>'
			. '<p aria-describedby="desc">Body paragraph.</p>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString(
			$this->utf16Be('Long description text'),
			$output,
			'aria-describedby must resolve to the target element\'s real text as /E.'
		);
	}

	/**
	 * An aria-labelledby whose target does not exist must warn (PDFUAauto) and
	 * must not emit an empty /Alt.
	 *
	 * @return void
	 */
	public function testUnresolvedTargetWarnsInAutoNeverEmptyAlt()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<div aria-labelledby="missing">Region content here</div>');

		$warnings = implode(' ', $mpdf->getPdfUaWarnings());
		$this->assertStringContainsString('Unresolved ARIA reference', $warnings);
		$this->assertStringContainsString('missing', $warnings);
		$this->assertStringNotContainsString(
			$this->emptyAltLiteral(),
			$output,
			'An unresolved aria-labelledby must never emit a BOM-only empty /Alt.'
		);
	}

	/**
	 * An aria-labelledby whose target does not exist must THROW in strict mode.
	 *
	 * @return void
	 */
	public function testUnresolvedTargetThrowsInStrict()
	{
		$mpdf = $this->makeMpdf();
		$this->expectException(\Mpdf\MpdfException::class);
		$this->getOutput($mpdf, '<div aria-labelledby="missing">Region content here</div>');
	}

	/**
	 * An aria-labelledby whose target resolves but carries no text must warn
	 * (PDFUAauto) and must not emit an empty /Alt.
	 *
	 * @return void
	 */
	public function testEmptyTargetWarnsInAutoNeverEmptyAlt()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<div id="cap"></div><div aria-labelledby="cap">Region content here</div>';
		$output = $this->getOutput($mpdf, $html);

		$warnings = implode(' ', $mpdf->getPdfUaWarnings());
		$this->assertStringContainsString('resolved to empty text', $warnings);
		$this->assertStringNotContainsString(
			$this->emptyAltLiteral(),
			$output,
			'An empty-text aria-labelledby target must never emit a BOM-only empty /Alt.'
		);
	}

	/**
	 * An aria-labelledby whose target resolves but carries no text must THROW in
	 * strict mode.
	 *
	 * @return void
	 */
	public function testEmptyTargetThrowsInStrict()
	{
		$mpdf = $this->makeMpdf();
		$this->expectException(\Mpdf\MpdfException::class);
		$this->getOutput($mpdf, '<div id="cap"></div><div aria-labelledby="cap">Region content here</div>');
	}

	/**
	 * A multi-line target resolves to its full text with words separated across
	 * the wrap boundary (own-text runs joined by a single space).
	 *
	 * @return void
	 */
	public function testMultiLineTargetJoinsTextWithSpaces()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// A narrow paragraph forces a wrap; the two lines must not be run together
		// (which would produce "worldSecond" instead of "world Second").
		$html = '<p id="cap" style="width: 30mm;">Hello world '
			. 'Second line follows here</p>'
			. '<div aria-labelledby="cap">Region</div>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString(
			$this->utf16Be('Hello world Second'),
			$output,
			'Wrapped target lines must be joined by a space, not concatenated.'
		);
	}
}
