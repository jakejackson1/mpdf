<?php

namespace Mpdf\Ua;

/**
 * Phase 1 PDF/UA-1 metadata and catalog tests.
 *
 * Verifies that the document-level requirements of ISO 14289-1:2014 are met by
 * the Phase 1 implementation: XMP identifier, MarkInfo, /Lang, ViewerPreferences,
 * /StructParents, /Tabs /S, font embedding enforcement, and PDF version header.
 *
 * All test methods that use PDFUA mode construct Mpdf with embedded TrueType
 * fonts (no mode='c'). See PdfUaTestCase::makeMpdf() for rationale.
 *
 * Spec references:
 *   - ISO 14289-1:2014 §6.2 — pdfuaid:part XMP identifier
 *   - ISO 14289-1:2014 §7.1 — document title, MarkInfo, ViewerPreferences, pdf version
 *   - ISO 14289-1:2014 §7.2 — /Lang catalog entry
 *   - ISO 14289-1:2014 §7.21 — font embedding
 *   - ISO 32000-1:2008 §14.7.4.4 — /StructParents and /Tabs /S per page
 *   - Matterhorn Protocol 1.1 conditions: 01-003, 04-001, 06-001, 06-003, 14-002, 28-001, 28-002
 *
 * @group pdfua
 */
class MetadataTest extends PdfUaTestCase
{

	/**
	 * ISO 14289-1:2014 §6.2 — the XMP metadata stream must contain the pdfuaid:part
	 * identifier with value 1 when PDFUA mode is active.
	 */
	public function testXmpContainsPdfuaidPart()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>Hello PDF/UA</p>');
		$this->assertStringContainsString('<pdfuaid:part>1</pdfuaid:part>', $output);
	}

	/**
	 * ISO 14289-1:2014 §7.1 (Matterhorn 01-003) — /MarkInfo with Marked=true must
	 * appear in the document catalog when PDFUA is active.
	 */
	public function testCatalogContainsMarkInfo()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>Hello PDF/UA</p>');
		$this->assertStringContainsString('/MarkInfo <</Marked true /Suspects false>>', $output);
	}

	/**
	 * ISO 32000-1:2008 §14.3.2 — the document catalog must reference the XMP
	 * metadata stream via /Metadata when PDFUA is active.
	 */
	public function testCatalogContainsMetadataRef()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>Hello PDF/UA</p>');
		$this->assertMatchesRegularExpression('/\/Metadata \d+ 0 R/', $output);
	}

	/**
	 * ISO 14289-1:2014 §7.1 (Matterhorn 06-001) — /ViewerPreferences /DisplayDocTitle
	 * must be true so viewers show the document title rather than the filename.
	 */
	public function testViewerPreferencesDisplayDocTitle()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>Hello PDF/UA</p>');
		$this->assertStringContainsString('/DisplayDocTitle true', $output);
	}

	/**
	 * ISO 14289-1:2014 §7.1 (Matterhorn 06-003) — PDF/UA-1 strict mode must throw
	 * MpdfException when the document title is empty at output time.
	 *
	 * Construction note: constructs Mpdf directly without makeMpdf() (which pre-sets
	 * 'title') and without calling SetTitle(), so $mpdf->title remains the empty
	 * ConfigVariables default.
	 */
	public function testThrowsWhenTitleMissing()
	{
		$this->expectException(\Mpdf\MpdfException::class);
		// Provide 'mode' so currentLang is set (avoiding the lang-missing exception)
		// and omit 'title' so the title-missing exception fires.
		// useActiveForms=true keeps the constructor happy so the title-missing
		// exception is what actually fires at output time (not the form guard).
		$mpdf = new \Mpdf\Mpdf(['PDFUA' => true, 'PDFUAauto' => false, 'mode' => 'en-GB', 'useActiveForms' => true]);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<p>no title</p>');
		$mpdf->Output(null, 'S');
	}

	/**
	 * ISO 14289-1:2014 §7.1 (Matterhorn 06-003) — PDFUAauto=true must record a
	 * warning (not throw) when the document title is empty, and must still produce output.
	 *
	 * Construction note: same direct-construction approach as testThrowsWhenTitleMissing.
	 * The internal $ua field is accessed via the warnings accumulated on UaState.
	 */
	public function testWarnsWhenTitleMissingWithAuto()
	{
		// Provide 'mode' so currentLang is set; omit 'title' so addWarning() fires.
		$mpdf = new \Mpdf\Mpdf(['PDFUA' => true, 'PDFUAauto' => true, 'mode' => 'en-GB', 'useActiveForms' => true]);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<p>no title auto</p>');
		$output = $mpdf->Output(null, 'S');

		// Output must be produced (no exception).
		$this->assertNotEmpty($output);

		// The XMP block must still be written (auto mode does not skip XMP).
		$this->assertStringContainsString('<pdfuaid:part>1</pdfuaid:part>', $output);

		// At least one warning must have been recorded via addWarning().
		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertNotEmpty($warnings);
	}

	/**
	 * ISO 14289-1:2014 §7.21 (Matterhorn 14-002) — core Type 1 fonts cannot be
	 * embedded and must be rejected when PDFUA is active.
	 *
	 * Construction with mode='c' succeeds; the check fires in FontWriter::writeFonts()
	 * at output time, not at construction time.
	 */
	public function testCoreFontsNotAllowed()
	{
		$this->expectException(\Mpdf\MpdfException::class);
		$mpdf = new \Mpdf\Mpdf(['PDFUA' => true, 'mode' => 'c', 'title' => 'Core Font Test', 'useActiveForms' => true]);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<p>x</p>');
		$mpdf->Output(null, 'S');
	}

	/**
	 * ISO 32000-1:2008 §14.7.4.4 (Matterhorn 28-001) — /Tabs /S must appear in
	 * every page dict, not just annotated pages, so structure order governs tab order.
	 *
	 * Tests three pages to confirm the emission is inside the per-page loop.
	 */
	public function testTabsSOnEveryPage()
	{
		$mpdf = $this->makeMpdf();
		$html = '<p>Page one</p><pagebreak /><p>Page two</p><pagebreak /><p>Page three</p>';
		$output = $this->getOutput($mpdf, $html);
		// All three page dicts must carry /Tabs /S.
		$tabsCount = substr_count($output, '/Tabs /S');
		$this->assertGreaterThanOrEqual(3, $tabsCount);
	}

	/**
	 * ISO 32000-1:2008 §14.7.4.4 (Matterhorn 28-002) — /StructParents integer key
	 * must appear in every page dict when the document has a StructTreeRoot.
	 *
	 * Tests three pages to confirm the counter increments per page.
	 */
	public function testStructParentsOnEveryPage()
	{
		$mpdf = $this->makeMpdf();
		$html = '<p>Page one</p><pagebreak /><p>Page two</p><pagebreak /><p>Page three</p>';
		$output = $this->getOutput($mpdf, $html);
		// Each page must carry a distinct, sequential /StructParents key.
		$this->assertMatchesRegularExpression('/\/StructParents 0\b/', $output);
		$this->assertMatchesRegularExpression('/\/StructParents 1\b/', $output);
		$this->assertMatchesRegularExpression('/\/StructParents 2\b/', $output);
	}

	/**
	 * ISO 14289-1:2014 §6 — PDF/UA-1 is defined on the PDF 1.7 base specification.
	 * The PDF header must read %PDF-1.7 when PDFUA is active.
	 */
	public function testPdfVersionForcedTo17()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>version test</p>');
		$this->assertStringContainsString('%PDF-1.7', $output);
	}

	/**
	 * ISO 14289-1:2014 §7.2 (Matterhorn 04-001) — the document catalog must contain
	 * a /Lang entry when PDFUA is active and a language mode is supplied.
	 */
	public function testCatalogContainsLang()
	{
		$mpdf = $this->makeMpdf(['mode' => 'en-GB']);
		$output = $this->getOutput($mpdf, '<p>language test</p>');
		$this->assertMatchesRegularExpression('/\/Lang \(\S+\)/', $output);
	}

	/**
	 * ISO 14289-1:2014 §7.2 (Matterhorn 04-001) — strict mode (PDFUAauto=false) must
	 * throw MpdfException when neither currentLang nor default_lang is set.
	 *
	 * Construction note: 'currentLang' and 'default_lang' are only populated inside
	 * the mode-processing block (Mpdf.php ~lines 1412-1413) and remain empty strings
	 * when no mode argument is supplied. Do NOT pass 'currentLang'=>'' in config —
	 * it is not a ConfigVariables key and would be silently dropped.
	 */
	public function testThrowsWhenLangMissingStrict()
	{
		$this->expectException(\Mpdf\MpdfException::class);
		$mpdf = new \Mpdf\Mpdf(['PDFUA' => true, 'PDFUAauto' => false, 'title' => 'Lang Test', 'useActiveForms' => true]);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<p>no lang</p>');
		$mpdf->Output(null, 'S');
	}

	/**
	 * Verify PDFUA=false (the default) does NOT emit /MarkInfo in the catalog.
	 * This confirms the PDFUA gate is conditional and does not activate globally.
	 */
	public function testPdfuaFalseByDefaultNoMarkInfo()
	{
		$mpdf = new \Mpdf\Mpdf(['mode' => 'c']); // core fonts fine when PDFUA=false
		$mpdf->compress = false;
		$mpdf->WriteHTML('<p>no pdfua</p>');
		$output = $mpdf->Output(null, 'S');
		$this->assertStringNotContainsString('/MarkInfo', $output);
	}

	/**
	 * Verify that PDFUA and PDFA can coexist in the same document.
	 *
	 * Both pdfuaid:part and pdfaid:part XMP elements must appear. Confirms the
	 * PDFUA block is a standalone `if`, not an `elseif` chained to the PDFA block.
	 *
	 * ISO 14289-1:2014 §6 allows PDFUA to be layered on PDF/A-1b, PDF/A-3, or PDF/X.
	 */
	public function testPdfuaPdfaCoexistenceXmp()
	{
		$mpdf = $this->makeMpdf(['PDFA' => true, 'PDFAauto' => true]);
		$output = $this->getOutput($mpdf, '<p>coexistence test</p>');
		$this->assertStringContainsString('<pdfuaid:part>1</pdfuaid:part>', $output);
		$this->assertStringContainsString('<pdfaid:part>', $output);
	}

	// ================== Encryption + XMP tests ==================

	/**
	 * An encrypted PDF/UA-1 document must keep its XMP metadata stream unencrypted.
	 *
	 * ISO 32000-1:2008 §14.3.2 — "The XMP data stream shall not be encrypted."
	 * ISO 32000-1:2008 §7.6.5 — the Identity crypt filter is the correct mechanism:
	 * /Filter [/Crypt] /DecodeParms <</Type /CryptFilterDecodeParms /Name /Identity>>
	 * on the metadata stream dict tells conforming readers to pass the bytes through
	 * without applying the document encryption.
	 *
	 * Plan §A5 (encryption audit) and §A9 (priority test list):
	 * testEncryptedOutputHasXmpNotEncrypted.
	 *
	 * Both invariants must hold simultaneously:
	 *   1. The metadata stream dict carries the Identity crypt filter declaration.
	 *   2. The XMP namespace identifier (pdfuaid:part) is readable as plaintext.
	 *
	 * @group pdfua
	 */
	public function testEncryptedOutputHasXmpNotEncrypted()
	{
		// PDFUAauto=true so SetProtection() auto-adds 'extract' permission without
		// throwing, keeping the test focused on the XMP encryption behaviour.
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->SetProtection(['extract'], 'user', 'owner_pass');
		$output = $this->getOutput($mpdf, '<p>Encrypted PDF/UA-1 test</p>');

		// Invariant 1: the metadata stream dict must declare the Identity crypt filter.
		// MetadataWriter::writeMetadata() emits this when PDFUA && encrypted.
		$this->assertStringContainsString(
			'/Filter[/Crypt]',
			$output,
			'Metadata stream dict must carry /Filter [/Crypt] when document is encrypted'
		);
		$this->assertStringContainsString(
			'/Name/Identity',
			$output,
			'Metadata stream dict must carry /Name /Identity to bypass document encryption'
		);

		// Invariant 2: XMP pdfuaid namespace must be readable as plaintext.
		// If the stream were RC4-encrypted, 'pdfuaid:part' would appear as binary noise.
		$this->assertStringContainsString(
			'pdfuaid:part',
			$output,
			'XMP pdfuaid:part must be plaintext in the raw PDF output (not RC4-encrypted)'
		);
	}

	// ================== Phase 2 tests ==================

	/**
	 * ISO 32000-1:2008 §14.7.2 Table 322 — the document catalog must reference
	 * the StructTreeRoot object via /StructTreeRoot N 0 R when PDFUA is active.
	 *
	 * Phase 2 wires StructureWriter::writeStructTree() via ResourceWriter;
	 * the returned object number is stored on UaState and emitted in the catalog.
	 */
	public function testStructTreeRootInCatalog()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>struct tree test</p>');
		$this->assertMatchesRegularExpression('/\/StructTreeRoot \d+ 0 R/', $output);
	}

	/**
	 * ISO 32000-1:2008 §14.7.2 Table 322 — a /Type /StructTreeRoot object must
	 * exist in the PDF output when PDFUA is active.
	 *
	 * Phase 2 StructureWriter emits the StructTreeRoot dict; this test confirms
	 * the object is present in the output byte stream.
	 */
	public function testStructTreeRootObjectExists()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<p>struct tree object test</p>');
		$this->assertStringContainsString('/Type /StructTreeRoot', $output);
	}
}
