<?php

namespace Mpdf\Tag;

/**
 * PDF/UA-1 Phase 4 — LI tag handler.
 *
 * BlockTag::open() already calls structureTree->open('LI') via
 * StructType::fromHtmlTag() and sets $currblk['pdfua_type'] = 'LI'.
 * This subclass additionally pushes an LBody child element onto the
 * struct tree and updates the blk pdfua_type to 'LBody' so that
 * finishFlowingBlock() emits the content BDC under LBody (not LI).
 *
 * On close, LBody is popped here, then BlockTag::close() pops LI.
 * BlockTag::close() reads pdfua_type from the blk dict; since we set it
 * to 'LBody', it calls structureTree->close() which pops LBody. We then
 * pop LI ourselves after the parent call completes. To achieve correct
 * ordering we save a flag on the blk dict indicating an extra LI is open,
 * restore pdfua_type to 'LBody' before the parent close (which pops it),
 * and then close LI afterward.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — LI, Lbl, LBody elements
 *   - Tagged PDF Best Practice Guide §4.2.3 — LI must contain Lbl + LBody
 *
 * @see BlockTag  open() pushes LI; this class adds LBody on top
 */
class Li extends BlockTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// After BlockTag::open() has pushed 'LI' onto the struct tree and set
		// $currblk['pdfua_type'] = 'LI', push LBody as a child of LI and update
		// the blk pdfua_type so finishFlowingBlock() emits the BDC under LBody.
		// Guard: PDFUA active, outside table (tableLevel guard already prevents the
		// parent PDFUA code from running inside tables), and a real struct element
		// was opened (pdfua_type set, not null/artifact).
		if ($this->mpdf->PDFUA
			&& !$this->mpdf->tableLevel
			&& isset($this->mpdf->blk[$this->mpdf->blklvl]['pdfua_type'])
			&& $this->mpdf->blk[$this->mpdf->blklvl]['pdfua_type'] === 'LI'
			&& empty($this->mpdf->blk[$this->mpdf->blklvl]['pdfua_artifact'])
		) {
			// Push LBody as the content container for this list item.
			// ISO 32000-1 §14.8 Table 333 — LBody is the content wrapper inside LI.
			$this->ua->getStructureTree()->open('LBody');
			// Update pdfua_type so finishFlowingBlock() emits the BDC as LBody.
			$this->mpdf->blk[$this->mpdf->blklvl]['pdfua_type'] = 'LBody';
			// Record that we owe an extra LI close (beyond BlockTag's own close call).
			$this->mpdf->blk[$this->mpdf->blklvl]['pdfua_li_lbody'] = true;
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// Read the flag before parent::close() decrements blklvl and destroys the blk.
		$hasLbody = $this->mpdf->PDFUA
			&& !$this->mpdf->tableLevel
			&& isset($this->mpdf->blk[$this->mpdf->blklvl]['pdfua_li_lbody'])
			&& $this->mpdf->blk[$this->mpdf->blklvl]['pdfua_li_lbody'];

		// parent::close() flushes the content and then pops whatever pdfua_type is
		// set on the blk (which is 'LBody').  After this call the struct stack top
		// is back at LI.
		parent::close($ahtml, $ihtml);

		// Pop LI, which BlockTag::close() did not pop because pdfua_type was 'LBody'.
		if ($hasLbody) {
			$this->ua->getStructureTree()->close();
		}
	}
}
