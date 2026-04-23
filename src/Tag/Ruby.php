<?php

namespace Mpdf\Tag;

/**
 * HTML <ruby> tag handler — v1 Span fallback (audit 2026-05-01 L4).
 *
 * mPDF does not implement ruby layout (rt-above-rb stacking). The rt content
 * flows linearly inline after the rb; the visual gap predates this handler.
 * What this class adds is the *tagged-tree* presence: an explicit Span struct
 * element wraps the ruby content so AT and tagged-PDF consumers see a
 * dedicated annotation handle rather than ruby parts dissolving into the
 * parent block's struct element.
 *
 * v2 (deferred) would replace the Span with the proper /Ruby standard struct
 * type (ISO 32000-1 §14.8.5.6 Tables 339/340), gated on a layout-engine RFC
 * — see plan 2026-05-01 §4b.
 *
 * The unconditional Span push (regardless of lang/aria-label attrs) is the
 * point of this handler; bare InlineTag would only push when those attrs are
 * present, which would defeat the audit fix for plain ruby.
 *
 * Spec references:
 *   - W3C Ruby Annotation §1 — HTML <ruby> semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Tables 339, 340 — Ruby/RB/RT/RP types (v2)
 *   - ISO 14289-1:2014 §7.1 — every piece of real content must be tagged
 */
class Ruby extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// PDF/UA-1 — push a Span struct element unconditionally so the ruby
		// container has its own tagged-tree handle. parent::open() only pushes
		// when lang= / aria-label= is present; bare <ruby>kanji<rt>furigana</rt></ruby>
		// would otherwise be tagged via the surrounding block's struct element.
		// pushInlineUaStructDepth() lets parent::close() pop both Spans (the
		// /Lang|/Alt one if any, and this unconditional one) on the same frame.
		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->open('Span');
			$this->pushInlineUaStructDepth(1);
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// parent::close() pops every Span on this tag's InlineUaStruct frame —
		// covers both the unconditional Span pushed by open() above and any
		// /Lang|/Alt Span pushed by InlineTag::openInlineUaStruct().
		parent::close($ahtml, $ihtml);
	}
}
