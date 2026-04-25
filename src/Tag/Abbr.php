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
 * abbreviation. PDF/UA-1 §7.1 recommends providing expansion text so screen
 * readers can speak the full form. The expansion is stored as the /E attribute
 * on the Span struct element per ISO 32000-1 §14.7.2 Table 322.
 *
 * Implementation:
 *   - open(): call parent InlineTag::open() for normal CSS/bidi handling, then
 *             open a 'Span' struct element with ['E' => $attr['TITLE']] when PDFUA.
 *   - close(): close the Span struct element, then call parent InlineTag::close().
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

		// PDF/UA-1 — push a Span struct element with /E expansion text from title attr.
		if ($this->mpdf->PDFUA) {
			$structAttrs = [];
			if (!empty($attr['TITLE'])) {
				$structAttrs['E'] = $attr['TITLE'];
			}
			if (isset($attr['LANG'])) {
				$structAttrs['Lang'] = $attr['LANG'];
			}
			$this->ua->getStructureTree()->open('Span', $structAttrs);
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// PDF/UA-1 — pop the Span struct element.
		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->close();
		}

		parent::close($ahtml, $ihtml);
	}
}
