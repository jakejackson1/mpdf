<?php

namespace Mpdf\Tag;

/**
 * HTML <rp> tag handler — RP standard struct type.
 *
 * <rp> wraps fallback parentheses ("(", ")") shown around the rt by user agents
 * that cannot render ruby. mPDF does not stack ruby, so the rp text flows inline
 * and is visible; this handler opens an RP struct element beneath the enclosing
 * Ruby so the parentheses are tagged as ruby punctuation rather than anonymous
 * inline content.
 *
 * Spec references:
 *   - W3C Ruby Annotation §3 — fallback parenthesis semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 337 — /RP standard struct type
 */
class Rp extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->open('RP');
			$this->pushInlineUaStructDepth(1);
		}

		// The fallback parentheses are for user agents that cannot stack ruby.
		// mPDF now stacks, so the layout engine suppresses rp runs visually while
		// the RP struct element above keeps them in the tagged tree (C1a).
		$this->mpdf->textparam['ruby'] = 'rp';
	}
}
