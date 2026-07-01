<?php

namespace Mpdf\Ua;

/**
 * Facade holding all runtime PDF/UA-1 state and collaborator references.
 *
 * Constructed once by ServiceFactory, stored as Mpdf::$ua (private), and injected
 * via constructor DI into every tag handler, writer, and \Mpdf\Ua\* collaborator
 * that needs to read or mutate UA state. The two mode flags PDFUA and PDFUAauto
 * live on Mpdf.php directly (matching $PDFA / $PDFAauto); UaState carries
 * everything else so removing PDF/UA-1 support later becomes a single property
 * deletion plus the src/Ua/ directory.
 *
 * All fields are protected. Collaborator fields are written only once in
 * __construct() (no ServiceFactory-wiring setters exist — the facade is
 * immutable from bootstrap onwards); the two runtime mutators that remain
 * (setStructTreeRootObjNum, setOpenedImplicitLI) guard actual runtime
 * state, not bootstrap wiring. No Strict trait — this class is self-contained.
 *
 * Spec references:
 *   - ISO 14289-1:2014 §7 — document-level UA requirements accumulated here
 *   - ISO 32000-1:2008 §14.7.4.4 — /StructParents allocation (see $structParentsCounter)
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — StructTreeRoot object number
 *     (see $structTreeRootObjNum, written by StructureWriter)
 *
 * @see \Mpdf\Ua\StructureTree         element stack + ParentTree
 * @see \Mpdf\Ua\MarkedContentHelper   BDC/EMC emission
 * @see \Mpdf\Ua\StructureWriter       StructTreeRoot serialisation
 */
class UaState
{

	/**
	 * PDFUAauto-mode warnings collected during rendering.
	 *
	 * In PDFUAauto=true mode, conformance violations that can be auto-corrected
	 * append a human-readable message here instead of throwing MpdfException.
	 * Inspect via getWarnings() after Output().
	 *
	 * @var string[]
	 */
	protected $warnings = [];

	/**
	 * Sequential /StructParents integer counter.
	 *
	 * Allocated by nextStructParents() and emitted on every page dict, every
	 * SVG Form XObject with internal MCIDs, and every FPDI tagged import.
	 * StructureWriter emits /ParentTreeNextKey equal to this counter's final
	 * value.
	 *
	 * ISO 32000-1 §14.7.4.4 — ParentTree key space; integers start at 0 and
	 * must be dense per-key (the array entry indexed by a given /StructParents
	 * integer must be a 0-based contiguous list of MCIDs).
	 *
	 * @var int
	 */
	protected $structParentsCounter = 0;

	/**
	 * PDF object number assigned to the StructTreeRoot dict.
	 *
	 * Set by StructureWriter::writeStructTree() immediately after it allocates
	 * the root object. Read by MetadataWriter::writeCatalog() to emit
	 * /StructTreeRoot N 0 R in the document catalog. Ordering is guaranteed
	 * because ResourceWriter runs StructureWriter before writeCatalog().
	 *
	 * @var int  0 until StructureWriter has run.
	 */
	protected $structTreeRootObjNum = 0;

	/**
	 * Per-<dl> stack of "implicit LI open" booleans. Each <dl> pushes a frame on
	 * open and pops it on close; DT/DD read and set only the top frame. A single
	 * shared bool would corrupt nested <dl> (a <dl> inside a <dd>): the inner
	 * <dt> would close the outer list's implicit LI instead of its own.
	 *
	 * A frame is true while a DT or DD has auto-opened an implicit LI parent
	 * because the DOM is <dl><dt>…</dt><dd>…</dd></dl> (no explicit LI in HTML);
	 * that LI is closed on the next sibling DT, or on </dl>.
	 *
	 * Tagged PDF Best Practice Guide §4.2.3 — DL maps to L, DT to Lbl, DD to
	 * LBody; Lbl/LBody must be children of an LI.
	 *
	 * @var bool[]
	 */
	protected $implicitLIStack = [];

	/**
	 * The last heading level (1-6) emitted into the struct tree, or 0 if no
	 * heading has been seen yet in the current document.
	 *
	 * Used by the heading-order auto-clamp (ISO 14289-1:2014 §7.4.2 rule 1):
	 * the first heading must be H1 and descending sequences must not skip
	 * intervening levels. BlockTag::open() reads and updates this value via
	 * getLastHeadingLevel() / setLastHeadingLevel() whenever it processes an
	 * H1-H6 struct element outside a table context.
	 *
	 * @var int  0 = no heading seen yet; 1-6 = last assigned struct heading level.
	 */
	protected $lastHeadingLevel = 0;

	// Collaborators are injected once via __construct; no setters.

	/** @var MarkedContentHelper */
	protected $markedContentHelper;

	/** @var StructureTree */
	protected $structureTree;

	/** @var StructureWriter */
	protected $structureWriter;

	/** @var AriaIdResolver */
	protected $ariaIdResolver;

	/** @var LigatureActualTextWriter */
	protected $ligatureActualTextWriter;

	/** @var Import\FpdiStructMerger */
	protected $fpdiStructMerger;

	/** @var InlineStructStack  per-tag inline Span depth stacks (replaces $mpdf->InlineUaStruct) */
	protected $inlineStructStack;

	/** @var AnchorState  current Link struct elem + strip stack + anchor struct type */
	protected $anchorState;

	/** @var ImageMap\ImageMapRegistry  HTML image-map registry, deferred queue, drain/emit */
	protected $imageMapRegistry;

	// The mode flags PDFUA and PDFUAauto live on Mpdf.php as var properties,
	// matching the $PDFA / $PDFAauto pattern. Writer/consumer code reads
	// $this->mpdf->PDFUA / $this->mpdf->PDFUAauto directly.

	/**
	 * Build a fully-populated UaState.
	 *
	 * All collaborators are mandatory and injected once at bootstrap by
	 * ServiceFactory. Every getter returns a non-null reference for the
	 * lifetime of the instance — callers never need null-guards and no
	 * partially-initialised facade is ever observable.
	 *
	 * The four collaborators that previously took UaState in their own
	 * constructors (StructureWriter, AriaIdResolver, LigatureActualTextWriter,
	 * FpdiStructMerger) now receive the specific pieces they need
	 * (StructureTree, MarkedContentHelper, BaseWriter, Mpdf) so there is no
	 * construction-time cycle and no setter-injection phase.
	 *
	 * @param StructureTree              $structureTree             element stack + ParentTree accumulator
	 * @param MarkedContentHelper        $markedContentHelper       BDC/EMC emitter
	 * @param StructureWriter            $structureWriter           StructTreeRoot serialiser
	 * @param AriaIdResolver             $ariaIdResolver            deferred ARIA ID-reference resolver
	 * @param LigatureActualTextWriter   $ligatureActualTextWriter  /Span /ActualText wrapper for OTL ligatures
	 * @param Import\FpdiStructMerger    $fpdiStructMerger          tagged-source struct subtree merger
	 * @param InlineStructStack          $inlineStructStack         per-tag inline Span depth stacks
	 * @param AnchorState                $anchorState               current Link struct elem + anchor strip stack
	 * @param ImageMap\ImageMapRegistry  $imageMapRegistry          <map>/<area> registry, deferred queue, drain/emit
	 */
	public function __construct(
		StructureTree $structureTree,
		MarkedContentHelper $markedContentHelper,
		StructureWriter $structureWriter,
		AriaIdResolver $ariaIdResolver,
		LigatureActualTextWriter $ligatureActualTextWriter,
		Import\FpdiStructMerger $fpdiStructMerger,
		InlineStructStack $inlineStructStack,
		AnchorState $anchorState,
		ImageMap\ImageMapRegistry $imageMapRegistry
	) {
		$this->structureTree            = $structureTree;
		$this->markedContentHelper      = $markedContentHelper;
		$this->structureWriter          = $structureWriter;
		$this->ariaIdResolver           = $ariaIdResolver;
		$this->ligatureActualTextWriter = $ligatureActualTextWriter;
		$this->fpdiStructMerger         = $fpdiStructMerger;
		$this->inlineStructStack        = $inlineStructStack;
		$this->anchorState              = $anchorState;
		$this->imageMapRegistry         = $imageMapRegistry;
	}

	/**
	 * Return accumulated PDFUAauto-mode warnings.
	 *
	 * PDFUAauto=true converts conformance violations into entries here instead
	 * of throwing MpdfException. Inspect via $mpdf->getPdfUaWarnings() after
	 * Output(). Empty array if PDFUA is off or no violations were recorded.
	 *
	 * @return string[]
	 */
	public function getWarnings()
	{
		return $this->warnings;
	}

	/**
	 * Return current value of the /StructParents allocator, BEFORE the next allocation.
	 *
	 * Used by StructureWriter to emit /ParentTreeNextKey = (highest key + 1).
	 *
	 * @return int
	 */
	public function getStructParentsCounter()
	{
		return $this->structParentsCounter;
	}

	/**
	 * Return PDF object number of the StructTreeRoot dict; 0 before StructureWriter runs.
	 *
	 * ISO 32000-1:2008 §14.7.2 Table 322 — StructTreeRoot dict entry in the catalog.
	 *
	 * @return int
	 */
	public function getStructTreeRootObjNum()
	{
		return $this->structTreeRootObjNum;
	}

	/**
	 * Return whether the current <dl> has an implicit LI open (top frame), or
	 * false when no <dl> is open.
	 *
	 * @return bool
	 */
	public function isOpenedImplicitLI()
	{
		return !empty($this->implicitLIStack) && end($this->implicitLIStack);
	}

	/**
	 * Push a fresh implicit-LI frame for a newly opened <dl>. Balanced by
	 * popImplicitLIFrame() on </dl>.
	 *
	 * @return void
	 */
	public function pushImplicitLIFrame()
	{
		$this->implicitLIStack[] = false;
	}

	/**
	 * Pop the current <dl>'s implicit-LI frame on </dl>. No-op if the stack is
	 * empty (malformed </dl> without a matching <dl>).
	 *
	 * @return void
	 */
	public function popImplicitLIFrame()
	{
		array_pop($this->implicitLIStack);
	}

	/** @return MarkedContentHelper */
	public function getMarkedContentHelper()
	{
		return $this->markedContentHelper;
	}

	/** @return StructureTree */
	public function getStructureTree()
	{
		return $this->structureTree;
	}

	/** @return StructureWriter */
	public function getStructureWriter()
	{
		return $this->structureWriter;
	}

	/** @return AriaIdResolver */
	public function getAriaIdResolver()
	{
		return $this->ariaIdResolver;
	}

	/** @return LigatureActualTextWriter */
	public function getLigatureActualTextWriter()
	{
		return $this->ligatureActualTextWriter;
	}

	/** @return Import\FpdiStructMerger */
	public function getFpdiStructMerger()
	{
		return $this->fpdiStructMerger;
	}

	/** @return InlineStructStack */
	public function getInlineStructStack()
	{
		return $this->inlineStructStack;
	}

	/** @return AnchorState */
	public function getAnchorState()
	{
		return $this->anchorState;
	}

	/** @return ImageMap\ImageMapRegistry */
	public function getImageMapRegistry()
	{
		return $this->imageMapRegistry;
	}

	/**
	 * Record the PDF object number assigned to the StructTreeRoot dict.
	 *
	 * Called once by StructureWriter::writeStructTree() immediately after it
	 * reserves the root object number via $mpdf->writer->object().
	 *
	 * @param  int $n
	 * @return void
	 */
	public function setStructTreeRootObjNum($n)
	{
		$this->structTreeRootObjNum = (int) $n;
	}

	/**
	 * Mark / unmark whether the current <dl> (top frame) has an implicit LI open.
	 *
	 * Called from DT/DD open handlers when the current struct parent is L
	 * (so no explicit LI is on the stack); cleared by the matching close. No-op
	 * when no <dl> frame is open.
	 *
	 * @param  bool $v
	 * @return void
	 */
	public function setOpenedImplicitLI($v)
	{
		if (empty($this->implicitLIStack)) {
			return;
		}
		$this->implicitLIStack[count($this->implicitLIStack) - 1] = (bool) $v;
	}

	/**
	 * Return the last heading level (1-6) recorded in the struct tree, or 0
	 * if no heading has been emitted yet.
	 *
	 * ISO 14289-1:2014 §7.4.2 rule 1 — used by BlockTag to enforce heading
	 * sequence validity before pushing each H1-H6 struct element.
	 *
	 * @return int
	 */
	public function getLastHeadingLevel()
	{
		return $this->lastHeadingLevel;
	}

	/**
	 * Record the heading level just assigned to the struct tree.
	 *
	 * Called by BlockTag::open() immediately after the final (possibly
	 * auto-clamped) heading struct type has been determined, so that the next
	 * heading can check continuity.
	 *
	 * @param  int $level  1-6
	 * @return void
	 */
	public function setLastHeadingLevel($level)
	{
		$this->lastHeadingLevel = (int) $level;
	}

	/**
	 * Append a PDFUAauto-mode warning.
	 *
	 * Called from any code path that detects a conformance issue that can be
	 * auto-corrected rather than thrown. In PDFUAauto=false mode, callers
	 * should throw \Mpdf\MpdfException instead of calling this.
	 *
	 * @param  string $msg  human-readable diagnostic; appears verbatim in getWarnings()
	 * @return void
	 */
	public function addWarning($msg)
	{
		$this->warnings[] = (string) $msg;
	}

	/**
	 * Allocate the next /StructParents integer and advance the counter.
	 *
	 * Returns the pre-increment value so callers emit that exact integer on the
	 * page/XObject dict and the matching ParentTree entry.
	 *
	 * ISO 32000-1 §14.7.4.4 — /StructParents keys are dense, 0-based. Every
	 * call site that emits /StructParents N on a dict MUST obtain N via this
	 * method so the counter stays monotonic and /ParentTreeNextKey is accurate.
	 *
	 * @return int  the previous counter value (the integer to emit)
	 */
	public function nextStructParents()
	{
		return $this->structParentsCounter++;
	}

	/**
	 * Read the current /StructParents counter without advancing it.
	 *
	 * Returned value equals the integer that the next nextStructParents()
	 * call would emit. Used as an upper bound for sanity-checking caller-
	 * supplied /StructParents indices (UA1 audit L-1 — defence-in-depth in
	 * StructureTree::addContent so an out-of-range $structParentsIndex
	 * cannot silently corrupt the ParentTree).
	 *
	 * @return int
	 */
	public function peekStructParents()
	{
		return $this->structParentsCounter;
	}
}
