<?php

namespace Mpdf\Ua;

/**
 * ToUnicode CMap validity tests for embedded TrueType subset fonts (audit E4).
 *
 * With /Encoding /Identity-H every character code shown in the content stream is
 * two bytes wide, and the ToUnicode CMap declares a matching <0000> <FFFF>
 * codespacerange. Each bfchar *source* token must therefore be exactly two bytes.
 *
 * A supplementary-plane codepoint (emoji, CJK Ext-B, math alphanumerics) is not
 * a 2-byte value: the content stream emits its UTF-16BE surrogate pair — two
 * 2-byte codes. The previous writer used the raw scalar as the source token
 * (sprintf('%04X', 0x1F600) => "1F600"), producing an odd-width <1F600> token
 * that violates the codespacerange and makes the WHOLE ToUnicode stream
 * unusable — breaking copy/paste text extraction for every glyph in the font,
 * in UA and non-UA output alike. The fix maps each surrogate code unit to
 * itself so the pair round-trips to the original scalar.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §9.10.3 — ToUnicode CMap; bfchar / codespacerange
 *   - ISO 14289-1:2014 §7.21.7 / Matterhorn 09-006 — natural-language text mapping
 *   - The Unicode Standard §3.9 — UTF-16 surrogate pair encoding
 *
 * @group pdfua
 * @see PdfUaTestCase  base class supplying makeMpdf() and getOutput()
 */
class ToUnicodeCMapTest extends PdfUaTestCase
{

	/**
	 * Extract every bfchar source→destination pair from every ToUnicode CMap in
	 * the (uncompressed) PDF bytes.
	 *
	 * @param  string $pdf  Raw PDF output bytes (compression disabled by makeMpdf()).
	 * @return array<int, array{0:string, 1:string}>  [ [srcHex, dstHex], ... ]
	 */
	private function extractBfcharPairs($pdf)
	{
		$pairs = [];
		if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $pdf, $blocks)) {
			foreach ($blocks[1] as $block) {
				if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $m, PREG_SET_ORDER)) {
					foreach ($m as $entry) {
						$pairs[] = [strtoupper($entry[1]), strtoupper($entry[2])];
					}
				}
			}
		}
		return $pairs;
	}

	/**
	 * Every bfchar source token in the ToUnicode CMap must be exactly two bytes
	 * (four hex digits) so it fits the declared <0000> <FFFF> codespacerange,
	 * even when the subset contains supplementary-plane codepoints.
	 *
	 * @return void
	 */
	public function testAllSourceTokensAreTwoBytes()
	{
		$mpdf = $this->makeMpdf();
		// A grinning-face emoji (U+1F600, SMP) and a CJK Ext-B ideograph (U+20000, SIP)
		// are both added to the DejaVuSans subset by UTF8StringToArray, so both would
		// have produced odd-width source tokens under the old writer.
		$pdf = $this->getOutput(
			$mpdf,
			'<h1>ToUnicode</h1>'
			. '<p style="font-family: dejavusans">Emoji &#128512; and CJK-Ext-B &#131072; glyphs.</p>'
		);

		$pairs = $this->extractBfcharPairs($pdf);
		$this->assertNotEmpty($pairs, 'The document must contain at least one ToUnicode bfchar entry.');

		foreach ($pairs as $pair) {
			$this->assertSame(
				4,
				strlen($pair[0]),
				'bfchar source token <' . $pair[0] . '> must be exactly 2 bytes (4 hex digits) '
				. 'to fit the <0000> <FFFF> codespacerange.'
			);
		}
	}

	/**
	 * A supplementary-plane codepoint must appear in the ToUnicode CMap as its
	 * two UTF-16BE surrogate code units, each mapping to itself, so that on
	 * extraction the two 2-byte codes concatenate back to the surrogate pair and
	 * decode to the original scalar (U+1F600).
	 *
	 * @return void
	 */
	public function testAstralCharRoundTripsToSurrogatePair()
	{
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput(
			$mpdf,
			'<p style="font-family: dejavusans">Grinning &#128512; face.</p>'
		);

		$map = [];
		foreach ($this->extractBfcharPairs($pdf) as $pair) {
			$map[$pair[0]] = $pair[1];
		}

		// U+1F600 -> UTF-16BE surrogate pair D83D DE00.
		$this->assertArrayHasKey('D83D', $map, 'High surrogate D83D must be a bfchar source.');
		$this->assertArrayHasKey('DE00', $map, 'Low surrogate DE00 must be a bfchar source.');
		$this->assertSame('D83D', $map['D83D'], 'High surrogate must map to itself.');
		$this->assertSame('DE00', $map['DE00'], 'Low surrogate must map to itself.');

		// The concatenated destinations form the UTF-16BE surrogate pair, which decodes
		// back to the original scalar U+1F600.
		$utf16be   = hex2bin($map['D83D'] . $map['DE00']);
		$roundTrip = mb_convert_encoding($utf16be, 'UTF-8', 'UTF-16BE');
		$this->assertSame(
			"\xF0\x9F\x98\x80", // U+1F600 in UTF-8
			$roundTrip,
			'The surrogate pair must round-trip to U+1F600.'
		);
	}

	/**
	 * A shared high surrogate must not be emitted as a duplicate bfchar source.
	 * U+1F600 and U+1F601 both begin with the high surrogate D83D; keying entries
	 * by source code collapses them to a single, unambiguous mapping.
	 *
	 * @return void
	 */
	public function testSharedSurrogateSourceIsNotDuplicated()
	{
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput(
			$mpdf,
			'<p style="font-family: dejavusans">Faces &#128512;&#128513;.</p>' // U+1F600, U+1F601
		);

		$sources = [];
		foreach ($this->extractBfcharPairs($pdf) as $pair) {
			$sources[] = $pair[0];
		}

		$counts = array_count_values($sources);
		$this->assertArrayHasKey('D83D', $counts, 'The shared high surrogate D83D must be present.');
		$this->assertSame(1, $counts['D83D'], 'The shared high surrogate D83D must appear exactly once.');
	}

	/**
	 * BMP codepoints must still map identity (source == destination), so the fix
	 * does not regress plain Latin text extraction.
	 *
	 * @return void
	 */
	public function testBmpCodepointsMapIdentity()
	{
		$mpdf = $this->makeMpdf();
		$pdf  = $this->getOutput(
			$mpdf,
			'<p style="font-family: dejavusans">A</p>' // U+0041
		);

		$map = [];
		foreach ($this->extractBfcharPairs($pdf) as $pair) {
			$map[$pair[0]] = $pair[1];
		}

		$this->assertArrayHasKey('0041', $map, "Capital 'A' (U+0041) must be a bfchar source.");
		$this->assertSame('0041', $map['0041'], 'A BMP codepoint must map to itself in the ToUnicode CMap.');
	}
}
