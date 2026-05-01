<?php

namespace Mpdf\Tag;

use Mpdf\Mpdf;

class A extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		if (isset($attr['NAME']) && $attr['NAME'] != '') {
			$e = '';
			/* -- BOOKMARKS -- */
			if ($this->mpdf->anchor2Bookmark) {
				$objattr = [];
				$objattr['CONTENT'] = htmlspecialchars_decode($attr['NAME'], ENT_QUOTES);
				$objattr['type'] = 'bookmark';
				if (!empty($attr['LEVEL'])) {
					$objattr['bklevel'] = $attr['LEVEL'];
				} else {
					$objattr['bklevel'] = 0;
				}
				$e = Mpdf::OBJECT_IDENTIFIER . "type=bookmark,objattr=" . serialize($objattr) . Mpdf::OBJECT_IDENTIFIER;
			}
			/* -- END BOOKMARKS -- */
			if ($this->mpdf->tableLevel) { // *TABLES*
				$this->mpdf->_saveCellTextBuffer($e, '', $attr['NAME']); // *TABLES*
			} // *TABLES*
			else { // *TABLES*
				$this->mpdf->_saveTextBuffer($e, '', $attr['NAME']); //an internal link (adds a space for recognition)
			} // *TABLES*
		}

		// PDF/UA-1 — distinguish hyperlink anchors from destination anchors.
		//
		// HTML5 §4.5.1: an <a> with no `href` (or with an empty/whitespace-only
		// `href`) is not a hyperlink — it is plain inline text and, if `name`
		// or `id` is present, a destination anchor only.
		//
		// PDF representation:
		//   - Hyperlink → Link struct element with OBJR to a link annotation
		//     (ISO 32000-1 §14.8.2.4 Table 335; ISO 14289-1 §7.18.5 / Matterhorn 02-003).
		//   - Destination anchor → no struct element at all; the surrounding
		//     block tags the inner text. The /Dests catalog registration is
		//     handled by the NAME/_saveTextBuffer path above and is independent
		//     of struct element creation.
		//
		// An empty/whitespace `href` is treated as "not a hyperlink". This
		// closes the M2 audit gap where <a name="x" href="">…</a> opened a
		// Link struct element with an empty `_href`, which then either got
		// pruned in PDFUAauto mode or threw in strict mode — both surprising
		// for what is plausibly just a templating artefact around a destination
		// anchor.
		//
		// pruneEmptyLinks() / findFirstEmptyLinkHref() (StructureTree) remain
		// in place as defence-in-depth for the residual case of authored
		// hyperlinks with non-empty `href` but empty bodies — which still
		// produce empty Link elements and still must throw / be pruned.
		$rawHref = isset($attr['HREF']) ? $attr['HREF'] : null;
		$isHyperlink = $rawHref !== null && trim($rawHref) !== '';

		if ($isHyperlink) {
			$this->mpdf->InlineProperties['A'] = $this->mpdf->saveInlineProperties();
			$properties = $this->cssManager->MergeCSS('INLINE', 'A', $attr);
			if (!empty($properties)) {
				$this->mpdf->setCSS($properties, 'INLINE');
			}
			$this->mpdf->HREF = $attr['HREF']; // mPDF 5.7.4 URLs

			// PDF/UA-1 Phase 4 — push a Link struct element for hyperlinks.
			// Destination anchors (<a name="...">) do not produce struct elements.
			if ($this->mpdf->PDFUA) {
				$structAttrs = [];
				if (isset($attr['LANG'])) {
					$structAttrs['Lang'] = $attr['LANG'];
				}
				// Matterhorn 28-002 — in PDFUAauto we pre-set /Alt synthesised
				// from the href so a Link wrapping only decorative content
				// (e.g. <a><img alt=""></a>) still has an accessible name.
				// When real link text is present, the inner content remains
				// the primary accessible name and /Alt acts as a fallback for
				// the link annotation (legitimate per ISO 32000-1 §14.7.2 Table 322).
				if (!empty($this->mpdf->PDFUAauto)) {
					$structAttrs['Alt'] = 'Link to ' . $attr['HREF'];
				}
				$this->ua->getStructureTree()->open('Link', $structAttrs);

				// Register ARIA ID references
				$elem = $this->ua->getStructureTree()->getCurrent();
				// Stash the source href on the element so StructureWriter's
				// strict-mode empty-Link check can quote it in its exception.
				// '_href' is filtered out by StructureWriter (it only emits
				// known PDF dict keys), so it is safe to use as a private hint.
				$elem->setAttribute('_href', $attr['HREF']);
				if (!empty($attr['ID'])) {
					$this->ua->getAriaIdResolver()->registerId($attr['ID'], $elem);
				}
				foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
						  'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $k) {
					if (!empty($attr[$k])) {
						$this->ua->getAriaIdResolver()->queue($elem, strtolower($k), $attr[$k]);
					}
				}

				// Capture the Link struct element so Mpdf::Link() can attach the
				// element reference to the PageLinks entry. writeAnnotations()
				// reads it back, allocates a /StructParent integer for the link
				// annotation, and adds an OBJR kid to the element so the link
				// annotation is reachable from the structure tree (ISO 14289-1
				// §7.18.5 / Matterhorn 02-003).
				$this->mpdf->pdfuaLinkStructElem = $elem;
				$this->mpdf->pdfuaAnchorStructType = 'Link';
			}
		} elseif ($this->mpdf->PDFUA) {
			// Non-hyperlink <a> (destination anchor or empty/whitespace href).
			// Emit a Span struct element only when the tag carries inline
			// accessibility metadata (Lang, aria-label) that needs a host
			// element to attach to. Otherwise emit nothing — the surrounding
			// block tags the inner text, and the /Dests catalog (populated via
			// the NAME path above) owns the destination registration.
			$structAttrs = [];
			if (isset($attr['LANG']) && $attr['LANG'] !== '') {
				$structAttrs['Lang'] = $attr['LANG'];
			}
			if (isset($attr['ARIA-LABEL']) && $attr['ARIA-LABEL'] !== '') {
				$structAttrs['Alt'] = $attr['ARIA-LABEL'];
			}
			if (!empty($structAttrs)) {
				$this->ua->getStructureTree()->open('Span', $structAttrs);
				$elem = $this->ua->getStructureTree()->getCurrent();
				if (!empty($attr['ID'])) {
					$this->ua->getAriaIdResolver()->registerId($attr['ID'], $elem);
				}
				foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
						  'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $k) {
					if (!empty($attr[$k])) {
						$this->ua->getAriaIdResolver()->queue($elem, strtolower($k), $attr[$k]);
					}
				}
				$this->mpdf->pdfuaAnchorStructType = 'Span';
			}
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// PDF/UA-1 — close whichever struct element open() pushed (Link for
		// hyperlinks, Span for non-hyperlinks with Lang/aria-label, none for
		// bare destination anchors). The flag lives on Mpdf because the Tag
		// dispatcher creates a fresh Tag\A instance per open/close call.
		if ($this->mpdf->PDFUA && $this->mpdf->pdfuaAnchorStructType !== null) {
			$this->ua->getStructureTree()->close();
			$this->mpdf->pdfuaAnchorStructType = null;
		}

		// PDF/UA-1 — clear the captured Link struct element ref. Any subsequent
		// Mpdf::Link() call (outside an <a href> scope) must not pick up a stale
		// reference from the previous link.
		$this->mpdf->pdfuaLinkStructElem = null;

		$this->mpdf->HREF = '';
		if (isset($this->mpdf->InlineProperties['A'])) {
			$this->mpdf->restoreInlineProperties($this->mpdf->InlineProperties['A']);
		}
		unset($this->mpdf->InlineProperties['A']);
	}
}
