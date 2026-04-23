<?php

namespace Mpdf\Ua;

/**
 * Holds the runtime state for `<a>` tag PDF/UA-1 emission.
 *
 * Three pieces of state, all owned by UaState (replaces the legacy properties
 * `$mpdf->pdfuaLinkStructElem`, `$mpdf->pdfuaStrippedAnchorStack`,
 * `$mpdf->pdfuaAnchorStructType`):
 *
 *  - **Current Link struct element** — set by Tag\A::open() when an <a href>
 *    opens a Link struct element. Read by Mpdf::Link() / Mpdf::Cell() /
 *    printbuffer() so the link annotation can be wired to the structure
 *    element via OBJR (ISO 14289-1 §7.18.5 / Matterhorn 02-003).
 *  - **Strip stack** — per-anchor [stripped, spanDepth] entries pushed by
 *    Tag\A::open() and popped by close(). When the href is a blocked URL
 *    scheme (javascript:, vbscript:) the link is stripped but a Span struct
 *    may still wrap the visible text to preserve lang/aria metadata.
 *  - **Last-pushed anchor struct type** — `'Link'`, `'Span'`, or null;
 *    informational tracker for downstream consumers.
 *
 * @see Tag\A
 * @see UaPolicy::isPolicyBlockedHref()
 */
class AnchorState
{

	/**
	 * Current Link struct element ref; null when no <a href> is open.
	 *
	 * @var \Mpdf\Ua\StructureElement|null
	 */
	protected $linkStructElem = null;

	/**
	 * Per-anchor stack of [bool $stripped, int $spanDepth] entries.
	 *
	 * One entry pushed per <a href> open, popped on close. `$stripped` is true
	 * when the href used a blocked scheme and the link annotation was
	 * suppressed; `$spanDepth` is the number of Span struct elements pushed
	 * for surviving lang/aria metadata.
	 *
	 * @var array<int,array{0:bool,1:int}>
	 */
	protected $strippedAnchorStack = [];

	/**
	 * Last-pushed anchor struct type: 'Link', 'Span', or null.
	 *
	 * @var string|null
	 */
	protected $anchorStructType = null;

	// ================== Link struct element ==================

	/**
	 * Set the current Link struct element. Called by Tag\A::open() when an
	 * <a href> opens a Link, and by Mpdf::Cell() / printbuffer() when
	 * restoring buffered cell entries that captured the element ref.
	 *
	 * @param \Mpdf\Ua\StructureElement|null $elem
	 * @return void
	 */
	public function setLinkStructElem($elem)
	{
		$this->linkStructElem = $elem;
	}

	/**
	 * Return the current Link struct element, or null when no <a href> is open.
	 *
	 * @return \Mpdf\Ua\StructureElement|null
	 */
	public function getLinkStructElem()
	{
		return $this->linkStructElem;
	}

	/**
	 * Convenience: clear the current Link struct element. Equivalent to
	 * setLinkStructElem(null).
	 *
	 * @return void
	 */
	public function clearLinkStructElem()
	{
		$this->linkStructElem = null;
	}

	// ================== Strip stack ==================

	/**
	 * Push a new entry on the strip stack.
	 *
	 * @param  bool $stripped   true if the link was stripped (blocked scheme)
	 * @param  int  $spanDepth  number of Span struct elements pushed for the stripped anchor
	 * @return void
	 */
	public function pushStripFrame($stripped, $spanDepth)
	{
		$this->strippedAnchorStack[] = [(bool) $stripped, (int) $spanDepth];
	}

	/**
	 * Pop the top strip-stack entry and return it, or null if the stack is empty.
	 *
	 * @return array{0:bool,1:int}|null
	 */
	public function popStripFrame()
	{
		if (empty($this->strippedAnchorStack)) {
			return null;
		}
		return array_pop($this->strippedAnchorStack);
	}

	/**
	 * @return bool  true if there is at least one strip-stack entry
	 */
	public function hasStripFrames()
	{
		return !empty($this->strippedAnchorStack);
	}

	// ================== Anchor struct type ==================

	/**
	 * Record the struct type just pushed by Tag\A: 'Link', 'Span', or null.
	 *
	 * @param  string|null $type
	 * @return void
	 */
	public function setAnchorStructType($type)
	{
		$this->anchorStructType = $type === null ? null : (string) $type;
	}

	/**
	 * @return string|null
	 */
	public function getAnchorStructType()
	{
		return $this->anchorStructType;
	}
}
