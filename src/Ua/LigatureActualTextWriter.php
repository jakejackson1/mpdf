<?php

namespace Mpdf\Ua;

use Mpdf\Writer\BaseWriter;

/**
 * Wrap OTL-substituted glyph clusters with /Span /ActualText BDC/EMC operators.
 *
 * When OpenType Layout (OTL) substitutes a ligature glyph (e.g. 'fi' → single
 * CID), the resulting glyph may not have a 1:1 Unicode entry in the font's
 * ToUnicode CMap. This violates Matterhorn 24-001, which requires that every
 * glyph in the content stream can be unambiguously mapped to a Unicode sequence.
 *
 * LigatureActualTextWriter wraps each such substituted run with:
 *   /Span <</ActualText <FEFF…>>> BDC … EMC
 * so that a conforming reader can extract the original Unicode text even when
 * the glyph → Unicode mapping is absent from the CMap.
 *
 * One instance per Mpdf lifecycle, constructed by ServiceFactory and reached
 * via $this->ua->getLigatureActualTextWriter().
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — /ActualText on StructElem dict
 *   - ISO 32000-1:2008 §14.6 — BDC/EMC marked content operators
 *   - Matterhorn Protocol 1.1 condition 24-001 — glyph without Unicode mapping
 *
 * Phase 5 stub — full implementation in §5 (LigatureActualTextWriter / Appendix A6).
 *
 * @see MarkedContentHelper  BDC/EMC emitter used by the full Phase 5 implementation
 * @see BaseWriter           buffer-routing target for BDC/EMC bytes
 */
class LigatureActualTextWriter
{

	/** @var BaseWriter */
	private $writer;

	/** @var MarkedContentHelper */
	private $mch;

	/**
	 * Construct with the buffer-routing writer and the BDC/EMC emitter.
	 *
	 * Called once by ServiceFactory before UaState is constructed. Neither
	 * $writer nor $mch is UaState — this avoids a construction-time cycle
	 * between UaState and its six collaborators (§2d wiring note).
	 *
	 * @param BaseWriter          $writer
	 * @param MarkedContentHelper $mch
	 */
	public function __construct(BaseWriter $writer, MarkedContentHelper $mch)
	{
		$this->writer = $writer;
		$this->mch    = $mch;
	}
}
