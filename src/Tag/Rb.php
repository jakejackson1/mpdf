<?php

namespace Mpdf\Tag;

/**
 * HTML <rb> tag handler — RB standard struct type.
 *
 * <rb> marks the ruby base (the word being annotated). This handler opens an
 * RB struct element beneath the enclosing Ruby so the base is tagged distinctly
 * from the <rt> annotation. When the base is bare text with no <rb> wrapper the
 * content attaches to the Ruby element directly (Ruby.php) — both shapes are
 * valid ruby structure.
 *
 * Spec references:
 *   - W3C Ruby Annotation §1 — HTML <rb> semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 337 — /RB standard struct type
 */
class Rb extends InlineTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->open('RB');
			$this->pushInlineUaStructDepth(1);
		}
	}
}
