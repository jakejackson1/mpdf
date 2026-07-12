<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 structure tagging for block-level tags inside a table cell (audit E9).
 *
 * Before this fix, BlockTag::open()'s struct-element creation was gated on
 * `!$this->mpdf->tableLevel`, so a `<td><h2>…</h2><ul><li>…</li></ul></td>`
 * produced only Table▸TR▸TD with the cell's content collapsed under a single
 * TD MCID — no H2, no L/LI — and the §7.4.2 heading-sequence tracker never saw
 * the cell's headings. These tests assert that a heading and a list inside a
 * cell now open their real struct elements beneath the TD, each owning its own
 * marked content, and that the cell's headings take part in the one
 * document-wide heading sequence.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — TD / list / heading struct types
 *   - ISO 32000-1:2008 §14.7.4.4 — one MCID maps to exactly one struct element
 *   - ISO 14289-1:2014 §7.2 — no empty structure elements
 *   - ISO 14289-1:2014 §7.4.2 — heading sequence
 *
 * @group pdfua
 */
class TableCellStructureTest extends PdfUaTestCase
{

	/**
	 * A heading inside a cell opens a real H2 struct element beneath the TD.
	 */
	public function testHeadingInCellProducesH2UnderTd()
	{
		$mpdf = $this->makeMpdf();
		$html = '<h1>Doc heading</h1>'
			. '<table><tr><td><h2>Cell heading</h2><p>Body</p></td></tr></table>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString('/S /H2', $output);
		$this->assertTdHasChildOfType($mpdf, 'H2');
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * A list inside a cell opens L ▸ LI beneath the TD.
	 */
	public function testListInCellProducesListStructureUnderTd()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table><tr><td><ul><li>Item one</li><li>Item two</li></ul></td></tr></table>';
		$output = $this->getOutput($mpdf, $html);

		$this->assertStringContainsString('/S /L', $output);
		$this->assertStringContainsString('/S /LI', $output);

		$l = $this->assertTdHasChildOfType($mpdf, 'L');
		$liChildren = 0;
		foreach ($l->getChildren() as $child) {
			if ($child->getType() === 'LI') {
				$liChildren++;
			}
		}
		$this->assertSame(2, $liChildren, 'the <ul> L element must contain one LI per <li>');
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * The heading and the list coexist beneath the same TD: TD ▸ H2 and TD ▸ L ▸ LI.
	 */
	public function testHeadingAndListCoexistUnderSameCell()
	{
		$mpdf = $this->makeMpdf();
		$html = '<h1>Doc heading</h1>'
			. '<table><tr><td><h2>Cell heading</h2>'
			. '<ul><li>Item one</li><li>Item two</li></ul></td></tr></table>';
		$output = $this->getOutput($mpdf, $html);

		$td = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'TD');
		$this->assertNotNull($td, 'a TD struct element must exist');

		$childTypes = [];
		foreach ($td->getChildren() as $child) {
			$childTypes[] = $child->getType();
		}
		$this->assertContains('H2', $childTypes, 'the cell heading must be a TD child');
		$this->assertContains('L', $childTypes, 'the cell list must be a TD child');
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Each nested cell element owns its OWN content MCID rather than the whole
	 * cell collapsing under one TD MCID (ISO 32000-1 §14.7.4.4). A list item's
	 * text lives in its LBody (ISO 14289-1 §7.2 test 20 — LI content must be in
	 * an LBody, not directly under LI).
	 */
	public function testNestedCellElementsOwnTheirMarkedContent()
	{
		$mpdf = $this->makeMpdf();
		$html = '<h1>Doc heading</h1>'
			. '<table><tr><td><h2>Cell heading</h2>'
			. '<ul><li>Item one</li><li>Item two</li></ul></td></tr></table>';
		$output = $this->getOutput($mpdf, $html);

		// The heading brackets its text in its own H2 BDC, and each list item's
		// content brackets in its own LBody BDC.
		$this->assertStringContainsString('/H2 <</MCID', $output);
		$this->assertSame(
			2,
			substr_count($output, '/LBody <</MCID'),
			'each <li> must own a distinct LBody marked-content sequence'
		);
		// The TD itself owns no direct content — all of it lives in the children.
		$this->assertStringNotContainsString('/TD <</MCID', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * A list item's content and any nested list live in an LBody, so a nested
	 * list is L ▸ LI ▸ LBody ▸ L (ISO 14289-1 §7.2 tests 18/20).
	 */
	public function testNestedListInCellWrapsInnerListInLBody()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table><tr><td><ul><li>outer'
			. '<ul><li>inner one</li><li>inner two</li></ul></li></ul></td></tr></table>';
		$this->getOutput($mpdf, $html);

		$outerL = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'L');
		$this->assertNotNull($outerL);
		$outerLi = $this->firstChildOfType($outerL, 'LI');
		$this->assertNotNull($outerLi, 'outer L must contain an LI');
		$lbody = $this->firstChildOfType($outerLi, 'LBody');
		$this->assertNotNull($lbody, 'the LI content must live in an LBody, not directly under LI');
		$this->assertNotNull(
			$this->firstChildOfType($lbody, 'L'),
			'the nested <ul> must be an L inside the LBody'
		);
	}

	/**
	 * A definition list inside a cell wraps DT/DD in an implicit LI so the
	 * structure is L ▸ LI ▸ (Lbl, LBody) even when the HTML omits end tags.
	 */
	public function testDefinitionListInCellWrapsTermsInImplicitLi()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table><tr><td><dl><dt>Term one<dd>Def one'
			. '<dt>Term two<dd>Def two</dl></td></tr></table>';
		$output = $this->getOutput($mpdf, $html);

		$l = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'L');
		$this->assertNotNull($l);
		$liCount = 0;
		foreach ($l->getChildren() as $child) {
			$this->assertSame('LI', $child->getType(), 'an L may contain only LI (and L/Caption) children');
			$liCount++;
			$kinds = [];
			foreach ($child->getChildren() as $g) {
				$kinds[] = $g->getType();
			}
			$this->assertSame(['Lbl', 'LBody'], $kinds, 'each implicit LI holds the term (Lbl) and definition (LBody)');
		}
		$this->assertSame(2, $liCount, 'two dt/dd pairs → two implicit LI items');
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * The heading-sequence tracker sees a cell heading: an H2 in a cell as the
	 * document's FIRST heading is a §7.4.2 violation and throws in strict mode.
	 */
	public function testCellHeadingParticipatesInSequenceStrictThrows()
	{
		$mpdf = $this->makeMpdf(); // PDFUAauto defaults to false → strict
		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessageMatches('/first heading in the document must be H1/');
		$mpdf->WriteHTML('<table><tr><td><h2>Cell heading</h2></td></tr></table>');
	}

	/**
	 * In auto mode the same first-heading-is-H2 cell is promoted to H1 rather
	 * than dropped, and a warning is recorded — proving the tracker runs for
	 * cell headings.
	 */
	public function testCellHeadingParticipatesInSequenceAutoPromotes()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<table><tr><td><h2>Cell heading</h2></td></tr></table>');

		$this->assertStringContainsString('/S /H1', $output);
		$this->assertStringNotContainsString('/S /H2', $output);

		$warnings = implode("\n", $mpdf->getPdfUaWarnings());
		$this->assertStringContainsString('first heading must be H1', $warnings);
	}

	/**
	 * Conformance gate — a heading + list inside a table cell validates as
	 * PDF/UA-1 under veraPDF (see VeraPdfConformanceTest for the gated run).
	 */
	public function testHeadingAndListInCellRemainsWellFormed()
	{
		$mpdf = $this->makeMpdf();
		$html = '<h1>Doc heading</h1>'
			. '<table border="1"><tr><td><h2>Cell heading</h2>'
			. '<ul><li>Item one</li><li>Item two</li></ul></td></tr></table>';
		$output = $this->getOutput($mpdf, $html);

		// Table structure is intact and headings/list are reachable.
		$this->assertStringContainsString('/S /Table', $output);
		$this->assertStringContainsString('/S /TD', $output);
		$this->assertStringContainsString('/S /H2', $output);
		$this->assertStringContainsString('/S /LI', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Assert the first TD in the tree has a direct child of the given struct
	 * type and return that child.
	 *
	 * @param  \Mpdf\Mpdf $mpdf
	 * @param  string     $type
	 * @return \Mpdf\Ua\StructureElement
	 */
	private function assertTdHasChildOfType(\Mpdf\Mpdf $mpdf, $type)
	{
		$td = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'TD');
		$this->assertNotNull($td, 'a TD struct element must exist');
		foreach ($td->getChildren() as $child) {
			if ($child->getType() === $type) {
				return $child;
			}
		}
		$this->fail('TD must have a direct ' . $type . ' child');
	}

	/**
	 * Return the first direct child of $node with the given struct type, or null.
	 *
	 * @param  \Mpdf\Ua\StructureElement $node
	 * @param  string                    $type
	 * @return \Mpdf\Ua\StructureElement|null
	 */
	private function firstChildOfType($node, $type)
	{
		foreach ($node->getChildren() as $child) {
			if ($child->getType() === $type) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Depth-first search for the first struct element of a given type.
	 *
	 * @param  \Mpdf\Ua\StructureElement $node
	 * @param  string                    $type
	 * @return \Mpdf\Ua\StructureElement|null
	 */
	private function findFirstOfType($node, $type)
	{
		foreach ($node->getChildren() as $child) {
			if ($child->getType() === $type) {
				return $child;
			}
			$found = $this->findFirstOfType($child, $type);
			if ($found !== null) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Assert BDC + BMC operators equal EMC operators in the raw PDF output.
	 *
	 * @param string $output  raw PDF bytes
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
