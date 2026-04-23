<?php

namespace Mpdf\Tag;

/**
 * PDF/UA-1 Phase 4 — DT (definition term) tag handler.
 *
 * StructType::fromHtmlTag('DT') maps to 'Lbl'. BlockTag's PDFUA hook would
 * open 'Lbl' directly onto the struct stack. However, the spec requires that
 * Lbl be a child of LI (not a direct child of L). When the current struct
 * parent is L (no explicit LI in the HTML), this handler opens an implicit LI
 * first, then delegates to BlockTag which opens Lbl.
 *
 * On a new DT following a previous DT/DD pair, close the previous implicit LI
 * before opening a new one.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — LI, Lbl, LBody elements
 *   - Tagged PDF Best Practice Guide §4.2.3 — DL → L → LI → (Lbl + LBody)
 *
 * @see Dl   closes any leftover implicit LI on </dl>
 * @see Dd   same implicit-LI logic for the definition body
 */
class Dt extends BlockTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		// If the previous DT/DD left an implicit LI open and we're starting a new
		// term, close the old implicit LI before opening a fresh one.
		if ($this->mpdf->PDFUA && $this->ua->isOpenedImplicitLI()) {
			$this->ua->getStructureTree()->close(); // close previous implicit LI
			$this->ua->setOpenedImplicitLI(false);
		}

		// When the current struct parent is L (the DL element), open an implicit LI
		// to satisfy the required L → LI → Lbl containment hierarchy.
		if ($this->mpdf->PDFUA
			&& !$this->mpdf->tableLevel
			&& $this->ua->getStructureTree()->getCurrent()->getType() === 'L'
		) {
			$this->ua->getStructureTree()->open('LI');
			$this->ua->setOpenedImplicitLI(true);
		}

		// BlockTag::open() will push Lbl (via StructType::fromHtmlTag('DT') = 'Lbl').
		parent::open($attr, $ahtml, $ihtml);
	}

	public function close(&$ahtml, &$ihtml)
	{
		// BlockTag::close() pops Lbl; implicit LI remains open until Dd.php closes it
		// (or Dl.php closes it on </dl>).
		parent::close($ahtml, $ihtml);
	}
}
