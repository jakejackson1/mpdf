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
			// discardTop() pops the TD AND removes it from the parent TR's
			// children, otherwise the discarded TD remains in /K and adds a
			// phantom cell that breaks ISO 14289-1 §7.2 test 43 (rows must have
			// equal column counts).
			$this->ua->getStructureTree()->discardTop();

			// Determine scope from HTML scope attribute (default: Column).
			// ISO 32000-1 Table 349 — /Scope values: Column, Row, Both.
			// HTML5 scope values map to PDF /Scope by axis:
			//   col, colgroup → Column (axis = column)
			//   row, rowgroup → Row    (axis = row)
			// HTML5 has no "both" value, but we accept it for forward compatibility
			// with explicit author intent on TH cells that label both axes.
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
			// rationale. TH is treated identically to TD by veraPDF for the
			// row-column-count check.
			if (isset($attr['COLSPAN']) && preg_match('/^\d+$/', $attr['COLSPAN']) && $attr['COLSPAN'] > 1) {
				$thAttrs['ColSpan'] = (int) $attr['COLSPAN'];
			}
			if (isset($attr['ROWSPAN']) && preg_match('/^\d+$/', $attr['ROWSPAN']) && $attr['ROWSPAN'] > 1) {
				$thAttrs['RowSpan'] = (int) $attr['ROWSPAN'];
			}

			$this->ua->getStructureTree()->open('TH', $thAttrs);

			// Assign a unique /ID to this TH struct element so that TD cells can
			// reference it via /Headers (Matterhorn 09-004/005).
			// Use the HTML id attribute when present; otherwise synthesise one.
			//
			// Two requirements drive the handling here:
			//   (1) The bytes emitted as /ID on this TH must equal the bytes
			//       emitted as a name in any matching TD's /Headers array. The
			//       HTML id attribute may legally contain characters that are
			//       illegal in PDF names (parens, brackets, %, /, whitespace, …)
			//       so we normalise via StructureElement::sanitiseIdForPdf()
			//       before storing. Td.php applies the identical normalisation
			//       to each token in headers="...".
			//   (2) The synthesised fallback must be unique across the whole
			//       document. The (tableLevel,row,col) triple alone collides
			//       between two tables at the same nesting level on the same
			//       page — AriaIdResolver::nextSyntheticThCounter() guarantees
			//       monotonic uniqueness instead.
			$thElem = $this->ua->getStructureTree()->getCurrent();
			if (!empty($attr['ID'])) {
				$thId = \Mpdf\Ua\StructureElement::sanitiseIdForPdf($attr['ID']);
			} else {
				$counter = $this->ua->getAriaIdResolver()->nextSyntheticThCounter();
				$thId = 'th-' . $this->mpdf->tableLevel . '-' . $this->mpdf->row
					. '-' . $this->mpdf->col . '-' . $counter;
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
