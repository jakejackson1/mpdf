<?php

namespace Mpdf\Tag;

use Mpdf\Ua\UaPolicy;

/**
 * HTML <area> handler (HTML5 §4.8.14): a clickable region inside a <map>.
 *
 * HTML-void; no rendering happens here. The handler validates inputs, enforces
 * the strict/auto missing-alt policy (Matterhorn 28-002), and appends the area
 * to the parent <map>'s registry. Actual Link annotations and Link struct
 * elements are emitted in Mpdf::printobjectbuffer() once the host <img usemap>
 * has been laid out.
 *
 * @see ISO 32000-1:2008 §12.5.6.5 (Link annotation /Rect /A /Contents).
 * @see ISO 32000-1:2008 §14.8 Table 335 (Link struct element).
 * @see ISO 14289-1:2014 §7.18 (interactive annotation tagging).
 */
class Area extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		if (!$this->mpdf->PDFUA) {
			return;
		}
		$registry = $this->ua->getImageMapRegistry();
		$mapName  = $registry->getCurrentMapName();
		if ($mapName === null) {
			// <area> outside any open <map>. HTML5 §4.8.14 also permits <area>
			// inside <picture>, but only the <map> case maps to a PDF link
			// annotation.
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

		// PDF/UA-1 — javascript:/vbscript: (and friends) hrefs have no
		// accessible alternative (Matterhorn 17-001 + 28-002). Mirror the
		// Tag\A::open() policy: strict throws, auto skips the area entirely
		// (unlike <a>, <area> is HTML-void with no inner text to preserve).
		if ($href !== null && $href !== '' && UaPolicy::isPolicyBlockedHref($href)) {
			if (empty($this->mpdf->PDFUAauto)) {
				throw new \Mpdf\MpdfException(
					'PDF/UA-1 Matterhorn 17-001 / 28-002: <area href="'
					. UaPolicy::formatHrefForMessage($href)
					. '"> uses a scheme with no accessible alternative. '
					. 'Remove the area, supply a real URL, or enable PDFUAauto '
					. 'to drop the area silently.'
				);
			}
			if ($this->ua !== null) {
				$this->ua->addWarning(
					'PDF/UA-1: <area href="'
					. UaPolicy::formatHrefForMessage($href)
					. '"> stripped (no Link annotation emitted) — '
					. 'scheme has no accessible alternative.'
				);
			}
			return;
		}

		// Missing alt becomes a Matterhorn 28-002 violation once the link
		// annotation is emitted: reject in strict mode, synthesise in auto
		// mode. Mirrors the <img> policy (Matterhorn 13-004) and Tag\A::open
		// for empty <a href>.
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
			// No href = no clickable region, so no annotation will be emitted
			// and no 28-002 violation can occur. Warn but do not fail strict.
			if ($this->ua !== null) {
				$this->ua->addWarning('PDF/UA-1: <area> without href in <map name="' . $mapName . '"> — skipped (no clickable region).');
			}
			return;
		}

		$registry->addArea($shape, $coords, $href, $alt, $target);
	}

	public function close(&$ahtml, &$ihtml)
	{
		// <area> is HTML-void; WriteHTML's self-closing path calls this
		// immediately after open().
	}

	/**
	 * Parse a coords="x1,y1,…" attribute into an ordered list of floats.
	 *
	 * Tolerates whitespace and/or comma separators. Non-numeric tokens are
	 * skipped; the shape-conversion step rejects under-sized results.
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
