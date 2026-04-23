<?php

namespace Mpdf\Ua;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pure unit tests for StructType — HTML tag / CSS class → PDF struct type mapping.
 *
 * No mPDF instantiation is required; all methods under test are static.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Tables 333–335 — standard PDF struct types
 *   - ISO 32000-1:2008 §14.7.3 — RoleMap (custom → standard type mapping)
 *   - Tagged PDF Best Practice Guide §4.2.3 — DL/DT/DD list treatment
 *
 * @group pdfua
 */
class StructTypeTest extends TestCase
{

	// ================== fromHtmlTag ==================

	/**
	 * Core tag-map entries for common block and inline tags.
	 */
	public function testFromHtmlTagReturnsCorrectType()
	{
		$this->assertSame('P', StructType::fromHtmlTag('P'));
		$this->assertSame('H1', StructType::fromHtmlTag('H1'));
		$this->assertSame('L', StructType::fromHtmlTag('UL'));
		$this->assertSame('L', StructType::fromHtmlTag('OL'));
		$this->assertSame('Table', StructType::fromHtmlTag('TABLE'));
		$this->assertSame('Figure', StructType::fromHtmlTag('IMG'));
		$this->assertSame('Span', StructType::fromHtmlTag('STRONG'));
	}

	/**
	 * Unrecognised tags return null so callers know not to open a struct element.
	 */
	public function testFromHtmlTagUnknownReturnsNull()
	{
		$this->assertNull(StructType::fromHtmlTag('UNKNOWN'));
		$this->assertNull(StructType::fromHtmlTag('HR'));
		$this->assertNull(StructType::fromHtmlTag('BR'));
	}

	/**
	 * A valid ROLE attribute value (one that is itself a standard PDF struct type)
	 * overrides the tag-map lookup — allowing ARIA role override.
	 */
	public function testFromHtmlTagRoleOverride()
	{
		$this->assertSame('Sect', StructType::fromHtmlTag('DIV', ['ROLE' => 'Sect']));
		$this->assertSame('Art', StructType::fromHtmlTag('DIV', ['ROLE' => 'Art']));
	}

	/**
	 * An invalid ROLE attribute value is ignored and the tag-map fallback applies.
	 */
	public function testFromHtmlTagRoleInvalidIgnored()
	{
		$this->assertSame('Div', StructType::fromHtmlTag('DIV', ['ROLE' => 'BadType']));
		$this->assertSame('Div', StructType::fromHtmlTag('DIV', ['ROLE' => 'presentation']));
	}

	/**
	 * Definition list tags map per Tagged PDF Best Practice Guide §4.2.3.
	 */
	public function testFromHtmlTagDl()
	{
		$this->assertSame('L', StructType::fromHtmlTag('DL'));
		$this->assertSame('Lbl', StructType::fromHtmlTag('DT'));
		$this->assertSame('LBody', StructType::fromHtmlTag('DD'));
	}

	/**
	 * HTML5 <figcaption> maps to PDF Caption struct type.
	 */
	public function testFromHtmlTagFigcaption()
	{
		$this->assertSame('Caption', StructType::fromHtmlTag('FIGCAPTION'));
	}

	/**
	 * Tag name lookup is case-insensitive.
	 */
	public function testFromHtmlTagCaseInsensitive()
	{
		$this->assertSame('P', StructType::fromHtmlTag('p'));
		$this->assertSame('H1', StructType::fromHtmlTag('h1'));
		$this->assertSame('Table', StructType::fromHtmlTag('table'));
	}

	/**
	 * <a> maps to Link as its default (tag handler is responsible for only
	 * calling open() when href is present).
	 */
	public function testFromHtmlTagAnchor()
	{
		$this->assertSame('Link', StructType::fromHtmlTag('A'));
	}

	/**
	 * <fieldset> / <legend> / <form> map to standard PDF struct types so that
	 * BlockTag-based emission produces tagged real content rather than
	 * untagged-content rule 7.1#3 violations (audit 2026-05-01 H2).
	 *
	 * - FIELDSET → Sect : closest grouping element for related form controls.
	 * - LEGEND   → Caption : Tagged PDF Best Practice — caption of a fieldset.
	 * - FORM     → Div : reserves the 'Form' struct type for individual widgets.
	 */
	public function testFromHtmlTagFormGrouping()
	{
		$this->assertSame('Sect', StructType::fromHtmlTag('FIELDSET'));
		$this->assertSame('Caption', StructType::fromHtmlTag('LEGEND'));
		$this->assertSame('Div', StructType::fromHtmlTag('FORM'));
	}

	// ================== fromCssClass ==================

	/**
	 * Exact match for the mPDF ToC container class.
	 */
	public function testFromCssClassToc()
	{
		$this->assertSame('TOC', StructType::fromCssClass('mpdf_toc'));
	}

	/**
	 * Prefix match for ToC level entries (mpdf_toc_level_0, mpdf_toc_level_1, …).
	 */
	public function testFromCssClassTociLevel()
	{
		$this->assertSame('TOCI', StructType::fromCssClass('mpdf_toc_level_2'));
		$this->assertSame('TOCI', StructType::fromCssClass('mpdf_toc_level_0'));
	}

	/**
	 * ToC link class must map to Link (not Reference) because it creates a real
	 * PDF Link annotation requiring an OBJR kid.
	 */
	public function testFromCssClassTocA()
	{
		$this->assertSame('Link', StructType::fromCssClass('mpdf_toc_a'));
	}

	/**
	 * Prefix match for ToC page number label class.
	 */
	public function testFromCssClassTocPLevel()
	{
		$this->assertSame('Lbl', StructType::fromCssClass('mpdf_toc_p_level_0'));
		$this->assertSame('Lbl', StructType::fromCssClass('mpdf_toc_p_level_3'));
	}

	/**
	 * Unrecognised CSS class returns null — not a ToC class.
	 */
	public function testFromCssClassUnknownReturnsNull()
	{
		$this->assertNull(StructType::fromCssClass('mpdf_other'));
		$this->assertNull(StructType::fromCssClass('some-random-class'));
		$this->assertNull(StructType::fromCssClass(''));
	}

	// ================== isValid ==================

	/**
	 * All common standard struct types must be accepted.
	 */
	public function testIsValidKnownType()
	{
		$this->assertTrue(StructType::isValid('P'));
		$this->assertTrue(StructType::isValid('Table'));
		$this->assertTrue(StructType::isValid('Link'));
		$this->assertTrue(StructType::isValid('Document'));
		$this->assertTrue(StructType::isValid('Figure'));
		$this->assertTrue(StructType::isValid('H6'));
		$this->assertTrue(StructType::isValid('TOCI'));
	}

	/**
	 * Non-standard and misspelled type names must be rejected.
	 */
	public function testIsValidUnknownType()
	{
		$this->assertFalse(StructType::isValid('BadType'));
		$this->assertFalse(StructType::isValid('p'));     // case-sensitive
		$this->assertFalse(StructType::isValid('PARAGRAPH'));
	}

	// ================== isGrouping ==================

	/**
	 * Grouping elements (ISO 32000-1 Table 333) must return true.
	 */
	public function testIsGroupingForGroupingTypes()
	{
		$this->assertTrue(StructType::isGrouping('TOC'));
		$this->assertTrue(StructType::isGrouping('L'));
		$this->assertTrue(StructType::isGrouping('Table'));
		$this->assertTrue(StructType::isGrouping('Document'));
		$this->assertTrue(StructType::isGrouping('Div'));
		$this->assertTrue(StructType::isGrouping('Sect'));
	}

	/**
	 * Leaf and block-level types (Table 334/335) must return false.
	 */
	public function testIsGroupingForLeafTypes()
	{
		$this->assertFalse(StructType::isGrouping('P'));
		$this->assertFalse(StructType::isGrouping('TD'));
		$this->assertFalse(StructType::isGrouping('Span'));
		$this->assertFalse(StructType::isGrouping('H1'));
		$this->assertFalse(StructType::isGrouping('Link'));
	}

	/**
	 * Ruby annotation tags map to Span (v1 fallback) — proper Ruby/RB/RT/RP
	 * struct types are deferred (plan 2026-05-01 §4b). The Span mapping closes
	 * the semantic-loss observation from audit L4 by ensuring every ruby part
	 * has its own struct element rather than leaning on the parent block.
	 */
	public function testFromHtmlTagRuby()
	{
		$this->assertSame('Span', StructType::fromHtmlTag('RUBY'));
		$this->assertSame('Span', StructType::fromHtmlTag('RB'));
		$this->assertSame('Span', StructType::fromHtmlTag('RT'));
		$this->assertSame('Span', StructType::fromHtmlTag('RP'));
		$this->assertSame('Span', StructType::fromHtmlTag('RTC'));
	}
}
