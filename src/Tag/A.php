<?php

namespace Mpdf\Tag;

use Mpdf\Mpdf;

class A extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		if (isset($attr['NAME']) && $attr['NAME'] != '') {
			$e = '';
			/* -- BOOKMARKS -- */
			if ($this->mpdf->anchor2Bookmark) {
				$objattr = [];
				$objattr['CONTENT'] = htmlspecialchars_decode($attr['NAME'], ENT_QUOTES);
				$objattr['type'] = 'bookmark';
				if (!empty($attr['LEVEL'])) {
					$objattr['bklevel'] = $attr['LEVEL'];
				} else {
					$objattr['bklevel'] = 0;
				}
				$e = Mpdf::OBJECT_IDENTIFIER . "type=bookmark,objattr=" . serialize($objattr) . Mpdf::OBJECT_IDENTIFIER;
			}
			/* -- END BOOKMARKS -- */
			if ($this->mpdf->tableLevel) { // *TABLES*
				$this->mpdf->_saveCellTextBuffer($e, '', $attr['NAME']); // *TABLES*
			} // *TABLES*
			else { // *TABLES*
				$this->mpdf->_saveTextBuffer($e, '', $attr['NAME']); //an internal link (adds a space for recognition)
			} // *TABLES*
		}
		if (isset($attr['HREF'])) {
			$this->mpdf->InlineProperties['A'] = $this->mpdf->saveInlineProperties();
			$properties = $this->cssManager->MergeCSS('INLINE', 'A', $attr);
			if (!empty($properties)) {
				$this->mpdf->setCSS($properties, 'INLINE');
			}
			$this->mpdf->HREF = $attr['HREF']; // mPDF 5.7.4 URLs

			// PDF/UA-1 Phase 4 — push a Link struct element for hyperlinks.
			// Destination anchors (<a name="...">) do not produce struct elements.
			if ($this->mpdf->PDFUA) {
				$structAttrs = [];
				if (isset($attr['LANG'])) {
					$structAttrs['Lang'] = $attr['LANG'];
				}
				$this->ua->getStructureTree()->open('Link', $structAttrs);

				// Register ARIA ID references
				$elem = $this->ua->getStructureTree()->getCurrent();
				if (!empty($attr['ID'])) {
					$this->ua->getAriaIdResolver()->registerId($attr['ID'], $elem);
				}
				foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
				          'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $k) {
					if (!empty($attr[$k])) {
						$this->ua->getAriaIdResolver()->queue($elem, strtolower($k), $attr[$k]);
					}
				}
			}
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// PDF/UA-1 — close the Link struct element (only if one was opened for HREF links).
		if ($this->mpdf->PDFUA && $this->mpdf->HREF !== '') {
			$this->ua->getStructureTree()->close();
		}

		$this->mpdf->HREF = '';
		if (isset($this->mpdf->InlineProperties['A'])) {
			$this->mpdf->restoreInlineProperties($this->mpdf->InlineProperties['A']);
		}
		unset($this->mpdf->InlineProperties['A']);
	}
}
