<?php

namespace Mpdf\Tag;

/**
 * PDF/UA-1 Phase 4 — LI tag handler.
 *
 * BlockTag::open() already calls structureTree->open('LI') via
 * StructType::fromHtmlTag() and sets $currblk['pdfua_type'] = 'LI'.
 * This subclass additionally:
 *   a) pushes a Lbl child element for position:outside markers (disc, circle,
 *      square, ordered counters, U+ symbols) — not for position:inside, none,
 *      or image markers which do not pass through the 'listmarker' objectbuffer
 *      path in printobjectbuffer();
 *   b) pushes an LBody child element for the list item text content.
 *
 * The Lbl element is created (pushed then immediately popped) so that a
 * reference to it can be stored in the blk dict. When printobjectbuffer()
 * later renders the 'listmarker' object, it retrieves the reference and calls
 * StructureTree::addContentForElement() to attach the MCID to Lbl without
 * needing Lbl on the open-element stack — exactly the deferred-render pattern
 * addContentForElement() was designed for.
 *
 * The struct hierarchy produced is: LI → [Lbl, LBody].
 *
 * Limitation: position:inside markers, list-style-type:none, and CSS image
 * markers do not produce a Lbl element. These cases are known gaps in the
 * Matterhorn 21-001 implementation; only the position:outside text/symbol
 * path is covered here.
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
 *   - Matterhorn Protocol 1.1 condition 21-001 — LI children must be Lbl/LBody
 *
 * @see BlockTag  open() pushes LI; this class adds Lbl (deferred) + LBody on top
 */
class Li extends BlockTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		// After BlockTag::open() has pushed 'LI' onto the struct tree and called
		// _setListMarker() (which sets $this->mpdf->listitem to a non-empty array
		// when a position:outside marker will be rendered via printobjectbuffer()),
		// optionally push Lbl and always push LBody.
		// Guard: PDFUA active, outside table, LI pdfua_type set, not in artifact.
		if ($this->mpdf->PDFUA
			&& !$this->mpdf->tableLevel
			&& isset($this->mpdf->blk[$this->mpdf->blklvl]['pdfua_type'])
			&& $this->mpdf->blk[$this->mpdf->blklvl]['pdfua_type'] === 'LI'
			&& empty($this->mpdf->blk[$this->mpdf->blklvl]['pdfua_artifact'])
		) {
			$blklvl = $this->mpdf->blklvl;
			$structureTree = $this->ua->getStructureTree();

			// Lbl — only when a position:outside marker will reach printobjectbuffer().
			// $this->mpdf->listitem is set to a non-empty array by _setListMarker()
			// exactly for the disc/circle/square/counter/U+ outside-position paths.
			// For position:inside, list-style-type:none, and image markers, listitem
			// is empty/false, so we skip Lbl to avoid empty struct elements.
			if (is_array($this->mpdf->listitem) && !empty($this->mpdf->listitem)) {
				// Open Lbl as a child of LI, capture the element reference, then pop
				// Lbl off the stack immediately so LBody becomes the stack top.
				// The stored reference is used by printobjectbuffer() via
				// addContentForElement() when the listmarker object actually renders.
				$structureTree->open('Lbl');
				$this->mpdf->blk[$blklvl]['pdfua_li_lbl_elem'] = $structureTree->getCurrent();
				$structureTree->close();
			}

			// Push LBody as the content container for this list item.
			// ISO 32000-1 §14.8 Table 333 — LBody is the content wrapper inside LI.
			$structureTree->open('LBody');
			// Update pdfua_type so finishFlowingBlock() emits the BDC as LBody.
			$this->mpdf->blk[$blklvl]['pdfua_type'] = 'LBody';
			// Record that we owe an extra LI close (beyond BlockTag's own close call).
			$this->mpdf->blk[$blklvl]['pdfua_li_lbody'] = true;
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
