<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 tests for resolved ARIA relationship attributes (audit E18).
 *
 * aria-controls / aria-owns / aria-flowto / aria-activedescendant were resolved
 * to their target struct element and stored under attributes['_aria_*'], but no
 * writer path emitted them — so the relationships were silently dropped and the
 * internal marker key never left the attribute map. E18 splits the four:
 *
 *   1. aria-owns / aria-controls map to a PDF /Ref entry (ISO 32000-2 §14.7) —
 *      an array of references to the struct elements this element refers to;
 *   2. aria-flowto / aria-activedescendant have no static PDF/UA-1
 *      representation and are surfaced as a visible warning (both modes) rather
 *      than store-and-dropped.
 *
 * In every case NO `_aria_*` marker key may appear in the emitted PDF.
 *
 * Spec references:
 *   - ISO 32000-2:2020 §14.7 — struct element /Ref relationship entries
 *   - WAI-ARIA 1.1 §6.6 — ID reference attributes
 *
 * @group pdfua
 * @see PdfUaTestCase  base class supplying makeMpdf() and getOutput()
 */
class AriaRelTest extends PdfUaTestCase
{

	/**
	 * aria-controls resolving to an existing id emits a /Ref cross-reference and
	 * leaves no `_aria_*` marker key in the output.
	 *
	 * @return void
	 */
	public function testAriaControlsEmitsRef()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p id="panel">Panel content</p>'
			. '<div aria-controls="panel">Controller</div>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString(
			'/Ref [',
			$output,
			'A resolved aria-controls must emit a /Ref cross-reference on the struct element.'
		);
		$this->assertStringNotContainsString(
			'_aria',
			$output,
			'No internal ARIA marker key may leak into the output dictionaries.'
		);

		$warnings = implode(' ', $mpdf->getPdfUaWarnings());
		$this->assertStringNotContainsString(
			'no PDF/UA-1 representation',
			$warnings,
			'aria-controls maps to /Ref and must not warn about a missing representation.'
		);
	}

	/**
	 * aria-owns resolving to an existing id emits a /Ref cross-reference.
	 *
	 * @return void
	 */
	public function testAriaOwnsEmitsRef()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p id="child">Owned content</p>'
			. '<div aria-owns="child">Owner</div>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString(
			'/Ref [',
			$output,
			'A resolved aria-owns must emit a /Ref cross-reference on the struct element.'
		);
		$this->assertStringNotContainsString('_aria', $output);
	}

	/**
	 * aria-controls also emits /Ref in strict mode without throwing.
	 *
	 * @return void
	 */
	public function testAriaControlsEmitsRefInStrict()
	{
		$mpdf = $this->makeMpdf();
		$html = '<p id="panel">Panel content</p>'
			. '<div aria-controls="panel">Controller</div>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString('/Ref [', $output);
		$this->assertStringNotContainsString('_aria', $output);
	}

	/**
	 * aria-flowto has no static PDF/UA-1 representation: resolveAll() emits
	 * nothing and records a visible warning (PDFUAauto) — never store-and-drop,
	 * and no `_aria_*` marker key leaks into the output.
	 *
	 * @return void
	 */
	public function testAriaFlowtoWarnsAndDropsNothingSilently()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p id="next">Next region</p>'
			. '<div aria-flowto="next">Region</div>';
		$output = $this->getOutput($mpdf, $html);

		$warnings = implode(' ', $mpdf->getPdfUaWarnings());
		$this->assertStringContainsString(
			'no PDF/UA-1 representation',
			$warnings,
			'aria-flowto must be surfaced as a visible warning, not silently dropped.'
		);
		$this->assertStringContainsString('aria-flowto', $warnings);
		$this->assertStringNotContainsString(
			'_aria',
			$output,
			'No internal ARIA marker key may leak into the output dictionaries.'
		);
	}

	/**
	 * aria-activedescendant likewise has no static PDF/UA-1 representation and is
	 * surfaced as a warning even in strict mode (it is not a conformance
	 * violation, so it must not throw).
	 *
	 * @return void
	 */
	public function testAriaActivedescendantWarnsInStrictWithoutThrowing()
	{
		$mpdf = $this->makeMpdf();
		$html = '<p id="opt">Option one</p>'
			. '<div aria-activedescendant="opt">Listbox</div>';
		$output = $this->getOutput($mpdf, $html);

		$warnings = implode(' ', $mpdf->getPdfUaWarnings());
		$this->assertStringContainsString('no PDF/UA-1 representation', $warnings);
		$this->assertStringContainsString('aria-activedescendant', $warnings);
		$this->assertStringNotContainsString('_aria', $output);
	}
}
