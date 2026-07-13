<?php

namespace Mpdf\Tag;

use Mpdf\Conversion\DecToAlpha;
use Mpdf\Conversion\DecToCjk;
use Mpdf\Conversion\DecToGreek;
use Mpdf\Conversion\DecToHebrew;
use Mpdf\Conversion\DecToOther;
use Mpdf\Conversion\DecToRoman;
use Mpdf\Mpdf;
use Mpdf\Utils\Arrays;
use Mpdf\Utils\UtfString;

abstract class BlockTag extends Tag
{

	/**
	 * PDF/UA-1 — resolve the struct type for a block-level tag.
	 *
	 * Determines the PDF struct type from the CSS class (ToC divs), the HTML
	 * tag, and any ROLE / aria-hidden override, then enforces the §7.4.2
	 * heading sequence (first heading must be H1; descending sequences must not
	 * skip a level) — recording the assigned level on the heading tracker.
	 *
	 * Shared by the normal block path and the in-table-cell path (audit E9) so a
	 * heading or list inside a `<td>`/`<th>` opens its real struct element and
	 * its headings take part in the one document-wide sequence, rather than the
	 * whole cell collapsing to direct TD content.
	 *
	 * @param  string $tag   the (upper-case) HTML tag name
	 * @param  array  $attr  the tag's attributes
	 * @return string|null   a PDF struct type, the '__artifact__' sentinel, or
	 *                       null when the tag maps to no struct element
	 */
	private function resolveBlockStructType($tag, $attr)
	{
		$structType = null;
		// Check CSS class first (for ToC divs: mpdf_toc, mpdf_toc_level_N, etc.)
		if (!empty($attr['CLASS'])) {
			foreach (explode(' ', strtolower($attr['CLASS'])) as $cls) {
				$tocType = \Mpdf\Ua\StructType::fromCssClass($cls);
				if ($tocType !== null) {
					$structType = $tocType;
					break;
				}
			}
		}
		if ($structType === null) {
			$structType = \Mpdf\Ua\StructType::fromHtmlTag($tag, $attr);
		}

		// ROLE attribute ARIA overrides for block elements
		if (!empty($attr['ROLE'])) {
			$role = strtolower($attr['ROLE']);
			if ($role === 'none' || $role === 'presentation' || $role === 'separator') {
				$structType = '__artifact__';
			} elseif ($role === 'heading') {
				$level = isset($attr['ARIA-LEVEL']) ? (int) $attr['ARIA-LEVEL'] : 2;
				$structType = 'H' . max(1, min(6, $level));
			} else {
				$ariaRoleMap = [
					'list'           => 'L',
					'listitem'       => 'LI',
					'table'          => 'Table',
					'grid'           => 'Table',
					'row'            => 'TR',
					'columnheader'   => 'TH',
					'rowheader'      => 'TH',
					'cell'           => 'TD',
					'gridcell'       => 'TD',
					'figure'         => 'Figure',
					'img'            => 'Figure',
					'note'           => 'Note',
					'doc-footnote'   => 'Note',
					'link'           => 'Link',
					'article'        => 'Art',
					'doc-chapter'    => 'Sect',
					'region'         => 'Sect',
					'navigation'     => 'Sect',
					'main'           => 'Div',
					'banner'         => 'Sect',
					'complementary'  => 'Sect',
					'contentinfo'    => 'Sect',
					'group'          => 'Div',
					'paragraph'      => 'P',
					'term'           => 'Span',
					'definition'     => 'Span',
					// 'doc-title' is the document's primary heading (DPUB-ARIA).
					// Map to H1 — the standard PDF struct type for a top-level
					// heading — rather than the literal 'Title' which is NOT in
					// ISO 32000-1 §14.8 Tables 333–335 and would throw via
					// StructType::isValid() at StructureTree::open().
					'doc-title'      => 'H1',
				];
				if (isset($ariaRoleMap[$role])) {
					$structType = $ariaRoleMap[$role];
				}
			}
		}

		// aria-hidden="true" → Artifact suppression context
		if (isset($attr['ARIA-HIDDEN']) && strtolower($attr['ARIA-HIDDEN']) === 'true') {
			$structType = '__artifact__';
		}

		// PDF/UA-1 §7.4.2 rule 1 — heading sequence enforcement.
		// The first heading must be H1; descending sequences must not skip
		// intervening levels (e.g. H1→H3 is invalid; auto-clamp to H1→H2).
		// Applies to struct types H1-H6 wherever they occur — including headings
		// inside table cells (audit E9), which now share this one tracker.
		if ($structType !== null && $structType !== '__artifact__'
			&& preg_match('/^H([1-6])$/', $structType, $hm)
		) {
			$requestedLevel = (int) $hm[1];
			$lastLevel      = $this->ua->getLastHeadingLevel();

			if ($lastLevel === 0 && $requestedLevel > 1) {
				// First heading in the document is not H1 — violation.
				if ($this->mpdf->PDFUAauto) {
					$this->ua->addWarning(
						'PDF/UA-1 §7.4.2: first heading must be H1; '
						. $structType . ' auto-promoted to H1.'
					);
					$structType = 'H1';
				} else {
					throw new \Mpdf\MpdfException(
						'PDF/UA-1 §7.4.2: first heading in the document must be H1; '
						. $structType . ' found. Enable PDFUAauto to auto-correct.'
					);
				}
			} elseif ($lastLevel > 0 && $requestedLevel > $lastLevel + 1) {
				// Descending sequence skips a level — violation.
				$clampedLevel = $lastLevel + 1;
				if ($this->mpdf->PDFUAauto) {
					$this->ua->addWarning(
						'PDF/UA-1 §7.4.2: heading sequence skips from H' . $lastLevel
						. ' to ' . $structType . '; auto-clamped to H' . $clampedLevel . '.'
					);
					$structType = 'H' . $clampedLevel;
				} else {
					throw new \Mpdf\MpdfException(
						'PDF/UA-1 §7.4.2: heading sequence skips from H' . $lastLevel
						. ' to ' . $structType . ' (skips H' . $clampedLevel . '). '
						. 'Enable PDFUAauto to auto-correct.'
					);
				}
			}

			// Record the final assigned level (after any clamping).
			if (preg_match('/^H([1-6])$/', $structType, $fm)) {
				$this->ua->setLastHeadingLevel((int) $fm[1]);
			}
		}

		return $structType;
	}

	/**
	 * PDF/UA-1 (audit E17) — map a resolved CSS `list-style-type` to the PDF
	 * `/ListNumbering` name carried by an `L` element's `/A <</O /List …>>`
	 * attribute object.
	 *
	 * ISO 32000-1:2008 §14.8.5.3.3 Table 347 enumerates the ordered-marker
	 * styles (Decimal / UpperRoman / LowerRoman / UpperAlpha / LowerAlpha) and
	 * the unordered glyphs (Disc / Circle / Square). mPDF's `upper-latin` /
	 * `lower-latin` aliases (from `<ol type="A|a">`) fold onto the alpha names.
	 * Marker styles outside the enumeration (greek, hebrew, cjk-decimal, the
	 * arabic-indic family, U+… glyphs) and `none` map to null, so no
	 * `/ListNumbering` — and thus no `/List` attribute object — is emitted.
	 *
	 * @param  string|null $listStyleType  the resolved CSS list-style-type
	 * @return string|null                 the /ListNumbering name, or null
	 */
	private static function listNumberingFromCssType($listStyleType)
	{
		switch (strtolower((string) $listStyleType)) {
			case 'decimal':
				return 'Decimal';
			case 'upper-roman':
				return 'UpperRoman';
			case 'lower-roman':
				return 'LowerRoman';
			case 'upper-alpha':
			case 'upper-latin':
				return 'UpperAlpha';
			case 'lower-alpha':
			case 'lower-latin':
				return 'LowerAlpha';
			case 'disc':
				return 'Disc';
			case 'circle':
				return 'Circle';
			case 'square':
				return 'Square';
			default:
				return null;
		}
	}

	/**
	 * HTML tags after which an open `<p>` end tag may be omitted (HTML5 §13.1.2).
	 * Mirrors the set in Tag::OpenTag(); reused for table cells (audit E9) where
	 * mPDF does not replay optional end tags.
	 *
	 * @var string[]
	 */
	private static $pClosingTags = [
		'P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'TABLE', 'PRE',
		'FORM', 'ADDRESS', 'BLOCKQUOTE', 'CENTER', 'DL', 'HR', 'ARTICLE', 'ASIDE',
		'FIELDSET', 'HGROUP', 'MAIN', 'NAV', 'SECTION',
	];

	/**
	 * PDF/UA-1 (audit E9) — push a frame recording what a block tag opened on
	 * the struct tree while inside a table cell, so it can be undone later.
	 *
	 * A frame is pushed for every BlockTag open() that occurs at tableLevel —
	 * '__struct__' (a struct element was opened), '__artifact__' (an artifact
	 * scope was opened) or null (nothing) — including the early bail-outs
	 * (display:none, caption). The HTML tag is stored so close() and the
	 * optional-end-tag logic can match a close to its open. Outside a table this
	 * is a no-op; the normal block path tracks state on the block dict instead.
	 *
	 * @param  string|null $kind    '__struct__', '__artifact__' or null
	 * @param  string      $tag     the HTML tag name
	 * @param  int         $closes  StructureTree::close() calls needed to undo it
	 * @return void
	 */
	private function pushCellBlockStructFrame($kind, $tag, $closes = 1)
	{
		if ($this->mpdf->PDFUA && $this->mpdf->tableLevel) {
			$this->mpdf->cellBlockStructStack[] = ['kind' => $kind, 'tag' => $tag, 'closes' => $closes];
		}
	}

	/**
	 * PDF/UA-1 (audit E9) — apply HTML's optional-end-tag rules before opening a
	 * new block inside a table cell.
	 *
	 * HTML5 omits many block end tags (`<li>a<li>b`, `<p>x<h2>`), and mPDF does
	 * not replay them inside tables — so without this the second sibling would
	 * nest inside the first (getCurrent() still points at the un-closed sibling).
	 * When the current cell's innermost open block is a sibling the new tag
	 * implicitly closes (li▸li, dt▸dt/dd, dd▸dt/dd, p▸block), close it first so
	 * the new element opens as a sibling. Mirrors Tag::OpenTag().
	 *
	 * @param  string $tag  the HTML tag about to open
	 * @return void
	 */
	private function autoCloseCellSiblingFor($tag)
	{
		if (!$this->mpdf->PDFUA || !$this->mpdf->tableLevel) {
			return;
		}
		if (count($this->mpdf->cellBlockStructStack) <= $this->mpdf->pdfuaCurrentCellFrameBase()) {
			return;
		}
		$top = end($this->mpdf->cellBlockStructStack);
		$topTag = $top['tag'];
		$close = ($topTag === 'LI' && $tag === 'LI')
			|| ($topTag === 'DT' && ($tag === 'DT' || $tag === 'DD'))
			|| ($topTag === 'DD' && ($tag === 'DT' || $tag === 'DD'))
			|| ($topTag === 'P' && in_array($tag, self::$pClosingTags, true));
		if ($close) {
			$this->mpdf->pdfuaPopCellBlockStructFrame();
		}
	}

	/**
	 * PDF/UA-1 (audit E9) — wrap a `<dt>`/`<dd>` inside a table cell in an
	 * implicit LI so the required L ▸ LI ▸ (Lbl | LBody) containment holds
	 * (ISO 14289-1 §7.2 tests 18/19 — Lbl/LBody must be children of LI, and L
	 * may contain only L/LI/Caption). Mirrors the non-table Dt/Dd handlers, whose
	 * own logic is gated out inside tables.
	 *
	 * A `<dt>` starting a new item first closes the previous item's implicit LI;
	 * a `<dd>` reuses the LI opened by its `<dt>`. The implicit LI is tracked as a
	 * cell frame (tag '__implicitLI__') so it is closed with `</dl>`
	 * (closeCellBlockStructFrame('DL') pops it above the L) or unwound at cell end.
	 *
	 * @param  string $tag  'DT' or 'DD'
	 * @return void
	 */
	private function openImplicitCellListItem($tag)
	{
		$tree = $this->ua->getStructureTree();
		// Implicitly end a preceding <dt>/<dd> whose end tag HTML omitted, so its
		// Lbl/LBody is a sibling — not an ancestor — of this one.
		if (count($this->mpdf->cellBlockStructStack) > $this->mpdf->pdfuaCurrentCellFrameBase()) {
			$topTag = end($this->mpdf->cellBlockStructStack)['tag'];
			if ($topTag === 'DT' || $topTag === 'DD') {
				$this->mpdf->pdfuaPopCellBlockStructFrame();
			}
		}
		$cur = $tree->getCurrent()->getType();
		if ($tag === 'DT' && $cur === 'LI') {
			// New term — close the previous item's implicit LI.
			$this->closeCellBlockStructFrame('__implicitLI__');
			$cur = $tree->getCurrent()->getType();
		}
		if ($cur === 'L') {
			$tree->open('LI');
			$this->pushCellBlockStructFrame('__struct__', '__implicitLI__');
		}
	}

	/**
	 * PDF/UA-1 (audit E9) — close the cell block frame this end tag matches.
	 *
	 * Scans the current cell's frames for one whose HTML tag equals $tag and, if
	 * found, closes every frame above it (still-open children whose end tag HTML
	 * omitted — e.g. the `<li>` inside `<ul><li>x</ul>`) and then the match
	 * itself. A stray close with no matching open frame is ignored. No-op outside
	 * a table.
	 *
	 * @param  string $tag  the HTML tag being closed
	 * @return void
	 */
	private function closeCellBlockStructFrame($tag)
	{
		if (!$this->mpdf->PDFUA || !$this->mpdf->tableLevel) {
			return;
		}
		$base = $this->mpdf->pdfuaCurrentCellFrameBase();
		$matchIdx = -1;
		for ($i = count($this->mpdf->cellBlockStructStack) - 1; $i >= $base; $i--) {
			if ($this->mpdf->cellBlockStructStack[$i]['tag'] === $tag) {
				$matchIdx = $i;
				break;
			}
		}
		if ($matchIdx < 0) {
			return;
		}
		while (count($this->mpdf->cellBlockStructStack) > $matchIdx) {
			$this->mpdf->pdfuaPopCellBlockStructFrame();
		}
	}

	public function open($attr, &$ahtml, &$ihtml)
	{
		$tag = $this->getTagName();

		// mPDF 6  Lists
		$this->mpdf->lastoptionaltag = '';

		// mPDF 6 bidi
		// Block
		// If unicode-bidi set on current clock, any embedding levels, isolates, or overrides are closed (not inherited)
		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['bidicode'])) {
			$blockpost = $this->mpdf->_setBidiCodes('end', $this->mpdf->blk[$this->mpdf->blklvl]['bidicode']);
			if ($blockpost) {
				$this->mpdf->OTLdata = [];
				if ($this->mpdf->tableLevel) {
					$this->mpdf->_saveCellTextBuffer($blockpost);
				} else {
					$this->mpdf->_saveTextBuffer($blockpost);
				}
			}
		}


		$p = $this->cssManager->PreviewBlockCSS($tag, $attr);
		if (isset($p['DISPLAY']) && strtolower($p['DISPLAY']) === 'none') {
			// PDF/UA-1 (audit E9) — this open() bails before the in-table struct
			// point below, so push an empty frame (tag recorded) to keep the cell
			// struct stack balanced against the matching close(). Non-table opens
			// are unaffected.
			$this->pushCellBlockStructFrame(null, $tag);
			$this->mpdf->blklvl++;
			$this->mpdf->blk[$this->mpdf->blklvl]['hide'] = true;
			$this->mpdf->blk[$this->mpdf->blklvl]['tag'] = $tag;  // mPDF 6
			return;
		}
		if ($tag === 'CAPTION') {
			// position is written in AdjstHTML
			$divpos = 'T';
			if (isset($attr['POSITION']) && strtolower($attr['POSITION']) === 'bottom') {
				$divpos = 'B';
			}

			$cappos = 'T';
			if (isset($attr['ALIGN']) && strtolower($attr['ALIGN']) === 'bottom') {
				$cappos = 'B';
			} elseif (isset($p['CAPTION-SIDE']) && strtolower($p['CAPTION-SIDE']) === 'bottom') {
				$cappos = 'B';
			}
			if (isset($attr['ALIGN'])) {
				unset($attr['ALIGN']);
			}
			if ($cappos != $divpos) {
				// PDF/UA-1 (audit E9) — see the display:none bail above.
				$this->pushCellBlockStructFrame(null, $tag);
				$this->mpdf->blklvl++;
				$this->mpdf->blk[$this->mpdf->blklvl]['hide'] = true;
				$this->mpdf->blk[$this->mpdf->blklvl]['tag'] = $tag;  // mPDF 6
				return;
			}
		}

		/* -- FORMS -- */
		if ($tag === 'FORM') {
			$this->form->formMethod = 'POST';
			if (isset($attr['METHOD']) && strtolower($attr['METHOD']) === 'get') {
				$this->form->formMethod = 'GET';
			}

			$this->form->formAction = '';
			if (isset($attr['ACTION'])) {
				$this->form->formAction = $attr['ACTION'];
			}
		}
		/* -- END FORMS -- */


		/* -- CSS-POSITION -- */
		if ((isset($p['POSITION'])
				&& (strtolower($p['POSITION']) === 'fixed'
					|| strtolower($p['POSITION']) === 'absolute'))
			&& $this->mpdf->blklvl == 0) {
			if ($this->mpdf->inFixedPosBlock) {
				throw new \Mpdf\MpdfException('Cannot nest block with position:fixed or position:absolute');
			}
			$this->mpdf->inFixedPosBlock = true;
			return;
		}
		/* -- END CSS-POSITION -- */
		// Start Block
		$this->mpdf->ignorefollowingspaces = true;

		$lastbottommargin = 0;
		if ($this->mpdf->blockjustfinished && !count($this->mpdf->textbuffer)
			&& $this->mpdf->y != $this->mpdf->tMargin
			&& $this->mpdf->collapseBlockMargins) {
			$lastbottommargin = $this->mpdf->lastblockbottommargin;
		}
		$this->mpdf->lastblockbottommargin = 0;
		$this->mpdf->blockjustfinished = false;


		$this->mpdf->InlineBDF = []; // mPDF 6
		$this->mpdf->InlineBDFctr = 0; // mPDF 6
		$this->mpdf->InlineProperties = [];
		$this->mpdf->divbegin = true;

		$this->mpdf->linebreakjustfinished = false;

		/* -- TABLES -- */
		if ($this->mpdf->tableLevel) {
			// If already something on the line
			if ($this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'] > 0 && !$this->mpdf->nestedtablejustfinished) {
				$this->mpdf->_saveCellTextBuffer("\n");
				if (!isset($this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['maxs'])) {
					$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['maxs'] = $this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'];
				} elseif ($this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['maxs'] < $this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s']) {
					$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['maxs'] = $this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'];
				}
				$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'] = 0; // reset
			}
			// Cannot set block properties inside table - use Bold to indicate h1-h6
			if ($tag === 'CENTER' && $this->mpdf->tdbegin) {
				$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['a'] = $this->getAlign('center');
			}

			$this->mpdf->InlineProperties['BLOCKINTABLE'] = $this->mpdf->saveInlineProperties();
			$properties = $this->cssManager->MergeCSS('', $tag, $attr);
			if (!empty($properties)) {
				$this->mpdf->setCSS($properties, 'INLINE');
			}

			// PDF/UA-1 (audit E9) — open this block tag's struct element beneath
			// the current TD/TH so a heading or list inside a cell gets a real
			// H2 / L / LI element (and its headings reach the sequence tracker)
			// instead of the whole cell collapsing to direct TD content. The
			// element is opened here — before the list-marker text below is
			// buffered — so getCurrent() (captured per chunk in
			// _saveCellTextBuffer) attributes the marker and text to it. The
			// deferred cell render (_tableWrite → printbuffer) then brackets each
			// chunk in its owner's marked content. ISO 32000-1 §14.8 / ISO
			// 14289-1 §7.2 (empty structure elements). The frame stack pairs each
			// open with the pop in close().
			if ($this->mpdf->PDFUA) {
				if ($tag === 'DT' || $tag === 'DD') {
					// Definition lists need an implicit LI between L and Lbl/LBody.
					$this->openImplicitCellListItem($tag);
				} else {
					// HTML omits many block end tags; close an implicitly-ended
					// sibling (li▸li, p▸block …) so the new element opens as a
					// sibling, not a child.
					$this->autoCloseCellSiblingFor($tag);
				}
				$structType = $this->resolveBlockStructType($tag, $attr);
				if ($structType === '__artifact__') {
					$this->ua->getStructureTree()->openArtifact();
					$this->pushCellBlockStructFrame('__artifact__', $tag);
				} elseif ($structType !== null) {
					$structAttrs = [];
					if (isset($attr['LANG'])) {
						$structAttrs['Lang'] = $attr['LANG'];
					}
					if (isset($attr['ARIA-LABEL']) && $attr['ARIA-LABEL'] !== '') {
						$structAttrs['Alt'] = $attr['ARIA-LABEL'];
					}
					$this->ua->getStructureTree()->open($structType, $structAttrs);
					$elem = $this->ua->getStructureTree()->getCurrent();
					$closes = 1;
					if ($structType === 'LI') {
						// ISO 14289-1 §7.2 test 20 — an LI's content (and any nested
						// list) must live in an LBody, not directly under LI. Open one
						// so the marker/text and nested <ul>/<ol> attach beneath it.
						$this->ua->getStructureTree()->open('LBody');
						$closes = 2;
					}
					$this->pushCellBlockStructFrame('__struct__', $tag, $closes);
					if (!empty($attr['ID'])) {
						$this->ua->getAriaIdResolver()->registerId($attr['ID'], $elem);
					}
					foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
							  'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $k) {
						if (!empty($attr[$k])) {
							$this->ua->getAriaIdResolver()->queue($elem, strtolower($k), $attr[$k]);
						}
					}
				} else {
					$this->pushCellBlockStructFrame(null, $tag);
				}
			}

			// mPDF 6  Lists
			if ($tag === 'UL' || $tag === 'OL') {
				$this->mpdf->listlvl++;
				if (isset($attr['START'])) {
					$this->mpdf->listcounter[$this->mpdf->listlvl] = (int) $attr['START'] - 1;
				} else {
					$this->mpdf->listcounter[$this->mpdf->listlvl] = 0;
				}
				$this->mpdf->listitem = [];
				if ($tag === 'OL') {
					$this->mpdf->listtype[$this->mpdf->listlvl] = 'decimal';
				} elseif ($tag === 'UL') {
					if ($this->mpdf->listlvl % 3 == 1) {
						$this->mpdf->listtype[$this->mpdf->listlvl] = 'disc';
					} elseif ($this->mpdf->listlvl % 3 == 2) {
						$this->mpdf->listtype[$this->mpdf->listlvl] = 'circle';
					} else {
						$this->mpdf->listtype[$this->mpdf->listlvl] = 'square';
					}
				}

				// Override with HTML TYPE attribute (lower specificity than CSS)
				if (!empty($attr['TYPE'])) {
					$listtype = $attr['TYPE'];
					switch ($listtype) {
						case 'A':
							$listtype = 'upper-latin';
							break;
						case 'a':
							$listtype = 'lower-latin';
							break;
						case 'I':
							$listtype = 'upper-roman';
							break;
						case 'i':
							$listtype = 'lower-roman';
							break;
						case '1':
							$listtype = 'decimal';
							break;
					}
					$this->mpdf->listtype[$this->mpdf->listlvl] = $listtype;
				}

				// Override with CSS list-style-type if specified (highest specificity)
				if (!empty($properties['LIST-STYLE-TYPE'])) {
					$this->mpdf->listtype[$this->mpdf->listlvl] = strtolower($properties['LIST-STYLE-TYPE']);
				}

				// PDF/UA-1 (audit E17) — record the resolved marker style on the
				// in-cell L element as /ListNumbering so the writer emits its
				// /A <</O /List /ListNumbering …>> attribute object (Table 347).
				if ($this->mpdf->PDFUA && isset($elem, $structType) && $structType === 'L') {
					$numbering = self::listNumberingFromCssType($this->mpdf->listtype[$this->mpdf->listlvl]);
					if ($numbering !== null) {
						$elem->setAttribute('ListNumbering', $numbering);
					}
				}
			}

			// mPDF 6  Lists - in Tables
			if ($tag === 'LI') {

				if ($this->mpdf->listlvl == 0) { //in case of malformed HTML code. Example:(...)</p><li>Content</li><p>Paragraph1</p>(...)
					$this->mpdf->listlvl++; // first depth level
					$this->mpdf->listcounter[$this->mpdf->listlvl] = 0;
				}

				$this->mpdf->listcounter[$this->mpdf->listlvl]++;
				$this->mpdf->listitem = [];
				//if in table - output here as a tabletextbuffer
				//position:inside OR position:outside (always output in table as position:inside)

				$currentListType = $this->mpdf->listtype[$this->mpdf->listlvl];

				// Allow individual LI to override list type via HTML TYPE attribute
				if (!empty($attr['TYPE'])) {
					$liType = $attr['TYPE'];
					switch ($liType) {
						case 'A':
							$liType = 'upper-latin';
							break;
						case 'a':
							$liType = 'lower-latin';
							break;
						case 'I':
							$liType = 'upper-roman';
							break;
						case 'i':
							$liType = 'lower-roman';
							break;
						case '1':
							$liType = 'decimal';
							break;
					}
					$currentListType = $liType;
				}

				// Allow individual LI to override list type via CSS (highest specificity)
				if (!empty($properties['LIST-STYLE-TYPE'])) {
					$currentListType = strtolower($properties['LIST-STYLE-TYPE']);
				}

				$decToAlpha = new DecToAlpha();
				$decToRoman = new DecToRoman();
				$counter = $this->mpdf->listcounter[$this->mpdf->listlvl];
				$list_item_color = '';

				switch ($currentListType) {
					case 'upper-alpha':
					case 'upper-latin':
					case 'A':
						$blt = $decToAlpha->convert($counter) . $this->mpdf->list_number_suffix;
						break;
					case 'lower-alpha':
					case 'lower-latin':
					case 'a':
						$blt = $decToAlpha->convert($counter, false) . $this->mpdf->list_number_suffix;
						break;
					case 'upper-roman':
					case 'I':
						$blt = $decToRoman->convert($counter) . $this->mpdf->list_number_suffix;
						break;
					case 'lower-roman':
					case 'i':
						$blt = $decToRoman->convert($counter, false) . $this->mpdf->list_number_suffix;
						break;
					case 'lower-greek':
						$decToGreek = new DecToGreek();
						$blt = $decToGreek->convert($counter) . $this->mpdf->list_number_suffix;
						break;
					case 'decimal':
					case '1':
						$blt = $counter . $this->mpdf->list_number_suffix;
						break;
					case 'hebrew':
						$decToHebrew = new DecToHebrew();
						$blt = $decToHebrew->convert($counter) . $this->mpdf->list_number_suffix;
						break;
					case 'cjk-decimal':
						$decToCjk = new DecToCjk();
						$blt = $decToCjk->convert($counter) . $this->mpdf->list_number_suffix;
						break;
					case 'arabic-indic':
					case 'bengali':
					case 'cambodian':
					case 'devanagari':
					case 'gujarati':
					case 'gurmukhi':
					case 'kannada':
					case 'khmer':
					case 'lao':
					case 'malayalam':
					case 'myanmar':
					case 'oriya':
					case 'persian':
					case 'tamil':
					case 'telugu':
					case 'thai':
					case 'urdu':
						$decToOther = new DecToOther($this->mpdf);
						$cp = $decToOther->getCodePage($currentListType);
						$blt = $decToOther->convert($counter, $cp, true) . $this->mpdf->list_number_suffix;
						break;
					case 'disc':
						$blt = '-';
						if ($this->mpdf->_charDefined($this->mpdf->CurrentFont['cw'], 8226)) {
							$blt = "\xe2\x80\xa2"; // U+2022 BULLET
						}
						break;
					case 'circle':
						$blt = '-';
						if ($this->mpdf->_charDefined($this->mpdf->CurrentFont['cw'], 9900)) {
							$blt = "\xe2\x9a\xac"; // U+26AC
						}
						break;
					case 'square':
						$blt = '-';
						if ($this->mpdf->_charDefined($this->mpdf->CurrentFont['cw'], 9642)) {
							$blt = "\xe2\x96\xaa"; // U+25AA
						}
						break;
					case 'none':
						$blt = '';
						break;
					default:
						if (preg_match('/U\+([a-fA-F0-9]+)/i', $currentListType, $m)) {
							$blt = '-';
							if ($this->mpdf->_charDefined($this->mpdf->CurrentFont['cw'], hexdec($m[1]))) {
								$blt = UtfString::codeHex2utf($m[1]);
							}
							if (preg_match('/rgb\(.*?\)/', $currentListType, $cm)) {
								$list_item_color = $this->colorConverter->convert($cm[0], $this->mpdf->PDFAXwarnings);
							}
						} else {
							$blt = '-';
							if ($this->mpdf->_charDefined($this->mpdf->CurrentFont['cw'], 8226)) {
								$blt = "\xe2\x80\xa2";
							}
						}
						break;
				}

				// change to &nbsp; spaces
				if ($currentListType !== 'none') {
					if ($this->mpdf->usingCoreFont) {
						$indent = str_repeat(chr(160) . chr(160), ($this->mpdf->listlvl - 1) * 2);
					} else {
						$indent = str_repeat("\xc2\xa0\xc2\xa0", ($this->mpdf->listlvl - 1) * 2);
					}

					if (!empty($list_item_color)) {
						// Write indentation without color
						if ($indent !== '') {
							$this->mpdf->_saveCellTextBuffer($indent);
							$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'] += $this->mpdf->GetStringWidth($indent);
						}
						// Write marker with color
						$save_colorarray = $this->mpdf->colorarray;
						$this->mpdf->colorarray = $list_item_color;
						$this->mpdf->_saveCellTextBuffer($blt);
						$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'] += $this->mpdf->GetStringWidth($blt);
						$this->mpdf->colorarray = $save_colorarray;
						// Write trailing space without color
						$this->mpdf->_saveCellTextBuffer(' ');
						$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'] += $this->mpdf->GetStringWidth(' ');
					} else {
						$ls = $indent . $blt . ' ';
						$this->mpdf->_saveCellTextBuffer($ls);
						$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['s'] += $this->mpdf->GetStringWidth($ls);
					}
				}
			}

			return;
		}
		/* -- END TABLES -- */

		if ($this->mpdf->lastblocklevelchange == 1) {
			$blockstate = 1;
		} // Top margins/padding only
		elseif ($this->mpdf->lastblocklevelchange < 1) {
			$blockstate = 0;
		} // NO margins/padding

		$this->mpdf->printbuffer($this->mpdf->textbuffer, $blockstate);
		$this->mpdf->textbuffer = [];

		$save_blklvl = $this->mpdf->blklvl;
		$save_blk = $this->mpdf->blk;

		$this->mpdf->Reset();

		$pagesel = '';
		/* -- CSS-PAGE -- */
		if (isset($p['PAGE'])) {
			$pagesel = $p['PAGE'];
		}  // mPDF 6 (uses $p - preview of properties so blklvl can be incremented after page-break)
		/* -- END CSS-PAGE -- */

		// If page-box has changed AND/OR PAGE-BREAK-BEFORE
		// mPDF 6 (uses $p - preview of properties so blklvl can be incremented after page-break)
		if (!$this->mpdf->tableLevel && (($pagesel && (!$this->mpdf->page_box['current'] || $pagesel != $this->mpdf->page_box['current']))
				|| (isset($p['PAGE-BREAK-BEFORE']) && $p['PAGE-BREAK-BEFORE']))) {
			// mPDF 6 pagebreaktype
			$startpage = $this->mpdf->page;
			$pagebreaktype = $this->mpdf->defaultPagebreakType;
			$this->mpdf->lastblocklevelchange = -1;
			if ($this->mpdf->ColActive) {
				$pagebreaktype = 'cloneall';
			}
			if ($pagesel && (!$this->mpdf->page_box['current'] || $pagesel != $this->mpdf->page_box['current'])) {
				$pagebreaktype = 'cloneall';
			}
			$this->mpdf->_preForcedPagebreak($pagebreaktype);

			if (isset($p['PAGE-BREAK-BEFORE'])) {
				if (strtoupper($p['PAGE-BREAK-BEFORE']) === 'RIGHT') {
					$this->mpdf->AddPage(
						$this->mpdf->CurOrientation,
						'NEXT-ODD',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						0,
						0,
						0,
						0,
						$pagesel
					);
				} elseif (strtoupper($p['PAGE-BREAK-BEFORE']) === 'LEFT') {
					$this->mpdf->AddPage(
						$this->mpdf->CurOrientation,
						'NEXT-EVEN',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						0,
						0,
						0,
						0,
						$pagesel
					);
				} elseif (strtoupper($p['PAGE-BREAK-BEFORE']) === 'ALWAYS') {
					$this->mpdf->AddPage($this->mpdf->CurOrientation, '', '', '', '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 0, $pagesel);
				} elseif ($this->mpdf->page_box['current'] != $pagesel) {
					$this->mpdf->AddPage($this->mpdf->CurOrientation, '', '', '', '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 0, $pagesel);
				} // *CSS-PAGE*
			} /* -- CSS-PAGE -- */
			// Must Add new page if changed page properties
			elseif (!$this->mpdf->page_box['current'] || $pagesel != $this->mpdf->page_box['current']) {
				$this->mpdf->AddPage($this->mpdf->CurOrientation, '', '', '', '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 0, $pagesel);
			}
			/* -- END CSS-PAGE -- */

			// mPDF 6 pagebreaktype
			$this->mpdf->_postForcedPagebreak($pagebreaktype, $startpage, $save_blk, $save_blklvl);
		}

		// mPDF 6 pagebreaktype - moved after pagebreak
		$this->mpdf->blklvl++;
		$currblk = & $this->mpdf->blk[$this->mpdf->blklvl];
		$this->mpdf->initialiseBlock($currblk);
		$prevblk = & $this->mpdf->blk[$this->mpdf->blklvl - 1];
		$currblk['tag'] = $tag;
		$currblk['attr'] = $attr;

		$properties = $this->cssManager->MergeCSS('BLOCK', $tag, $attr); // mPDF 6 - moved to after page-break-before
		// mPDF 6 page-break-inside:avoid
		if (isset($properties['PAGE-BREAK-INSIDE']) && strtoupper($properties['PAGE-BREAK-INSIDE']) === 'AVOID'
			&& !$this->mpdf->ColActive && !$this->mpdf->keep_block_together && !isset($attr['PAGEBREAKAVOIDCHECKED'])) {
			// avoid re-iterating using PAGEBREAKAVOIDCHECKED; set in CloseTag
			$currblk['keep_block_together'] = 1;
			$currblk['array_i'] = $ihtml; // mPDF 6
			$this->mpdf->kt_y00 = $this->mpdf->y;
			$this->mpdf->kt_p00 = $this->mpdf->page;
			$this->mpdf->keep_block_together = 1;
		}
		if ($lastbottommargin && !empty($properties['MARGIN-TOP']) && empty($properties['FLOAT'])) {
			$currblk['lastbottommargin'] = $lastbottommargin;
		}

		if (isset($properties['Z-INDEX']) && $this->mpdf->current_layer == 0) {
			$v = (int) $properties['Z-INDEX'];
			if ($v > 0) {
				$currblk['z-index'] = $v;
				$this->mpdf->BeginLayer($v);
			}
		}


		// mPDF 6  Lists
		// List-type set by attribute
		if ($tag === 'OL' || $tag === 'UL' || $tag === 'LI') {
			if (!empty($attr['TYPE'])) {
				$listtype = $attr['TYPE'];
				switch ($listtype) {
					case 'A':
						$listtype = 'upper-latin';
						break;
					case 'a':
						$listtype = 'lower-latin';
						break;
					case 'I':
						$listtype = 'upper-roman';
						break;
					case 'i':
						$listtype = 'lower-roman';
						break;
					case '1':
						$listtype = 'decimal';
						break;
				}
				$currblk['list_style_type'] = $listtype;
			}
		}

		$this->mpdf->setCSS($properties, 'BLOCK', $tag); //name(id/class/style) found in the CSS array!
		$currblk['InlineProperties'] = $this->mpdf->saveInlineProperties();

		if (isset($properties['VISIBILITY'])) {
			$v = strtolower($properties['VISIBILITY']);
			if (($v === 'hidden' || $v === 'printonly' || $v === 'screenonly') && $this->mpdf->visibility === 'visible' && !$this->mpdf->tableLevel) {
				$currblk['visibility'] = $v;
				$this->mpdf->SetVisibility($v);
			}
		}

		// mPDF 6
		if (!empty($attr['ALIGN'])) {
			$currblk['block-align'] = $this->getAlign($attr['ALIGN']);
		}


		if (isset($properties['HEIGHT'])) {
			$currblk['css_set_height'] = $this->sizeConverter->convert(
				$properties['HEIGHT'],
				$this->mpdf->h - $this->mpdf->tMargin - $this->mpdf->bMargin,
				$this->mpdf->FontSize,
				false
			);
			if (($currblk['css_set_height'] + $this->mpdf->y) > $this->mpdf->PageBreakTrigger
				&& $this->mpdf->y > $this->mpdf->tMargin + 5
				&& $currblk['css_set_height'] < ($this->mpdf->h - ($this->mpdf->tMargin + $this->mpdf->bMargin))) {
				$this->mpdf->AddPage($this->mpdf->CurOrientation);
			}
		} else {
			$currblk['css_set_height'] = false;
		}


		// Added mPDF 3.0 Float DIV
		if (isset($prevblk['blockContext'])) {
			$currblk['blockContext'] = $prevblk['blockContext'];
		} // *CSS-FLOAT*

		if (isset($properties['CLEAR'])) {
			$this->mpdf->ClearFloats(strtoupper($properties['CLEAR']), $this->mpdf->blklvl - 1);
		} // *CSS-FLOAT*

		$currblk['padding_left'] = is_numeric($currblk['padding_left']) ? $currblk['padding_left'] : 0;
		$currblk['padding_right'] = is_numeric($currblk['padding_right']) ? $currblk['padding_right'] : 0;

		$container_w = $prevblk['inner_width'];
		$bdr = $currblk['border_right']['w'];
		$bdl = $currblk['border_left']['w'];
		$pdr = $currblk['padding_right'];
		$pdl = $currblk['padding_left'];

		$setwidth = 0;
		if (isset($currblk['css_set_width'])) {
			$setwidth = $currblk['css_set_width'];
		}

		/* -- CSS-FLOAT -- */
		if (isset($properties['FLOAT']) && strtoupper($properties['FLOAT']) === 'RIGHT' && !$this->mpdf->ColActive) {

			// Cancel Keep-Block-together
			$currblk['keep_block_together'] = false;
			$this->mpdf->kt_y00 = 0;
			$this->mpdf->keep_block_together = 0;

			$this->mpdf->blockContext++;
			$currblk['blockContext'] = $this->mpdf->blockContext;

			list($l_exists, $r_exists, $l_max, $r_max, $l_width, $r_width) = $this->mpdf->GetFloatDivInfo($this->mpdf->blklvl - 1);

			// DIV is too narrow for text to fit!
			$maxw = $container_w - $l_width - $r_width;
			$doubleCharWidth = (2 * $this->mpdf->GetCharWidth('W', false));
			if (($setwidth + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) > $maxw
				|| ($maxw - ($currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr)) < (2 * $this->mpdf->GetCharWidth('W', false))) {
				// Too narrow to fit - try to move down past L or R float
				if ($l_max < $r_max && ($setwidth + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) <= ($container_w - $r_width)
					&& (($container_w - $r_width) - ($currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr)) > $doubleCharWidth) {
					$this->mpdf->ClearFloats('LEFT', $this->mpdf->blklvl - 1);
				} elseif ($r_max < $l_max && ($setwidth + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) <= ($container_w - $l_width)
					&& (($container_w - $l_width) - ($currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr)) > $doubleCharWidth) {
					$this->mpdf->ClearFloats('RIGHT', $this->mpdf->blklvl - 1);
				} else {
					$this->mpdf->ClearFloats('BOTH', $this->mpdf->blklvl - 1);
				}
				list($l_exists, $r_exists, $l_max, $r_max, $l_width, $r_width) = $this->mpdf->GetFloatDivInfo($this->mpdf->blklvl - 1);
			}

			if ($r_exists) {
				$currblk['margin_right'] += $r_width;
			}

			$currblk['float'] = 'R';
			$currblk['float_start_y'] = $this->mpdf->y;

			if (isset($currblk['css_set_width'])) {
				$currblk['margin_left'] = $container_w - ($setwidth + $bdl + $pdl + $bdr + $pdr + $currblk['margin_right']);
				$currblk['float_width'] = ($setwidth + $bdl + $pdl + $bdr + $pdr + $currblk['margin_right']);
			} else {
				// *** If no width set - would need to buffer and keep track of max width, then Right-align if not full width
				// and do borders and backgrounds - For now - just set to maximum width left

				if ($l_exists) {
					$currblk['margin_left'] += $l_width;
				}
				$currblk['css_set_width'] = $container_w - ($currblk['margin_left'] + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr);

				$currblk['float_width'] = ($currblk['css_set_width'] + $bdl + $pdl + $bdr + $pdr + $currblk['margin_right']);
			}

		} elseif (isset($properties['FLOAT']) && strtoupper($properties['FLOAT']) === 'LEFT' && !$this->mpdf->ColActive) {
			// Cancel Keep-Block-together
			$currblk['keep_block_together'] = false;
			$this->mpdf->kt_y00 = 0;
			$this->mpdf->keep_block_together = 0;

			$this->mpdf->blockContext++;
			$currblk['blockContext'] = $this->mpdf->blockContext;

			list($l_exists, $r_exists, $l_max, $r_max, $l_width, $r_width) = $this->mpdf->GetFloatDivInfo($this->mpdf->blklvl - 1);

			// DIV is too narrow for text to fit!
			$maxw = $container_w - $l_width - $r_width;
			$doubleCharWidth = (2 * $this->mpdf->GetCharWidth('W', false));
			if (($setwidth + $currblk['margin_left'] + $bdl + $pdl + $bdr + $pdr) > $maxw
				|| ($maxw - ($currblk['margin_left'] + $bdl + $pdl + $bdr + $pdr)) < (2 * $this->mpdf->GetCharWidth('W', false))) {
				// Too narrow to fit - try to move down past L or R float
				if ($l_max < $r_max && ($setwidth + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) <= ($container_w - $r_width)
					&& (($container_w - $r_width) - ($currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr)) > $doubleCharWidth) {
					$this->mpdf->ClearFloats('LEFT', $this->mpdf->blklvl - 1);
				} elseif ($r_max < $l_max && ($setwidth + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) <= ($container_w - $l_width)
					&& (($container_w - $l_width) - ($currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr)) > $doubleCharWidth) {
					$this->mpdf->ClearFloats('RIGHT', $this->mpdf->blklvl - 1);
				} else {
					$this->mpdf->ClearFloats('BOTH', $this->mpdf->blklvl - 1);
				}
				list($l_exists, $r_exists, $l_max, $r_max, $l_width, $r_width) = $this->mpdf->GetFloatDivInfo($this->mpdf->blklvl - 1);
			}

			if ($l_exists) {
				$currblk['margin_left'] += $l_width;
			}

			$currblk['float'] = 'L';
			$currblk['float_start_y'] = $this->mpdf->y;
			if ($setwidth) {
				$currblk['margin_right'] = $container_w - ($setwidth + $bdl + $pdl + $bdr + $pdr + $currblk['margin_left']);
				$currblk['float_width'] = ($setwidth + $bdl + $pdl + $bdr + $pdr + $currblk['margin_left']);
			} else {
				// *** If no width set - would need to buffer and keep track of max width, then Right-align if not full width
				// and do borders and backgrounds - For now - just set to maximum width left

				if ($r_exists) {
					$currblk['margin_right'] += $r_width;
				}
				$currblk['css_set_width'] = $container_w - ($currblk['margin_left'] + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr);

				$currblk['float_width'] = ($currblk['css_set_width'] + $bdl + $pdl + $bdr + $pdr + $currblk['margin_left']);
			}
		} else {
			// Don't allow overlap - if floats present - adjust padding to avoid overlap with Floats
			list($l_exists, $r_exists, $l_max, $r_max, $l_width, $r_width) = $this->mpdf->GetFloatDivInfo($this->mpdf->blklvl - 1);
			$maxw = $container_w - $l_width - $r_width;

			$pdl = is_numeric($pdl) ? $pdl : 0;
			$pdr = is_numeric($pdr) ? $pdr : 0;

			$doubleCharWidth = (2 * $this->mpdf->GetCharWidth('W', false));
			if (($setwidth + $currblk['margin_left'] + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) > $maxw
				|| ($maxw - ($currblk['margin_right'] + $currblk['margin_left'] + $bdl + $pdl + $bdr + $pdr)) < $doubleCharWidth) {
				// Too narrow to fit - try to move down past L or R float
				if ($l_max < $r_max && ($setwidth + $currblk['margin_left'] + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) <= ($container_w - $r_width)
					&& (($container_w - $r_width) - ($currblk['margin_right'] + $currblk['margin_left'] + $bdl + $pdl + $bdr + $pdr)) > $doubleCharWidth) {
					$this->mpdf->ClearFloats('LEFT', $this->mpdf->blklvl - 1);
				} elseif ($r_max < $l_max && ($setwidth + $currblk['margin_left'] + $currblk['margin_right'] + $bdl + $pdl + $bdr + $pdr) <= ($container_w - $l_width)
					&& (($container_w - $l_width) - ($currblk['margin_right'] + $currblk['margin_left'] + $bdl + $pdl + $bdr + $pdr)) > $doubleCharWidth) {
					$this->mpdf->ClearFloats('RIGHT', $this->mpdf->blklvl - 1);
				} else {
					$this->mpdf->ClearFloats('BOTH', $this->mpdf->blklvl - 1);
				}
				list($l_exists, $r_exists, $l_max, $r_max, $l_width, $r_width) = $this->mpdf->GetFloatDivInfo($this->mpdf->blklvl - 1);
			}
			if ($r_exists) {
				$currblk['padding_right'] = max($r_width - $currblk['margin_right'] - $bdr, $pdr);
			}
			if ($l_exists) {
				$currblk['padding_left'] = max($l_width - $currblk['margin_left'] - $bdl, $pdl);
			}
		}
		/* -- END CSS-FLOAT -- */


		/* -- BORDER-RADIUS -- */
		// Automatically increase padding if required for border-radius
		if ($this->mpdf->autoPadding && !$this->mpdf->ColActive) {
			$currblk['border_radius_TL_H'] = Arrays::get($currblk, 'border_radius_TL_H', 0);
			$currblk['border_radius_TL_V'] = Arrays::get($currblk, 'border_radius_TL_V', 0);
			$currblk['border_radius_TR_H'] = Arrays::get($currblk, 'border_radius_TR_H', 0);
			$currblk['border_radius_TR_V'] = Arrays::get($currblk, 'border_radius_TR_V', 0);
			$currblk['border_radius_BL_H'] = Arrays::get($currblk, 'border_radius_BL_H', 0);
			$currblk['border_radius_BL_V'] = Arrays::get($currblk, 'border_radius_BL_V', 0);
			$currblk['border_radius_BR_H'] = Arrays::get($currblk, 'border_radius_BR_H', 0);
			$currblk['border_radius_BR_V'] = Arrays::get($currblk, 'border_radius_BR_V', 0);

			if ($currblk['border_radius_TL_H'] > $currblk['padding_left'] && $currblk['border_radius_TL_V'] > $currblk['padding_top']) {
				if ($currblk['border_radius_TL_H'] > $currblk['border_radius_TL_V']) {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_TL_H'],
						$currblk['border_radius_TL_V'],
						$currblk['padding_left'],
						$currblk['padding_top']
					);
				} else {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_TL_V'],
						$currblk['border_radius_TL_H'],
						$currblk['padding_top'],
						$currblk['padding_left']
					);
				}
			}
			if ($currblk['border_radius_TR_H'] > $currblk['padding_right'] && $currblk['border_radius_TR_V'] > $currblk['padding_top']) {
				if ($currblk['border_radius_TR_H'] > $currblk['border_radius_TR_V']) {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_TR_H'],
						$currblk['border_radius_TR_V'],
						$currblk['padding_right'],
						$currblk['padding_top']
					);
				} else {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_TR_V'],
						$currblk['border_radius_TR_H'],
						$currblk['padding_top'],
						$currblk['padding_right']
					);
				}
			}
			if ($currblk['border_radius_BL_H'] > $currblk['padding_left'] && $currblk['border_radius_BL_V'] > $currblk['padding_bottom']) {
				if ($currblk['border_radius_BL_H'] > $currblk['border_radius_BL_V']) {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_BL_H'],
						$currblk['border_radius_BL_V'],
						$currblk['padding_left'],
						$currblk['padding_bottom']
					);
				} else {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_BL_V'],
						$currblk['border_radius_BL_H'],
						$currblk['padding_bottom'],
						$currblk['padding_left']
					);
				}
			}
			if ($currblk['border_radius_BR_H'] > $currblk['padding_right'] && $currblk['border_radius_BR_V'] > $currblk['padding_bottom']) {
				if ($currblk['border_radius_BR_H'] > $currblk['border_radius_BR_V']) {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_BR_H'],
						$currblk['border_radius_BR_V'],
						$currblk['padding_right'],
						$currblk['padding_bottom']
					);
				} else {
					$this->mpdf->_borderPadding(
						$currblk['border_radius_BR_V'],
						$currblk['border_radius_BR_H'],
						$currblk['padding_bottom'],
						$currblk['padding_right']
					);
				}
			}
		}
		/* -- END BORDER-RADIUS -- */

		// Hanging indent - if negative indent: ensure padding is >= indent
		if (!isset($currblk['text_indent'])) {
			$currblk['text_indent'] = null;
		}
		if (!isset($currblk['inner_width'])) {
			$currblk['inner_width'] = null;
		}
		$cbti = $this->sizeConverter->convert(
			$currblk['text_indent'],
			$this->mpdf->blk[$this->mpdf->blklvl]['inner_width'],
			$this->mpdf->FontSize,
			false
		);
		if ($cbti < 0) {
			$hangind = -$cbti;
			if (isset($currblk['direction']) && $currblk['direction'] === 'rtl') { // *OTL*
				$currblk['padding_right'] = max($currblk['padding_right'], $hangind); // *OTL*
			} // *OTL*
			else { // *OTL*
				$currblk['padding_left'] = max($currblk['padding_left'], $hangind);
			} // *OTL*
		}

		if (isset($currblk['css_set_width'])) {
			if (isset($properties['MARGIN-LEFT'], $properties['MARGIN-RIGHT'])
				&& strtolower($properties['MARGIN-LEFT']) === 'auto' && strtolower($properties['MARGIN-RIGHT']) === 'auto') {
				// Try to reduce margins to accomodate - if still too wide, set margin-right/left=0 (reduces width)
				$anyextra = $prevblk['inner_width'] - ($currblk['css_set_width'] + $currblk['border_left']['w']
						+ $currblk['padding_left'] + $currblk['border_right']['w'] + $currblk['padding_right']);
				if ($anyextra > 0) {
					$currblk['margin_left'] = $currblk['margin_right'] = $anyextra / 2;
				} else {
					$currblk['margin_left'] = $currblk['margin_right'] = 0;
				}
			} elseif (isset($properties['MARGIN-LEFT']) && strtolower($properties['MARGIN-LEFT']) === 'auto') {
				// Try to reduce margin-left to accomodate - if still too wide, set margin-left=0 (reduces width)
				$currblk['margin_left'] = $prevblk['inner_width'] - ($currblk['css_set_width']
						+ $currblk['border_left']['w'] + $currblk['padding_left'] + $currblk['border_right']['w']
						+ $currblk['padding_right'] + $currblk['margin_right']);
				if ($currblk['margin_left'] < 0) {
					$currblk['margin_left'] = 0;
				}
			} elseif (isset($properties['MARGIN-RIGHT']) && strtolower($properties['MARGIN-RIGHT']) === 'auto') {
				// Try to reduce margin-right to accomodate - if still too wide, set margin-right=0 (reduces width)
				$currblk['margin_right'] = $prevblk['inner_width'] - ($currblk['css_set_width']
						+ $currblk['border_left']['w'] + $currblk['padding_left']
						+ $currblk['border_right']['w'] + $currblk['padding_right'] + $currblk['margin_left']);
				if ($currblk['margin_right'] < 0) {
					$currblk['margin_right'] = 0;
				}
			} else {
				if ($currblk['direction'] === 'rtl') { // *OTL*
					// Try to reduce margin-left to accomodate - if still too wide, set margin-left=0 (reduces width)
					$currblk['margin_left'] = $prevblk['inner_width'] - ($currblk['css_set_width']
							+ $currblk['border_left']['w'] + $currblk['padding_left'] + $currblk['border_right']['w']
							+ $currblk['padding_right'] + $currblk['margin_right']); // *OTL*
					if ($currblk['margin_left'] < 0) { // *OTL*
						$currblk['margin_left'] = 0; // *OTL*
					} // *OTL*
				} // *OTL*
				else { // *OTL*
					// Try to reduce margin-right to accomodate - if still too wide, set margin-right=0 (reduces width)
					$currblk['margin_right'] = $prevblk['inner_width'] - ($currblk['css_set_width']
							+ $currblk['border_left']['w'] + $currblk['padding_left'] + $currblk['border_right']['w']
							+ $currblk['padding_right'] + $currblk['margin_left']);
					if ($currblk['margin_right'] < 0) {
						$currblk['margin_right'] = 0;
					}
				} // *OTL*
			}
		}

		$currblk['outer_left_margin'] = $prevblk['outer_left_margin'] + $currblk['margin_left']
			+ $prevblk['border_left']['w'] + $prevblk['padding_left'];

		$currblk['outer_right_margin'] = $prevblk['outer_right_margin'] + $currblk['margin_right']
			+ $prevblk['border_right']['w'] + $prevblk['padding_right'];

		$currblk['width'] = $this->mpdf->pgwidth - ($currblk['outer_right_margin'] + $currblk['outer_left_margin']);

		$currblk['inner_width'] = $currblk['width']
			- ($currblk['border_left']['w'] + $currblk['padding_left'] + $currblk['border_right']['w'] + $currblk['padding_right']);

		// Check DIV is not now too narrow to fit text
		$mw = 2 * $this->mpdf->GetCharWidth('W', false);
		if ($currblk['inner_width'] < $mw) {
			$currblk['padding_left'] = 0;
			$currblk['padding_right'] = 0;
			$currblk['border_left']['w'] = 0.2;
			$currblk['border_right']['w'] = 0.2;
			$currblk['margin_left'] = 0;
			$currblk['margin_right'] = 0;
			$currblk['outer_left_margin'] = $prevblk['outer_left_margin'] + $currblk['margin_left']
				+ $prevblk['border_left']['w'] + $prevblk['padding_left'];
			$currblk['outer_right_margin'] = $prevblk['outer_right_margin'] + $currblk['margin_right']
				+ $prevblk['border_right']['w'] + $prevblk['padding_right'];
			$currblk['width'] = $this->mpdf->pgwidth - ($currblk['outer_right_margin'] + $currblk['outer_left_margin']);
			$currblk['inner_width'] = $this->mpdf->pgwidth - ($currblk['outer_right_margin']
					+ $currblk['outer_left_margin'] + $currblk['border_left']['w'] + $currblk['padding_left']
					+ $currblk['border_right']['w'] + $currblk['padding_right']);
			// if ($currblk['inner_width'] < $mw) { throw new \Mpdf\MpdfException("DIV is too narrow for text to fit!"); }
		}

		$this->mpdf->x = $this->mpdf->lMargin + $currblk['outer_left_margin'];

		// Push a struct element for this block onto the struct tree. The struct
		// type is determined from the HTML tag and optional ROLE attribute.
		// A CSS float is a visual-positioning hint, not an accessibility one:
		// floated content is real content and is tagged in reading order with its
		// normal struct type. role="presentation" remains the explicit opt-out.
		if ($this->mpdf->PDFUA && !$this->mpdf->tableLevel) {
			$structType = $this->resolveBlockStructType($tag, $attr);

			if ($structType === '__artifact__') {
				$this->ua->getStructureTree()->openArtifact();
				$currblk['pdfua_artifact'] = true;
				$currblk['pdfua_type']     = null;
			} elseif ($structType !== null) {
				$structAttrs = [];
				if (isset($attr['LANG'])) {
					$structAttrs['Lang'] = $attr['LANG'];
				}
				if (isset($attr['ARIA-LABEL']) && $attr['ARIA-LABEL'] !== '') {
					$structAttrs['Alt'] = $attr['ARIA-LABEL'];
				}
				$this->ua->getStructureTree()->open($structType, $structAttrs);
				$currblk['pdfua_type'] = $structType;
				$currblk['pdfua_artifact'] = false;

				// ARIA ID registration and deferred-reference queuing
				$elem = $this->ua->getStructureTree()->getCurrent();
				// Capture the struct element reference so the per-page lazy opener
				// (Mpdf::ensureBlockBdcOpen) can call addContentForElement() against
				// THIS block element each time content emits on a new page. Mirrors
				// Tag/Td.php:434.
				$currblk['pdfua_struct_elem'] = $elem;
				if (!empty($attr['ID'])) {
					$this->ua->getAriaIdResolver()->registerId($attr['ID'], $elem);
				}
				foreach (['ARIA-LABELLEDBY', 'ARIA-DESCRIBEDBY', 'ARIA-DETAILS',
						  'ARIA-CONTROLS', 'ARIA-OWNS', 'ARIA-FLOWTO', 'ARIA-ACTIVEDESCENDANT'] as $k) {
					if (!empty($attr[$k])) {
						$this->ua->getAriaIdResolver()->queue($elem, strtolower($k), $attr[$k]);
					}
				}
			} else {
				$currblk['pdfua_type']     = null;
				$currblk['pdfua_artifact'] = false;
			}
		}

		/* -- BACKGROUNDS -- */
		if (!empty($properties['BACKGROUND-IMAGE']) && !$this->mpdf->kwt && !$this->mpdf->ColActive && !$this->mpdf->keep_block_together) {
			$ret = $this->mpdf->SetBackground($properties, $currblk['inner_width']);
			if ($ret) {
				$currblk['background-image'] = $ret;
			}
		}
		/* -- END BACKGROUNDS -- */

		/* -- TABLES -- */
		if ($this->mpdf->use_kwt && isset($attr['KEEP-WITH-TABLE']) && !$this->mpdf->ColActive && !$this->mpdf->keep_block_together) {
			$this->mpdf->kwt = true;
			$this->mpdf->kwt_y0 = $this->mpdf->y;
			//$this->mpdf->kwt_x0 = $this->mpdf->x;
			$this->mpdf->kwt_x0 = $this->mpdf->lMargin; // mPDF 6
			$this->mpdf->kwt_height = 0;
			$this->mpdf->kwt_buffer = [];
			$this->mpdf->kwt_Links = [];
			$this->mpdf->kwt_Annots = [];
			$this->mpdf->kwt_moved = false;
			$this->mpdf->kwt_saved = false;
			$this->mpdf->kwt_Reference = [];
			$this->mpdf->kwt_BMoutlines = [];
			$this->mpdf->kwt_toc = [];
		} else {
			/* -- END TABLES -- */
			$this->mpdf->kwt = false;
		} // *TABLES*

		// Save x,y coords in case we need to print borders...
		$currblk['y0'] = $this->mpdf->y;
		$currblk['initial_y0'] = $this->mpdf->y; // mPDF 6
		$currblk['x0'] = $this->mpdf->x;
		$currblk['initial_x0'] = $this->mpdf->x; // mPDF 6
		$currblk['initial_startpage'] = $this->mpdf->page;
		$currblk['startpage'] = $this->mpdf->page; // mPDF 6
		$this->mpdf->oldy = $this->mpdf->y;

		$this->mpdf->lastblocklevelchange = 1;

		// mPDF 6  Lists
		if ($tag === 'OL' || $tag === 'UL') {
			$this->mpdf->listlvl++;
			if (!empty($attr['START'])) {
				$this->mpdf->listcounter[$this->mpdf->listlvl] = (int) $attr['START'] - 1;
			} else {
				$this->mpdf->listcounter[$this->mpdf->listlvl] = 0;
			}
			$this->mpdf->listitem = [];

			// List-type
			if (empty($currblk['list_style_type'])) {
				if ($tag === 'OL') {
					$currblk['list_style_type'] = 'decimal';
				} elseif ($tag === 'UL') {
					if ($this->mpdf->listlvl % 3 == 1) {
						$currblk['list_style_type'] = 'disc';
					} elseif ($this->mpdf->listlvl % 3 == 2) {
						$currblk['list_style_type'] = 'circle';
					} else {
						$currblk['list_style_type'] = 'square';
					}
				}
			}

			// PDF/UA-1 (audit E17) — record the resolved marker style on the L
			// struct element as /ListNumbering so the writer emits its
			// /A <</O /List /ListNumbering …>> attribute object (ISO 32000-1
			// §14.8.5.3.3 Table 347). Set after the default marker above so an
			// unstyled <ol>/<ul> still carries the right value (Decimal / Disc …).
			if ($this->mpdf->PDFUA && !empty($currblk['pdfua_struct_elem'])) {
				$numbering = self::listNumberingFromCssType($currblk['list_style_type']);
				if ($numbering !== null) {
					$currblk['pdfua_struct_elem']->setAttribute('ListNumbering', $numbering);
				}
			}

			// List-image
			if (empty($currblk['list_style_image'])) {
				$currblk['list_style_image'] = 'none';
			}

			// List-position
			if (empty($currblk['list_style_position'])) {
				$currblk['list_style_position'] = 'outside';
			}

			// Default indentation using padding
			if (strtolower($this->mpdf->list_auto_mode) === 'mpdf' && isset($currblk['list_style_position'])
				&& $currblk['list_style_position'] === 'outside' && isset($currblk['list_style_image'])
				&& $currblk['list_style_image'] === 'none' && (!isset($currblk['list_style_type'])
					|| !preg_match('/U\+([a-fA-F0-9]+)/i', $currblk['list_style_type']))) {
				$autopadding = $this->mpdf->_getListMarkerWidth($currblk, $ahtml, $ihtml);
				if ($this->mpdf->listlvl > 1 || $this->mpdf->list_indent_first_level) {
					$autopadding += $this->sizeConverter->convert(
						$this->mpdf->list_indent_default,
						$currblk['inner_width'],
						$this->mpdf->FontSize,
						false
					);
				}
				// autopadding value is applied to left or right according
				// to dir of block. Once a CSS value is set for padding it overrides this default value.
				if (isset($properties['PADDING-RIGHT']) && $properties['PADDING-RIGHT'] === 'auto'
					&& isset($currblk['direction']) && $currblk['direction'] === 'rtl') {
					$currblk['padding_right'] = $autopadding;
				} elseif (isset($properties['PADDING-LEFT']) && $properties['PADDING-LEFT'] === 'auto') {
					$currblk['padding_left'] = $autopadding;
				}
			} else {
				// Initial default value is set by $this->mpdf->list_indent_default in config.php; this value is applied to left or right according
				// to dir of block. Once a CSS value is set for padding it overrides this default value.
				if (isset($properties['PADDING-RIGHT']) && $properties['PADDING-RIGHT'] === 'auto'
					&& isset($currblk['direction']) && $currblk['direction'] === 'rtl') {
					$currblk['padding_right'] = $this->sizeConverter->convert(
						$this->mpdf->list_indent_default,
						$currblk['inner_width'],
						$this->mpdf->FontSize,
						false
					);
				} elseif (isset($properties['PADDING-LEFT']) && $properties['PADDING-LEFT'] === 'auto') {
					$currblk['padding_left'] = $this->sizeConverter->convert(
						$this->mpdf->list_indent_default,
						$currblk['inner_width'],
						$this->mpdf->FontSize,
						false
					);
				}
			}
		}

		// mPDF 6  Lists
		if ($tag === 'LI') {
			if ($this->mpdf->listlvl == 0) { // in case of malformed HTML code. Example:(...)</p><li>Content</li><p>Paragraph1</p>(...)
				$this->mpdf->listlvl++; // first depth level
				$this->mpdf->listcounter[$this->mpdf->listlvl] = 0;
			}

			if (!isset($attr['PAGEBREAKAVOIDCHECKED']) || !$attr['PAGEBREAKAVOIDCHECKED']) {
				$this->mpdf->listcounter[$this->mpdf->listlvl]++;
			}

			$this->mpdf->listitem = [];

			// Listitem-type — guard with isset() because a bare <li> outside a <ul>/<ol>
			// may arrive here without the list_style_* keys being initialised (mPDF
			// PHP-5.6-compatible null-coalesce with ternary; long-standing latent notice
			// exposed by PHPUnit's strict error handler).
			$listStyleType     = isset($currblk['list_style_type'])     ? $currblk['list_style_type']     : 'disc';
			$listStyleImage    = isset($currblk['list_style_image'])    ? $currblk['list_style_image']    : 'none';
			$listStylePosition = isset($currblk['list_style_position']) ? $currblk['list_style_position'] : 'outside';
			$this->mpdf->_setListMarker($listStyleType, $listStyleImage, $listStylePosition);
		}

		// mPDF 6 Bidirectional formatting for block elements
		$bdf = false;
		$bdf2 = '';
		$popd = '';

		// Get current direction
		$currdir = 'ltr';
		if (isset($currblk['direction'])) {
			$currdir = $currblk['direction'];
		}
		if (isset($attr['DIR']) && $attr['DIR'] != '') {
			$currdir = strtolower($attr['DIR']);
		}
		if (isset($properties['DIRECTION'])) {
			$currdir = strtolower($properties['DIRECTION']);
		}

		// mPDF 6 bidi
		// cf. http://www.w3.org/TR/css3-writing-modes/#unicode-bidi
		if (isset($properties ['UNICODE-BIDI'])
			&& (strtolower($properties ['UNICODE-BIDI']) === 'bidi-override' || strtolower($properties ['UNICODE-BIDI']) === 'isolate-override')) {
			if ($currdir === 'rtl') {
				$bdf = 0x202E;
				$popd = 'RLOPDF';
			} // U+202E RLO
			else {
				$bdf = 0x202D;
				$popd = 'LROPDF';
			} // U+202D LRO
		} elseif (isset($properties ['UNICODE-BIDI']) && strtolower($properties ['UNICODE-BIDI']) === 'plaintext') {
			$bdf = 0x2068;
			$popd = 'FSIPDI'; // U+2068 FSI
		}
		if ($bdf) {
			if ($bdf2) {
				$bdf2 = UtfString::code2utf($bdf);
			}
			$this->mpdf->OTLdata = [];
			if ($this->mpdf->tableLevel) {
				$this->mpdf->_saveCellTextBuffer(UtfString::code2utf($bdf) . $bdf2);
			} else {
				$this->mpdf->_saveTextBuffer(UtfString::code2utf($bdf) . $bdf2);
			}
			$this->mpdf->biDirectional = true;
			$currblk['bidicode'] = $popd;
		}
	}

	public function close(&$ahtml, &$ihtml)
	{
		$tag = $this->getTagName();

		// PDF/UA-1 (audit E9) — close the struct element / artifact scope this
		// block tag opened inside a table cell (and any children whose end tag
		// HTML omitted). Done first so it runs on every close() path (the table
		// branch below returns early), popping before the next sibling opens.
		$this->closeCellBlockStructFrame($tag);

		// mPDF 6 bidi
		// Block
		// If unicode-bidi set, any embedding levels, isolates, or overrides started by this box are closed
		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['bidicode'])) {
			$blockpost = $this->mpdf->_setBidiCodes('end', $this->mpdf->blk[$this->mpdf->blklvl]['bidicode']);
			if ($blockpost) {
				$this->mpdf->OTLdata = [];
				if ($this->mpdf->tableLevel) {
					$this->mpdf->_saveCellTextBuffer($blockpost);
				} else {
					$this->mpdf->_saveTextBuffer($blockpost);
				}
			}
		}

		$this->mpdf->ignorefollowingspaces = true; //Eliminate exceeding left-side spaces
		$this->mpdf->blockjustfinished = true;

		$this->mpdf->lastblockbottommargin = $this->mpdf->blk[$this->mpdf->blklvl]['margin_bottom'];
		// mPDF 6  Lists
		if ($tag === 'UL' || $tag === 'OL') {
			if ($this->mpdf->listlvl > 0 && $this->mpdf->tableLevel) {
				if (isset($this->mpdf->listtype[$this->mpdf->listlvl])) {
					unset($this->mpdf->listtype[$this->mpdf->listlvl]);
				}
			}
			$this->mpdf->listlvl--;
			$this->mpdf->listitem = [];
		}
		if ($tag === 'LI') {
			$this->mpdf->listitem = [];
		}

		if (preg_match('/^H\d/', $tag) && !$this->mpdf->tableLevel && !$this->mpdf->writingToC) {
			if (isset($this->mpdf->h2toc[$tag]) || isset($this->mpdf->h2bookmarks[$tag])) {
				$content = '';
				if (count($this->mpdf->textbuffer) == 1) {
					$content = $this->mpdf->textbuffer[0][0];
				} else {
					for ($i = 0; $i < count($this->mpdf->textbuffer); $i++) {
						if (0 !== strpos($this->mpdf->textbuffer[$i][0], Mpdf::OBJECT_IDENTIFIER)) { //inline object
							$content .= $this->mpdf->textbuffer[$i][0];
						}
					}
				}
				/* -- TOC -- */
				if (isset($this->mpdf->h2toc[$tag])) {
					$objattr = [];
					$objattr['type'] = 'toc';
					$objattr['toclevel'] = $this->mpdf->h2toc[$tag];
					$objattr['CONTENT'] = htmlspecialchars($content);
					$e = Mpdf::OBJECT_IDENTIFIER . "type=toc,objattr=" . serialize($objattr) . Mpdf::OBJECT_IDENTIFIER;
					array_unshift($this->mpdf->textbuffer, [$e]);
				}
				/* -- END TOC -- */
				/* -- BOOKMARKS -- */
				if (isset($this->mpdf->h2bookmarks[$tag])) {
					$objattr = [];
					$objattr['type'] = 'bookmark';
					$objattr['bklevel'] = $this->mpdf->h2bookmarks[$tag];
					$objattr['CONTENT'] = $content;
					$e = Mpdf::OBJECT_IDENTIFIER . "type=toc,objattr=" . serialize($objattr) . Mpdf::OBJECT_IDENTIFIER;
					array_unshift($this->mpdf->textbuffer, [$e]);
				}
				/* -- END BOOKMARKS -- */
			}
		}

		/* -- TABLES -- */
		if ($this->mpdf->tableLevel) {
			if ($this->mpdf->linebreakjustfinished) {
				$this->mpdf->blockjustfinished = false;
			}
			if (isset($this->mpdf->InlineProperties['BLOCKINTABLE'])) {
				if ($this->mpdf->InlineProperties['BLOCKINTABLE']) {
					$this->mpdf->restoreInlineProperties($this->mpdf->InlineProperties['BLOCKINTABLE']);
				}
				unset($this->mpdf->InlineProperties['BLOCKINTABLE']);
			}
			if ($tag === 'PRE') {
				$this->mpdf->ispre = false;
			}
			return;
		}
		/* -- END TABLES -- */
		$this->mpdf->lastoptionaltag = '';
		$this->mpdf->divbegin = false;

		$this->mpdf->linebreakjustfinished = false;

		$this->mpdf->x = $this->mpdf->lMargin + $this->mpdf->blk[$this->mpdf->blklvl]['outer_left_margin'];

		/* -- CSS-FLOAT -- */
		// If float contained in a float, need to extend bottom to allow for it
		$currpos = $this->mpdf->page * 1000 + $this->mpdf->y;
		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['float_endpos']) && $this->mpdf->blk[$this->mpdf->blklvl]['float_endpos'] > $currpos) {
			$old_page = $this->mpdf->page;
			$new_page = (int) ($this->mpdf->blk[$this->mpdf->blklvl]['float_endpos'] / 1000);
			if ($old_page != $new_page) {
				$s = $this->mpdf->PrintPageBackgrounds();
				// Writes after the marker so not overwritten later by page background etc.
				$this->mpdf->pages[$this->mpdf->page] = preg_replace(
					'/(___BACKGROUND___PATTERNS' . $this->mpdf->uniqstr . ')/',
					'\\1' . "\n" . $s . "\n",
					$this->mpdf->pages[$this->mpdf->page]
				);
				$this->mpdf->pageBackgrounds = [];
				$this->mpdf->page = $new_page;
				$this->mpdf->ResetMargins();
				$this->mpdf->Reset();
				$this->mpdf->pageoutput[$this->mpdf->page] = [];
			}
			// mod changes operands to integers before processing
			$this->mpdf->y = (round($this->mpdf->blk[$this->mpdf->blklvl]['float_endpos'] * 1000) % 1000000) / 1000;
		}
		/* -- END CSS-FLOAT -- */


		//Print content
		$blockstate = 0;
		if ($this->mpdf->lastblocklevelchange == 1) {
			$blockstate = 3;
		} // Top & bottom margins/padding
		elseif ($this->mpdf->lastblocklevelchange == -1) {
			$blockstate = 2;
		} // Bottom margins/padding only

		// called from after e.g. </table> </div> </div> ...    Outputs block margin/border and padding
		if (count($this->mpdf->textbuffer) && $this->mpdf->textbuffer[count($this->mpdf->textbuffer) - 1]) {
			if (0 !== strpos($this->mpdf->textbuffer[count($this->mpdf->textbuffer) - 1][0], Mpdf::OBJECT_IDENTIFIER)) { // not special content
				// Right trim last content and adjust OTLdata
				if (preg_match('/[ ]+$/', $this->mpdf->textbuffer[count($this->mpdf->textbuffer) - 1][0], $m)) {
					$strip = strlen($m[0]);
					$this->mpdf->textbuffer[count($this->mpdf->textbuffer) - 1][0] = substr(
						$this->mpdf->textbuffer[count($this->mpdf->textbuffer) - 1][0],
						0,
						strlen($this->mpdf->textbuffer[count($this->mpdf->textbuffer) - 1][0]) - $strip
					);
					/* -- OTL -- */
					if (!empty($this->mpdf->CurrentFont['useOTL'])) {
						$this->otl->trimOTLdata($this->mpdf->textbuffer[count($this->mpdf->textbuffer) - 1][18], false); // mPDF 6  ZZZ99K
					}
					/* -- END OTL -- */
				}
			}
		}

		if (count($this->mpdf->textbuffer) == 0 && $this->mpdf->lastblocklevelchange != 0) {
			/*$this->mpdf->newFlowingBlock(
				$this->mpdf->blk[$this->mpdf->blklvl]['width'],
				$this->mpdf->lineheight,
				'',
				false,
				2,
				true,
				(isset($this->mpdf->blk[$this->mpdf->blklvl]['direction']) ? $this->mpdf->blk[$this->mpdf->blklvl]['direction'] : 'ltr')
			);*/

			$this->mpdf->newFlowingBlock(
				$this->mpdf->blk[$this->mpdf->blklvl]['width'],
				$this->mpdf->lineheight,
				'',
				false,
				$blockstate,
				true,
				(isset($this->mpdf->blk[$this->mpdf->blklvl]['direction']) ? $this->mpdf->blk[$this->mpdf->blklvl]['direction'] : 'ltr')
			);

			// PDF/UA-1 — restore pdfua_struct_open/pdfua_artifact_open flags for empty
			// blocks (same reasoning as printbuffer() — newFlowingBlock() resets them
			// to false/false on every call). Shared with the printbuffer() call sites.
			$this->mpdf->restoreFlowingBlockPdfuaState();

			$this->mpdf->finishFlowingBlock(true); // true = END of flowing block
			$this->mpdf->PaintDivBB('', $blockstate);
		} else {
			$this->mpdf->printbuffer($this->mpdf->textbuffer, $blockstate);
		}


		$this->mpdf->textbuffer = [];

		if ($this->mpdf->kwt) {
			$this->mpdf->kwt_height = $this->mpdf->y - $this->mpdf->kwt_y0;
		}

		/* -- CSS-IMAGE-FLOAT -- */
		$this->mpdf->printfloatbuffer();
		/* -- END CSS-IMAGE-FLOAT -- */

		if ($tag === 'PRE') {
			$this->mpdf->ispre = false;
		}

		/* -- CSS-FLOAT -- */
		if ($this->mpdf->blk[$this->mpdf->blklvl]['float'] === 'R') {
			// If width not set, here would need to adjust and output buffer
			$s = $this->mpdf->PrintPageBackgrounds();
			// Writes after the marker so not overwritten later by page background etc.
			$this->mpdf->pages[$this->mpdf->page] = preg_replace('/(___BACKGROUND___PATTERNS' . $this->mpdf->uniqstr . ')/', '\\1' . "\n" . $s . "\n", $this->mpdf->pages[$this->mpdf->page]);
			$this->mpdf->pageBackgrounds = [];
			$this->mpdf->Reset();
			$this->mpdf->pageoutput[$this->mpdf->page] = [];

			for ($i = ($this->mpdf->blklvl - 1); $i >= 0; $i--) {
				if (isset($this->mpdf->blk[$i]['float_endpos'])) {
					$this->mpdf->blk[$i]['float_endpos'] = max($this->mpdf->blk[$i]['float_endpos'], $this->mpdf->page * 1000 + $this->mpdf->y);
				} else {
					$this->mpdf->blk[$i]['float_endpos'] = $this->mpdf->page * 1000 + $this->mpdf->y;
				}
			}

			$this->mpdf->floatDivs[] = [
				'side' => 'R',
				'startpage' => $this->mpdf->blk[$this->mpdf->blklvl]['startpage'],
				'y0' => $this->mpdf->blk[$this->mpdf->blklvl]['float_start_y'],
				'startpos' => $this->mpdf->blk[$this->mpdf->blklvl]['startpage'] * 1000 + $this->mpdf->blk[$this->mpdf->blklvl]['float_start_y'],
				'endpage' => $this->mpdf->page,
				'y1' => $this->mpdf->y,
				'endpos' => $this->mpdf->page * 1000 + $this->mpdf->y,
				'w' => $this->mpdf->blk[$this->mpdf->blklvl]['float_width'],
				'blklvl' => $this->mpdf->blklvl,
				'blockContext' => $this->mpdf->blk[$this->mpdf->blklvl - 1]['blockContext']
			];

			$this->mpdf->y = $this->mpdf->blk[$this->mpdf->blklvl]['float_start_y'];
			$this->mpdf->page = $this->mpdf->blk[$this->mpdf->blklvl]['startpage'];
			$this->mpdf->ResetMargins();
			$this->mpdf->pageoutput[$this->mpdf->page] = [];
		}
		if ($this->mpdf->blk[$this->mpdf->blklvl]['float'] === 'L') {
			// If width not set, here would need to adjust and output buffer
			$s = $this->mpdf->PrintPageBackgrounds();
			// Writes after the marker so not overwritten later by page background etc.
			$this->mpdf->pages[$this->mpdf->page] = preg_replace('/(___BACKGROUND___PATTERNS' . $this->mpdf->uniqstr . ')/', '\\1' . "\n" . $s . "\n", $this->mpdf->pages[$this->mpdf->page]);
			$this->mpdf->pageBackgrounds = [];
			$this->mpdf->Reset();
			$this->mpdf->pageoutput[$this->mpdf->page] = [];

			for ($i = ($this->mpdf->blklvl - 1); $i >= 0; $i--) {
				if (isset($this->mpdf->blk[$i]['float_endpos'])) {
					$this->mpdf->blk[$i]['float_endpos'] = max($this->mpdf->blk[$i]['float_endpos'], $this->mpdf->page * 1000 + $this->mpdf->y);
				} else {
					$this->mpdf->blk[$i]['float_endpos'] = $this->mpdf->page * 1000 + $this->mpdf->y;
				}
			}

			$this->mpdf->floatDivs[] = [
				'side' => 'L',
				'startpage' => $this->mpdf->blk[$this->mpdf->blklvl]['startpage'],
				'y0' => $this->mpdf->blk[$this->mpdf->blklvl]['float_start_y'],
				'startpos' => $this->mpdf->blk[$this->mpdf->blklvl]['startpage'] * 1000 + $this->mpdf->blk[$this->mpdf->blklvl]['float_start_y'],
				'endpage' => $this->mpdf->page,
				'y1' => $this->mpdf->y,
				'endpos' => $this->mpdf->page * 1000 + $this->mpdf->y,
				'w' => $this->mpdf->blk[$this->mpdf->blklvl]['float_width'],
				'blklvl' => $this->mpdf->blklvl,
				'blockContext' => $this->mpdf->blk[$this->mpdf->blklvl - 1]['blockContext']
			];

			$this->mpdf->y = $this->mpdf->blk[$this->mpdf->blklvl]['float_start_y'];
			$this->mpdf->page = $this->mpdf->blk[$this->mpdf->blklvl]['startpage'];
			$this->mpdf->ResetMargins();
			$this->mpdf->pageoutput[$this->mpdf->page] = [];
		}
		/* -- END CSS-FLOAT -- */

		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['visibility']) && $this->mpdf->blk[$this->mpdf->blklvl]['visibility'] !== 'visible') {
			$this->mpdf->SetVisibility('visible');
		}

		$page_break_after = '';
		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['page_break_after'])) {
			$page_break_after = $this->mpdf->blk[$this->mpdf->blklvl]['page_break_after'];
		}

		// Reset values
		$this->mpdf->Reset();

		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['z-index']) && $this->mpdf->blk[$this->mpdf->blklvl]['z-index'] > 0) {
			$this->mpdf->EndLayer();
		}

		// mPDF 6 page-break-inside:avoid
		if ($this->mpdf->blk[$this->mpdf->blklvl]['keep_block_together']) {
			$movepage = false;
			// If page-break-inside:avoid section has broken to new page but fits on one side - then move:
			if (($this->mpdf->page - $this->mpdf->kt_p00) == 1 && $this->mpdf->y < $this->mpdf->kt_y00) {
				$movepage = true;
			}
			if (($this->mpdf->page - $this->mpdf->kt_p00) > 0) {
				for ($i = $this->mpdf->page; $i > $this->mpdf->kt_p00; $i--) {
					unset($this->mpdf->pages[$i]);
					if (isset($this->mpdf->blk[$this->mpdf->blklvl]['bb_painted'][$i])) {
						unset($this->mpdf->blk[$this->mpdf->blklvl]['bb_painted'][$i]);
					}
					if (isset($this->mpdf->blk[$this->mpdf->blklvl]['marginCorrected'][$i])) {
						unset($this->mpdf->blk[$this->mpdf->blklvl]['marginCorrected'][$i]);
					}
					if (isset($this->mpdf->pageoutput[$i])) {
						unset($this->mpdf->pageoutput[$i]);
					}
				}
				$this->mpdf->page = $this->mpdf->kt_p00;
			}
			$this->mpdf->keep_block_together = 0;
			$this->mpdf->pageoutput[$this->mpdf->page] = [];

			$this->mpdf->y = $this->mpdf->kt_y00;

			$ihtml = $this->mpdf->blk[$this->mpdf->blklvl]['array_i'] - 1;

			$ahtml[$ihtml + 1] .= ' pagebreakavoidchecked="true";'; // avoid re-iterating; read in OpenTag()

			unset($this->mpdf->blk[$this->mpdf->blklvl]);
			$this->mpdf->blklvl--;

			for ($blklvl = 1; $blklvl <= $this->mpdf->blklvl; $blklvl++) {
				$this->mpdf->blk[$blklvl]['y0'] = $this->mpdf->blk[$blklvl]['initial_y0'];
				$this->mpdf->blk[$blklvl]['x0'] = $this->mpdf->blk[$blklvl]['initial_x0'];
				$this->mpdf->blk[$blklvl]['startpage'] = $this->mpdf->blk[$blklvl]['initial_startpage'];
			}

			if (isset($this->mpdf->blk[$this->mpdf->blklvl]['x0'])) {
				$this->mpdf->x = $this->mpdf->blk[$this->mpdf->blklvl]['x0'];
			} else {
				$this->mpdf->x = $this->mpdf->lMargin;
			}

			$this->mpdf->lastblocklevelchange = 0;
			$this->mpdf->ResetMargins();
			if ($movepage) {
				$this->mpdf->AddPage();
			}
			return;
		}

		// Pop the struct element from the tree. Must happen after the block content
		// is flushed (printbuffer/finishFlowingBlock above) but before blklvl is
		// decremented so pdfua_type is still accessible.
		if ($this->mpdf->PDFUA && !$this->mpdf->tableLevel) {
			$blk = isset($this->mpdf->blk[$this->mpdf->blklvl]) ? $this->mpdf->blk[$this->mpdf->blklvl] : [];
			if (!empty($blk['pdfua_artifact'])) {
				$this->ua->getStructureTree()->closeArtifact();
			} elseif (!empty($blk['pdfua_type'])) {
				$this->ua->getStructureTree()->close();
			}
		}

		if ($this->mpdf->blklvl > 0) { // ==0 SHOULDN'T HAPPEN - NOT XHTML
			if ($this->mpdf->blk[$this->mpdf->blklvl]['tag'] == $tag) {
				unset($this->mpdf->blk[$this->mpdf->blklvl]);
				$this->mpdf->blklvl--;
			}
			//else { echo $tag; exit; }	// debug - forces error if incorrectly nested html tags
		}

		$this->mpdf->lastblocklevelchange = -1;
		// Reset Inline-type properties
		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['InlineProperties'])) {
			$this->mpdf->restoreInlineProperties($this->mpdf->blk[$this->mpdf->blklvl]['InlineProperties']);
		}

		$this->mpdf->x = $this->mpdf->lMargin + $this->mpdf->blk[$this->mpdf->blklvl]['outer_left_margin'];

		if (!$this->mpdf->tableLevel && $page_break_after) {
			$save_blklvl = $this->mpdf->blklvl;
			$save_blk = $this->mpdf->blk;
			$save_silp = $this->mpdf->saveInlineProperties();
			$save_ilp = $this->mpdf->InlineProperties;
			$save_bflp = $this->mpdf->InlineBDF;
			$save_bflpc = $this->mpdf->InlineBDFctr; // mPDF 6
			// mPDF 6 pagebreaktype
			$startpage = $this->mpdf->page;
			$pagebreaktype = $this->mpdf->defaultPagebreakType;
			if ($this->mpdf->ColActive) {
				$pagebreaktype = 'cloneall';
			}

			// mPDF 6 pagebreaktype
			$this->mpdf->_preForcedPagebreak($pagebreaktype);

			if ($page_break_after === 'RIGHT') {
				$this->mpdf->AddPage($this->mpdf->CurOrientation, 'NEXT-ODD');
			} elseif ($page_break_after === 'LEFT') {
				$this->mpdf->AddPage($this->mpdf->CurOrientation, 'NEXT-EVEN');
			} else {
				$this->mpdf->AddPage($this->mpdf->CurOrientation);
			}

			// mPDF 6 pagebreaktype
			$this->mpdf->_postForcedPagebreak($pagebreaktype, $startpage, $save_blk, $save_blklvl);

			$this->mpdf->InlineProperties = $save_ilp;
			$this->mpdf->InlineBDF = $save_bflp;
			$this->mpdf->InlineBDFctr = $save_bflpc; // mPDF 6
			$this->mpdf->restoreInlineProperties($save_silp);
		}
		// mPDF 6 bidi
		// Block
		// If unicode-bidi set, any embedding levels, isolates, or overrides reopened in the continuing block
		if (isset($this->mpdf->blk[$this->mpdf->blklvl]['bidicode'])) {
			$blockpre = $this->mpdf->_setBidiCodes('start', $this->mpdf->blk[$this->mpdf->blklvl]['bidicode']);
			if ($blockpre) {
				$this->mpdf->OTLdata = [];
				if ($this->mpdf->tableLevel) {
					$this->mpdf->_saveCellTextBuffer($blockpre);
				} else {
					$this->mpdf->_saveTextBuffer($blockpre);
				}
			}
		}
	}

}
