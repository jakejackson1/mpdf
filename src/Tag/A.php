<?php

namespace Mpdf\Tag;

use Mpdf\Mpdf;
use Mpdf\Ua\UaPolicy;

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
		if (isset($attr['HREF'])) {
			$this->mpdf->InlineProperties['A'] = $this->mpdf->saveInlineProperties();
			$properties = $this->cssManager->MergeCSS('INLINE', 'A', $attr);
			if (!empty($properties)) {
				$this->mpdf->setCSS($properties, 'INLINE');
			}
			$this->mpdf->HREF = $attr['HREF']; // mPDF 5.7.4 URLs

			// PDF/UA-1 audit L2 — javascript:/vbscript: hrefs have no accessible
			// alternative (ISO 14289-1:2014 §7.18 / Matterhorn 17-001 + 28-002).
			// Most readers refuse to execute them, AT announces them verbatim,
			// and they are not keyboard-equivalent (WCAG 2.1 §2.1.1).
			//
			// Strict mode (PDFUAauto=false): throw — the user must remove the
			// link, supply a real URL, or opt into auto-mode.
			//
			// Auto mode (PDFUAauto=true): clear HREF (no Link annotation, no
			// Link struct element), open a Span struct element for any ARIA /
			// lang attributes so they are preserved, and emit a single warning.
			// The visible inner text survives as plain inline content.
			//
			// Plan: /Users/jakejackson/Sites/mpdf/.claude/plans/2026-05-01-ua1-javascript-url-handling.md
			if ($this->mpdf->PDFUA && UaPolicy::isPolicyBlockedHref($attr['HREF'])) {
				if (empty($this->mpdf->PDFUAauto)) {
					throw new \Mpdf\MpdfException(
						'PDF/UA-1 Matterhorn 17-001 / 28-002: <a href="'
						. UaPolicy::formatHrefForMessage($attr['HREF'])
						. '"> uses a scheme with no accessible alternative. '
						. 'Remove the link, supply a real URL (https:, mailto:, '
						. 'tel:, #fragment, ...), or enable PDFUAauto to strip '
						. 'the link and keep the visible text.'
					);
				}
				$this->ua->addWarning(
					'PDF/UA-1: <a href="'
					. UaPolicy::formatHrefForMessage($attr['HREF'])
					. '"> stripped (no Link annotation emitted) — '
					. 'javascript:/vbscript: schemes have no accessible alternative.'
				);
				// Strip: clear HREF so subsequent Cell() calls do not register
				// a Link annotation (Mpdf::Link is a no-op when HREF is empty
				// at the dispatch sites in Mpdf::Cell()/processFragment()).
				$this->mpdf->HREF = '';
				// Preserve ARIA / lang as a Span struct element if present, so
				// the anchor's accessibility hints survive even though the link
				// itself is gone. Mirrors InlineTag::openInlineUaStruct().
				$spanDepth = $this->openStrippedAnchorSpan($attr) ? 1 : 0;
				$this->mpdf->pdfuaStrippedAnchorStack[] = [true, $spanDepth];
				return;
			}

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
				// Record this anchor on the stack as "not stripped" so close()
				// pops a Link element here regardless of any nested anchor.
				$this->mpdf->pdfuaStrippedAnchorStack[] = [false, 0];
			}
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		// PDF/UA-1 — close balanced struct elements based on the strip stack.
		// Each open() of an <a href> pushed exactly one entry; pop it here.
		if ($this->mpdf->PDFUA && !empty($this->mpdf->pdfuaStrippedAnchorStack)) {
			$entry = array_pop($this->mpdf->pdfuaStrippedAnchorStack);
			$stripped = $entry[0];
			$spanDepth = $entry[1];
			if ($stripped) {
				// Strip path: pop the Span struct element if one was pushed.
				while ($spanDepth > 0) {
					$this->ua->getStructureTree()->close();
					$spanDepth--;
				}
			} else {
				// Normal Link path: pop the Link struct element opened above.
				$this->ua->getStructureTree()->close();
			}
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

	/**
	 * Open a Span struct element with /Lang and /Alt when PDFUA + (lang |
	 * aria-label) is present on a stripped anchor. Mirrors
	 * InlineTag::openInlineUaStruct() but cannot reuse it because A does not
	 * extend InlineTag. ARIA ID cross-references are registered the same way.
	 *
	 * Only `lang` and `aria-label` produce a /Lang or /Alt entry directly.
	 * `aria-labelledby`, `aria-describedby`, etc. are queued for the second-pass
	 * resolver only when there is already a struct element to attach them to.
	 *
	 * @param  array $attr
	 * @return bool  true if a Span was pushed (so close() pops one).
	 */
	private function openStrippedAnchorSpan(array $attr)
	{
		$structAttrs = [];
		if (isset($attr['LANG']) && $attr['LANG'] !== '') {
			$structAttrs['Lang'] = $attr['LANG'];
		}
		if (isset($attr['ARIA-LABEL']) && $attr['ARIA-LABEL'] !== '') {
			$structAttrs['Alt'] = $attr['ARIA-LABEL'];
		}
		// If there is no direct attribute that needs a Span and no ARIA ID
		// reference to anchor, do not produce a Span — a stripped <a> with
		// nothing to carry should render exactly like its inner text.
		$hasAriaRef = false;
		foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
				 'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $ariaKey) {
			if (!empty($attr[$ariaKey])) {
				$hasAriaRef = true;
				break;
			}
		}
		if (empty($structAttrs) && !$hasAriaRef && empty($attr['ID'])) {
			return false;
		}
		$this->ua->getStructureTree()->open('Span', $structAttrs);
		$elem = $this->ua->getStructureTree()->getCurrent();
		if (!empty($attr['ID'])) {
			$this->ua->getAriaIdResolver()->registerId($attr['ID'], $elem);
		}
		foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
				 'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $ariaKey) {
			if (!empty($attr[$ariaKey])) {
				$this->ua->getAriaIdResolver()->queue($elem, strtolower($ariaKey), $attr[$ariaKey]);
			}
		}
		return true;
	}
}
