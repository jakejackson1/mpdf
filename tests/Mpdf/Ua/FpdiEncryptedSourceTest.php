<?php

namespace Mpdf\Ua;

/**
 * Tests for the FPDI encrypted-source detection / fallback path (audit gap L1).
 *
 * Verifies the three-tier classification documented on FpdiStructMerger:
 *   - Tier 0 (encrypted source) — auto mode wraps a placeholder /Artifact pair
 *     and records a warning citing Matterhorn 01-007; strict mode throws
 *     \Mpdf\MpdfException with the same citation.
 *   - Tier 2 (tagged source, sanity check passes) — existing struct-merge path.
 *   - Tier 2 → Tier 1 demotion when a forward-compat sanity check on /Alt
 *     ciphertext fails — auto mode demotes + warns, strict mode throws.
 *
 * Encrypted fixtures are produced inline at set_up() time by generating a
 * tiny mPDF document and re-emitting it via SetProtection() with PDFUA off.
 * The garbage-/Alt fixture is hand-crafted as a minimal tagged PDF with a
 * /Alt value composed of bytes that fail the printable-codepoint gauntlet.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §7.6           — encryption (general)
 *   - ISO 32000-1:2008 §7.6.4         — standard security handler
 *   - ISO 32000-1:2008 §7.6.5         — crypt filters and strings-only encryption
 *   - ISO 32000-1:2008 §7.9.2.2       — text string type (decode rules)
 *   - ISO 32000-1:2008 §14.6          — marked content (BMC/EMC empty content)
 *   - ISO 32000-1:2008 §14.7.4.4 T324 — MCR dict
 *   - ISO 14289-1:2014 §7.1           — real content tagged or marked Artifact
 *   - Matterhorn Protocol 1.1 01-007  — real content not tagged or Artifact
 *
 * @group pdfua
 */
class FpdiEncryptedSourceTest extends PdfUaTestCase
{

	/** @var string|null  path to an inline-generated encrypted PDF; deleted on tear_down() */
	private $encryptedPdf;

	/** @var string|null  path to a hand-crafted tagged PDF with junk-byte /Alt */
	private $garbageAltPdf;

	/** @var string|null  path to an inline-generated clean tagged PDF (forward compat tests) */
	private $cleanTaggedPdf;

	protected function set_up()
	{
		parent::set_up();
		$this->encryptedPdf   = null;
		$this->garbageAltPdf  = null;
		$this->cleanTaggedPdf = null;
	}

	protected function tear_down()
	{
		parent::tear_down();
		foreach (['encryptedPdf', 'garbageAltPdf', 'cleanTaggedPdf'] as $prop) {
			if ($this->{$prop} !== null && file_exists($this->{$prop})) {
				@unlink($this->{$prop});
				$this->{$prop} = null;
			}
		}
	}

	/**
	 * Generate an encrypted PDF on disk via mPDF + SetProtection().
	 *
	 * Built with PDFUA disabled so SetProtection() does not refuse the
	 * 'extract' permission requirement. The output PDF carries an /Encrypt
	 * dictionary in its trailer — exactly the condition vendor/setasign/fpdi
	 * rejects with CrossReferenceException::ENCRYPTED.
	 *
	 * @return string  absolute path to the generated encrypted PDF
	 */
	private function makeEncryptedPdf()
	{
		$src = new \Mpdf\Mpdf(['mode' => 'c']);
		$src->SetProtection(['copy', 'print'], 'user', 'owner', 40);
		$src->WriteHTML('<p>Encrypted source content</p>');
		$tmp = tempnam(sys_get_temp_dir(), 'mpdf_enc_') . '.pdf';
		$src->Output($tmp, 'F');
		return $tmp;
	}

	/**
	 * Generate a clean tagged PDF — used as the Tier 2 control fixture.
	 *
	 * @return string  absolute path
	 */
	private function makeCleanTaggedPdf()
	{
		$src = $this->makeMpdf();
		$src->WriteHTML('<h1>Tagged title</h1><p>Tagged content</p>');
		$tmp = tempnam(sys_get_temp_dir(), 'mpdf_clean_') . '.pdf';
		$src->Output($tmp, 'F');
		return $tmp;
	}

	/**
	 * Hand-craft a tiny tagged PDF whose first /Alt is a 1024-byte run of
	 * 0x01 — bytes that decode (via PDFDocEncoding) to U+FFFD-laden output
	 * which fails the printable-codepoint gauntlet.
	 *
	 * The PDF is intentionally minimal: one page, one struct element with
	 * /S /P and a /Alt PdfHexString of 0x01 repeated 1024 times. Sufficient
	 * for sourceIsTagged() to return true and for the sanity check to fail.
	 *
	 * Note: this is a conformance-corner fixture; it is not itself a valid
	 * PDF/UA-1 document. It exists only to drive verifyAndPrepareMerge()
	 * down its failure path.
	 *
	 * @return string  absolute path
	 */
	private function makeGarbageAltPdf()
	{
		// Build the /Alt as a hex string of 0x01 repeated; PDFDocEncoding 0x01
		// is undefined → U+FFFD. 1024 bytes pushes the suspicious-byte ratio
		// past the 50% threshold by a comfortable margin and stays within the
		// 4 KiB length cap (so it fails on the printable-ratio check, not the
		// length check).
		$altPayload = str_repeat('01', 1024);

		// Object 1: catalog with a StructTreeRoot reference.
		// Object 2: pages
		// Object 3: page
		// Object 4: page contents (empty)
		// Object 5: StructTreeRoot
		// Object 6: StructElem with /S /P and /Alt
		$objs = [];
		$objs[1] = "<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R >>";
		$objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
		$objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << >> >>";
		$objs[4] = "<< /Length 0 >>\nstream\n\nendstream";
		$objs[5] = "<< /Type /StructTreeRoot /K [6 0 R] >>";
		$objs[6] = "<< /Type /StructElem /S /P /P 5 0 R /Alt <" . $altPayload . "> >>";

		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objs as $n => $body) {
			$offsets[$n] = strlen($pdf);
			$pdf .= $n . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xrefPos = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objs) + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ($i = 1; $i <= count($objs); $i++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}
		$pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n" . $xrefPos . "\n%%EOF\n";

		$tmp = tempnam(sys_get_temp_dir(), 'mpdf_garbage_') . '.pdf';
		file_put_contents($tmp, $pdf);
		return $tmp;
	}

	// ========================= Tier 0 — sourceIsEncrypted detection =========================

	/**
	 * sourceIsEncrypted() returns true for a PDF whose trailer carries /Encrypt.
	 *
	 * Live FPDI parsers throw before this method's normal call path (during
	 * setSourceFile()), so we exercise the helper directly via an in-memory
	 * PDF reader bypass: the encrypted source is loaded with PDFUA enabled
	 * but auto mode active, so setSourceFile() returns 1 instead of throwing
	 * and importPage() returns a placeholder. The encrypted-source check then
	 * runs against the underlying parser cache via getSourcePdfReader().
	 *
	 * Because the placeholder pageId carries no readerId, this test instead
	 * verifies that getPdfUaWarnings() captures the encryption diagnostic.
	 */
	public function testEncryptedSourceProducesUaWarningInAutoMode()
	{
		$this->encryptedPdf = $this->makeEncryptedPdf();

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$count = $mpdf->setSourceFile($this->encryptedPdf);

		// Auto-mode setSourceFile() returns synthetic page count = 1 instead of throwing.
		$this->assertSame(1, $count);

		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'encrypted') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue(
			$found,
			'Auto-mode setSourceFile() on an encrypted PDF must record a UA warning citing encryption'
		);
	}

	/**
	 * sourceIsEncrypted() returns false for an unencrypted PDF.
	 */
	public function testSourceIsEncryptedReturnsFalseForCleanPdf()
	{
		$this->cleanTaggedPdf = $this->makeCleanTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->cleanTaggedPdf);
		$mpdf->importPage(1);

		$merger   = $mpdf->getPdfUaFpdiStructMerger();
		$pages    = $mpdf->getImportedPages();
		$readerId = reset($pages)['readerId'];

		$this->assertFalse(
			$merger->sourceIsEncrypted($readerId),
			'Clean PDF source must not be flagged as encrypted'
		);
	}

	// ========================= Tier 0 — auto mode → /Artifact placeholder =========================

	/**
	 * Importing an encrypted PDF in PDFUA + PDFUAauto mode falls back to a Tier 0
	 * Artifact placeholder. The output must contain an /Artifact <</Type /Layout>> BDC ... EMC
	 * pair on the host page, and the call must not raise.
	 */
	public function testEncryptedImportAutoModeFallsBackToArtifact()
	{
		$this->encryptedPdf = $this->makeEncryptedPdf();

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->setSourceFile($this->encryptedPdf);
		$pageId = $mpdf->importPage(1);

		// importPage() must return a synthetic placeholder pageId.
		$this->assertStringContainsString(
			\Mpdf\Ua\Import\FpdiStructMerger::ENCRYPTED_PAGE_PLACEHOLDER_ID_PREFIX,
			$pageId,
			'Auto-mode importPage() on encrypted source must return a placeholder pageId'
		);
		$this->assertTrue(
			$mpdf->isEncryptedPlaceholder($pageId),
			'isEncryptedPlaceholder() must recognise the synthetic pageId'
		);

		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId, 0, 0, 100, 50);
		$output = $mpdf->Output(null, 'S');

		$this->assertStringContainsString(
			'/Artifact <</Type /Layout>> BDC',
			$output,
			'Tier 0 placeholder must emit /Artifact <</Type /Layout>> BDC on the page'
		);
		$this->assertStringContainsString('EMC', $output);

		$warnings = $mpdf->getPdfUaWarnings();
		$encMessages = 0;
		foreach ($warnings as $w) {
			if (stripos($w, 'encrypted') !== false) {
				$encMessages++;
			}
		}
		$this->assertGreaterThanOrEqual(
			1,
			$encMessages,
			'getPdfUaWarnings() must contain at least one encrypted-source warning after Tier 0 fallback'
		);
	}

	// ========================= Tier 0 — strict mode throws =========================

	/**
	 * Importing an encrypted PDF in strict PDFUA mode throws \Mpdf\MpdfException.
	 *
	 * The throw happens at setSourceFile() time because the FPDI parser hits
	 * the encryption check during cross-reference loading. The message must
	 * cite ISO 32000-1 §7.6 and Matterhorn 01-007 so callers know exactly
	 * what to fix.
	 */
	public function testEncryptedImportStrictModeThrowsAtSetSourceFile()
	{
		$this->encryptedPdf = $this->makeEncryptedPdf();

		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);

		try {
			$mpdf->setSourceFile($this->encryptedPdf);
			$this->fail('setSourceFile() on encrypted source in strict mode must throw');
		} catch (\Mpdf\MpdfException $e) {
			$this->assertStringContainsString('encrypted', $e->getMessage());
			$this->assertStringContainsString('Matterhorn 01-007', $e->getMessage());
			$this->assertStringContainsString('7.6', $e->getMessage());
		}
	}

	// ========================= Tier 2 — string sanity check =========================

	/**
	 * Clean tagged sources pass verifyAndPrepareMerge() and produce the existing
	 * Tier 2 struct merge — regression guard so the new gate does not break the
	 * happy path.
	 */
	public function testStringSanityCheckPassesForCleanTaggedSource()
	{
		$this->cleanTaggedPdf = $this->makeCleanTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->cleanTaggedPdf);
		$pageId = $mpdf->importPage(1);

		$merger = $mpdf->getPdfUaFpdiStructMerger();
		$this->assertTrue(
			$merger->verifyAndPrepareMerge($pageId),
			'Clean tagged source must pass the string sanity gauntlet'
		);
		$this->assertFalse(
			$merger->wasSanityCheckFailed($pageId),
			'wasSanityCheckFailed() must be false after a successful verify'
		);
	}

	/**
	 * A tagged source whose /Alt is junk bytes (PDFDocEncoding 0x01 → U+FFFD)
	 * fails the sanity gauntlet and demotes the page from Tier 2 to Tier 1
	 * in auto mode. The output must therefore contain the Artifact wrap
	 * (Tier 1 fallback) and the warnings must include the sanity-failure
	 * citation.
	 */
	public function testStringSanityCheckDemotesOnGarbageAltAutoMode()
	{
		$this->garbageAltPdf = $this->makeGarbageAltPdf();

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->setSourceFile($this->garbageAltPdf);
		$pageId = $mpdf->importPage(1);

		$merger = $mpdf->getPdfUaFpdiStructMerger();

		$this->assertTrue(
			$merger->sourceIsTagged($mpdf->getImportedPages()[$pageId]['readerId']),
			'Synthetic fixture must report as tagged so the demotion path is exercised'
		);

		$this->assertFalse(
			$merger->verifyAndPrepareMerge($pageId),
			'Garbage /Alt source must fail verifyAndPrepareMerge() in auto mode'
		);
		$this->assertTrue(
			$merger->wasSanityCheckFailed($pageId),
			'wasSanityCheckFailed() must be true after the demotion'
		);

		// Render and assert the demoted Tier 1 Artifact wrap appears.
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$output = $mpdf->Output(null, 'S');
		$this->assertStringContainsString(
			'/Artifact <</Type /Layout>> BDC',
			$output,
			'Demoted Tier 2 → Tier 1 page must emit the Artifact wrap'
		);

		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'sanity') !== false || stripos($w, 'printable-codepoint') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue(
			$found,
			'Demotion warning must mention the sanity-gauntlet failure'
		);
	}

	/**
	 * Strict mode throws \Mpdf\MpdfException instead of demoting when the
	 * sanity check fails — same payload as the auto-mode test but the throw
	 * is asserted via try/catch.
	 */
	public function testStringSanityCheckDemotesOnGarbageAltStrictMode()
	{
		$this->garbageAltPdf = $this->makeGarbageAltPdf();

		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);
		$mpdf->setSourceFile($this->garbageAltPdf);
		$pageId = $mpdf->importPage(1);

		$merger = $mpdf->getPdfUaFpdiStructMerger();

		try {
			$merger->verifyAndPrepareMerge($pageId);
			$this->fail('verifyAndPrepareMerge() must throw in strict mode for garbage /Alt');
		} catch (\Mpdf\MpdfException $e) {
			$this->assertStringContainsString('§7.6.5', $e->getMessage());
			$this->assertStringContainsString('Matterhorn 01-007', $e->getMessage());
		}
	}

	// ========================= Regression: non-PDFUA path is unchanged =========================

	/**
	 * Non-PDFUA callers must still receive a raw CrossReferenceException for an
	 * encrypted source — the new wrapper is gated on $this->PDFUA so legacy
	 * users see no behaviour change.
	 */
	public function testNonPdfUaCallerStillReceivesCrossReferenceException()
	{
		$this->encryptedPdf = $this->makeEncryptedPdf();

		$mpdf = new \Mpdf\Mpdf(['mode' => 'c']);

		try {
			$mpdf->setSourceFile($this->encryptedPdf);
			$this->fail('Non-PDFUA setSourceFile() on encrypted source must still throw');
		} catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
			$this->assertSame(
				\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException::ENCRYPTED,
				$e->getCode(),
				'Underlying FPDI exception code must be ENCRYPTED'
			);
		}
	}
}
