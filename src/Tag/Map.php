<?php

namespace Mpdf\Tag;

/**
 * PDF/UA-1 — HTML <map> handler.
 *
 * <map name="…"> defines a named registry of <area> hotspots that an
 * <img usemap="#…"> may reference. The element is parser-side metadata only:
 * it produces no layout output and is NOT added to the structure tree (the
 * Link struct elements for each <area> are pushed at image-render time, under
 * the host image's Figure).
 *
 * Spec:
 *   - HTML5 §4.8.13 — the <map> element.
 *   - ISO 32000-1:2008 §12.5.6.5 — Link annotation.
 *
 * Ignored when PDFUA is off (no struct tree to attach to).
 */
class Map extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		if (!$this->mpdf->PDFUA) {
			return;
		}
		if (empty($attr['NAME'])) {
			// HTML5 §4.8.13: name is required. Without it the map is unreferenceable.
			if ($this->ua !== null) {
				$this->ua->addWarning('PDF/UA-1: <map> missing name attribute; ignored.');
			}
			return;
		}
		$name = strtolower($attr['NAME']);
		if (!isset($this->mpdf->pdfUaImageMaps[$name])) {
			$this->mpdf->pdfUaImageMaps[$name] = [];
		}
		$this->mpdf->pdfUaCurrentMapName = $name;
	}

	public function close(&$ahtml, &$ihtml)
	{
		if (!$this->mpdf->PDFUA) {
			return;
		}
		$this->mpdf->pdfUaCurrentMapName = null;
	}
}
