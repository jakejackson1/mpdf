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

	// --- accumulated state ---

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
	 * SVG Form XObject with internal MCIDs, and every FPDI tagged import (§1h,
	 * §"SVG Images", §"Imported PDFs via FPDI"). StructureWriter emits
	 * /ParentTreeNextKey equal to this counter's final value.
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
	 * True while a DT or DD tag handler has auto-opened an implicit LI parent
	 * because the DOM structure is <dl><dt>…</dt><dd>…</dd></dl> (no explicit
	 * LI in HTML). Closed on the next sibling DT/DD or on </dl>.
	 *
	 * Tagged PDF Best Practice Guide §4.2.3 — DL maps to L, DT to Lbl, DD to
	 * LBody; Lbl/LBody must be children of an LI (see §"Definition lists").
	 *
	 * @var bool
	 */
	protected $openedImplicitLI = false;

	// --- collaborators (injected once via __construct; no setters) ---

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

	// The mode flags PDFUA and PDFUAauto live on Mpdf.php as var properties,
	// matching the $PDFA / $PDFAauto pattern. Writer/consumer code reads
	// $this->mpdf->PDFUA / $this->mpdf->PDFUAauto directly.

	// ================== Constructor ==================

	/**
	 * Build a fully-populated UaState.
	 *
	 * All six collaborators are mandatory and injected once at bootstrap by
	 * ServiceFactory (§2d). Every getter returns a non-null reference for the
	 * lifetime of the instance — callers never need null-guards and no
	 * partially-initialised facade is ever observable.
	 *
	 * The four collaborators that previously took UaState in their own
	 * constructors (StructureWriter, AriaIdResolver, LigatureActualTextWriter,
	 * FpdiStructMerger) now receive the specific pieces they need
	 * (StructureTree, MarkedContentHelper, BaseWriter, Mpdf) so there is no
	 * construction-time cycle and no setter-injection phase.
	 *
	 * @param StructureTree            $structureTree             element stack + ParentTree accumulator (Phase 2b)
	 * @param MarkedContentHelper      $markedContentHelper       BDC/EMC emitter (Phase 3a)
	 * @param StructureWriter          $structureWriter           StructTreeRoot serialiser (Phase 2e)
	 * @param AriaIdResolver           $ariaIdResolver            deferred ARIA ID-reference resolver (Phase 4)
	 * @param LigatureActualTextWriter $ligatureActualTextWriter  /Span /ActualText wrapper for OTL ligatures (Phase 5)
	 * @param Import\FpdiStructMerger  $fpdiStructMerger          tagged-source struct subtree merger (Phase 4)
	 */
	public function __construct(
		StructureTree $structureTree,
		MarkedContentHelper $markedContentHelper,
		StructureWriter $structureWriter,
		AriaIdResolver $ariaIdResolver,
		LigatureActualTextWriter $ligatureActualTextWriter,
		Import\FpdiStructMerger $fpdiStructMerger
	) {
		$this->structureTree            = $structureTree;
		$this->markedContentHelper      = $markedContentHelper;
		$this->structureWriter          = $structureWriter;
		$this->ariaIdResolver           = $ariaIdResolver;
		$this->ligatureActualTextWriter = $ligatureActualTextWriter;
		$this->fpdiStructMerger         = $fpdiStructMerger;
	}

	// ================== Getters ==================

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
	 * Return whether a DT/DD tag has auto-opened an implicit LI on the struct stack.
	 *
	 * @return bool
	 */
	public function isOpenedImplicitLI()
	{
		return $this->openedImplicitLI;
	}

	/** @return MarkedContentHelper BDC/EMC emitter (Phase 3a). */
	public function getMarkedContentHelper()
	{
		return $this->markedContentHelper;
	}

	/** @return StructureTree element stack + ParentTree accumulator (Phase 2b). */
	public function getStructureTree()
	{
		return $this->structureTree;
	}

	/** @return StructureWriter StructTreeRoot serialiser (Phase 2e). */
	public function getStructureWriter()
	{
		return $this->structureWriter;
	}

	/** @return AriaIdResolver deferred resolver for ID-referencing ARIA attrs (Phase 4). */
	public function getAriaIdResolver()
	{
		return $this->ariaIdResolver;
	}

	/** @return LigatureActualTextWriter /Span /ActualText wrapper for OTL ligatures (Phase 5). */
	public function getLigatureActualTextWriter()
	{
		return $this->ligatureActualTextWriter;
	}

	/** @return Import\FpdiStructMerger tagged-source struct subtree merger (Phase 4). */
	public function getFpdiStructMerger()
	{
		return $this->fpdiStructMerger;
	}

	// ================== Setters ==================

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
	 * Mark / unmark whether a DT/DD tag has opened an implicit LI parent.
	 *
	 * Called from DT/DD open handlers when the current struct parent is L
	 * (so no explicit LI is on the stack); cleared by the matching close.
	 *
	 * @param  bool $v
	 * @return void
	 */
	public function setOpenedImplicitLI($v)
	{
		$this->openedImplicitLI = (bool) $v;
	}

	// The six collaborator fields (markedContentHelper, structureTree,
	// structureWriter, ariaIdResolver, ligatureActualTextWriter,
	// fpdiStructMerger) are populated exclusively through __construct(). There
	// are deliberately no setters for them: the facade is immutable from
	// bootstrap onwards so every getter always returns the same non-null reference.

	// ================== Behaviour ==================

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
}
