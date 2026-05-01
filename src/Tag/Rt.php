<?php

namespace Mpdf\Tag;

/**
 * HTML <rt> tag handler — v1 Span fallback (audit 2026-05-01 L4).
 *
 * <rt> carries the ruby annotation glyphs (e.g. furigana). Like Ruby.php,
 * this handler unconditionally opens a Span struct element so the rt's
 * content has its own tagged-tree handle separate from the rb. AT can then
 * inspect the tagged tree to recognise the annotation as distinct from the
 * base, even though mPDF still flows the rt linearly inline.
 *
 * v2 (deferred) would:
 *   - Use the /RT standard struct type (ISO 32000-1 §14.8.5.6 Table 339).
 *   - Optionally stack the rt visually above the rb (4b.i / 4b.ii / 4b.iii
 *     in plan 2026-05-01 §4b).
 *   - Propagate the rt's text content as /E (expansion text) on the
 *     enclosing Ruby element so AT can announce the reading. The /E hook
 *     requires a side-channel text buffer between open and close that
 *     InlineTag does not currently expose — see plan §4c.
 *
 * Spec references:
 *   - W3C Ruby Annotation §1 — HTML <rt> semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 339 — RT struct type (v2)
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — /E expansion text (v2 polish)
 */
class Rt extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// PDF/UA-1 — push a Span unconditionally so the annotation glyphs are
		// tagged separately from the ruby base. See Ruby.php for the rationale.
		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->open('Span');
			$this->pushInlineUaStructDepth(1);
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// parent::close() pops every Span on this tag's InlineUaStruct frame.
		parent::close($ahtml, $ihtml);
	}
}
