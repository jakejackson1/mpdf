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

	/** @var array<int, StructureElement>  Annotation /StructParent integer → owning struct element. */
	protected $annotParentTree;

	/**
	 * @var \Mpdf\Ua\UaState|null  Facade injected after construction (ServiceFactory
	 *   builds StructureTree before UaState — see ServiceFactory bootstrap order).
	 *   Used to obtain the next /StructParent integer from the same pool that pages
	 *   draw from, avoiding collisions where a page's /StructParents N and an
	 *   annotation's /StructParent N both target the same ParentTree key
	 *   (ISO 32000-1 §14.7.4.4 — page-level plural keys and annotation singular
	 *   keys SHARE the ParentTree NumTree).
	 */
	protected $uaState;

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
		$this->annotParentTree    = [];
		$this->roleMappings       = [];
		$this->uaState            = null;
	}

	/**
	 * Inject the UaState facade so ParentTree-key allocation can share a single
	 * counter with page /StructParents. Called once by ServiceFactory after the
	 * UaState facade is built (the build order is StructureTree → UaState, so a
	 * constructor-time injection is impossible).
	 *
	 * @param  \Mpdf\Ua\UaState $uaState
	 * @return void
	 */
	public function setUaState(UaState $uaState)
	{
		$this->uaState = $uaState;
	}

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
	 * Return the innermost open struct element when it is an inline text
	 * wrapper (Link, Span, Ruby, RB, RT, RP), or null when the stack top is a
	 * block-level element.
	 *
	 * An MCID maps to exactly one struct element via the ParentTree, so text
	 * flowing inside a Link / lang-Span / Abbr /E-Span / Ruby RB·RT must have
	 * its content item attributed to that inline element rather than the
	 * enclosing block — otherwise the inline element owns no content and its
	 * /Lang, /Alt, /E or (for Link) the text run is lost, and veraPDF flags an
	 * empty Link/Span (ISO 14289-1 §7.18.5 / §7.2, Matterhorn 02-003 / 11-001).
	 * The stack top IS the direct owner of any text buffered at this instant,
	 * so only the top is inspected; block-owned text returns null and stays on
	 * the block's marked-content sequence.
	 *
	 * Captured per textbuffer entry during HTML parse (Mpdf::_saveTextBuffer)
	 * and replayed at emit time (UA1 audit E6). Reused by the deferred
	 * cell / image content paths (E9, E11).
	 *
	 * @return StructureElement|null
	 */
	public function getCurrentInline()
	{
		$top = end($this->stack);
		if ($top === false) {
			return null;
		}
		return in_array($top->getType(), self::$inlineContentTypes, true) ? $top : null;
	}

	/**
	 * Struct types that wrap flowing inline text and therefore own the MCID of
	 * the text buffered while they are the stack top. ISO 32000-1 §14.8.5
	 * inline-level structure types plus the Ruby group (§14.8.5.6 Table 337).
	 *
	 * @var string[]
	 */
	private static $inlineContentTypes = ['Link', 'Span', 'Ruby', 'RB', 'RT', 'RP'];

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

	/**
	 * Close the innermost open table row-group element (THead / TBody / TFoot)
	 * when it is the stack top; otherwise a no-op.
	 *
	 * Row groups reach Table::close() still open in two situations, both handled
	 * here as a backstop so the Table pop that follows lands on the Table itself:
	 *   - the HTML omitted the group's optional end tag (ISO 32000-1 §14.8
	 *     Table 333 — </thead>/</tbody>/</tfoot> are optional), so the group's
	 *     own close() never fired; or
	 *   - Tr::open() synthesised a TBody for rows the HTML wrote directly under
	 *     <table>, and that synthetic group stays open across the group-less rows.
	 *
	 * Also called from a group handler's open() to collapse a preceding
	 * synthetic TBody before a real THead/TBody/TFoot begins.
	 *
	 * @return void
	 */
	public function closeRowGroup()
	{
		if (count($this->stack) <= 1 || $this->isInArtifact()) {
			return;
		}
		$type = end($this->stack)->getType();
		if ($type === 'THead' || $type === 'TBody' || $type === 'TFoot') {
			array_pop($this->stack);
		}
	}

	/**
	 * Push an existing struct element onto the open-element stack without
	 * creating a new one or appending it as a child of the current top.
	 *
	 * Used when the deferred-render path needs to make a previously-pushed
	 * element (e.g. a TD cell saved during HTML parse) the parent of new
	 * children that are opened during render time. Without this, opens of
	 * Figure / Span / etc. inside a cell would attach those children to the
	 * wrong parent (the parse-time stack top — usually Document or Table).
	 *
	 * The caller MUST balance the push with a close() call (which simply pops
	 * the element off the stack — the structure-tree linkage was established
	 * when the element was originally pushed via open()).
	 *
	 * @param  StructureElement $elem
	 * @return void
	 */
	public function pushExisting(StructureElement $elem)
	{
		if ($this->isInArtifact()) {
			return;
		}
		$this->stack[] = $elem;
	}

	/**
	 * Pop the top struct element AND remove it from its parent's children.
	 *
	 * Use when a tag handler needs to discard a previously-opened element
	 * (for example Th::open() inherits a TD push from Td::open() and must
	 * replace it with TH). A plain close() leaves the discarded element in
	 * the parent's /K array, which breaks ISO 14289-1 §7.2 test 43 by adding
	 * phantom column counts.
	 *
	 * Idempotent: no-op when only the Document root is on the stack or when
	 * we are inside an artifact scope.
	 *
	 * @return void
	 */
	public function discardTop()
	{
		if (count($this->stack) <= 1) {
			return;
		}
		if ($this->isInArtifact()) {
			return;
		}
		$top = end($this->stack);
		$parent = $top->getParent();
		array_pop($this->stack);
		if ($parent !== null) {
			$parent->popLastChild();
		}
	}

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
		// UA1 audit L-1 — upper-bound sanity. /StructParents integers are
		// allocated monotonically from UaState::nextStructParents(), so any
		// value at or above the current counter has not been allocated and
		// would silently extend $parentTree past its dense-key invariant.
		// No untrusted reach is known today; the guard is defence-in-depth
		// against future tag-handler regressions or test fixtures that build
		// the index by hand. UaState is null only during ServiceFactory
		// bootstrap; callers reach addContent() after wiring is complete.
		if ($this->uaState !== null) {
			$ceiling = $this->uaState->peekStructParents();
			if ($structParentsIndex >= $ceiling) {
				throw new \Mpdf\Exception\InvalidArgumentException(
					'structParentsIndex ' . $structParentsIndex
					. ' is outside the allocated range [0, ' . $ceiling . ')'
				);
			}
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
		if (!is_int($structParentsIndex) || $structParentsIndex < 0) {
			throw new \Mpdf\Exception\InvalidArgumentException(
				'structParentsIndex must be a non-negative integer'
			);
		}
		// UA1 audit L-1 — see addContent() for rationale.
		if ($this->uaState !== null) {
			$ceiling = $this->uaState->peekStructParents();
			if ($structParentsIndex >= $ceiling) {
				throw new \Mpdf\Exception\InvalidArgumentException(
					'structParentsIndex ' . $structParentsIndex
					. ' is outside the allocated range [0, ' . $ceiling . ')'
				);
			}
		}
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
		// Allocate from the SHARED ParentTree pool (UaState's counter, also
		// used by page /StructParents). A separate counter would let an annot
		// /StructParent collide with a page /StructParents N — both index the
		// same NumTree, so the second writer would overwrite the first entry
		// and either the page MCID array or the annotation map would vanish.
		$idx = $this->uaState->nextStructParents();
		$this->annotParentTree[$idx] = $elem;
		return $idx;
	}

	/**
	 * Reserve the next /StructParent integer without registering a struct element.
	 *
	 * Used by MetadataWriter before widget annotation dicts are written, so that
	 * form widget dicts can include /StructParent N before their corresponding
	 * Form struct elements exist. StructureWriter::writeStructTree() completes
	 * the registration by calling registerAnnotStructParent() for each widget.
	 *
	 * ISO 32000-1 §14.7.4.4 — /StructParent on annotation dicts (singular).
	 *
	 * @return int  the reserved /StructParent integer
	 */
	public function reserveAnnotStructParent()
	{
		return $this->uaState->nextStructParents();
	}

	/**
	 * Register a struct element for a previously reserved /StructParent integer.
	 *
	 * Called by StructureWriter::writeStructTree() after Form struct elements
	 * are constructed, to complete the ParentTree registration that
	 * reserveAnnotStructParent() deferred.
	 *
	 * @param  int              $idx   the /StructParent integer previously reserved
	 * @param  StructureElement $elem  the struct element that owns this annotation
	 * @return void
	 */
	public function registerAnnotStructParent($idx, StructureElement $elem)
	{
		$this->annotParentTree[$idx] = $elem;
	}

	/**
	 * Remove Link struct elements that ended up with no kids, no MCRs, and no
	 * OBJR references after annotation writing has completed.
	 *
	 * Matterhorn 02-003 (ISO 14289-1 §7.18.5) — every Link struct element
	 * MUST reference either marked content or an annotation via OBJR. A Link
	 * with an empty /K array is structurally invalid; veraPDF either flags it
	 * directly or treats the surrounding content as untagged.
	 *
	 * Two ways an empty Link reaches this point:
	 *   1. <a href="x"></a> with no inner content — open() created the Link,
	 *      no inner HTML produced an MCID, and Mpdf::Link() was never invoked
	 *      because there is no glyph extent to draw a clickable rect over.
	 *   2. <a href="x"><img alt=""></a> where the inner <img> is decorative —
	 *      Img.php opens an Artifact scope, suppressing any descendant struct
	 *      element creation, and Mpdf::Link() may still produce no annotation
	 *      if the rendered rect is empty.
	 *
	 * Tag\A::close() may have set /Alt synthesised from the href in PDFUAauto
	 * mode; that does NOT save the element from pruning here, because an
	 * /Alt-only Link with no /K is still invalid — and there is no real
	 * clickable annotation in the output PDF if no OBJR exists, so removing
	 * the struct element loses no information.
	 *
	 * Must be called AFTER MetadataWriter::writeAnnotations() has run (so
	 * OBJR refs are already attached) and BEFORE StructureWriter walks the
	 * tree to allocate object numbers.
	 *
	 * @return void
	 */
	public function pruneEmptyLinks()
	{
		$this->pruneEmptyLinksRecursive($this->root);
	}

	/**
	 * Recursive worker for pruneEmptyLinks().
	 *
	 * Walks children depth-first, then re-examines this element's children
	 * list for Links that meet the prune predicate. Pruning happens after the
	 * recursion so a Link nested inside another Link (rare, malformed HTML)
	 * is still examined in correct child-before-parent order.
	 *
	 * @param  StructureElement $elem
	 * @return void
	 */
	private function pruneEmptyLinksRecursive(StructureElement $elem)
	{
		foreach ($elem->getChildren() as $child) {
			$this->pruneEmptyLinksRecursive($child);
		}
		// Re-fetch because recursion may have mutated grandchildren.
		$toRemove = [];
		foreach ($elem->getChildren() as $child) {
			if ($child->getType() === 'Link'
				&& count($child->getChildren()) === 0
				&& count($child->getMcids()) === 0
				&& count($child->getObjrefs()) === 0
			) {
				$toRemove[] = $child;
			}
		}
		foreach ($toRemove as $child) {
			$elem->removeChild($child);
		}
	}

	/**
	 * Find the first Link struct element with an empty /K (no kids, no MCRs,
	 * AND no OBJR refs) and return its source href hint, or null if every
	 * Link in the tree carries at least one /K kid.
	 *
	 * "Empty" means the element would be serialised with no /K array — that
	 * is the Matterhorn 02-003 violation. An OBJR-only Link (the typical
	 * shape for `<a>text</a>` where the text MCID lives on the surrounding
	 * P element and only the annotation OBJR is attached to Link) is
	 * acceptable; the OBJR provides the structure-tree linkage to the link
	 * annotation, and the annotation's own /Contents (or the producer's
	 * synthesised /Alt) carries the accessible name.
	 *
	 * Caller MUST run this BEFORE pruneEmptyLinks() (which would silently
	 * remove the offending elements).
	 *
	 * @return string|null  href hint from the first offending Link's '_href' attribute,
	 *                      or '<unknown>' if the hint is missing, or null when no
	 *                      offending Link exists in the tree.
	 */
	public function findFirstEmptyLinkHref()
	{
		return $this->findFirstEmptyLinkHrefRecursive($this->root);
	}

	/**
	 * @param  StructureElement $elem
	 * @return string|null
	 */
	private function findFirstEmptyLinkHrefRecursive(StructureElement $elem)
	{
		if ($elem->getType() === 'Link'
			&& count($elem->getChildren()) === 0
			&& count($elem->getMcids()) === 0
			&& count($elem->getObjrefs()) === 0
		) {
			$attrs = $elem->getAttributes();
			return isset($attrs['_href']) ? (string) $attrs['_href'] : '<unknown>';
		}
		foreach ($elem->getChildren() as $child) {
			$href = $this->findFirstEmptyLinkHrefRecursive($child);
			if ($href !== null) {
				return $href;
			}
		}
		return null;
	}

	/**
	 * Register a struct element for a specific MCR key that was cloned from an
	 * imported tagged PDF (Tier 2 FPDI merge).
	 *
	 * Unlike addContent() / addContentForElement(), this method does NOT allocate
	 * a new MCID — the MCID comes directly from the source PDF and must be
	 * preserved so that the Form XObject's content stream MCIDs match the
	 * ParentTree back-map. Callers are responsible for ensuring that the MCID
	 * does not collide with other MCIDs registered under the same $structParents key.
	 *
	 * Called only by FpdiStructMerger::registerMcrInParentTree().
	 *
	 * ISO 32000-1:2008 §14.7.4.4 — ParentTree entry for a /StructParents key must
	 * be a dense array indexed by MCID pointing to the owning struct element.
	 *
	 * @param  int              $structParents  /StructParents integer of the Form XObject
	 * @param  int              $mcid           MCID from the source content stream
	 * @param  StructureElement $elem           cloned host struct element owning this MCR
	 * @return void
	 */
	public function registerImportedMcr($structParents, $mcid, StructureElement $elem)
	{
		$this->parentTree[$structParents][$mcid] = $elem;
	}

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
