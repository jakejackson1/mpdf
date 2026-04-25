<?php

namespace Mpdf\Ua\Import;

use Mpdf\Mpdf;
use Mpdf\Ua\StructureTree;

/**
 * Merge struct subtrees from FPDI-imported tagged PDF pages into the host tree.
 *
 * When FPDI imports a page from a PDF that is itself tagged (carries a
 * /StructTreeRoot), FpdiStructMerger reads the source document's struct tree
 * for the imported page and splices it into the host StructureTree as a subtree
 * under the Document root. MCR dicts in the merged subtree have their /Pg
 * entries rewritten to the host page and gain a /Stm entry pointing at the
 * FPDI Form XObject — per ISO 32000-1 §14.7.4.4 Table 324 (Appendix A14).
 *
 * When the source is untagged, the imported page's Do operator is instead
 * wrapped as /Artifact <</Type /Layout>> BDC … EMC and a warning is recorded
 * via addUntaggedWarning() so callers can surface it through UaState::addWarning().
 *
 * FpdiStructMerger does NOT hold a UaState reference — it holds only Mpdf and
 * StructureTree. This avoids a construction-time cycle: FpdiStructMerger is
 * constructed before UaState (§2d wiring block). The caller (FpdiTrait) is
 * responsible for flushing getUntaggedWarnings() into $this->ua->addWarning()
 * after each import decision.
 *
 * One instance per Mpdf lifecycle, constructed by ServiceFactory and reached
 * via $this->ua->getFpdiStructMerger().
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 — Structure Hierarchy
 *   - ISO 32000-1:2008 §14.7.4.4 Table 324 — MCR dict /Pg and /Stm entries
 *   - ISO 14289-1:2014 Matterhorn 01-007 — untagged real content in a tagged document
 *
 * Phase 4 stub — full implementation in §4 (FPDI import section / Appendix A7).
 *
 * @see StructureTree  host tree that merged subtrees are appended to
 */
class FpdiStructMerger
{

	/** @var Mpdf */
	private $mpdf;

	/** @var StructureTree */
	private $tree;

	/** @var string[]  Collected warnings for untagged imported pages. */
	private $untaggedWarnings = [];

	/**
	 * Construct with the host Mpdf and the host StructureTree.
	 *
	 * Called once by ServiceFactory before UaState is constructed. No UaState
	 * reference is held — warnings are surfaced through getUntaggedWarnings()
	 * and flushed into UaState by the FpdiTrait caller.
	 *
	 * @param Mpdf          $mpdf
	 * @param StructureTree $tree
	 */
	public function __construct(Mpdf $mpdf, StructureTree $tree)
	{
		$this->mpdf = $mpdf;
		$this->tree = $tree;
	}

	/**
	 * Determine whether the given FPDI PDF reader exposes a tagged source document.
	 *
	 * Phase 4 stub — returns false in Phase 2 (all imports treated as untagged).
	 *
	 * @param  mixed $reader  FPDI reader object for the source PDF
	 * @return bool
	 */
	public function sourceIsTagged($reader)
	{
		return false;
	}

	/**
	 * Read the source struct subtree for the imported page and splice it into
	 * the host StructureTree, rewriting MCR /Pg and adding /Stm references.
	 *
	 * Phase 4 stub — no-op in Phase 2.
	 *
	 * @param  mixed $reader      FPDI reader object for the source PDF
	 * @param  int   $sourcePage  1-based page number in the source document
	 * @param  int   $hostPage    1-based page number in the host (mPDF) document
	 * @param  int   $xObjNum    PDF object number of the FPDI Form XObject
	 * @return void
	 */
	public function mergePageStructSubtree($reader, $sourcePage, $hostPage, $xObjNum)
	{
		// Phase 4 stub — full implementation in §4.
	}

	/**
	 * Add per-page MCR kids to a previously merged struct subtree for reused templates.
	 *
	 * When SetPageTemplate() reuses the same FPDI source page on multiple host pages,
	 * only one struct subtree is emitted but MCR dicts accumulate one entry per host page.
	 *
	 * Phase 4 stub — no-op in Phase 2.
	 *
	 * @param  int $hostPage  1-based page number of the additional host page
	 * @param  int $xObjNum  PDF object number of the FPDI Form XObject for this host page
	 * @return void
	 */
	public function addPerPageMcrKids($hostPage, $xObjNum)
	{
		// Phase 4 stub — full implementation in §4.
	}

	/**
	 * Record a warning for an untagged imported page.
	 *
	 * The FpdiTrait caller flushes these via getUntaggedWarnings() into
	 * UaState::addWarning() — FpdiStructMerger itself never touches UaState.
	 *
	 * @param  string $message
	 * @return void
	 */
	public function addUntaggedWarning($message)
	{
		$this->untaggedWarnings[] = $message;
	}

	/**
	 * Return and clear collected untagged-import warnings.
	 *
	 * Called by FpdiTrait after each import decision to flush warnings into
	 * UaState::addWarning() where callers can inspect them via getPdfUaWarnings().
	 *
	 * @return string[]
	 */
	public function getUntaggedWarnings()
	{
		$w = $this->untaggedWarnings;
		$this->untaggedWarnings = [];
		return $w;
	}
}
