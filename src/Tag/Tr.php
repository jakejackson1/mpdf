<?php

namespace Mpdf\Tag;

use Mpdf\Css\Border;

class Tr extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{

		$this->mpdf->lastoptionaltag = 'TR'; // Save current HTML specified optional endtag
		$this->cssManager->tbCSSlvl++;
		$this->mpdf->row++;
		$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['nr'] ++;
		$this->mpdf->col = -1;
		$properties = $this->cssManager->MergeCSS('TABLE', 'TR', $attr);

		// write pagebreak markers into row list, so _tableWrite can respect it
		if (isset($properties['PAGE-BREAK-BEFORE']) && strtoupper($properties['PAGE-BREAK-BEFORE']) === 'AVOID'
			&& !$this->mpdf->ColActive && !$this->mpdf->keep_block_together && !isset($attr['PAGEBREAKAVOIDCHECKED'])) {
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['pagebreak-before'][$this->mpdf->row] = 'avoid';
		}

		if (isset($properties['PAGE-BREAK-AFTER']) && strtoupper($properties['PAGE-BREAK-AFTER']) === 'AVOID'
			&& !$this->mpdf->ColActive && !$this->mpdf->keep_block_together && !isset($attr['PAGEBREAKAVOIDCHECKED'])) {
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['pagebreak-before'][$this->mpdf->row + 1] = 'avoid';
		}

		if (!$this->mpdf->simpleTables && (!isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['borders_separate'])
				|| !$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['borders_separate'])) {
			if (!empty($properties['BORDER-LEFT'])) {
				$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trborder-left'][$this->mpdf->row] = $properties['BORDER-LEFT'];
			}
			if (!empty($properties['BORDER-RIGHT'])) {
				$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trborder-right'][$this->mpdf->row] = $properties['BORDER-RIGHT'];
			}
			if (!empty($properties['BORDER-TOP'])) {
				$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trborder-top'][$this->mpdf->row] = $properties['BORDER-TOP'];
			}
			if (!empty($properties['BORDER-BOTTOM'])) {
				$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trborder-bottom'][$this->mpdf->row] = $properties['BORDER-BOTTOM'];
			}
		}

		if (isset($properties['BACKGROUND-COLOR'])) {
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'] ? $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'] : [];
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'][$this->mpdf->row] = $properties['BACKGROUND-COLOR'];
		} elseif (isset($attr['BGCOLOR'])) {
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'] ? $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'] : [];
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['bgcolor'][$this->mpdf->row] = $attr['BGCOLOR'];
		}

		/* -- BACKGROUNDS -- */
		if (isset($properties['BACKGROUND-GRADIENT']) && !$this->mpdf->kwt && !$this->mpdf->ColActive) {
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trgradients'][$this->mpdf->row] = $properties['BACKGROUND-GRADIENT'];
		}

		// FIXME: undefined variable $currblk
		if (!empty($properties['BACKGROUND-IMAGE']) && !$this->mpdf->kwt && !$this->mpdf->ColActive) {
			$ret = $this->mpdf->SetBackground($properties, $currblk['inner_width']);
			if ($ret) {
				$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trbackground-images'][$this->mpdf->row] = $ret;
			}
		}
		/* -- END BACKGROUNDS -- */

		if (isset($properties['TEXT-ROTATE'])) {
			$this->mpdf->trow_text_rotate = $properties['TEXT-ROTATE'];
		}
		if (isset($attr['TEXT-ROTATE'])) {
			$this->mpdf->trow_text_rotate = $attr['TEXT-ROTATE'];
		}

		if ($this->mpdf->tablethead) {
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_thead'][$this->mpdf->row] = true;
		}
		if ($this->mpdf->tabletfoot) {
			$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_tfoot'][$this->mpdf->row] = true;
		}

		// Push TR beneath its row-grouping element (THead/TBody/TFoot). ISO
		// 32000-1:2008 §14.8 Table 333 — TR belongs to a row group, not directly
		// to Table. When the HTML wrote rows straight under <table> the stack top
		// is the Table itself, so synthesise a TBody to hold the implicit rows; it
		// stays open across consecutive group-less rows and is collapsed by
		// Table::close() (or by the next explicit row group).
		if ($this->mpdf->PDFUA) {
			$tree = $this->ua->getStructureTree();
			if (!$tree->isInArtifact() && $tree->getCurrent()->getType() === 'Table') {
				$tree->open('TBody');
			}
			$tree->open('TR');

			$trElem = $this->ua->getStructureTree()->getCurrent();
			if (!empty($attr['ID'])) {
				$this->ua->getAriaIdResolver()->registerId($attr['ID'], $trElem);
			}
			foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
				'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $ariaKey) {
				if (!empty($attr[$ariaKey])) {
					$this->ua->getAriaIdResolver()->queue($trElem, strtolower($ariaKey), $attr[$ariaKey]);
				}
			}
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// ISO 32000-1:2008 §14.8 Table 333 — pop the TR struct element.
		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->close();
		}

		if ($this->mpdf->tableLevel) {
			// If Border set on TR - Update right border
			if (isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trborder-left'][$this->mpdf->row])) {
				$c = & $this->mpdf->cell[$this->mpdf->row][$this->mpdf->col];
				if ($c) {
					if ($this->mpdf->packTableData) {
						$cell = $this->mpdf->_unpackCellBorder($c['borderbin']);
					} else {
						$cell = $c;
					}
					$cell['border_details']['R'] = $this->mpdf->border_details(
						$this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['trborder-right'][$this->mpdf->row]
					);
					$this->mpdf->setBorder($cell['border'], Border::RIGHT, $cell['border_details']['R']['s']);
					if ($this->mpdf->packTableData) {
						$c['borderbin'] = $this->mpdf->_packCellBorder($cell);
						unset($c['border'], $c['border_details']);
					} else {
						$c = $cell;
					}
				}
			}
			$this->mpdf->lastoptionaltag = '';
			unset($this->cssManager->tablecascadeCSS[$this->cssManager->tbCSSlvl]);
			$this->cssManager->tbCSSlvl--;
			$this->mpdf->trow_text_rotate = '';
			$this->mpdf->tabletheadjustfinished = false;
		}
	}
}
