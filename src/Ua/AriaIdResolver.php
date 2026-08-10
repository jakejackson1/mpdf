<?php

namespace Mpdf\Ua;

/**
 * Two-pass resolver for ID-referencing ARIA attributes.
 *
 * mPDF's HTML parse is sequential and single-pass, so a tag handler reading
 * `aria-labelledby="caption"` may not yet have seen the `<span id="caption">`
 * target. This resolver records every referencer during parse (Pass 1) and
 * resolves them against the id-element map at `_enddoc()` time (Pass 2),
 * BEFORE `StructureWriter::writeStructTree()` serialises the tree.
 *
 * Pass-2 mutations touch the in-memory `StructureElement::$attributes` and
 * relationship kids only — the page content stream is already flushed by
 * `_enddoc()` time, but that does not matter because /Alt, /E, and OBJR kids
 * live on the struct element dict (not in the content stream).
 *
 * Supported attributes (WAI-ARIA 1.2):
 *   - aria-labelledby   → /Alt (StructElem dict, ISO 32000-1 Table 322)
 *   - aria-describedby  → /E (expansion text, ISO 32000-1 Table 322)
 *   - aria-details      → /E (same treatment as aria-describedby)
 *   - aria-controls     → /Ref cross-reference (ISO 32000-2 §14.7 struct /Ref)
 *   - aria-owns         → /Ref cross-reference
 *   - aria-flowto       → no static PDF/UA-1 representation → visible warning
 *   - aria-activedescendant → no static PDF/UA-1 representation → visible warning
 *
 * Interactive-state ARIA (aria-live, aria-busy, aria-checked, …) has no
 * static-PDF analog and is intentionally out of scope.
 *
 * AriaIdResolver holds no UaState back-reference — unresolved-reference
 * diagnostics accumulate in $unresolvedWarnings and are flushed into
 * UaState::addWarning() by the Mpdf::_enddoc() caller after resolveAll().
 * This breaks the construction-time cycle that would otherwise require
 * setter-based wiring.
 *
 * One instance per Mpdf lifecycle, constructed by ServiceFactory and reached
 * via $this->ua->getAriaIdResolver().
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — /Alt and /E on struct elements
 *   - WAI-ARIA 1.1 §6.6 — aria-labelledby, aria-describedby ID reference semantics
 *
 * @see StructureElement::setAttribute()
 * @see StructureElement::addRelationship()
 */
class AriaIdResolver
{

	/**
	 * Maximum byte length of an ARIA ID-list attribute value before queue()
	 * rejects it. Defends against pathological inputs where a 1 MB
	 * `aria-labelledby="a a a..."` would amplify to ~300 MB peak memory in
	 * the resolver pending queue (UA1 audit M-1).
	 *
	 * 16 KiB is an order of magnitude beyond any legitimate use — even an
	 * extreme accessibility annotation would not exceed a few hundred bytes.
	 */
	const MAX_ARIA_IDS_LENGTH = 16384;

	/**
	 * Maximum number of IDs split out of a single ARIA attribute. Bounds
	 * the per-element memory footprint of the deferred resolution queue.
	 *
	 * 256 tokens is well beyond any plausible legitimate fan-in (a typical
	 * `aria-labelledby` references one or two IDs).
	 */
	const MAX_ARIA_IDS_TOKENS = 256;

	/**
	 * ID-referencing ARIA attributes (canonical lowercase, hyphenated) queued
	 * by queueAriaRefs() and resolved in the second pass by resolveAll().
	 *
	 * @var string[]
	 */
	const REFERENCE_ARIA_ATTRS = [
		'aria-labelledby', 'aria-describedby', 'aria-details',
		'aria-controls', 'aria-owns', 'aria-flowto', 'aria-activedescendant',
	];

	/** @var StructureTree  injected once; walked during resolveAll() to populate /Alt /E /Ref. */
	private $tree;

	/** @var array<string, StructureElement>  id attribute value → struct element that carries it. */
	private $idMap = [];

	/**
	 * @var array  Pending [referencing element, aria attribute name (lowercase), target ID] tuples.
	 *             Resolved in a second pass at _enddoc() time via resolveAll().
	 */
	private $pending = [];

	/**
	 * @var string[]  Unresolved-reference diagnostics produced by resolveAll().
	 *                Flushed into UaState by the _enddoc() caller.
	 */
	private $unresolvedWarnings = [];

	/**
	 * @var string[]  Name-resolution failures produced by resolveAll(): an
	 *                aria-labelledby / aria-describedby / aria-details reference
	 *                whose target is missing or carries no text. Emitting an /Alt
	 *                or /E for these would write a BOM-only empty string that,
	 *                per ISO 32000-1 Table 322, REPLACES the referring element's
	 *                content for assistive technology and silently hides it
	 *                (UA1 audit E8). The _enddoc() caller throws on these in
	 *                strict mode and warns in PDFUAauto mode (Matterhorn
	 *                13-004 / 28-002); resolveAll() never writes the empty value.
	 */
	private $nameResolutionErrors = [];

	/**
	 * @var string[]  Diagnostics produced by resolveAll() for resolved ARIA
	 *                relationships that have no static PDF/UA-1 representation —
	 *                aria-flowto (a reading-order override, determined here by
	 *                structure-tree order) and aria-activedescendant (a transient
	 *                interactive-focus relationship). Emitting nothing for these
	 *                is correct — a static tagged PDF cannot carry the semantics —
	 *                but they must be surfaced visibly rather than stored-and-
	 *                dropped (UA1 audit E18). The _enddoc() caller flushes them
	 *                into UaState::addWarning(); resolveAll() never stores the
	 *                relationship on the element.
	 */
	private $relationshipWarnings = [];

	/**
	 * @var int  Monotonic counter for synthesised TH /ID values.
	 *           Two tables at the same nesting level on the same page would
	 *           otherwise collide on `th-{tableLevel}-{row}-{col}` — the
	 *           counter guarantees document-wide uniqueness so that TD
	 *           /Headers references resolve to the intended TH.
	 */
	private $syntheticThCounter = 0;

	/**
	 * Construct with the structure tree that holds the /ID-tagged elements.
	 *
	 * Called once by ServiceFactory before UaState is constructed.
	 * No UaState reference is held — warnings accumulate locally and are
	 * flushed into UaState::addWarning() by the _enddoc() caller.
	 *
	 * @param StructureTree $tree  element stack / ParentTree accumulator
	 */
	public function __construct(StructureTree $tree)
	{
		$this->tree = $tree;
	}

	/**
	 * Record an HTML `id` attribute on a struct element.
	 *
	 * Called from any tag handler whose open() observes an `id` attribute.
	 * First-declaration wins — duplicate IDs in malformed HTML silently keep
	 * the first binding rather than throwing, matching typical browser
	 * behaviour for getElementById.
	 *
	 * @param  string           $id    value of the HTML `id` attribute
	 * @param  StructureElement $elem  struct element carrying this id
	 * @return void
	 */
	public function registerId($id, StructureElement $elem)
	{
		// Normalize to lowercase for case-insensitive matching.
		// mPDF's HTML parser uppercases the value of the id= attribute (Mpdf.php ~line 14204)
		// but does NOT uppercase aria-* target values, so both sides must normalize.
		$id = strtolower((string) $id);
		if ($id !== '' && !isset($this->idMap[$id])) {
			$this->idMap[$id] = $elem;
		}
	}

	/**
	 * Register a struct element's HTML id and queue all of its ID-referencing
	 * ARIA attributes in one call — collapsing the registerId() + per-attribute
	 * queue() loop that was copy-pasted across Tag\A, Tag\InlineTag and the
	 * image object paths (Figure / barcode / text-circle) in Mpdf.
	 *
	 * Two source conventions carry the same data under different key spellings:
	 *   - HTML tag handlers: uppercase, hyphenated — ID, ARIA-LABELLEDBY, …
	 *   - image object buffer ($objattr = true): pdfua_-prefixed, lowercase,
	 *     underscored — pdfua_id, pdfua_aria_labelledby, …. ($objattr also
	 *     carries an unrelated integer 'ID' = Form XObject number, so the
	 *     convention must be selected explicitly rather than sniffed.)
	 * Either way the canonical lowercase-hyphenated name is passed to queue().
	 *
	 * @param  StructureElement $elem     struct element to bind the id / refs to
	 * @param  array            $attr     source attribute array
	 * @param  bool             $objattr  true → read pdfua_-prefixed keys; false → HTML tag keys
	 * @return void
	 */
	public function queueAriaRefs(StructureElement $elem, array $attr, $objattr = false)
	{
		$idKey = $objattr ? 'pdfua_id' : 'ID';
		if (!empty($attr[$idKey])) {
			$this->registerId($attr[$idKey], $elem);
		}
		foreach (self::REFERENCE_ARIA_ATTRS as $ariaName) {
			$key = $objattr
				? 'pdfua_' . str_replace('-', '_', $ariaName) // pdfua_aria_labelledby
				: strtoupper($ariaName);                       // ARIA-LABELLEDBY
			if (!empty($attr[$key])) {
				$this->queue($elem, $ariaName, $attr[$key]);
			}
		}
	}

	/**
	 * Return the next synthetic TH /ID counter value.
	 *
	 * Used by Th.php when an HTML <th> has no explicit id="...". The counter
	 * increases monotonically across the entire document so two tables at the
	 * same nesting level on the same page cannot produce identical synthesised
	 * IDs (which would silently break TD /Headers cross-references).
	 *
	 * @return int next counter value (1-based)
	 */
	public function nextSyntheticThCounter()
	{
		return ++$this->syntheticThCounter;
	}

	/**
	 * Queue an ID-referencing ARIA attribute for deferred resolution.
	 *
	 * Called from every tag handler that sees aria-labelledby, aria-describedby,
	 * aria-details, aria-controls, aria-owns, aria-flowto, or
	 * aria-activedescendant. ARIA allows space-separated ID lists; this splits
	 * them and queues one pending entry per ID.
	 *
	 * @param  StructureElement $elem          element that owns the ARIA attribute
	 * @param  string           $ariaAttrName  lowercase ARIA attribute name (e.g. 'aria-labelledby')
	 * @param  string           $targetIds     raw attribute value; may be space-separated list
	 * @return void
	 */
	public function queue(StructureElement $elem, $ariaAttrName, $targetIds)
	{
		$targetIds = (string) $targetIds;

		// UA1 audit M-1 — reject oversized inputs at the call site rather
		// than letting preg_split allocate millions of tuples in $pending.
		if (strlen($targetIds) > self::MAX_ARIA_IDS_LENGTH) {
			$this->unresolvedWarnings[] = $ariaAttrName
				. ' attribute exceeded ' . self::MAX_ARIA_IDS_LENGTH
				. ' bytes; ignored to prevent memory amplification (UA1 audit M-1).';
			return;
		}

		// Normalize IDs to lowercase to match registerId() normalization.
		// Both mPDF-uppercased ID values (from HTML id= attributes, Mpdf.php ~line 14204)
		// and mixed-case aria-* target values resolve to the same key.
		// Cap the split at MAX_ARIA_IDS_TOKENS so a value packed with whitespace
		// cannot expand to an unbounded number of pending tuples.
		$tokens = preg_split(
			'/\s+/',
			trim($targetIds),
			self::MAX_ARIA_IDS_TOKENS + 1,
			PREG_SPLIT_NO_EMPTY
		);
		if (!is_array($tokens)) {
			return;
		}
		if (count($tokens) > self::MAX_ARIA_IDS_TOKENS) {
			$this->unresolvedWarnings[] = $ariaAttrName
				. ' attribute had more than ' . self::MAX_ARIA_IDS_TOKENS
				. ' IDs; truncated (UA1 audit M-1).';
			$tokens = array_slice($tokens, 0, self::MAX_ARIA_IDS_TOKENS);
		}

		foreach ($tokens as $id) {
			$this->pending[] = [$elem, $ariaAttrName, strtolower($id)];
		}
	}

	/**
	 * Walk every queued reference and mutate the referencing struct element.
	 *
	 * Called exactly once from Mpdf::_enddoc(), before
	 * UaState::getStructureWriter()->writeStructTree() serialises the tree.
	 * Unresolved IDs append a PDFUAauto-mode diagnostic to
	 * $this->unresolvedWarnings. The _enddoc() caller flushes those into
	 * UaState::addWarning() immediately after resolveAll() returns.
	 *
	 * @return void
	 */
	public function resolveAll()
	{
		// aria-labelledby → /Alt, aria-describedby / aria-details → /E. These are
		// the "naming" attributes: an unresolved or empty target must NOT emit an
		// /Alt or /E, because a BOM-only empty string replaces and hides the
		// referring element's content (ISO 32000-1 Table 322, UA1 audit E8).
		$namingAttrs = ['aria-labelledby', 'aria-describedby', 'aria-details'];

		foreach ($this->pending as $pending) {
			$elem = $pending[0];
			$attr = $pending[1];
			$id   = $pending[2];

			if (!isset($this->idMap[$id])) {
				$msg = 'Unresolved ARIA reference: ' . $attr . '="' . $id
					. '" has no matching id="' . $id . '" in the document';
				// A naming attribute that resolves to nothing is a hard failure —
				// route it so strict throws / auto warns rather than silently
				// leaving the referring element unnamed.
				if (in_array($attr, $namingAttrs, true)) {
					$this->nameResolutionErrors[] = $msg;
				} else {
					$this->unresolvedWarnings[] = $msg;
				}
				continue;
			}
			$target = $this->idMap[$id];
			switch ($attr) {
				case 'aria-labelledby':
					// ISO 32000-1 §14.7.2 Table 322 — /Alt is a direct StructElem key
					// (NOT inside /A). Only fill if the element does not already carry
					// Alt from an explicit alt="" or aria-label="" source.
					$existing = $elem->getAttributes();
					if (!isset($existing['Alt'])) {
						$text = $this->collectText($target);
						if ($text === '') {
							// E8 — never write an empty /Alt: the BOM-only string would
							// REPLACE and hide $elem's content for AT (Matterhorn 13-004).
							$this->nameResolutionErrors[] = 'ARIA reference resolved to empty text: '
								. $attr . '="' . $id . '" target carries no text content; '
								. 'refusing to emit an empty /Alt (Matterhorn 13-004)';
						} else {
							$elem->setAttribute('Alt', $text);
						}
					}
					break;
				case 'aria-describedby':
				case 'aria-details':
					// ISO 32000-1 §14.7.2 Table 322 — /E carries expansion / description text.
					$text = $this->collectText($target);
					if ($text === '') {
						// E8 — never write an empty /E (Matterhorn 28-002).
						$this->nameResolutionErrors[] = 'ARIA reference resolved to empty text: '
							. $attr . '="' . $id . '" target carries no text content; '
							. 'refusing to emit an empty /E (Matterhorn 28-002)';
					} else {
						$elem->setAttribute('E', $text);
					}
					break;
				case 'aria-owns':
				case 'aria-controls':
					// ISO 32000-2 §14.7 — /Ref cross-reference: this struct
					// element refers to the resolved target struct element(s).
					// aria-owns / aria-controls both express a structural
					// reference and map cleanly to /Ref, which StructureWriter
					// emits as `/Ref [N 0 R …]` on the referring element's dict.
					$elem->addRelationship($attr, $target);
					break;
				case 'aria-flowto':
				case 'aria-activedescendant':
					// No static PDF/UA-1 (ISO 32000-1) representation: aria-flowto
					// overrides reading order (which a tagged PDF derives from the
					// structure-tree order) and aria-activedescendant names a
					// transient interactive-focus target. There is nothing to emit,
					// but the loss MUST be visible rather than store-and-drop
					// (UA1 audit E18). Record a warning the _enddoc() caller flushes
					// into UaState::addWarning(); do not store the relationship.
					$this->relationshipWarnings[] = 'ARIA relationship has no PDF/UA-1 representation: '
						. $attr . '="' . $id . '" cannot be expressed in a static PDF/UA-1 '
						. 'structure tree; the relationship was not emitted.';
					break;
			}
		}
	}

	/**
	 * Gather the accessible-name text of a struct element for use as /Alt or /E.
	 *
	 * Resolution order (first non-empty wins, matching the WAI-ARIA name
	 * computation's preference for an explicit accessible name):
	 *   1. the element's own /ActualText, then /Alt (an explicit accessible name);
	 *   2. otherwise the element's own captured text plus every descendant's
	 *      collected text, in document order, joined by single spaces.
	 *
	 * The captured own-text comes from StructureElement::getOwnText(), which the
	 * layout engine populates as it emits each line of the element's marked
	 * content (UA1 audit E8). This replaces the former stub that returned only
	 * the target's own ActualText/Alt and, for an ordinary text target, an empty
	 * string — which resolveAll() then wrote as a content-hiding empty /Alt.
	 *
	 * Produces plain UTF-8 text suitable for StructureElement::setAttribute();
	 * StructureWriter encodes it to a UTF-16BE PDF string at emit time.
	 *
	 * @param  StructureElement $elem
	 * @return string  concatenated accessible-name text (may be empty)
	 */
	private function collectText(StructureElement $elem)
	{
		$attrs = $elem->getAttributes();
		if (isset($attrs['ActualText']) && $attrs['ActualText'] !== '') {
			return $attrs['ActualText'];
		}
		if (isset($attrs['Alt']) && $attrs['Alt'] !== '') {
			return $attrs['Alt'];
		}
		$parts = [];
		$own = $elem->getOwnText();
		if ($own !== '') {
			$parts[] = $own;
		}
		foreach ($elem->getChildren() as $child) {
			$childText = $this->collectText($child);
			if ($childText !== '') {
				$parts[] = $childText;
			}
		}
		return implode(' ', $parts);
	}

	/**
	 * Diagnostics collected by resolveAll() for IDs that had no matching element.
	 *
	 * The _enddoc() caller flushes these into UaState::addWarning() —
	 * AriaIdResolver itself holds no UaState reference.
	 *
	 * @return string[]
	 */
	public function getUnresolvedWarnings()
	{
		return $this->unresolvedWarnings;
	}

	/**
	 * Name-resolution failures collected by resolveAll(): an aria-labelledby /
	 * aria-describedby / aria-details reference whose target is missing or holds
	 * no text.
	 *
	 * resolveAll() never emits an /Alt or /E for these (that would write a
	 * content-hiding empty string — UA1 audit E8). The _enddoc() caller throws on
	 * a non-empty list in strict mode and flushes the messages into
	 * UaState::addWarning() in PDFUAauto mode (Matterhorn 13-004 / 28-002).
	 *
	 * @return string[]
	 */
	public function getNameResolutionErrors()
	{
		return $this->nameResolutionErrors;
	}

	/**
	 * Diagnostics collected by resolveAll() for resolved ARIA relationships that
	 * have no static PDF/UA-1 representation (aria-flowto / aria-activedescendant).
	 *
	 * These are not conformance violations — the emitted PDF is valid PDF/UA-1
	 * either way — so they are surfaced as warnings in both strict and PDFUAauto
	 * mode (like getUnresolvedWarnings()) rather than thrown. The _enddoc() caller
	 * flushes them into UaState::addWarning(); AriaIdResolver holds no UaState
	 * reference (UA1 audit E18).
	 *
	 * @return string[]
	 */
	public function getRelationshipWarnings()
	{
		return $this->relationshipWarnings;
	}
}
