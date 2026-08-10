<?php

namespace Mpdf\Tag;

/**
 * TH (table header cell) tag handler.
 *
 * Extends Td and reuses its whole cell layout/CSS path. Only the PDF/UA-1
 * struct wiring differs: a header cell is a 'TH' element carrying a /Scope
 * attribute and a /ID that TD cells reference through /Headers (HTML
 * headers="id"). Rather than round-tripping through a throwaway TD, Th
 * overrides the three struct hooks Td exposes — pdfuaCellStructType(),
 * pdfuaCellStructAttrs() and pdfuaRegisterCellId() — so the TH element is
 * built ONCE and its id / Headers / Scope are registered against it (audit E12).
 *
 * The artifact-scope guard lives in Td::pdfuaOpenCellStruct(), so a <th> in a
 * running header/footer renders as pagination artifact rather than attaching
 * its id to the Document root.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — TH table element
 *   - ISO 32000-1:2008 Table 349 — /Scope attribute (Column, Row, Both)
 *   - ISO 14289-1:2014 §7.5 — Matterhorn 09-004/005: /Headers + /ID
 */
class Th extends Td
{

	/**
	 * A header cell is a 'TH' struct element (Td → 'TD').
	 *
	 * @return string
	 */
	protected function pdfuaCellStructType()
	{
		return 'TH';
	}

	/**
	 * Add the /Scope attribute on top of the shared /Headers / /ColSpan /
	 * /RowSpan attributes built by Td.
	 *
	 * ISO 32000-1 Table 349 — /Scope values: Column, Row, Both. HTML5 scope:
	 * col/colgroup → Column, row/rowgroup → Row. HTML5 has no "both" value but we
	 * accept it for explicit author intent on TH cells that label both axes.
	 *
	 * @param array $attr  the <th> tag's parsed HTML attributes
	 * @return array
	 */
	protected function pdfuaCellStructAttrs($attr)
	{
		$cellAttrs = parent::pdfuaCellStructAttrs($attr);

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
		$cellAttrs['Scope'] = $scope;

		return $cellAttrs;
	}

	/**
	 * Write the /ID onto the TH struct dict and register it so TD /Headers can
	 * cross-reference this header cell (Matterhorn 09-004/005).
	 *
	 * Two requirements:
	 *   (1) The bytes of this /ID must equal the bytes Td writes into the matching
	 *       /Headers entry. HTML id values may contain characters illegal in PDF
	 *       names (parens, brackets, %, /, whitespace …) so both sides normalise
	 *       via sanitiseIdForPdf().
	 *   (2) The synthesised fallback (for a <th> with no id="") must be unique
	 *       across the document. The (tableLevel,row,col) triple alone collides
	 *       between two tables at the same nesting level on the same page, so we
	 *       lean on AriaIdResolver's monotonic counter instead.
	 *
	 * @param array                      $attr      the <th> tag's parsed HTML attributes
	 * @param \Mpdf\Ua\StructureElement  $cellElem  the TH struct element
	 * @return void
	 */
	protected function pdfuaRegisterCellId($attr, $cellElem)
	{
		if (!empty($attr['ID'])) {
			$thId = \Mpdf\Ua\StructureElement::sanitiseIdForPdf($attr['ID']);
		} else {
			$counter = $this->ua->getAriaIdResolver()->nextSyntheticThCounter();
			$thId = 'th-' . $this->mpdf->tableLevel . '-' . $this->mpdf->row
				. '-' . $this->mpdf->col . '-' . $counter;
		}
		$cellElem->setId($thId);

		if (!empty($attr['ID'])) {
			$this->ua->getAriaIdResolver()->registerId($attr['ID'], $cellElem);
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		$this->mpdf->SetStyle('B', false);
		parent::close($ahtml, $ihtml);
	}
}
