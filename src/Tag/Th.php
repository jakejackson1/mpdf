<?php

namespace Mpdf\Tag;

/**
 * TH (table header cell) tag handler.
 *
 * Extends Td. Td::open() runs the cell layout/CSS code and pushes a 'TD' struct
 * element; this override replays parent::open() then swaps the 'TD' for 'TH'
 * with a /Scope attribute and a unique /ID that TD cells can reference through
 * /Headers (HTML headers="id"). Td::open() couples the layout work with the
 * struct-tree push, so we cannot call parent::open() and skip the push.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — TH table element
 *   - ISO 32000-1:2008 Table 349 — /Scope attribute (Column, Row, Both)
 *   - ISO 14289-1:2014 §7.5 — Matterhorn 09-004/005: /Headers + /ID
 */
class Th extends Td
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		parent::open($attr, $ahtml, $ihtml);

		if ($this->mpdf->PDFUA) {
			// discardTop() pops the TD AND removes it from the parent TR's
			// children. A plain pop would leave the discarded TD in /K and
			// add a phantom cell that breaks ISO 14289-1 §7.2 test 43 (rows
			// must have equal column counts).
			$this->ua->getStructureTree()->discardTop();

			// ISO 32000-1 Table 349 — /Scope values: Column, Row, Both.
			// HTML5 scope: col/colgroup → Column, row/rowgroup → Row. HTML5
			// has no "both" value but we accept it for explicit author intent
			// on TH cells that label both axes.
			$scope = 'Column';
			if (!empty($attr['SCOPE'])) {
				$s = strtolower($attr['SCOPE']);
				if ($s === 'row' || $s === 'rowgroup') {
					$scope = 'Row';
				} elseif ($s === 'col' || $s === 'colgroup') {
					$scope = 'Column';
				} elseif ($s === 'both') {
					$scope = 'Both';
				}
			}

			$thAttrs = ['Scope' => $scope];
			// ISO 14289-1 §7.5 / Matterhorn 09-008 — see Td::open() for the
			// rationale; TH is treated identically to TD by veraPDF for the
			// row-column-count check.
			if (isset($attr['COLSPAN']) && preg_match('/^\d+$/', $attr['COLSPAN']) && $attr['COLSPAN'] > 1) {
				$thAttrs['ColSpan'] = (int) $attr['COLSPAN'];
			}
			if (isset($attr['ROWSPAN']) && preg_match('/^\d+$/', $attr['ROWSPAN']) && $attr['ROWSPAN'] > 1) {
				$thAttrs['RowSpan'] = (int) $attr['ROWSPAN'];
			}

			$this->ua->getStructureTree()->open('TH', $thAttrs);

			// /ID lets TD cells reference this TH via /Headers (Matterhorn 09-004/005).
			// Two requirements:
			//   (1) The bytes on this /ID must equal the bytes Td.php writes
			//       into the matching /Headers entry. HTML id values may contain
			//       characters illegal in PDF names (parens, brackets, %, /,
			//       whitespace …) so both sides normalise via the same helper.
			//   (2) The synthesised fallback must be unique across the document.
			//       The (tableLevel,row,col) triple alone collides between two
			//       tables at the same nesting level on the same page, so we
			//       lean on AriaIdResolver's monotonic counter instead.
			$thElem = $this->ua->getStructureTree()->getCurrent();
			if (!empty($attr['ID'])) {
				$thId = \Mpdf\Ua\StructureElement::sanitiseIdForPdf($attr['ID']);
			} else {
				$counter = $this->ua->getAriaIdResolver()->nextSyntheticThCounter();
				$thId = 'th-' . $this->mpdf->tableLevel . '-' . $this->mpdf->row
					. '-' . $this->mpdf->col . '-' . $counter;
			}
			$thElem->setId($thId);

			if (!empty($attr['ID'])) {
				$this->ua->getAriaIdResolver()->registerId($attr['ID'], $thElem);
			}

			// ISO 14289-1:2014 §7.1 — ARIA relationship attributes map to /A entries on struct elem.
			foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
				'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $ariaKey) {
				if (!empty($attr[$ariaKey])) {
					$this->ua->getAriaIdResolver()->queue($thElem, strtolower($ariaKey), $attr[$ariaKey]);
				}
			}

			$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['pdfua_struct_elem'] = $thElem;
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		$this->mpdf->SetStyle('B', false);
		parent::close($ahtml, $ihtml);
	}
}
