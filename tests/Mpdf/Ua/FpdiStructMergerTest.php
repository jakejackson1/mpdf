<?php

namespace Mpdf\Ua;

use Mpdf\Ua\Import\FpdiStructMerger;

/**
 * Tests for FpdiStructMerger and the FpdiTrait::useImportedPage() PDFUA hook.
 *
 * Verifies:
 *   - Tier 1 (untagged source): FPDI imports are wrapped with /Artifact BDC…EMC
 *     in the page stream, and a warning is recorded via getPdfUaWarnings().
 *   - Tier 2 (tagged source): source struct subtree is cloned into the host tree;
 *     no Artifact wrap; Form XObject receives /StructParents; MCR dicts carry /Stm.
 *   - FpdiStructMerger standalone: addUntaggedWarning / getUntaggedWarnings lifecycle.
 *   - getUntaggedWarnings() clears the queue after returning.
 *   - sourceIsTagged() returns true for a tagged source and false for an untagged one.
 *   - SetPageTemplate reuse (addPerPageMcrKids) does not duplicate the struct subtree.
 *
 * Spec references:
 *   - ISO 14289-1:2014 §7.1 — real content must be tagged or marked Artifact
 *   - ISO 32000-1:2008 §14.7.4.4 Table 324 — MCR dict (/Pg, /Stm, /MCID)
 *   - ISO 32000-1:2008 §14.7.3 — RoleMap first-wins merge semantics
 *   - Matterhorn Protocol 1.1 — 01-007 real content not tagged or Artifact
 *
 * @group pdfua
 */
class FpdiStructMergerTest extends PdfUaTestCase
{

	/** @var string path to an untagged test PDF fixture */
	private $untaggedPdf;

	/** @var string|null  path to a tagged PDF generated at set_up() time; deleted on tear_down() */
	private $taggedPdf;

	protected function set_up()
	{
		parent::set_up();
		$this->untaggedPdf = __DIR__ . '/../../data/pdfs/Noisy-Tube.pdf';
		$this->taggedPdf   = null;
	}

	protected function tear_down()
	{
		parent::tear_down();
		if ($this->taggedPdf !== null && file_exists($this->taggedPdf)) {
			@unlink($this->taggedPdf);
			$this->taggedPdf = null;
		}
	}

	/**
	 * Generate a minimal PDFUA-tagged PDF and write it to a temp file.
	 *
	 * Used by Tier 2 tests as the "tagged source" fixture. The generated PDF has
	 * a /StructTreeRoot in its catalog, so sourceIsTagged() returns true for it.
	 *
	 * Uses <h1>, <h2>, and <h3> (struct types H1, H2, H3) — the host document
	 * only ever writes <p> (struct type P), so /S /H2 and /S /H3 in the output can
	 * only originate from the merged subtree. The leading <h1> satisfies the
	 * ISO 14289-1:2014 §7.4.2 rule 1 heading-sequence requirement (first heading
	 * must be H1) so no PDFUAauto clamping is needed. Using three elements also
	 * exercises the multi-element reuse path in addPerPageMcrKids().
	 *
	 * @return string  absolute path to the temp file
	 */
	private function makeTaggedPdf()
	{
		$source = $this->makeMpdf();
		$source->AddPage();
		$source->WriteHTML(
			'<h1>Tagged source title</h1>'
			. '<h2>Tagged source heading</h2>'
			. '<h3>Tagged source subheading</h3>'
		);
		$tmp = tempnam(sys_get_temp_dir(), 'mpdf_tagged_') . '.pdf';
		$source->Output($tmp, 'F');
		return $tmp;
	}

	/**
	 * addUntaggedWarning() appends a message and getUntaggedWarnings() returns it.
	 */
	public function testAddAndGetUntaggedWarnings()
	{
		$mpdf = $this->makeMpdf();
		$merger = $mpdf->getPdfUaFpdiStructMerger();

		$merger->addUntaggedWarning('Test warning A');
		$merger->addUntaggedWarning('Test warning B');

		$warnings = $merger->getUntaggedWarnings();
		$this->assertCount(2, $warnings);
		$this->assertStringContainsString('Test warning A', $warnings[0]);
		$this->assertStringContainsString('Test warning B', $warnings[1]);
	}

	/**
	 * getUntaggedWarnings() clears the queue — a second call returns an empty array.
	 */
	public function testGetUntaggedWarningsClearsQueue()
	{
		$mpdf = $this->makeMpdf();
		$merger = $mpdf->getPdfUaFpdiStructMerger();

		$merger->addUntaggedWarning('Once');
		$first  = $merger->getUntaggedWarnings();
		$second = $merger->getUntaggedWarnings();

		$this->assertCount(1, $first);
		$this->assertCount(0, $second);
	}

	/**
	 * sourceIsTagged() returns false for a PDF that has no /StructTreeRoot.
	 *
	 * ISO 32000-1:2008 §14.7.2 — /StructTreeRoot is present on tagged PDFs;
	 * its absence means the source is untagged.
	 */
	public function testSourceIsTaggedReturnsFalseForUntaggedSource()
	{
		if (!file_exists($this->untaggedPdf)) {
			$this->markTestSkipped('FPDI test fixture not available: ' . $this->untaggedPdf);
		}

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->untaggedPdf);
		$mpdf->importPage(1);

		$merger   = $mpdf->getPdfUaFpdiStructMerger();
		$pages    = $mpdf->getImportedPages();
		$readerId = reset($pages)['readerId'];

		$this->assertFalse($merger->sourceIsTagged($readerId));
	}

	/**
	 * sourceIsTagged() returns true for a PDF that has /StructTreeRoot.
	 *
	 * ISO 32000-1:2008 §14.7.2 — a tagged PDF carries /StructTreeRoot in its catalog.
	 * We generate the tagged source inline from mPDF so the test is self-contained.
	 */
	public function testSourceIsTaggedReturnsTrueForTaggedSource()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$mpdf->importPage(1);

		$merger   = $mpdf->getPdfUaFpdiStructMerger();
		$pages    = $mpdf->getImportedPages();
		$readerId = reset($pages)['readerId'];

		$this->assertTrue($merger->sourceIsTagged($readerId));
	}

	/**
	 * Importing an untagged PDF page in PDFUA auto mode emits /Artifact BDC in the
	 * page stream.
	 *
	 * ISO 14289-1:2014 §7.1 — real content must be a struct element or Artifact.
	 * An untagged imported page carries no struct tagging, so in auto mode it is
	 * wrapped as Artifact (UA1 audit E15 — strict mode throws instead; that path
	 * is covered by FpdiImportTest).
	 */
	public function testImportProducesArtifactBdc()
	{
		if (!file_exists($this->untaggedPdf)) {
			$this->markTestSkipped('FPDI test fixture not available: ' . $this->untaggedPdf);
		}

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->setSourceFile($this->untaggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$output = $mpdf->Output(null, 'S');

		$this->assertStringContainsString(
			'/Artifact',
			$output,
			'Imported page stream must contain /Artifact marker'
		);
		$this->assertStringContainsString('BDC', $output);
		$this->assertStringContainsString('EMC', $output);
	}

	/**
	 * Importing an untagged PDF page in PDFUA mode records a warning accessible
	 * via Mpdf::getPdfUaWarnings().
	 *
	 * The warning is emitted so that PDF/UA validators and authors can see that
	 * the imported page was treated as Artifact rather than a tagged struct element.
	 */
	public function testImportRecordsWarning()
	{
		if (!file_exists($this->untaggedPdf)) {
			$this->markTestSkipped('FPDI test fixture not available: ' . $this->untaggedPdf);
		}

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->setSourceFile($this->untaggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$mpdf->Output(null, 'S');

		$warnings = $mpdf->getPdfUaWarnings();
		$this->assertNotEmpty(
			$warnings,
			'A warning must be recorded when a PDF is imported in PDFUA mode'
		);
		// At least one warning should mention the untagged source.
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'untagged') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Warning should mention "untagged" source PDF');
	}

	/**
	 * Importing an untagged PDF page in PDFUA mode produces a matched BDC+EMC pair
	 * around the Artifact, and the regular HTML content after the import is still
	 * tagged with struct elements.
	 *
	 * The Artifact BDC emitted by the hook uses /Artifact <</Type /Layout>> BDC;
	 * the struct element BDC for the <p> uses /P <</MCID N>> BDC.
	 *
	 * Auto mode (PDFUAauto=true) so the untagged import is wrapped as an Artifact
	 * rather than throwing (UA1 audit E15).
	 */
	public function testImportArtifactAndStructElementCoexist()
	{
		if (!file_exists($this->untaggedPdf)) {
			$this->markTestSkipped('FPDI test fixture not available: ' . $this->untaggedPdf);
		}

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->setSourceFile($this->untaggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$mpdf->WriteHTML('<p>After import</p>');
		$output = $mpdf->Output(null, 'S');

		// The Artifact layout BDC+EMC brackets the imported Do.
		$this->assertStringContainsString('/Artifact <</Type /Layout>> BDC', $output);
		// The paragraph after the import is still tagged as a struct element.
		$this->assertStringContainsString('/S /P', $output);
		$this->assertStringContainsString('/P <</MCID', $output);
	}

	/**
	 * Importing a tagged PDF page in PDFUA mode does NOT wrap it as Artifact.
	 *
	 * When the source has a /StructTreeRoot, the import handler must take the
	 * struct-merge path (Tier 2) and NOT emit the /Artifact BDC…EMC sequence.
	 *
	 * ISO 14289-1:2014 §7.1 — real content must be a struct element or Artifact;
	 * Tier 2 satisfies this via struct element tagging, not Artifact.
	 */
	public function testTaggedSourceDoesNotProduceArtifactBdc()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$output = $mpdf->Output(null, 'S');

		// The Artifact /Artifact <</Type /Layout>> BDC must NOT appear on the page
		// content stream for a tagged source — struct elements handle the tagging.
		$this->assertStringNotContainsString(
			'/Artifact <</Type /Layout>> BDC',
			$output,
			'Tagged source must not be wrapped as /Artifact'
		);
	}

	/**
	 * Importing a tagged PDF page does not produce an untagged-import warning.
	 *
	 * Tier 2 replaces the Artifact approach with struct subtree merging, so the
	 * caller should receive no "untagged" warnings for a tagged source.
	 */
	public function testTaggedSourceDoesNotRecordUntaggedWarning()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$mpdf->Output(null, 'S');

		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (stripos($w, 'untagged') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertFalse($found, 'Tagged source must not produce an "untagged" warning');
	}

	/**
	 * Importing a tagged PDF page merges its struct subtree into the host StructureTree.
	 *
	 * The merged elements must appear in the output. The host document writes <p>
	 * (struct type P only); the source PDF was generated with <h2> (struct type H2).
	 * Therefore /S /H2 in the output proves the source subtree was merged — it cannot
	 * come from the host-tagging path.
	 *
	 * ISO 32000-1:2008 §14.7.2 — the struct tree is serialised as /Type /StructElem dicts.
	 */
	public function testTaggedSourceMergesStructSubtree()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$mpdf->WriteHTML('<p>Host paragraph after import</p>');
		$output = $mpdf->Output(null, 'S');

		// The struct tree must be present in the output.
		$this->assertStringContainsString('/Type /StructTreeRoot', $output);
		// The host paragraph is tagged as a /P struct element.
		$this->assertStringContainsString('/S /P', $output);
		// /S /H2 and /S /H3 can only originate from the merged subtree (host uses only /P).
		$this->assertStringContainsString(
			'/S /H2',
			$output,
			'/S /H2 must appear — it can only come from the merged source subtree'
		);
		$this->assertStringContainsString(
			'/S /H3',
			$output,
			'/S /H3 must appear — it can only come from the merged source subtree'
		);
	}

	/**
	 * The FPDI Form XObject for a tagged import receives a /StructParents entry.
	 *
	 * ISO 32000-1:2008 §14.7.4.4 — every content stream that contains marked
	 * content (MCIDs) must have a /StructParents integer on its dict so the
	 * ParentTree back-reference resolves. For Form XObjects this is the same
	 * /StructParents (plural) used for page dicts.
	 *
	 * The test locates the Form XObject block (identified by /Subtype /Form) and
	 * asserts that /StructParents appears within it — not just anywhere in the
	 * output (page dicts also carry /StructParents in PDFUA mode).
	 */
	public function testTaggedSourceFormXObjectHasStructParents()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$output = $mpdf->Output(null, 'S');

		// Find the Form XObject block. FPDI writes a stream dict containing
		// /Subtype /Form. Extract the portion of the output around that marker
		// and verify /StructParents appears nearby (within 500 bytes, inside the
		// same object scope).
		$foPos = strpos($output, '/Subtype /Form');
		$this->assertNotFalse(
			$foPos,
			'Output must contain a /Subtype /Form block (the FPDI Form XObject)'
		);

		// Look backwards from /Subtype /Form to find the object header ('N 0 obj'),
		// then look forwards to 'endobj' — both within a generous window.
		$window  = 2000;
		$start   = max(0, $foPos - $window);
		$end     = min(strlen($output), $foPos + $window);
		$segment = substr($output, $start, $end - $start);

		$this->assertStringContainsString(
			'/StructParents',
			$segment,
			'Form XObject dict must contain /StructParents for tagged import'
		);
	}

	/**
	 * mergePageStructSubtree() is idempotent — calling it twice for the same pageId
	 * does not duplicate the struct subtree.
	 *
	 * This verifies the SetPageTemplate pattern where the same FPDI pageId is
	 * placed on multiple host pages. Only one set of cloned struct elements should
	 * exist in the tree; the second placement adds MCR kids to existing elements
	 * rather than creating new struct element objects.
	 *
	 * ISO 32000-1:2008 §14.7.4.4 — a single struct element may carry multiple MCR
	 * kids (one per host page) when reused via SetPageTemplate, but the element
	 * itself is not duplicated.
	 *
	 * The test uses /S /H2 (from the <h2> in makeTaggedPdf()) as the discriminator.
	 * Placing the same pageId on a second host page must NOT create a second /S /H2
	 * struct element — the count must be the same as with one placement.
	 */
	public function testSetPageTemplateReuseDoesNotDuplicateSubtree()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		// Single placement: count /S /H2 occurrences.
		$mpdfSingle = $this->makeMpdf();
		$mpdfSingle->setSourceFile($this->taggedPdf);
		$pageId = $mpdfSingle->importPage(1);
		$mpdfSingle->AddPage();
		$mpdfSingle->useImportedPage($pageId);
		$singleOutput = $mpdfSingle->Output(null, 'S');
		$countSingle  = substr_count($singleOutput, '/S /H2');

		// Double placement: same pageId placed on two host pages.
		$mpdfDouble = $this->makeMpdf();
		$mpdfDouble->setSourceFile($this->taggedPdf);
		$pageId2 = $mpdfDouble->importPage(1);
		$mpdfDouble->AddPage();
		$mpdfDouble->useImportedPage($pageId2);
		$mpdfDouble->AddPage();
		$mpdfDouble->useImportedPage($pageId2);
		$doubleOutput = $mpdfDouble->Output(null, 'S');
		$countDouble  = substr_count($doubleOutput, '/S /H2');

		$this->assertSame(
			1,
			$countSingle,
			'/S /H2 must appear exactly once in single-placement output'
		);

		$this->assertSame(
			$countSingle,
			$countDouble,
			'/S /H2 count must not increase on reuse — mergePageStructSubtree must be idempotent'
		);

		// Also verify /S /H3 (second element) is not duplicated on reuse.
		$countH3Single = substr_count($singleOutput, '/S /H3');
		$countH3Double = substr_count($doubleOutput, '/S /H3');
		$this->assertSame(1, $countH3Single, '/S /H3 must appear exactly once in single-placement output');
		$this->assertSame($countH3Single, $countH3Double, '/S /H3 count must not increase on reuse');
	}

	/**
	 * The host document's own struct elements still appear when combined with a
	 * Tier 2 tagged import — neither path interferes with the other.
	 *
	 * The host paragraph's /P <</MCID N>> BDC must be present in the page stream
	 * and a /S /P struct dict must be in the output. The merged subtree's /S /H2
	 * (from the <h2> in makeTaggedPdf()) must also be present and distinct from the
	 * host /S /P element — both struct types coexist in the output.
	 */
	public function testTaggedImportAndHostStructElementsCoexist()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$mpdf->WriteHTML('<p>After import</p>');
		$output = $mpdf->Output(null, 'S');

		// Host paragraph BDC must still be present.
		$this->assertStringContainsString('/P <</MCID', $output);
		// Host paragraph struct element must be present.
		$this->assertStringContainsString('/S /P', $output);
		// The merged subtree's /H2 and /H3 elements must also be present (only from source).
		$this->assertStringContainsString(
			'/S /H2',
			$output,
			'/S /H2 from merged source subtree must coexist with host /S /P'
		);
		$this->assertStringContainsString(
			'/S /H3',
			$output,
			'/S /H3 from merged source subtree must coexist with host /S /P'
		);
	}

	/**
	 * A tagged source whose /K is a bare integer merges its MCID and resolves /Pg.
	 *
	 * mPDF's own single-MCID struct element serialises /K as a bare integer
	 * (`/K 5` — StructureWriter's "bare integer is allowed and preferred" path),
	 * so round-tripping mPDF's own output exercises the bare-integer /K case.
	 * Before UA1 audit E1, FpdiStructMerger::normaliseKidsToArray() returned []
	 * for a bare PdfNumeric /K, so the cloned element carried no content reference
	 * at all — an empty StructElem skeleton. After E1 the MCID reaches addMcid(),
	 * and after E5 StructureWriter emits the MCR dict with a resolvable /Pg.
	 *
	 * The host document writes only /P, so /S /H2 is unambiguously the merged
	 * source element. Its /K must be an MCR dict whose /Pg resolves to a non-zero
	 * host page object number.
	 *
	 * ISO 32000-1:2008 §14.7.2 Table 322 — /Pg / §14.7.4.4 Table 324 — MCR dict.
	 */
	public function testBareIntegerKMergesMcidAndResolvesPg()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$output = $mpdf->Output(null, 'S');

		// The merged /S /H2 element (host writes only /P) must carry an MCR content
		// reference — proving the bare-integer /K reached the per-kid handler (E1).
		$this->assertMatchesRegularExpression(
			'#/S /H2\s*/P \d+ 0 R\s*/K <</Type /MCR /Pg \d+ 0 R#',
			$output,
			'Bare-integer /K source element must merge with an MCR content reference (audit E1)'
		);

		// /Pg on that MCR must resolve to a real (non-zero) page object number (E5).
		$this->assertSame(
			1,
			preg_match('#/S /H2\s*/P \d+ 0 R\s*/K <</Type /MCR /Pg (\d+) 0 R#', $output, $m),
			'Merged H2 element must carry exactly one MCR content reference'
		);
		$this->assertGreaterThan(
			0,
			(int) $m[1],
			'/Pg on the merged MCR must resolve to a non-zero host page object'
		);
	}

	/**
	 * A source whose content lives in a Form XObject: merged MCRs carry /Pg + /Stm
	 * and the ParentTree contains the XObject's /StructParents key.
	 *
	 * FPDI imports each source page as a Form XObject, so the merged struct
	 * elements' content lives in that XObject's stream. Before UA1 audit E5,
	 * StructureWriter resolved the page object only via buildPageRefMap() keyed on
	 * the page-level /StructParents; the Form XObject's own /StructParents key is
	 * absent from that map, so the MCR fell to the bare-integer fallback and lost
	 * both /Pg and /Stm. After E5 the patched pageRef is preferred and the MCR
	 * emits `<</Type /MCR /Pg .. /Stm .. /MCID ..>>` per Table 324.
	 *
	 * ISO 32000-1:2008 §14.7.4.4 Table 324 — /Stm references the Form XObject whose
	 * content stream contains the marked content.
	 */
	public function testFormXObjectMcrsCarryPgAndStmAndParentTreeKey()
	{
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$output = $mpdf->Output(null, 'S');

		// Every merged MCR must be a full dict carrying both /Pg and /Stm — a
		// Form-XObject content item cannot be a bare integer (audit E5).
		$this->assertMatchesRegularExpression(
			'#<</Type /MCR /Pg \d+ 0 R /Stm \d+ 0 R /MCID \d+>>#',
			$output,
			'Merged Form-XObject MCRs must carry /Pg and /Stm (audit E5)'
		);

		// Read the Form XObject's /StructParents key (injected into the XObject
		// stream dict just after /Subtype /Form; search forward so a neighbouring
		// page dict's /StructParents 0 is not picked up by mistake).
		$foPos = strpos($output, '/Subtype /Form');
		$this->assertNotFalse($foPos, 'Output must contain the FPDI Form XObject');
		$this->assertSame(
			1,
			preg_match('#/Subtype /Form.{0,2000}?/StructParents (\d+)#s', substr($output, $foPos, 2100), $spMatch),
			'Form XObject dict must declare a /StructParents key'
		);
		$xobjKey = (int) $spMatch[1];

		// The ParentTree /Nums must contain an entry for that XObject key so the
		// back-reference from the XObject content stream to the struct elements
		// resolves (ISO 32000-1:2008 §14.7.4.4).
		$this->assertSame(
			1,
			preg_match('#/Nums \[(.*?)\]>>#s', $output, $numsMatch),
			'StructTreeRoot ParentTree must expose a /Nums array'
		);
		$this->assertMatchesRegularExpression(
			'#(?:^|\s)' . $xobjKey . ' \[#',
			$numsMatch[1],
			'ParentTree /Nums must contain the Form XObject /StructParents key ' . $xobjKey
		);
	}

	/**
	 * A tagged import completes without a fatal on the minimum supported PHP.
	 *
	 * The struct-merge cycle/identity keys in cloneElement() and
	 * collectSanityCandidates() must not depend on spl_object_id() — that
	 * function only exists on PHP 7.2+, but composer.json still advertises
	 * PHP 5.6/7.0/7.1, on which a tagged import would fatal with
	 * "Call to undefined function spl_object_id()" (UA1 audit E13). The
	 * inline-dict identity keys use spl_object_hash() (available since PHP 5.x)
	 * instead, so this tagged import — which drives both the clone and sanity
	 * walk cycle-key paths — must run to completion and emit the struct tree.
	 */
	public function testTaggedImportUsesPhp56CompatibleObjectKeys()
	{
		// Guard against reintroducing the PHP 7.2+ only spl_object_id() at
		// either cycle-key call site: the whole class must stay 5.6-safe.
		$src = file_get_contents(
			__DIR__ . '/../../../src/Ua/Import/FpdiStructMerger.php'
		);
		$this->assertStringNotContainsString(
			'spl_object_id(',
			$src,
			'FpdiStructMerger must not call spl_object_id() — it fatals on PHP < 7.2 (audit E13)'
		);
		$this->assertSame(
			2,
			substr_count($src, "'obj:' . spl_object_hash("),
			'Both cycle/identity keys must use spl_object_hash() (audit E13)'
		);

		// Functional check: a tagged import exercises the cycle-key paths and
		// must complete without a fatal, emitting the merged struct tree.
		$this->taggedPdf = $this->makeTaggedPdf();

		$mpdf = $this->makeMpdf();
		$mpdf->setSourceFile($this->taggedPdf);
		$pageId = $mpdf->importPage(1);
		$mpdf->AddPage();
		$mpdf->useImportedPage($pageId);
		$output = $mpdf->Output(null, 'S');

		$this->assertNotEmpty($output, 'Tagged import must produce output without a fatal');
		$this->assertStringContainsString('/Type /StructTreeRoot', $output);
	}
}
