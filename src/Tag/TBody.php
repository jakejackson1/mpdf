<?php

namespace Mpdf\Tag;

class TBody extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		$this->mpdf->tablethead = 0;
		$this->mpdf->tabletfoot = 0;
		$this->mpdf->lastoptionaltag = 'TBODY'; // Save current HTML specified optional endtag
		$this->cssManager->tbCSSlvl++;
		$this->cssManager->MergeCSS('TABLE', 'TBODY', $attr);

		// ISO 32000-1:2008 §14.8 Table 333 — TBody is a table row-grouping
		// element; the enclosed TR rows nest beneath it. Collapse any TBody that
		// Tr::open() synthesised for preceding group-less rows first.
		if ($this->mpdf->PDFUA) {
			$tree = $this->ua->getStructureTree();
			$tree->closeRowGroup();
			$tree->open('TBody');
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// ISO 32000-1:2008 §14.8 Table 333 — pop the TBody row-group element.
		if ($this->mpdf->PDFUA) {
			$this->ua->getStructureTree()->closeRowGroup();
		}

		$this->mpdf->lastoptionaltag = '';
		unset($this->cssManager->tablecascadeCSS[$this->cssManager->tbCSSlvl]);
		$this->cssManager->tbCSSlvl--;
	}
}
