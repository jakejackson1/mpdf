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
 *   - aria-controls     → relationship kid (ISO 32000-1 §14.8.5.3)
 *   - aria-owns         → relationship kid
 *   - aria-flowto       → relationship kid
 *   - aria-activedescendant → relationship kid
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
		// Normalize IDs to lowercase to match registerId() normalization.
		// Both mPDF-uppercased ID values (from HTML id= attributes, Mpdf.php ~line 14204)
		// and mixed-case aria-* target values resolve to the same key.
		foreach (preg_split('/\s+/', trim((string) $targetIds)) as $id) {
			if ($id !== '') {
				$this->pending[] = [$elem, $ariaAttrName, strtolower($id)];
			}
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
		foreach ($this->pending as $pending) {
			$elem = $pending[0];
			$attr = $pending[1];
			$id   = $pending[2];

			if (!isset($this->idMap[$id])) {
				$this->unresolvedWarnings[] = 'Unresolved ARIA reference: ' . $attr . '="' . $id
					. '" has no matching id="' . $id . '" in the document';
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
						$elem->setAttribute('Alt', $this->collectText($target));
					}
					break;
				case 'aria-describedby':
				case 'aria-details':
					// ISO 32000-1 §14.7.2 Table 322 — /E carries expansion / description text.
					$elem->setAttribute('E', $this->collectText($target));
					break;
				case 'aria-controls':
				case 'aria-owns':
				case 'aria-flowto':
				case 'aria-activedescendant':
					// ISO 32000-1 §14.8.5.3 — relationship attributes.
					$elem->addRelationship($attr, $target);
					break;
			}
		}
	}

	/**
	 * Gather concatenated descendant text from a struct element for use as
	 * /Alt or /E content.
	 *
	 * Recursively walks the target's children and concatenates every text
	 * content item. Produces plain UTF-8 text suitable for passing to
	 * StructureElement::setAttribute().
	 *
	 * For the current stub implementation the tree does not store separate
	 * text-node children — the element's /ActualText or its text attributes
	 * are used when present. A full text-walk would require the struct tree
	 * to track text runs per element.
	 *
	 * @param  StructureElement $elem
	 * @return string  concatenated descendant text (may be empty)
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
		$text = '';
		foreach ($elem->getChildren() as $child) {
			$text .= $this->collectText($child);
		}
		return $text;
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
}
