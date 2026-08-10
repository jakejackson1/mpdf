<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 (audit E6) — inline struct elements must own their own content.
 *
 * Every flowing-text run used to be tagged against the enclosing BLOCK element
 * (a single per-line /P BDC), so an inline Link / lang-Span / Abbr /E-Span /
 * Ruby RB·RT owned no content item: the Link carried only its OBJR and the
 * inline element's /Lang, /Alt or /E applied to nothing. veraPDF flags an empty
 * Link/Span (ISO 14289-1 §7.18.5 / §7.2, Matterhorn 02-003 / 11-001).
 *
 * The fix brackets a chunk's marked content in its owning inline element's own
 * BDC and attributes the MCID to that element, resuming the block's BDC once the
 * inline run ends. These tests assert the resulting content stream and struct
 * tree: the Link owns both its text MCID(s) and the annotation OBJR, and the
 * lang-Span / Abbr /E-Span / Ruby RB·RT each own their own text MCID.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.4.4 — one MCID maps to exactly one struct element
 *   - ISO 32000-1:2008 §14.8.5 / §14.8.5.6 Table 337 — inline + Ruby struct types
 *   - ISO 14289-1:2014 §7.18.5 — Link struct element must reference its annotation
 *
 * @see PdfUaTestCase  base class supplying makeMpdf() and getOutput()
 */
class InlineStructContentTest extends PdfUaTestCase
{

	/**
	 * A hyperlink wrapping a lang-Span: the Link element's /K must contain both
	 * the text MCID (for "the report ") and the annotation OBJR, while the
	 * nested lang-Span owns its own MCID (for "rapport"). Before E6 the Link's
	 * /K held only an OBJR and both text runs were tagged against the block P.
	 *
	 * @return void
	 */
	public function testLinkOwnsTextMcidAndObjrWithNestedLangSpan()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p>see <a href="https://example.com">the report '
			. '<span lang="fr">rapport</span></a> today</p>'
		);

		// The link text is bracketed in the Link element's own BDC (not the
		// block's) — the core E6 behaviour. Before the fix no /Link BDC was
		// emitted at all; the text lived under the surrounding /P BDC.
		$this->assertStringContainsString('/Link <</MCID', $output);
		// The nested lang run is bracketed in the Span element's own BDC.
		$this->assertStringContainsString('/Span <</MCID', $output);
		// The block still owns its own direct text ("see ", " today").
		$this->assertStringContainsString('/P <</MCID', $output);

		// The Link struct element's /K must carry BOTH a marked-content
		// reference (its text MCID) AND an object reference (its annotation).
		$linkBody = $this->firstStructBodyContaining($output, 'Link');
		$this->assertNotNull($linkBody, 'a /S /Link struct element must exist');
		$this->assertStringContainsString('/MCR', $linkBody, 'Link /K must contain a marked-content reference (the link text MCID)');
		$this->assertStringContainsString('/OBJR', $linkBody, 'Link /K must contain the annotation OBJR');

		// The lang-Span must carry /Lang="fr" (UTF-16BE) and own a content item.
		$spanBody = $this->firstStructBodyContaining($output, 'Span');
		$this->assertNotNull($spanBody, 'a /S /Span struct element must exist');
		$this->assertStringContainsString("\xfe\xff\x00f\x00r", $spanBody, 'lang-Span must carry /Lang "fr"');
		$this->assertStringContainsString('/K', $spanBody, 'lang-Span must own its own content item');

		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * <abbr title="…"> opens a Span carrying /E expansion text; the abbreviation
	 * glyphs must be tagged in that Span's own BDC so the /E applies to the
	 * abbreviation content rather than to an empty element.
	 *
	 * @return void
	 */
	public function testAbbrExpansionSpanOwnsItsText()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p>The <abbr title="World Health Organization">WHO</abbr> said so.</p>'
		);

		// The abbreviation text is bracketed in the /E Span's own BDC.
		$this->assertStringContainsString('/Span <</MCID', $output);

		$spanBody = $this->firstStructBodyContaining($output, 'Span');
		$this->assertNotNull($spanBody, 'a /S /Span struct element must exist for <abbr>');
		$this->assertStringContainsString('/E', $spanBody, 'the abbr Span must carry /E expansion text');
		$this->assertStringContainsString('/K', $spanBody, 'the abbr Span must own its own content item');

		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Ruby: an explicit <rb> base and its <rt> annotation each open their own
	 * struct element and must own their own text MCID — the base tagged in the
	 * RB BDC, the annotation in the RT BDC (ISO 32000-1 §14.8.5.6 Table 337).
	 *
	 * @return void
	 */
	public function testRubyRbAndRtEachOwnTheirText()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p>Read <ruby><rb>KANJI</rb><rt>kan</rt></ruby> now.</p>'
		);

		$this->assertStringContainsString('/S /Ruby', $output);
		$this->assertStringContainsString('/S /RB', $output);
		$this->assertStringContainsString('/S /RT', $output);

		// The base and the annotation are each bracketed in their own BDC.
		$this->assertStringContainsString('/RB <</MCID', $output);
		$this->assertStringContainsString('/RT <</MCID', $output);

		$rbBody = $this->firstStructBodyContaining($output, 'RB');
		$this->assertNotNull($rbBody, 'a /S /RB struct element must exist');
		$this->assertStringContainsString('/K', $rbBody, 'RB must own its own content item');

		$rtBody = $this->firstStructBodyContaining($output, 'RT');
		$this->assertNotNull($rtBody, 'a /S /RT struct element must exist');
		$this->assertStringContainsString('/K', $rtBody, 'RT must own its own content item');

		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * A bare ruby base (no <rb> wrapper) attaches its content directly to the
	 * Ruby element; the Ruby must own the base text MCID while the <rt> owns its
	 * own annotation MCID.
	 *
	 * @return void
	 */
	public function testBareRubyBaseOwnsItsText()
	{
		$output = $this->getOutput(
			$this->makeMpdf(),
			'<p>Read <ruby>KANJI<rt>kan</rt></ruby> now.</p>'
		);

		$this->assertStringContainsString('/Ruby <</MCID', $output);
		$this->assertStringContainsString('/RT <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Return the dict body of the first `N 0 obj … endobj` whose contents include
	 * `/S /<type>` (a struct element of that type), or null if none is present.
	 *
	 * @param  string $pdf   raw PDF bytes (uncompressed — PdfUaTestCase disables compression)
	 * @param  string $type  struct type without the leading slash (e.g. 'Link')
	 * @return string|null   the object body between "obj" and "endobj", or null
	 */
	private function firstStructBodyContaining($pdf, $type)
	{
		if (preg_match_all('/\d+ 0 obj(.*?)endobj/s', $pdf, $m)) {
			foreach ($m[1] as $body) {
				if (strpos($body, '/S /' . $type) !== false) {
					return $body;
				}
			}
		}
		return null;
	}

	/**
	 * Assert BDC/BMC opens and EMC closes are balanced across the whole document.
	 *
	 * @param  string $output  raw PDF bytes
	 * @return void
	 */
	private function assertBdcEmcBalanced($output)
	{
		$bdcCount = preg_match_all('/\bBDC\b/', $output);
		$bmcCount = preg_match_all('/\bBMC\b/', $output);
		$emcCount = preg_match_all('/\bEMC\b/', $output);
		$this->assertEquals(
			$bdcCount + $bmcCount,
			$emcCount,
			sprintf('BDC(%d)+BMC(%d) must equal EMC(%d)', $bdcCount, $bmcCount, $emcCount)
		);
	}
}
