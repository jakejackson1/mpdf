<?php

namespace Mpdf\Ua;

/**
 * Per-tag depth-counted stack tracking how many inline Span struct elements
 * an inline tag's open() handler pushed, so the matching close() can pop the
 * same number.
 *
 * Owned by UaState; accessed via UaState::getInlineStructStack(). Replaces the
 * legacy $mpdf->InlineUaStruct array — same semantics, no Mpdf surface.
 *
 * Stack shape: tag-name (uppercase) → list of integers. Each integer is the
 * number of struct elements the corresponding open() pushed. close() pops the
 * top integer and closes that many StructureTree elements.
 *
 * Example: nested <span><span lang="fr">…</span></span>:
 *   open()  push frame 0 ('SPAN'): [0]
 *   open()  push frame 1 ('SPAN'): [0, 1]   (lang= ⇒ Span pushed)
 *   close() pop top frame:         [0]      (closes 1 struct element)
 *   close() pop top frame:         []       (closes 0 struct elements)
 *
 * Subclasses (e.g. Tag\Abbr for /E expansion text) call addToTopFrame() after
 * parent::open() to layer additional struct elements onto the same frame.
 *
 * @see Tag\InlineTag::open()    pushes per-tag frame
 * @see Tag\InlineTag::close()   pops per-tag frame
 * @see Tag\Abbr / Ruby / Rb / Rt   add to top frame
 */
class InlineStructStack
{

	/**
	 * Per-tag stacks. Key = uppercase tag name; value = list of integer depths.
	 *
	 * @var array<string,int[]>
	 */
	protected $stacks = [];

	/**
	 * Push a new frame for $tag with the given initial depth (0 or 1 in practice).
	 *
	 * @param  string $tag    uppercase tag name
	 * @param  int    $depth  initial number of struct elements opened
	 * @return void
	 */
	public function pushFrame($tag, $depth)
	{
		if (!isset($this->stacks[$tag])) {
			$this->stacks[$tag] = [];
		}
		$this->stacks[$tag][] = (int) $depth;
	}

	/**
	 * Increase the top frame's depth for $tag by $count. No-op if the stack is
	 * empty (subclasses defensively call this even when parent::open() did not
	 * push a frame because PDFUA is off).
	 *
	 * @param  string $tag
	 * @param  int    $count
	 * @return void
	 */
	public function addToTopFrame($tag, $count)
	{
		if (empty($this->stacks[$tag])) {
			return;
		}
		$idx = count($this->stacks[$tag]) - 1;
		$this->stacks[$tag][$idx] += (int) $count;
	}

	/**
	 * Pop the top frame for $tag and return its depth. Returns 0 if the stack
	 * is empty (no struct elements should be closed).
	 *
	 * @param  string $tag
	 * @return int
	 */
	public function popFrame($tag)
	{
		if (empty($this->stacks[$tag])) {
			return 0;
		}
		return (int) array_pop($this->stacks[$tag]);
	}
}
