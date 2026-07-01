<?php

namespace Mpdf\Tag;

/**
 * HTML <ruby> tag handler — Ruby standard struct type.
 *
 * mPDF does not implement ruby layout (rt-above-rb stacking); the rt content
 * flows linearly inline after the rb. Tagging is independent of that visual
 * gap: this handler opens a Ruby struct element (ISO 32000-1 §14.8.5.6
 * Table 337) so AT and tagged-PDF consumers see real ruby structure. The <rb>,
 * <rt>, <rp> children open their own RB/RT/RP elements beneath it; a bare-text
 * base (no <rb>) attaches its content directly to this Ruby element.
 *
 * The unconditional push (regardless of lang/aria-label attrs) is the point of
 * this handler; bare InlineTag would only push when those attrs are present,
 * which would leave plain ruby untagged.
 *
 * Spec references:
 *   - W3C Ruby Annotation §1 — HTML <ruby> semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 337 — Ruby/RB/RT/RP standard struct types
 *   - ISO 14289-1:2014 §7.1 — every piece of real content must be tagged
 */
class Ruby extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// pushInlineUaStructDepth() lets parent::close() pop both this element
		// and the /Lang|/Alt Span (if parent::open() pushed one) on the same frame.
		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->open('Ruby');
			$this->pushInlineUaStructDepth(1);
		}
	}
}
