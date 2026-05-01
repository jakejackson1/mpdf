<?php

namespace Mpdf\Tag;

/**
 * HTML <ruby> tag handler — Span fallback.
 *
 * mPDF does not implement ruby layout (rt-above-rb stacking). The rt content
 * flows linearly inline after the rb; the visual gap predates this handler.
 * What this class adds is the *tagged-tree* presence: an explicit Span struct
 * element wraps the ruby content so AT and tagged-PDF consumers see a
 * dedicated annotation handle rather than ruby parts dissolving into the
 * parent block's struct element.
 *
 * The unconditional Span push (regardless of lang/aria-label attrs) is the
 * point of this handler; bare InlineTag would only push when those attrs are
 * present, which would leave plain ruby untagged.
 *
 * Spec references:
 *   - W3C Ruby Annotation §1 — HTML <ruby> semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Tables 339, 340 — Ruby/RB/RT/RP standard struct types
 *   - ISO 14289-1:2014 §7.1 — every piece of real content must be tagged
 */
class Ruby extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// Push a Span unconditionally so the ruby container has its own
		// tagged-tree handle. parent::open() only pushes when lang= / aria-label=
		// is present; bare <ruby>kanji<rt>furigana</rt></ruby> would otherwise
		// be tagged via the surrounding block's struct element.
		// pushInlineUaStructDepth() lets parent::close() pop both Spans (the
		// /Lang|/Alt one if any, and this unconditional one) on the same frame.
		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->open('Span');
			$this->pushInlineUaStructDepth(1);
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		parent::close($ahtml, $ihtml);
	}
}
