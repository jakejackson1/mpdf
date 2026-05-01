<?php

namespace Mpdf\Tag;

/**
 * PDF/UA-1 — HTML <area> handler.
 *
 * <area> declares a single clickable region inside a <map>. It is HTML-void —
 * no children, no end tag (WriteHTML treats <area …/> as self-closing).
 *
 * The handler does no rendering. It validates inputs, applies the strict /
 * auto policy for missing alt text (Matterhorn 28-002 prevention), and
 * appends the area to the parent <map>'s registry on $mpdf->pdfUaImageMaps.
 *
 * The actual Link annotations and Link struct elements are emitted later,
 * inside Mpdf::printobjectbuffer(), once the host <img usemap> is laid out
 * and its placed-image rectangle is known.
 *
 * Spec:
 *   - HTML5 §4.8.14 — the <area> element (shape, coords, alt, href).
 *   - ISO 32000-1:2008 §12.5.6.5 — Link annotation /Rect /A /Contents.
 *   - ISO 32000-1:2008 §14.8 Table 335 — Link struct element.
 *   - ISO 14289-1:2014 §7.18 — interactive annotation tagging.
 *   - Matterhorn Protocol 1.1 condition 28-002 — Link annotation needs a
 *     text alternative.
 */
class Area extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		if (!$this->mpdf->PDFUA) {
			return;
		}
		$mapName = $this->mpdf->pdfUaCurrentMapName;
		if ($mapName === null) {
			// <area> outside any open <map>. HTML5 §4.8.14 says it MAY
			// appear inside <picture> too, but we only care about the
			// <map> case for PDF link-annotation purposes.
			if ($this->ua !== null) {
				$this->ua->addWarning('PDF/UA-1: <area> outside <map>; ignored.');
			}
			return;
		}

		$shape = isset($attr['SHAPE']) ? strtolower($attr['SHAPE']) : 'rect';
		$coords = $this->parseCoords(isset($attr['COORDS']) ? $attr['COORDS'] : '');
		$href = isset($attr['HREF']) ? $attr['HREF'] : null;
		$alt = isset($attr['ALT']) ? $attr['ALT'] : null;
		$target = isset($attr['TARGET']) ? $attr['TARGET'] : null;

		// Strict-mode policy: missing alt is a Matterhorn 28-002 violation
		// the moment the link annotation is emitted, so we reject it at
		// parse time when strict and synthesise a fallback when auto. This
		// mirrors the policy in Mpdf::printobjectbuffer() for <img> itself
		// (Matterhorn 13-004) and Tag\A::open() for empty <a href>.
		if ($alt === null && ($href !== null && $href !== '')) {
			if (empty($this->mpdf->PDFUAauto)) {
				throw new \Mpdf\MpdfException(
					'PDF/UA-1 (Matterhorn 28-002): <area> missing alt attribute. '
					. 'Provide alt="description" so the link annotation has a text alternative. '
					. 'Enable PDFUAauto to auto-correct (synthesises alt from href).'
				);
			}
			$alt = 'Link to ' . $href;
			if ($this->ua !== null) {
				$this->ua->addWarning('PDF/UA-1: <area> missing alt; synthesised "' . $alt . '" for Link/Alt.');
			}
		}

		if ($href === null || $href === '') {
			// No href ⇒ no clickable region to emit. Warn so authors can
			// clean up the markup; do not fail strict mode (this is a markup
			// quality issue, not a 28-002 violation since no annotation
			// will be emitted).
			if ($this->ua !== null) {
				$this->ua->addWarning('PDF/UA-1: <area> without href in <map name="' . $mapName . '"> — skipped (no clickable region).');
			}
			return;
		}

		$this->mpdf->pdfUaImageMaps[$mapName][] = [
			'shape' => $shape,
			'coords' => $coords,
			'href' => $href,
			'alt' => $alt,
			'target' => $target,
		];
	}

	public function close(&$ahtml, &$ihtml)
	{
		// <area> is HTML-void; close() is a no-op. WriteHTML's self-closing
		// path (src/Mpdf.php — preg_match('/\/$/', $e)) calls this immediately
		// after open().
	}

	/**
	 * Parse a coords="x1,y1,…" attribute into an ordered list of floats.
	 *
	 * Tolerates both whitespace and comma separators. Non-numeric tokens
	 * are skipped — the shape-conversion step will reject the area if the
	 * resulting count is too small.
	 *
	 * @param string $raw
	 * @return array
	 */
	private function parseCoords($raw)
	{
		$raw = trim((string) $raw);
		if ($raw === '') {
			return [];
		}
		$parts = preg_split('/[\s,]+/', $raw);
		$nums = [];
		foreach ($parts as $p) {
			if ($p === '' || !is_numeric($p)) {
				continue;
			}
			$nums[] = (float) $p;
		}
		return $nums;
	}
}
