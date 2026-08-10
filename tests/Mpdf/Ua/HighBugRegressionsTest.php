<?php

namespace Mpdf\Ua;

/**
 * Regression tests for HIGH-severity PDF/UA-1 bugs.
 *
 *   - TH /ID was emitted as UTF-16BE text string while TD /Headers references
 *     were emitted as PDF names; readers could not resolve the cross-reference.
 *   - `role="doc-title"` mapped to the invalid struct type "Title" and crashed
 *     StructureTree::open() with MpdfException.
 *   - FpdiStructMerger stored imported /Alt /ActualText /Lang values verbatim;
 *     StructureWriter then double-encoded them, producing unreadable garbage
 *     on every imported tagged page with non-ASCII.
 *   - Th.php used the HTML id verbatim (potentially containing PDF-name-illegal
 *     bytes) and synthesised a (tableLevel,row,col) id that collided between
 *     two tables on the same page.
 *
 * @group pdfua
 */
class HighBugRegressionsTest extends PdfUaTestCase
{

	/**
	 * The /ID written on a TH StructElem must be byte-equivalent to the
	 * matching reference in any TD's /Headers array — otherwise readers and
	 * AT cannot resolve the cross-reference at all.
	 *
	 * ISO 32000-1 Table 322 — /ID is a byte string.
	 * ISO 32000-1 Table 349 — /Headers is an array of names.
	 * Matterhorn 09-004/09-005 — header-cell association.
	 */
	public function testThIdBytesEqualHeadersReferenceBytes()
	{
		$html = '<table>'
			. '<tr><th id="rate">Rate</th><th id="qty">Qty</th></tr>'
			. '<tr><td headers="rate">5%</td><td headers="qty">12</td></tr>'
			. '</table>';
		$output = $this->getOutput($this->makeMpdf(), $html);

		// Pre-fix the TH /ID was emitted as `(\xFE\xFFr\x00a\x00t\x00e)`.
		// After the fix it must be a paren byte string with the literal id.
		// The sanitiser lowercases (mPDF uppercases id="..." values) so the
		// byte sequence matches the un-uppercased headers="..." token.
		$this->assertMatchesRegularExpression('@/ID\s*\(rate\)@', $output);
		$this->assertMatchesRegularExpression('@/ID\s*\(qty\)@', $output);

		// And the TD /Headers must reference the same bytes as PDF names.
		$this->assertMatchesRegularExpression('@/Headers\s*\[\s*/rate\s*\]@', $output);
		$this->assertMatchesRegularExpression('@/Headers\s*\[\s*/qty\s*\]@', $output);

		// The pre-fix UTF-16BE form must NOT appear.
		$this->assertStringNotContainsString("\xFE\xFFr\x00a\x00t\x00e", $output);
	}

	/**
	 * An HTML id containing characters illegal in PDF names (ISO 32000-1
	 * §7.3.5) must be #-escaped consistently in BOTH /ID and /Headers, so
	 * the cross-reference still resolves and the PDF is well-formed.
	 */
	public function testHeaderIdWithIllegalCharsSanitisedConsistently()
	{
		// 'col(1)' contains parens — illegal in both byte-string and name
		// without escaping; '#28' is `(` and '#29' is `)`.
		$html = '<table>'
			. '<tr><th id="col(1)">A</th></tr>'
			. '<tr><td headers="col(1)">x</td></tr>'
			. '</table>';
		$output = $this->getOutput($this->makeMpdf(), $html);

		// Both the /ID byte string and the /Headers name must use the
		// identical escaped form.  '(' = 0x28, ')' = 0x29; the sanitiser
		// lowercases the rest so it matches the un-uppercased headers token.
		// Result: col#281#29 (no separator — '#28' eats the '(', then '1'
		// is literal, then '#29' eats the ')').
		$this->assertStringContainsString('/ID (col#281#29)', $output);
		$this->assertStringContainsString('/Headers [/col#281#29]', $output);
	}

	/**
	 * Two <table>s on the same page with no explicit id="" on TH cells must
	 * still produce unique /ID values across the document, otherwise TD
	 * /Headers references silently address the wrong TH.
	 */
	public function testMultipleTablesProduceUniqueSyntheticThIds()
	{
		$html = ''
			. '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'
			. '<table><tr><th>C</th><th>D</th></tr><tr><td>3</td><td>4</td></tr></table>';
		$output = $this->getOutput($this->makeMpdf(), $html);

		// Extract every /ID value emitted on a struct element.
		preg_match_all('#/ID\s*\(([^)]+)\)#', $output, $matches);
		$ids = $matches[1];
		$this->assertNotEmpty($ids, 'expected at least one /ID emitted');
		// Four TH cells across two tables ⇒ four unique synthesised ids.
		$this->assertCount(
			count(array_unique($ids)),
			$ids,
			'synthesised TH /ID values must be unique across tables: ' . implode(',', $ids)
		);
	}

	/**
	 * `<div role="doc-title">…</div>` must produce a PDF struct element and
	 * not throw. Pre-fix it mapped to the literal struct type "Title" which
	 * is not in ISO 32000-1 §14.8 Tables 333–335 and tripped
	 * StructureTree::open()'s validity check.
	 */
	public function testRoleDocTitleDoesNotCrash()
	{
		// Direct PHP API would also work, but the bug surfaced on real HTML.
		$html = '<div role="doc-title">My Document Title</div><p>Body.</p>';
		$output = $this->getOutput($this->makeMpdf(), $html);

		// Should produce an H1 struct element (heading-as-doc-title is the
		// natural mapping). Look for any /S /H1 in the struct tree output.
		$this->assertStringContainsString('/S /H1', $output);

		// And the literal invalid type must not have leaked through.
		$this->assertStringNotContainsString('/S /Title', $output);
	}

	/**
	 * UTF-16BE-with-BOM source values must round-trip to UTF-8 so that
	 * StructureWriter's downstream utf16BigEndianTextString() produces a
	 * single, correct encoding (not a double-BOMed garbage sequence).
	 *
	 * ISO 32000-1 §7.9.2.2 — text strings MAY use UTF-16BE prefixed by U+FEFF.
	 */
	public function testFpdiMergerDecodesUtf16BeAltText()
	{
		$decoded = $this->invokeDecode($this->makeUtf16BeHexString('Café résumé'));
		$this->assertSame('Café résumé', $decoded);
	}

	/**
	 * UTF-8-with-BOM is also legal (ISO 32000-1 §7.9.2.2).
	 */
	public function testFpdiMergerStripsUtf8Bom()
	{
		$decoded = $this->invokeDecode($this->makeRawHexString("\xEF\xBB\xBFhello"));
		$this->assertSame('hello', $decoded);
	}

	/**
	 * No BOM → ASCII passthrough (PDFDocEncoding 0x00–0x7F == ASCII).
	 * BCP-47 lang tags are the common case here.
	 */
	public function testFpdiMergerPassesAsciiLangThrough()
	{
		$decoded = $this->invokeDecode($this->makeRawHexString('en-GB'));
		$this->assertSame('en-GB', $decoded);
	}

	/**
	 * UTF-16LE-with-BOM is uncommon but legal in older producers.
	 */
	public function testFpdiMergerDecodesUtf16Le()
	{
		// "AB" in UTF-16LE with BOM = FF FE 41 00 42 00
		$decoded = $this->invokeDecode($this->makeRawHexString("\xFF\xFEA\x00B\x00"));
		$this->assertSame('AB', $decoded);
	}

	/**
	 * `<a href="x"></a>` produces no rendered annotation and no inner
	 * content. The Link struct element opened in Tag\A::open() ends up with
	 * no kids, no MCRs, and no OBJR refs — Matterhorn 02-003 (ISO 14289-1
	 * §7.18.5). PDFUAauto must silently prune it from the struct tree.
	 */
	public function testEmptyAnchorPrunedInAutoMode()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p>Before <a href="https://example.com"></a> after.</p>');

		$this->assertStringNotContainsString('/S /Link', $output);
	}

	/**
	 * Same condition in strict mode (PDFUAauto=false) must throw with
	 * guidance pointing at the offending href. (This fires only for non-empty
	 * hrefs whose body produces no content / no annotation — empty hrefs are
	 * no longer treated as hyperlinks.)
	 */
	public function testEmptyAnchorThrowsInStrictMode()
	{
		$mpdf = $this->makeMpdf();
		$this->expectException('\Mpdf\MpdfException');
		$this->expectExceptionMessageMatches('/no accessible content/');
		$this->getOutput($mpdf, '<p>Before <a href="https://example.com"></a> after.</p>');
	}

	/**
	 * `<a name="x">Section</a>` (HTML5 destination anchor — no `href`) must
	 * not open a Link struct element. The surrounding <p> tags the inner
	 * text via MCID; the /Dests catalog (registered through the existing
	 * NAME path in Tag\A) owns the destination registration.
	 */
	public function testNamedAnchorWithoutHrefDoesNotOpenLinkInAuto()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p><a name="section-2">Section 2</a></p>');

		$this->assertStringNotContainsString('/S /Link', $output);
	}

	/**
	 * Same input in strict mode (PDFUAauto=false). The throw was never
	 * reached previously (the `isset($attr['HREF'])` guard already excluded
	 * NAME-only anchors); this regression locks in that behaviour.
	 */
	public function testNamedAnchorWithoutHrefDoesNotThrowInStrict()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p><a name="section-2">Section 2</a></p>');

		$this->assertNotEmpty($output);
		$this->assertStringNotContainsString('/S /Link', $output);
	}

	/**
	 * `<a name="x" href="">Section</a>` — destination anchor with empty
	 * `href` (common templating artefact). Previously this opened a Link
	 * struct element, got pruned in auto mode, and threw in strict mode
	 * quoting `<a href="">` — a confusing message for what is a legitimate
	 * destination anchor. Now: no Link struct element, no throw.
	 */
	public function testNamedAnchorWithEmptyHrefDoesNotThrowInStrict()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p><a name="section-2" href="">Section 2</a></p>');

		$this->assertNotEmpty($output);
		$this->assertStringNotContainsString('/S /Link', $output);
	}

	/**
	 * `<a href="   ">Section</a>` — whitespace-only `href`. HTML5 specifies
	 * that whitespace-only hyperlink targets are not valid hyperlinks; mPDF
	 * therefore must not open a Link struct element for them.
	 */
	public function testWhitespaceHrefDoesNotOpenLink()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p><a href="   ">Section</a></p>');

		$this->assertStringNotContainsString('/S /Link', $output);
	}

	/**
	 * `<a name="x" lang="fr">Section</a>` — non-hyperlink anchor that does
	 * carry inline accessibility metadata. Tag\A emits a Span struct element
	 * (not a Link) to host the /Lang entry — Matterhorn 11-001/11-002.
	 *
	 * The /Lang value is written by StructureWriter as a UTF-16BE-with-BOM
	 * text string; the BOM-prefixed bytes are asserted directly so the test
	 * does not depend on encoding internals.
	 */
	public function testNamedAnchorWithLangOpensSpan()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p><a name="section-2" lang="fr">Section 2</a></p>');

		$this->assertStringNotContainsString('/S /Link', $output);
		$this->assertStringContainsString('/S /Span', $output);
		// /Lang (<utf16-bom>fr) — BOM is FE FF, then 00 'f' 00 'r'.
		$this->assertStringContainsString("/Lang (\xFE\xFF\x00f\x00r)", $output);
	}

	/**
	 * Regression: a non-hyperlink `<a name="x" lang="fr">` opens a Span to host
	 * its /Lang, but Tag\A::open() previously pushed no strip frame, so
	 * Tag\A::close() never popped that Span off the struct-element stack. The
	 * leaked Span then swallowed every following block: the second `<p>` nested
	 * inside the first instead of being its sibling, corrupting reading order.
	 *
	 * The corruption is invisible to veraPDF (P-inside-P is not a UA-1
	 * violation), so this asserts the in-memory struct tree directly: both
	 * paragraphs must be direct children of the Document root.
	 */
	public function testNonHyperlinkAnchorSpanDoesNotLeakIntoFollowingBlocks()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML('<h1>Heading</h1><p>x <a name="a" lang="fr">y</a> z</p><p>second</p>');

		$topLevelParagraphs = 0;
		foreach ($mpdf->getPdfUaStructureTree()->getRoot()->getChildren() as $child) {
			if ($child->getType() === 'P') {
				$topLevelParagraphs++;
			}
		}

		$this->assertSame(
			2,
			$topLevelParagraphs,
			'both <p> elements must be siblings under Document; a leaked anchor Span nests the second inside the first'
		);
	}

	/**
	 * Locked-in regression for the residual empty-link throw path: a
	 * non-empty `href` whose body produces no MCRs and no OBJR refs is still
	 * a Matterhorn 02-003 violation and must throw in strict mode.
	 *
	 * Identical input shape to {@see testEmptyAnchorThrowsInStrictMode}; kept
	 * as a separate test so future audit replays confirm the defence-in-depth
	 * pruning path remains live after the Tag\A rewrite.
	 */
	public function testEmptyHyperlinkBodyThrowsInStrictMode()
	{
		$mpdf = $this->makeMpdf();
		$this->expectException('\Mpdf\MpdfException');
		$this->expectExceptionMessageMatches('@example\.com@');
		$this->getOutput($mpdf, '<p>Before <a href="https://example.com"></a> after.</p>');
	}

	/**
	 * Auto-mode counterpart to the strict-mode throw above: the empty Link
	 * is pruned silently and no /S /Link makes it into the output.
	 */
	public function testEmptyHyperlinkBodyPrunedInAutoMode()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p>Before <a href="https://example.com"></a> after.</p>');

		$this->assertStringNotContainsString('/S /Link', $output);
	}

	/**
	 * `<a href="x"><img alt=""></a>` — decorative image inside a link.
	 * The img renders inside an Artifact scope, so the Link struct element
	 * receives no MCRs from descendants. Tag\A::open() in PDFUAauto mode
	 * pre-sets /Alt = "Link to {href}" so any annotation that does get
	 * attached has an accessible name — Matterhorn 28-002.
	 *
	 * The Link element may or may not survive pruning depending on
	 * whether mPDF emits a clickable annotation; either way the synthesised
	 * /Alt must appear in the PDF output (or no /S /Link at all).
	 */
	public function testImageOnlyLinkSynthesisesAltInAutoMode()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="https://example.com/foo"><img src="' . $png . '" alt="" width="20" height="20"></a></p>'
		);

		// Acceptable outputs:
		//   (a) Link struct survives with synthesised /Alt — assert /Alt present.
		//   (b) Link struct pruned (no annotation drawn) — assert /S /Link absent.
		$linkPresent = strpos($output, '/S /Link') !== false;
		if ($linkPresent) {
			// /Alt is utf16-encoded by the writer; check for the BOM-prefixed
			// "Link to" prefix (UTF-16BE: 00 4C 00 69 00 6E 00 6B 00 20 00 74 00 6F).
			$expectedPrefix = "\xFE\xFF\x00L\x00i\x00n\x00k\x00 \x00t\x00o";
			$this->assertStringContainsString(
				$expectedPrefix,
				$output,
				'PDFUAauto must synthesise /Alt on a Link wrapping only decorative content'
			);
		} else {
			// Link pruned — that's also acceptable (Matterhorn 02-003 satisfied
			// by removal). No further assertion needed.
			$this->assertTrue(true);
		}
	}

	/**
	 * Same image-only link in strict mode — does NOT throw on its own. The
	 * Link struct element ends up with one OBJR kid (the link annotation
	 * Mpdf::Link generates over the image rect), so it satisfies the
	 * empty-Link guard at write time. Accessibility is provided by the
	 * annotation's own /Contents (synthesised from href by mPDF), per ISO
	 * 32000-1 §12.5.6.5. PDFUAauto goes one step further and synthesises
	 * a struct-level /Alt (asserted in testImageOnlyLinkSynthesisesAltInAutoMode).
	 *
	 * If a future stricter check is added that rejects image-only links
	 * with no descendant accessible name, update this assertion.
	 */
	public function testImageOnlyLinkDoesNotThrowInStrictMode()
	{
		$mpdf = $this->makeMpdf();
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
		$output = $this->getOutput(
			$mpdf,
			'<p><a href="https://example.com/foo"><img src="' . $png . '" alt="" width="20" height="20"></a></p>'
		);
		// Successful generation (no exception) is the assertion. The Link
		// struct element should be present because Mpdf::Link attached an
		// OBJR — but the precise output shape is implementation detail.
		$this->assertNotEmpty($output);
	}

	/**
	 * PDFDocEncoding 0xB7 is U+00B7 (middle dot) per ISO 32000-1 Annex D
	 * Table D.2. The previous lossy fallback passed it through verbatim,
	 * which then got double-encoded by utf16BigEndianTextString().
	 */
	public function testFpdiMergerDecodesPdfDocEncodingHighBytes()
	{
		$decoded = $this->invokeDecode($this->makeRawHexString("\xB7"));
		$this->assertSame("\xC2\xB7", $decoded, 'PDFDocEncoding 0xB7 → U+00B7 (middle dot)');
	}

	/**
	 * PDFDocEncoding 0x86 is U+2020 (dagger) per ISO 32000-1 Annex D Table
	 * D.2 — a special PDF-only mapping that diverges from ISO-8859-1.
	 */
	public function testFpdiMergerDecodesPdfDocEncodingSpecialBytes()
	{
		$decoded = $this->invokeDecode($this->makeRawHexString("\x86"));
		$this->assertSame("\xE2\x80\xA0", $decoded, 'PDFDocEncoding 0x86 → U+2020 (dagger)');
	}

	/**
	 * PDFDocEncoding 0x7F is undefined; the decoder must produce a
	 * replacement marker without throwing.
	 */
	public function testFpdiMergerHandlesUndefinedPdfDocByte()
	{
		$decoded = $this->invokeDecode($this->makeRawHexString("\x7F"));
		// Expect the Unicode replacement character U+FFFD (UTF-8 EF BF BD).
		$this->assertSame("\xEF\xBF\xBD", $decoded, 'undefined PDFDocEncoding byte → U+FFFD');
	}

	/**
	 * ISO 32000-1 §7.3.5 — PDF names are limited to 127 bytes after the
	 * leading '/'. A 50-char UTF-8 input made entirely of multibyte chars
	 * expands to ~150 bytes once #-escaped. The sanitiser must cap output
	 * at 127 bytes while keeping different inputs distinct.
	 */
	public function testSanitiseIdHandlesOverlongInput()
	{
		// Build two distinct 200-char UTF-8 strings made of multibyte chars
		// so #-escape expansion blows past 127 bytes for both.
		$a = str_repeat("\xE2\x98\x85", 100); // 100 × ★ = 300 source bytes
		$b = str_repeat("\xE2\x98\x86", 100); // 100 × ☆ = 300 source bytes (different glyph)

		$sa = \Mpdf\Ua\StructureElement::sanitiseIdForPdf($a);
		$sb = \Mpdf\Ua\StructureElement::sanitiseIdForPdf($b);

		$this->assertLessThanOrEqual(127, strlen($sa), 'sanitised id must be ≤ 127 bytes');
		$this->assertLessThanOrEqual(127, strlen($sb), 'sanitised id must be ≤ 127 bytes');
		$this->assertNotSame($sa, $sb, 'distinct long inputs must produce distinct sanitised ids');
	}

	/**
	 * Build a PdfHexString whose ->value is the hex encoding of the supplied
	 * UTF-8 input encoded as UTF-16BE-with-BOM (the most common source form
	 * for /Alt and /ActualText in the wild).
	 */
	private function makeUtf16BeHexString($utf8)
	{
		$utf16 = "\xFE\xFF" . mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
		return $this->makeRawHexString($utf16);
	}

	/**
	 * Build a PdfHexString whose ->value is the hex encoding of $raw bytes.
	 */
	private function makeRawHexString($raw)
	{
		$node = new \setasign\Fpdi\PdfParser\Type\PdfHexString();
		$node->value = bin2hex($raw);
		return $node;
	}

	/**
	 * Reflection trampoline into FpdiStructMerger::decodeImportedTextString().
	 */
	private function invokeDecode($node)
	{
		$mpdf   = $this->makeMpdf();
		$merger = $mpdf->getPdfUaFpdiStructMerger();
		$rm     = new \ReflectionMethod($merger, 'decodeImportedTextString');
		$rm->setAccessible(true);
		return $rm->invoke($merger, $node);
	}
}
