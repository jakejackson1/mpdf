<?php

namespace Mpdf\Ua;

/**
 * Single source of truth for HTML tag / CSS class → PDF struct type mapping.
 *
 * All tag handlers and rendering code that needs to translate an HTML tag to a
 * PDF structure type must use this class rather than ad-hoc strtoupper() calls.
 * Centralising the mapping prevents divergence and lets each translation
 * decision carry a spec rationale in one place.
 *
 * Static-only class — no instantiation required. All maps are private and
 * accessed exclusively through the public static API.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — grouping structure elements
 *   - ISO 32000-1:2008 §14.8 Table 334 — block-level structure elements (BLSE)
 *   - ISO 32000-1:2008 §14.8 Table 335 — inline-level structure elements (ILSE)
 *   - ISO 32000-1:2008 §14.7.3 — RoleMap (custom → standard type mapping)
 *   - Tagged PDF Best Practice Guide §4.2.3 — DL/DT/DD list treatment
 *
 * @see StructureTree::addRoleMapping() for non-standard / ARIA custom roles
 */
class StructType
{

	/**
	 * HTML tag (uppercase) → PDF struct type.
	 *
	 * Only the default mapping for each tag is stored here. The <a> tag
	 * maps to 'Link' as its default but the A.php tag handler must check
	 * whether an href attribute is present before calling open(); destination
	 * anchors (<a name="...">) must not open a struct element.
	 *
	 * @var array<string,string>
	 */
	private static $tagMap = [
		'P'          => 'P',
		'H1'         => 'H1', 'H2' => 'H2', 'H3' => 'H3',
		'H4'         => 'H4', 'H5' => 'H5', 'H6' => 'H6',
		'BLOCKQUOTE' => 'BlockQuote',
		'DIV'        => 'Div',
		'SPAN'       => 'Span',
		'A'          => 'Link',
		'UL'         => 'L',  'OL' => 'L',
		'LI'         => 'LI',
		// Definition lists: DL → L, DT → Lbl (term label), DD → LBody (definition body).
		// Per Tagged PDF Best Practice Guide §4.2.3 — treat <dl> as a list structure.
		'DL'         => 'L', 'DT' => 'Lbl', 'DD' => 'LBody',
		'TABLE'      => 'Table',
		'TR'         => 'TR', 'TD' => 'TD', 'TH' => 'TH',
		'THEAD'      => 'THead', 'TBODY' => 'TBody', 'TFOOT' => 'TFoot',
		'FIGURE'     => 'Figure', 'IMG' => 'Figure',
		'CAPTION'    => 'Caption',
		'FIGCAPTION' => 'Caption',   // HTML5 figure caption — maps to PDF Caption
		'SECTION'    => 'Sect',
		'ARTICLE'    => 'Art',
		'NAV'        => 'Sect',
		'ASIDE'      => 'Sect',
		'MAIN'       => 'Div',
		'HEADER'     => 'Div',
		'FOOTER'     => 'Div',
		'ADDRESS'    => 'P',
		// <fieldset> groups related form controls — Sect is the closest grouping
		// element (ISO 32000-1 Table 333). <form> wraps an interactive form
		// region; the 'Form' struct type (Table 335) is reserved for an individual
		// form-widget marked-content sequence (used by Mpdf\Form per-widget
		// tagging), so mapping the HTML <form> container to 'Div' avoids
		// double-association at the widget level.
		'FIELDSET'   => 'Sect',
		'FORM'       => 'Div',
		// LEGEND maps to Caption per Tagged PDF Best Practice. Note that the
		// LEGEND tag handler (Tag/Legend.php) does not currently open a struct
		// element — the legend text is hoisted onto the parent fieldset's border
		// chrome. Adding the entry here keeps the type map authoritative for any
		// future Legend.php update that does push a Caption onto the struct tree.
		'LEGEND'     => 'Caption',
		'PRE'        => 'Code',
		'CODE'       => 'Code',
		'Q'          => 'Quote',
		'STRONG'     => 'Span', 'B' => 'Span',
		'EM'         => 'Span', 'I' => 'Span',
		'ABBR'       => 'Span', 'ACRONYM' => 'Span',
		'SUB'        => 'Span', 'SUP' => 'Span',
		'MARK'       => 'Span', 'DEL' => 'Span',
		'INS'        => 'Span', 'S'   => 'Span',
		'SMALL'      => 'Span',
	];

	/**
	 * mPDF-generated ToC CSS class prefix/exact name → PDF struct type.
	 *
	 * Both exact matches and prefix matches are checked (see fromCssClass()).
	 * Prefixes end with '_' so 'mpdf_toc_level_0', 'mpdf_toc_level_1', etc.
	 * all map to 'TOCI'.
	 *
	 * ISO 32000-1:2008 §14.8 Table 333 — TOC / TOCI grouping elements.
	 *
	 * @var array<string,string>
	 */
	private static $tocClassMap = [
		'mpdf_toc'          => 'TOC',
		'mpdf_toc_level_'   => 'TOCI',   // prefix — handles level_0, level_1, …
		// <a class="mpdf_toc_a"> creates a real PDF Link annotation → must be Link,
		// NOT Reference (Reference is for textual cross-refs without annotations).
		// ISO 32000-1 §14.8.4.4.2 Table 338: Link requires an OBJR kid pointing at
		// the annotation object.
		'mpdf_toc_a'        => 'Link',
		'mpdf_toc_p_level_' => 'Lbl',    // prefix — page number label
	];

	/**
	 * Standard PDF struct types per ISO 32000-1 §14.8 Tables 333–335.
	 *
	 * Only these types may appear as the /S key in a StructElem dict. Non-standard
	 * roles must be registered via StructureTree::addRoleMapping() and mapped to
	 * one of these values.
	 *
	 * @var array<int,string>
	 */
	private static $validTypes = [
		'Document', 'Part', 'Art', 'Sect', 'Div', 'BlockQuote', 'Caption',
		'TOC', 'TOCI', 'Index', 'NonStruct', 'Private',
		'P', 'H', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
		'L', 'LI', 'Lbl', 'LBody',
		'Table', 'TR', 'TH', 'TD', 'THead', 'TBody', 'TFoot',
		'Span', 'Quote', 'Note', 'Reference', 'BibEntry', 'Code',
		'Link', 'Annot',
		'Figure', 'Formula', 'Form',
	];

	/**
	 * Grouping elements that cannot hold direct content (MCIDs).
	 *
	 * ISO 32000-1 §14.8 Table 333 — grouping structure elements must contain
	 * block-level or inline-level structure elements, not direct content items.
	 *
	 * @var array<int,string>
	 */
	private static $groupingTypes = [
		'Document', 'Part', 'Art', 'Sect', 'Div', 'BlockQuote', 'Caption',
		'TOC', 'TOCI', 'Index', 'NonStruct', 'Private',
		'Table', 'THead', 'TBody', 'TFoot', 'L',
	];

	/**
	 * Resolve an HTML tag + attributes to a PDF struct type.
	 *
	 * Priority order:
	 *   1. ROLE attribute — if it contains a recognised standard PDF struct type,
	 *      that type is returned directly (allows ARIA role override).
	 *   2. $tagMap lookup on the uppercase tag name.
	 *   3. null — tag is not mapped and should produce no struct element.
	 *
	 * The ROLE attribute override ignores invalid/custom role values and falls
	 * through to the tag-map so that role="BadType" on a <div> still yields 'Div'.
	 *
	 * @param  string $htmlTag  HTML tag name (any case; uppercased internally)
	 * @param  array  $attr     tag attributes array (uppercase keys)
	 * @return string|null      PDF struct type, or null if unrecognised
	 */
	public static function fromHtmlTag($htmlTag, $attr = [])
	{
		// ROLE attribute override — only accepted if the value is itself a valid PDF type.
		if (!empty($attr['ROLE'])) {
			$role = trim($attr['ROLE']);
			if (in_array($role, self::$validTypes, true)) {
				return $role;
			}
		}
		$upper = strtoupper($htmlTag);
		return isset(self::$tagMap[$upper]) ? self::$tagMap[$upper] : null;
	}

	/**
	 * Resolve a single CSS class name to a PDF struct type.
	 *
	 * Checked against the ToC class map (exact match first, then prefix match).
	 * Used by BlockTag.php to detect mPDF-generated ToC HTML and assign the
	 * correct TOC/TOCI struct types.
	 *
	 * @param  string $cssClass  single CSS class name (not space-separated)
	 * @return string|null       PDF struct type, or null if not a recognised ToC class
	 */
	public static function fromCssClass($cssClass)
	{
		// Exact match first — handles 'mpdf_toc', 'mpdf_toc_a'.
		if (isset(self::$tocClassMap[$cssClass])) {
			return self::$tocClassMap[$cssClass];
		}
		// Prefix match — only for entries ending with '_' (designated prefix keys):
		// 'mpdf_toc_level_' handles level_0, level_1, … etc.
		// 'mpdf_toc_p_level_' handles page number labels at each level.
		foreach (self::$tocClassMap as $prefix => $type) {
			// Only treat entries as prefixes when the key itself ends with '_'.
			// Exact-key entries ('mpdf_toc', 'mpdf_toc_a') are not prefixes.
			if (substr($prefix, -1) === '_' && strncmp($cssClass, $prefix, strlen($prefix)) === 0) {
				return $type;
			}
		}
		return null;
	}

	/**
	 * Return true if $type is a standard PDF struct type (ISO 32000-1 §14.8).
	 *
	 * Used by StructureElement and StructureTree::open() to validate the type
	 * before creating or pushing an element.
	 *
	 * @param  string $type
	 * @return bool
	 */
	public static function isValid($type)
	{
		return in_array($type, self::$validTypes, true);
	}

	/**
	 * Return true if $type is a grouping element that cannot hold MCIDs directly.
	 *
	 * Grouping elements (ISO 32000-1 Table 333) must contain block-level or
	 * inline-level structure elements. StructureWriter uses this to decide
	 * whether to emit a /K entry for direct content items or to skip it.
	 *
	 * @param  string $type
	 * @return bool
	 */
	public static function isGrouping($type)
	{
		return in_array($type, self::$groupingTypes, true);
	}
}
