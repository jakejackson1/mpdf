<?php

namespace Mpdf\Tag;

/**
 * HTML <rt> tag handler — Span fallback.
 *
 * <rt> carries the ruby annotation glyphs (e.g. furigana). Like Ruby.php,
 * this handler unconditionally opens a Span struct element so the rt's
 * content has its own tagged-tree handle separate from the rb. AT can then
 * inspect the tagged tree to recognise the annotation as distinct from the
 * base, even though mPDF still flows the rt linearly inline.
 *
 * Spec references:
 *   - W3C Ruby Annotation §1 — HTML <rt> semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 339 — /RT standard struct type
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — /E expansion text
 */
class Rt extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// Push a Span unconditionally so the annotation glyphs are tagged
		// separately from the ruby base. See Ruby.php for the rationale.
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
