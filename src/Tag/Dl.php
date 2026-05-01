<?php

namespace Mpdf\Tag;

/**
 * DL (definition list) tag handler.
 *
 * StructType::fromHtmlTag('DL') maps to 'L', so BlockTag's PDFUA hook
 * opens an L struct element on tag open and closes it on tag close.
 * Nested DT/DD items open implicit LI parents as needed (see Dt.php
 * and Dd.php).
 *
 * On </dl>, if a DT or DD left an implicit LI open (tracked via
 * UaState::$openedImplicitLI), the implicit LI is closed first,
 * then BlockTag's close pops L.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — L grouping element
 *   - Tagged PDF Best Practice Guide §4.2.3 — DL → L; DT → Lbl; DD → LBody
 */
class Dl extends BlockTag
{

	public function close(&$ahtml, &$ihtml)
	{
		// If the last DT or DD left an implicit LI open, close it before L closes.
		// ISO 32000-1 §14.8 Table 333 — Lbl/LBody must be children of LI.
		if ($this->mpdf->PDFUA && $this->ua->isOpenedImplicitLI()) {
			$this->ua->getStructureTree()->close(); // close the implicit LI
			$this->ua->setOpenedImplicitLI(false);
		}
		parent::close($ahtml, $ihtml);
	}
}
