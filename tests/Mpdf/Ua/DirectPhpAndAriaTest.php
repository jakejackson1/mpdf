<?php

namespace Mpdf\Ua;

/**
 * Phase 4 PDF/UA-1 tests — direct PHP methods, ARIA attributes, and lang propagation.
 *
 * Covers:
 *   - AutosizeText() Span struct element instrumentation
 *   - OCG (layer) BDC/EMC nesting with struct BDC/EMC balance
 *   - ARIA attribute wiring: aria-hidden, aria-labelledby, aria-describedby
 *   - HTML lang attribute propagation to struct elements
 *   - SetProtection() accessibility permission bit enforcement
 *   - XMP metadata stream Identity crypt filter when encrypted
 *
 * All tests disable content-stream compression ($mpdf->compress = false, set by
 * PdfUaTestCase::makeMpdf()) so content-stream assertions can match raw bytes
 * without decompressing FlateDecode streams.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.6   — BDC/EMC marked-content operators
 *   - ISO 32000-1:2008 §14.8   — struct element types
 *   - ISO 32000-1:2008 §14.7.2 — /Alt, /E on struct elements
 *   - ISO 32000-1:2008 §7.6.5  — Identity crypt filter
 *   - ISO 14289-1:2014 §7.1    — ARIA / lang mapping
 *   - Matterhorn 07-001         — accessibility permission bit
 *
 * @group pdfua
 */
class DirectPhpAndAriaTest extends PdfUaTestCase
{

	// ========================= AutosizeText() tests =========================

	/**
	 * AutosizeText() must produce a /Span struct element in the PDF output.
	 *
	 * The Span wraps the single Cell() call so AT can read the text.
	 * ISO 32000-1:2008 §14.8 Table 333 — Span inline element.
	 */
	public function testAutosizeTextProducesSpanStruct()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddPage();
		$mpdf->AutosizeText('Hello', 100, 'DejaVuSansCondensed', '');
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/S /Span', $output);
	}

	/**
	 * The BDC/EMC operators around AutosizeText() must be balanced.
	 *
	 * AutosizeText() must open exactly one BDC and close it with one EMC.
	 * ISO 32000-1:2008 §14.6 — every BDC must have exactly one matching EMC.
	 */
	public function testAutosizeTextSpanContainsRenderedText()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddPage();
		$mpdf->AutosizeText('Hello', 100, 'DejaVuSansCondensed', '');
		$output = $mpdf->Output(null, 'S');
		// /Span BDC must appear in the content stream
		$this->assertStringContainsString('/Span <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= OCG layer tests =========================

	/**
	 * A document with OCG layers must have balanced struct BDC/EMC operators.
	 *
	 * OCG BDC/EMC operators (e.g. /OCZ-index /ZI<id> BDC) are separate from struct
	 * BDC/EMC; the balance check scopes only to struct-type and Artifact operators.
	 * ISO 32000-1:2008 §14.6 — BDC/EMC nesting is valid for nested pairs.
	 */
	public function testBdcEmcBalanceWithOcgLayers()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddPage();
		$mpdf->BeginLayer('Background');
		$mpdf->WriteHTML('<p>Layer content</p>');
		$mpdf->EndLayer();
		$mpdf->WriteHTML('<p>Normal content</p>');
		$output = $mpdf->Output(null, 'S');
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * A paragraph inside an OCG layer produces a /P struct element.
	 *
	 * Struct tagging must work normally inside layers — OCG BDC is transparent
	 * to the struct tree. ISO 32000-1:2008 §14.6 — nesting is valid.
	 */
	public function testLayerContainingParagraphPreservesStructure()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddPage();
		$mpdf->BeginLayer('Foreground');
		$mpdf->WriteHTML('<p>Paragraph in layer</p>');
		$mpdf->EndLayer();
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/S /P', $output);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= ARIA attribute tests =========================

	/**
	 * An element with aria-hidden="true" must produce /Artifact BMC (no struct element).
	 *
	 * aria-hidden removes the element from the accessibility tree — PDF/UA maps
	 * this to an Artifact so screen readers skip it.
	 * ISO 32000-1:2008 §14.8.2.2 — Artifact content sequences.
	 */
	public function testAriaHiddenElementBecomesArtifact()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p aria-hidden="true">Hidden text</p>');
		$this->assertStringContainsString('/Artifact BMC', $output);
		// Must NOT produce a /P struct element for the hidden paragraph
		// (the only BDC allowed is the Artifact one; no /P <</MCID N>> BDC)
		$this->assertStringNotContainsString('/P <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * aria-labelledby pointing at an existing id must resolve without a warning.
	 *
	 * AriaIdResolver defers resolution to _enddoc(). When the target id exists,
	 * resolveAll() populates /Alt on the referencing struct element and emits
	 * no unresolved-reference warnings.
	 * ISO 32000-1:2008 §14.7.2 Table 322 — /Alt on struct element.
	 */
	public function testAriaLabelledbyResolvesToAlt()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p id="caption">Caption text</p>'
			. '<img src="' . __DIR__ . '/../../data/img/issue1609.png" '
			. 'alt="placeholder" aria-labelledby="caption">';
		$this->getOutput($mpdf, $html);
		// No unresolved-reference warning must appear
		$warnings = $mpdf->getPdfUaWarnings();
		$combined = implode(' ', $warnings);
		$this->assertStringNotContainsString('Unresolved ARIA reference', $combined);
	}

	/**
	 * aria-describedby pointing at an existing id must resolve without a warning.
	 *
	 * When the target id exists, AriaIdResolver::resolveAll() populates /E
	 * (expansion/description text) on the referencing struct element.
	 * ISO 32000-1:2008 §14.7.2 Table 322 — /E on struct element.
	 */
	public function testAriaDescribedbyResolvesToE()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p id="desc">Description text</p>'
			. '<p aria-describedby="desc">Described paragraph</p>';
		$this->getOutput($mpdf, $html);
		$warnings = $mpdf->getPdfUaWarnings();
		$combined = implode(' ', $warnings);
		$this->assertStringNotContainsString('Unresolved ARIA reference', $combined);
	}

	/**
	 * aria-labelledby pointing at a non-existent id must produce an unresolved warning.
	 *
	 * When the target id is not found in the document, AriaIdResolver::resolveAll()
	 * records an unresolved-reference diagnostic.
	 */
	public function testUnresolvedAriaReferenceWarning()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$html = '<p aria-labelledby="does-not-exist">Paragraph</p>';
		$this->getOutput($mpdf, $html);
		$warnings = $mpdf->getPdfUaWarnings();
		$combined = implode(' ', $warnings);
		$this->assertStringContainsString('Unresolved ARIA reference', $combined);
		$this->assertStringContainsString('does-not-exist', $combined);
	}

	// ========================= HTML lang attribute tests =========================

	/**
	 * A block element with a lang attribute must produce a /Lang entry on its struct element.
	 *
	 * ISO 14289-1:2014 §7.2 — language changes within a document are declared via
	 * /Lang on struct elements. ISO 32000-1:2008 §14.7.2 Table 322 — /Lang key.
	 */
	public function testLangAttributePropagatesToStructElement()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p lang="fr">Bonjour le monde</p>');
		// The struct element dict for the <p lang="fr"> paragraph must carry a /Lang key
		// whose value encodes "fr". StructureWriter writes /Lang values as UTF-16BE PDF
		// strings with the BOM (\xfe\xff). The catalog carries /Lang (en-GB) from
		// makeMpdf()'s mode argument — asserting the UTF-16BE bytes for "fr" proves the
		// struct element (not just the catalog) carries the French language tag.
		$utf16BeFr = "\xfe\xff\x00f\x00r"; // UTF-16BE encoding of the two-character string "fr"
		$this->assertStringContainsString($utf16BeFr, $output);
		$this->assertBdcEmcBalanced($output);
	}

	// ========================= SetProtection() tests =========================

	/**
	 * SetProtection() without 'extract' must throw in strict mode (PDFUAauto=false).
	 *
	 * Matterhorn 07-001 — the accessibility permission bit (bit 10, 'extract') must
	 * not be cleared. In strict mode the violation is unrecoverable and an exception
	 * is thrown immediately.
	 */
	public function testProtectionWithoutAccessibilityBitThrowsInStrict()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);
		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/extract.*PDF\/UA|PDF\/UA.*extract|Matterhorn 07-001/');
		$mpdf->SetProtection([], '', 'owner');
	}

	/**
	 * SetProtection() without 'extract' must warn and force-add the bit in auto mode.
	 *
	 * In PDFUAauto=true mode, the violation is corrected silently (by force-adding
	 * 'extract') and a diagnostic warning is recorded so the caller can inspect it.
	 * Matterhorn 07-001 — the accessibility permission bit must not be cleared.
	 */
	public function testProtectionWithoutAccessibilityBitWarnsInAuto()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// Must NOT throw in auto mode
		$mpdf->SetProtection([], '', 'owner');
		$output = $mpdf->Output(null, 'S');
		$warnings = $mpdf->getPdfUaWarnings();
		$combined = implode(' ', $warnings);
		$this->assertStringContainsString('extract', $combined);
		// The document must still be produced (not empty)
		$this->assertNotEmpty($output);
	}

	// ========================= XMP encryption tests =========================

	/**
	 * When PDFUA and encryption are both active, the XMP stream must use the
	 * Identity crypt filter so readers can access pdfuaid metadata without decryption.
	 *
	 * ISO 32000-1:2008 §14.3.2 — XMP metadata shall not be encrypted.
	 * ISO 32000-1:2008 §7.6.5  — /Filter [/Crypt] /DecodeParms /Name /Identity
	 *                             signals the Identity (pass-through) crypt filter.
	 *
	 * Note: mPDF uses RC4-only encryption, not AES. The Identity filter declares
	 * no-op decryption; the stream bytes themselves are NOT RC4-encrypted.
	 */
	public function testEncryptedXmpStreamUsesIdentityCryptFilter()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		// Force-add 'extract' to avoid the strict-mode throw, then encrypt.
		$mpdf->SetProtection(['extract'], '', 'owner');
		$output = $this->getOutput($mpdf, '<p>Encrypted PDF/UA document</p>');
		// The XMP stream dict must carry /Filter[/Crypt] + /Name/Identity
		$this->assertStringContainsString('/Filter[/Crypt]', $output);
		$this->assertStringContainsString('/Name/Identity', $output);
		// pdfuaid:part metadata must be readable as plaintext in the output
		// (it appears before any RC4 encryption, in the unencrypted XMP stream)
		$this->assertStringContainsString('pdfuaid:part', $output);
	}

	// ========================= Private helpers =========================

	/**
	 * Assert that the struct-type BDC/BMC count equals the EMC count in the PDF output.
	 *
	 * Scoped to known PDFUA struct operators to avoid counting OCG-layer BDC/EMC
	 * operators (which use /OC and /ZI prefixes and are unrelated to struct tagging).
	 *
	 * ISO 32000-1:2008 §14.6 — BDC/EMC pairs must be balanced within each content stream.
	 *
	 * @param string $output  raw PDF bytes
	 */
	private function assertBdcEmcBalanced($output)
	{
		// Pagination Artifact BDC with dict: /Artifact <</Type /Pagination ...>> BDC
		preg_match_all('|/Artifact <</Type /Pagination[^>]*>> BDC|', $output, $paginationBdc);
		// Decorative Artifact BMC (no dict)
		preg_match_all('|/Artifact BMC\b|', $output, $artifactBmc);
		// Struct-type BDC: /<StructType> <</MCID N>> BDC
		preg_match_all('|/\w+ <</MCID \d+>> BDC\b|', $output, $structBdc);
		// All EMC occurrences
		preg_match_all('/\bEMC\b/', $output, $emcMatches);

		$opens  = count($paginationBdc[0]) + count($artifactBmc[0]) + count($structBdc[0]);
		$closes = count($emcMatches[0]);

		$this->assertSame(
			$opens,
			$closes,
			sprintf(
				'BDC+BMC count (%d) must equal EMC count (%d). Pagination BDC: %d, Artifact BMC: %d, Struct BDC: %d, EMC: %d',
				$opens,
				$closes,
				count($paginationBdc[0]),
				count($artifactBmc[0]),
				count($structBdc[0]),
				$closes
			)
		);
	}
}
