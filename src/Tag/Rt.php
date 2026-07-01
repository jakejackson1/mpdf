<?php

namespace Mpdf\Tag;

/**
 * HTML <rt> tag handler — RT standard struct type.
 *
 * <rt> carries the ruby annotation glyphs (e.g. furigana). This handler opens
 * an RT struct element beneath the enclosing Ruby so the annotation is tagged
 * distinctly from the base, even though mPDF still flows the rt linearly inline.
 *
 * Spec references:
 *   - W3C Ruby Annotation §1 — HTML <rt> semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 337 — /RT standard struct type
 */
class Rt extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->open('RT');
			$this->pushInlineUaStructDepth(1);
		}
	}
}
