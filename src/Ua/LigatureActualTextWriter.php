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
 * @see MarkedContentHelper  BDC/EMC emitter used elsewhere in the UA layer
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

	/**
	 * Build the opening BDC bytes for a /Span /ActualText wrapper.
	 *
	 * Returns a PDF content-stream fragment string — NOT written to the buffer
	 * directly. Callers (applyGPOSpdf) splice this string into the TJ sequence
	 * they are building so the operator appears outside the TJ array:
	 *
	 *   >] TJ /Span <</ActualText <FEFF…>>> BDC [(
	 *
	 * The depth counter in MarkedContentHelper is NOT incremented here because
	 * this class manages its own inline splicing without going through MCH's
	 * write path (MCH writes immediately; applyGPOSpdf returns a string).
	 *
	 * ISO 32000-1 §14.6 — BDC operator.
	 * ISO 32000-1 §14.7.2 Table 322 — /ActualText attribute.
	 *
	 * @param  string $actualTextHex  Hex-encoded UTF-16BE with BOM, e.g. 'FEFF00660069'
	 * @return string  PDF content-stream fragment
	 */
	public function buildBdcBytes($actualTextHex)
	{
		return '/Span <</ActualText <' . $actualTextHex . '>>> BDC';
	}

	/**
	 * Build the closing EMC bytes that pair with buildBdcBytes().
	 *
	 * Returns a PDF content-stream fragment string. Callers splice this into
	 * the TJ sequence around the ligature glyph hex codes:
	 *
	 *   >] TJ /Span <</ActualText <FEFF…>>> BDC [<ligHex>] TJ EMC [(
	 *
	 * ISO 32000-1 §14.6 — EMC closes the most recently opened BDC.
	 *
	 * @return string  PDF content-stream fragment
	 */
	public function buildEmcBytes()
	{
		return 'EMC';
	}

	/**
	 * Encode an array of Unicode codepoints as a UTF-16BE hex string with BOM.
	 *
	 * The resulting string (e.g. 'FEFF00660069' for ['f','i']) is used directly
	 * as the /ActualText value in the BDC property dictionary.
	 *
	 * ISO 32000-1 §14.7.2 Table 322 — ActualText is a text string (UTF-16BE).
	 * PDF spec §7.9.2.2 — hex strings are written as <hexdigits>.
	 *
	 * @param  int[] $codepoints  Array of Unicode codepoints (integers)
	 * @return string  Hex string with BOM prefix, e.g. 'FEFF00660069'
	 */
	public function getActualTextEncoding($codepoints)
	{
		// UTF-16BE Byte Order Mark (U+FEFF).
		$hex = 'FEFF';
		foreach ($codepoints as $cp) {
			if ($cp < 0x10000) {
				// BMP codepoint: 2 bytes in UTF-16BE.
				$hex .= sprintf('%04X', $cp);
			} else {
				// Supplementary codepoint: encode as UTF-16BE surrogate pair.
				$cp -= 0x10000;
				$high = 0xD800 + (($cp >> 10) & 0x3FF);
				$low  = 0xDC00 + ($cp & 0x3FF);
				$hex .= sprintf('%04X%04X', $high, $low);
			}
		}
		return $hex;
	}

	/**
	 * Check whether the font's ToUnicode CMap already covers this ligature.
	 *
	 * When FontWriter has emitted a multi-character dstString for the glyph
	 * index (a beginbfchar entry mapping the single CID to the full Unicode
	 * source sequence), an ActualText wrapper is redundant and should be
	 * skipped to avoid bloating the content stream.
	 *
	 * In practice, mPDF's current FontWriter only emits 1-to-1 bfchar entries
	 * (each CID maps to exactly one Unicode code unit), so this method returns
	 * false for all ligature glyphs and the wrapper is always emitted. The
	 * check is parameterised via the font's 'toUnicodeMultiChar' array so that
	 * tests can inject synthetic entries without parsing emitted CMap bytes.
	 *
	 * ISO 32000-1 §9.10.3 — ToUnicode CMap; beginbfchar / beginbfrange.
	 * Matterhorn 24-001 — glyph without Unicode mapping.
	 *
	 * @param  int   $glyphIndex  Glyph / codepoint index in the font subset
	 * @param  int[] $sourceChars Source Unicode codepoints (two or more)
	 * @param  array $currentFont Reference to $mpdf->CurrentFont
	 * @return bool  True when the CMap already covers the mapping (skip wrapper)
	 */
	public function toUnicodeCovers($glyphIndex, $sourceChars, $currentFont)
	{
		// Check for a synthetic / injected multi-char CMap entry (used by tests
		// and future FontWriter enhancements that emit multi-char dstStrings).
		if (isset($currentFont['toUnicodeMultiChar'][$glyphIndex])) {
			$mapped = $currentFont['toUnicodeMultiChar'][$glyphIndex];
			if (is_array($mapped) && $mapped === $sourceChars) {
				return true;
			}
		}
		return false;
	}
}
