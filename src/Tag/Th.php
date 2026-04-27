<?php

namespace Mpdf\Tag;

/**
 * PDF/UA-1 Phase 4 — TH (table header cell) tag handler.
 *
 * Extends Td. Overrides the PDFUA struct-tree hook to push 'TH' instead of
 * 'TD', adds a /Scope attribute (Column by default, Row when scope="row"),
 * and assigns a unique /ID to the struct element so that TD cells referencing
 * this header via the HTML headers="id" attribute can resolve it via /Headers.
 *
 * The parent Td::open() already runs layout/CSS code and pushes 'TD' onto the
 * struct tree; this class overrides only the PDFUA block at the end of open().
 * Because Td::open() adds the PDFUA hook after the main cell logic, this class
 * must replicate that specific block (not call parent::open()) and then add the
 * TH-specific additions.
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
		// Run all of Td::open() first (layout, CSS, cell dict construction,
		// and the parent PDFUA TD push). Then replace the TD struct element
		// with a TH by undoing the TD push and pushing TH instead.
		parent::open($attr, $ahtml, $ihtml);

		if ($this->mpdf->PDFUA) {
			// parent::open() pushed 'TD' onto the struct tree. Replace it with 'TH':
			// pop the TD and push TH (which is the correct type for a header cell).
			$this->ua->getStructureTree()->close();

			// Determine scope from HTML scope attribute (default: Column).
			// ISO 32000-1 Table 349 — /Scope values: Column, Row, Both.
			$scope = 'Column';
			if (!empty($attr['SCOPE'])) {
				$s = strtolower($attr['SCOPE']);
				if ($s === 'row') {
					$scope = 'Row';
				} elseif ($s === 'colgroup' || $s === 'rowgroup' || $s === 'both') {
					$scope = 'Both';
				}
			}

			$thAttrs = ['Scope' => $scope];

			$this->ua->getStructureTree()->open('TH', $thAttrs);

			// Assign a unique /ID to this TH struct element so that TD cells can
			// reference it via /Headers (Matterhorn 09-004/005).
			// Use the HTML id attribute when present; otherwise synthesise one.
			$thElem = $this->ua->getStructureTree()->getCurrent();
			if (!empty($attr['ID'])) {
				$thId = $attr['ID'];
			} else {
				// Synthesise a unique ID from the HTML tag counter.
				$thId = 'th-' . $this->mpdf->tableLevel . '-' . $this->mpdf->row . '-' . $this->mpdf->col;
			}
			$thElem->setId($thId);

			// Register the HTML id (if any) with AriaIdResolver for ARIA cross-refs.
			if (!empty($attr['ID'])) {
				$this->ua->getAriaIdResolver()->registerId($attr['ID'], $thElem);
			}

			// Queue aria-* cross-references on the TH struct element.
			// ISO 14289-1:2014 §7.1 — ARIA relationship attributes map to /A entries on struct elem.
			foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
				'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $ariaKey) {
				if (!empty($attr[$ariaKey])) {
					$this->ua->getAriaIdResolver()->queue($thElem, strtolower($ariaKey), $attr[$ariaKey]);
				}
			}

			// Update the cell dict with the TH struct element reference.
			$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['pdfua_struct_elem'] = $thElem;
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		$this->mpdf->SetStyle('B', false);
		parent::close($ahtml, $ihtml);
	}
}
