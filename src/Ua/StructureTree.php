<?php

namespace Mpdf\Ua;

/**
 * Accumulator for the logical document structure during HTML parse.
 *
 * Maintains the currently-open element stack, allocates per-page MCID integers,
 * builds the ParentTree mapping from /StructParents keys to struct elements, and
 * tracks the artifact suppression depth so decorative content (running
 * headers/footers, OCG-layer wrappers, aria-hidden subtrees) bypasses struct-
 * element creation entirely.
 *
 * One instance per Mpdf lifecycle, constructed by ServiceFactory and reached
 * via $this->ua->getStructureTree().
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 — Structure Hierarchy
 *   - ISO 32000-1:2008 §14.7.4.4 — ParentTree; dense MCID arrays per /StructParents key
 *   - ISO 32000-1:2008 §14.8.2.2 — Real Content vs Artifacts
 *   - ISO 32000-1:2008 §14.7.3 — RoleMap (custom → standard type mapping)
 *
 * @see StructureElement  the node type managed by the stack / tree
 * @see StructureWriter   serialises this tree to PDF objects
 */
class StructureTree
{

	/** @var StructureElement  Permanent Document root; never popped from $stack. */
	protected $root;

	/** @var StructureElement[]  Open element stack; index 0 is always $root. */
	protected $stack;

	/**
	 * @var array<int,int>
	 *   Per-page MCID counters keyed by /StructParents integer. ISO 32000-1
	 *   §14.7.4.4 — the ParentTree entry for each key must be a dense array
	 *   starting at MCID 0. The counter resets automatically because a new
	 *   /StructParents integer gets a new key.
	 */
	protected $mcidByPage;

	/**
	 * @var array<int, array<int, StructureElement>>
	 *   Outer key: /StructParents integer (from PageWriter). Inner key: MCID.
	 *   Value: the struct element that owns that content item. Serialised by
	 *   StructureWriter as the ParentTree NumTree.
	 */
	protected $parentTree;

	/**
	 * @var int  Artifact suppression depth; incremented by openArtifact() /
	 *   decremented by closeArtifact(). When > 0, open()/addContent() become
	 *   no-ops — content renders but produces no struct element and no MCID.
	 *   ISO 32000-1 §14.8.2.2 — Artifacts are content outside the logical
	 *   structure (pagination, decoration, layout helpers).
	 */
	protected $artifactDepth;

	/** @var int  Sequential /StructParent integer for annotations (singular key per annotation dict). */
	protected $annotParentCounter;

	/** @var array<int, StructureElement>  Annotation /StructParent integer → owning struct element. */
	protected $annotParentTree;

	/**
	 * @var array<string,string>
	 *   RoleMap entries collected from ARIA role="custom-name" usage.
	 *   Key: custom role name. Value: standard PDF struct type it maps to.
	 *   Emitted by StructureWriter on the StructTreeRoot dict.
	 *   ISO 32000-1 §14.7.3 — RoleMap dict.
	 */
	protected $roleMappings;

	/**
	 * Initialise the tree with a Document root already on the stack and all
	 * accumulators empty. Called once by ServiceFactory; UaState stores the
	 * resulting instance.
	 */
	public function __construct()
	{
		// ISO 32000-1 §14.8 Table 333 — Document is the permanent root of every
		// struct tree. It never has a parent and never carries MCIDs directly.
		$this->root               = new StructureElement('Document');
		$this->stack              = [$this->root];
		$this->mcidByPage         = [];
		$this->parentTree         = [];
		$this->artifactDepth      = 0;
		$this->annotParentCounter = 0;
		$this->annotParentTree    = [];
		$this->roleMappings       = [];
	}

	// ================== Getters ==================

	/** @return StructureElement permanent Document root. */
	public function getRoot()
	{
		return $this->root;
	}

	/**
	 * Return the top of the open-element stack; never null because the Document
	 * root is always present.
	 *
	 * @return StructureElement
	 */
	public function getCurrent()
	{
		return end($this->stack);
	}

	/**
	 * Return ParentTree contents keyed by /StructParents integer, then by MCID.
	 *
	 * @return array<int, array<int, StructureElement>>
	 */
	public function getParentTree()
	{
		return $this->parentTree;
	}

	/**
	 * Return annotation /StructParent index → owning struct element map.
	 *
	 * @return array<int, StructureElement>
	 */
	public function getAnnotParentTree()
	{
		return $this->annotParentTree;
	}

	/**
	 * Return collected RoleMap entries.
	 *
	 * @return array<string,string>  customRole => standardType
	 */
	public function getRoleMappings()
	{
		return $this->roleMappings;
	}

	/**
	 * Return whether the current render position is inside an Artifact scope.
	 *
	 * True while running header/footer rendering, aria-hidden subtrees, OCG
	 * layer wrappers, decorative image paths, or any other
	 * openArtifact()/closeArtifact() bracket. Tag handlers should treat this
	 * as "do not emit struct elements".
	 *
	 * @return bool
	 */
	public function isInArtifact()
	{
		return $this->artifactDepth > 0;
	}

	// ================== open / close ==================

	/**
	 * Push a new struct element onto the stack as a child of the current top.
	 *
	 * Called from every tag handler's open() when PDFUA is active and the tag
	 * maps to a standard struct type (see StructType::fromHtmlTag()). In
	 * artifact scope this is a no-op — the paired close() is also a no-op
	 * so the stack stays balanced.
	 *
	 * @param  string $type        PDF struct type (must be valid per StructType::isValid())
	 * @param  array  $attributes  optional attribute map (Alt, Scope, ColSpan, …)
	 * @return void
	 * @throws \Mpdf\Exception\InvalidArgumentException  if $type is not a standard PDF struct type
	 */
	public function open($type, $attributes = [])
	{
		if (!StructType::isValid($type)) {
			throw new \Mpdf\Exception\InvalidArgumentException('Invalid struct type: "' . $type . '"');
		}
		if ($this->isInArtifact()) {
			// ISO 32000-1 §14.8.2.2 — Artifact content must NOT appear in the
			// structure tree. Suppress element creation; the paired close() is
			// also a no-op below.
			return;
		}
		$elem = new StructureElement($type, $attributes);
		$this->getCurrent()->addChild($elem);
		$this->stack[] = $elem;
	}

	/**
	 * Pop the top of the open-element stack.
	 *
	 * Two guard cases, both no-ops:
	 *   - stack size <= 1: never pop the Document root (would corrupt the tree).
	 *   - artifact scope: the paired open() was a no-op, so close() must match.
	 *
	 * @return void
	 */
	public function close()
	{
		if (count($this->stack) <= 1) {
			return;
		}
		if ($this->isInArtifact()) {
			return;
		}
		array_pop($this->stack);
	}

	// ================== content / artifact ==================

	/**
	 * Allocate an MCID for a content item on the CURRENT struct element.
	 *
	 * Called by tag handlers / rendering code immediately before emitting the
	 * BDC operator: the returned integer is embedded in the property dict
	 * (e.g. /P <</MCID 3>> BDC) and registered in the ParentTree.
	 *
	 * ISO 32000-1 §14.7.4.4 — the ParentTree entry for a given /StructParents
	 * key must be dense starting at MCID 0; nextMcidForPage() enforces this.
	 *
	 * In artifact scope returns -1 (the Artifact sentinel); callers pass -1
	 * through to MarkedContentHelper::begin() to emit /Artifact BMC instead
	 * of a property-dict BDC.
	 *
	 * @param  int $structParentsIndex  /StructParents integer of the host page or Form XObject
	 * @return int                      assigned MCID, or -1 when in artifact scope
	 * @throws \Mpdf\Exception\InvalidArgumentException  if $structParentsIndex is not a non-negative integer
	 */
	public function addContent($structParentsIndex)
	{
		if (!is_int($structParentsIndex) || $structParentsIndex < 0) {
			throw new \Mpdf\Exception\InvalidArgumentException(
				'structParentsIndex must be a non-negative integer'
			);
		}
		if ($this->isInArtifact()) {
			return $this->addArtifact();
		}
		$mcid = $this->nextMcidForPage($structParentsIndex);
		$this->getCurrent()->addMcid($structParentsIndex, $mcid);
		$this->parentTree[$structParentsIndex][$mcid] = $this->getCurrent();
		return $mcid;
	}

	/**
	 * Allocate an MCID and attach it to an EXPLICIT struct element rather
	 * than the stack top.
	 *
	 * Used by _tableWrite() and other deferred-rendering code paths where
	 * the struct element was pushed at parse time but the BDC is emitted
	 * later (when the render-time stack top is a different element).
	 *
	 * @param  StructureElement $elem                target element
	 * @param  int              $structParentsIndex  /StructParents integer of the host page/XObject
	 * @return int                                   assigned MCID, or -1 in artifact scope
	 */
	public function addContentForElement(StructureElement $elem, $structParentsIndex)
	{
		if ($this->isInArtifact()) {
			return -1;
		}
		$mcid = $this->nextMcidForPage($structParentsIndex);
		$elem->addMcid($structParentsIndex, $mcid);
		$this->parentTree[$structParentsIndex][$mcid] = $elem;
		return $mcid;
	}

	/**
	 * Return the Artifact sentinel MCID (-1).
	 *
	 * Convenience wrapper so callers in non-artifact-scope code paths can also
	 * route explicit Artifact content through a single entry point.
	 * MarkedContentHelper::begin(..., -1) emits /Artifact BMC (no dict).
	 *
	 * @return int  -1 (Artifact sentinel)
	 */
	public function addArtifact()
	{
		return -1;
	}

	/**
	 * Enter an Artifact suppression scope.
	 *
	 * Paired with closeArtifact(). Nesting is permitted; the depth counter
	 * tracks balance so nested opens/closes behave correctly.
	 *
	 * @return void
	 */
	public function openArtifact()
	{
		$this->artifactDepth++;
	}

	/**
	 * Leave one Artifact suppression scope.
	 *
	 * Clamped at 0 — extra closeArtifact() calls are no-ops. This tolerates
	 * tag-handler bugs rather than throwing mid-render.
	 *
	 * @return void
	 */
	public function closeArtifact()
	{
		if ($this->artifactDepth > 0) {
			$this->artifactDepth--;
		}
	}

	// ================== role map ==================

	/**
	 * Register a RoleMap entry for a custom ARIA / HTML role.
	 *
	 * First registration wins — a second call with the same $role but a
	 * different $standardType is silently ignored so conflicting RoleMap
	 * entries can't reach veraPDF (which rejects them).
	 *
	 * ISO 32000-1 §14.7.3 — RoleMap dict on StructTreeRoot.
	 *
	 * @param  string $role          non-standard struct type seen in the HTML
	 * @param  string $standardType  fallback standard struct type (e.g. 'Div')
	 * @return void
	 */
	public function addRoleMapping($role, $standardType)
	{
		if (!isset($this->roleMappings[$role])) {
			$this->roleMappings[$role] = $standardType;
		}
	}

	// ================== annotation struct parent ==================

	/**
	 * Allocate the next annotation /StructParent integer and associate it with
	 * the given struct element.
	 *
	 * Annotation dicts carry /StructParent (singular) — a single integer that
	 * indexes an entry in the ParentTree pointing back to one struct element.
	 * This differs from page dicts, which use /StructParents (plural) and a
	 * dense MCID array. StructureWriter emits the singular-key ParentTree
	 * entries from $annotParentTree.
	 *
	 * ISO 32000-1 §14.7.4.4 — /StructParent vs /StructParents distinction.
	 *
	 * @param  StructureElement $elem  the struct element owning the annotation
	 * @return int                     the /StructParent integer to emit on the annotation dict
	 */
	public function nextAnnotStructParent(StructureElement $elem)
	{
		$idx = $this->annotParentCounter++;
		$this->annotParentTree[$idx] = $elem;
		return $idx;
	}

	// ================== internals ==================

	/**
	 * Allocate the next MCID for a given /StructParents key.
	 *
	 * ISO 32000-1 §14.7.4.4 — MCIDs within one content stream (one page or
	 * one Form XObject) must be dense and 0-based. Each /StructParents key
	 * has its own independent counter; a new key starts at 0.
	 *
	 * @param  int $page  /StructParents integer (NOT a 1-based page number)
	 * @return int        previous counter value
	 */
	private function nextMcidForPage($page)
	{
		if (!isset($this->mcidByPage[$page])) {
			$this->mcidByPage[$page] = 0;
		}
		return $this->mcidByPage[$page]++;
	}
}
