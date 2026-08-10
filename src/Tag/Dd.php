<?php

namespace Mpdf\Tag;

/**
 * DD (definition description) tag handler.
 *
 * StructType::fromHtmlTag('DD') maps to 'LBody'. BlockTag's PDFUA hook would
 * open 'LBody' directly onto the struct stack. However, the spec requires that
 * LBody be a child of LI. When the current struct parent is L (no explicit LI
 * in the HTML), this handler opens an implicit LI first.
 *
 * If there is already an open implicit LI (from a preceding DT), DD reuses
 * it — a single LI can contain both a Lbl (from DT) and an LBody (from DD).
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — LI, Lbl, LBody elements
 *   - Tagged PDF Best Practice Guide §4.2.3 — DL → L → LI → (Lbl + LBody)
 *
 * @see Dt   handles the definition term (opens implicit LI)
 * @see Dl   closes any leftover implicit LI on </dl>
 */
class Dd extends BlockTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		// When the current struct parent is L (i.e. no implicit LI is open yet,
		// for example a bare <dd> without a preceding <dt>), open one now.
		if ($this->mpdf->PDFUA
			&& !$this->mpdf->tableLevel
			&& !$this->ua->isOpenedImplicitLI()
			&& $this->ua->getStructureTree()->getCurrent()->getType() === 'L'
		) {
			$this->ua->getStructureTree()->open('LI');
			$this->ua->setOpenedImplicitLI(true);
		}

		// BlockTag::open() will push LBody (via StructType::fromHtmlTag('DD') = 'LBody').
		parent::open($attr, $ahtml, $ihtml);
	}

	public function close(&$ahtml, &$ihtml)
	{
		// BlockTag::close() pops LBody.
		// The implicit LI stays open; it will be closed when the next DT opens
		// (in Dt::open()) or when </dl> fires (in Dl::close()).
		parent::close($ahtml, $ihtml);
	}
}
