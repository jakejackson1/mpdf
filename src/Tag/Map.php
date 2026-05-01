<?php

namespace Mpdf\Tag;

/**
 * HTML <map> handler (HTML5 §4.8.13).
 *
 * Parser-side metadata only: produces no layout output and is not added to the
 * structure tree. The Link struct elements for each <area> are pushed under the
 * host image's Figure at image-render time. Ignored when PDFUA is off.
 *
 * @see ISO 32000-1:2008 §12.5.6.5 (Link annotation).
 */
class Map extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		if (!$this->mpdf->PDFUA) {
			return;
		}
		if (empty($attr['NAME'])) {
			// HTML5 §4.8.13: name is required.
			if ($this->ua !== null) {
				$this->ua->addWarning('PDF/UA-1: <map> missing name attribute; ignored.');
			}
			return;
		}
		$name = strtolower($attr['NAME']);
		$this->ua->getImageMapRegistry()->openMap($name);
	}

	public function close(&$ahtml, &$ihtml)
	{
		if (!$this->mpdf->PDFUA) {
			return;
		}
		$this->ua->getImageMapRegistry()->closeMap();
	}
}
