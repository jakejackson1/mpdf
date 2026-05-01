<?php

namespace Mpdf\Tag;

/**
 * HTML <abbr> tag handler.
 *
 * In normal rendering mode, <abbr> acts identically to the generic InlineTag
 * handler — the title attribute may optionally emit a sticky-note annotation
 * (via the parent InlineTag::open() title2annots path).
 *
 * In PDF/UA-1 mode, the title attribute provides the expansion text for the
 * abbreviation so screen readers can speak the full form. The expansion is
 * stored as the /E attribute on a Span struct element.
 *
 * Spec references:
 *   - ISO 14289-1:2014 §7.1 — abbreviations and acronyms should carry expansion text
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — /E (expansion text) entry on StructElem dict
 */
class Abbr extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// Push a Span carrying /E expansion text from the title attr. /Lang, /Alt
		// (from aria-label), id registration and aria-* cross-refs are handled by
		// parent::open(); we only layer the abbreviation-specific /E Span on top
		// and bump the inline struct depth so close() pops both.
		if ($this->mpdf->PDFUA && !empty($attr['TITLE'])) {
			$this->ua->getStructureTree()->open('Span', ['E' => $attr['TITLE']]);
			$this->pushInlineUaStructDepth(1);
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// InlineTag::close pops every Span on this tag's inline-struct frame —
		// covering both the /Lang|/Alt Span (if any) and the /E Span from open().
		parent::close($ahtml, $ihtml);
	}
}
