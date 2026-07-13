<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 table row-grouping structure (audit E16).
 *
 * Before this fix Tr::open() pushed TR directly beneath the enclosing Table and
 * the <thead>/<tbody>/<tfoot> handlers emitted no struct element at all, so the
 * row-grouping semantics (and THead repetition) were lost. These tests assert
 * that:
 *   - <thead>/<tbody>/<tfoot> now open THead/TBody/TFoot struct elements with
 *     their TR rows nested beneath them (Table ▸ THead ▸ TR, etc.); and
 *   - a table that writes rows straight under <table> has a TBody synthesised so
 *     TR is never a direct child of Table.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — Table / THead / TBody / TFoot / TR
 *   - ISO 14289-1:2014 §7.2 — no empty structure elements
 *
 * @group pdfua
 */
class TableStructureTest extends PdfUaTestCase
{

	/**
	 * A table with all three row groups nests each group's TR beneath the group
	 * element: Table ▸ THead ▸ TR, Table ▸ TBody ▸ TR, Table ▸ TFoot ▸ TR.
	 */
	public function testExplicitRowGroupsNestTrBeneathGroupElements()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table>'
			. '<thead><tr><th>Head</th></tr></thead>'
			. '<tbody><tr><td>Body</td></tr></tbody>'
			. '<tfoot><tr><td>Foot</td></tr></tfoot>'
			. '</table>';
		$output = $this->getOutput($mpdf, $html);

		$table = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'Table');
		$this->assertNotNull($table, 'a Table struct element must exist');

		// The Table's direct children are exactly the three row groups, in order.
		$groups = [];
		foreach ($table->getChildren() as $child) {
			$groups[] = $child->getType();
		}
		$this->assertSame(['THead', 'TBody', 'TFoot'], $groups, 'Table must group its rows into THead/TBody/TFoot');

		// Each group holds its TR (and nothing but TR).
		foreach ($table->getChildren() as $group) {
			$rowTypes = [];
			foreach ($group->getChildren() as $row) {
				$rowTypes[] = $row->getType();
			}
			$this->assertSame(['TR'], $rowTypes, $group->getType() . ' must contain its TR row');
		}

		$this->assertStringContainsString('/S /THead', $output);
		$this->assertStringContainsString('/S /TBody', $output);
		$this->assertStringContainsString('/S /TFoot', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * A table whose rows are written straight under <table> gets a synthesised
	 * TBody so TR is never a direct child of Table.
	 */
	public function testImplicitRowsAreWrappedInSynthesisedTbody()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table><tr><td>One</td></tr><tr><td>Two</td></tr></table>';
		$this->getOutput($mpdf, $html);

		$table = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'Table');
		$this->assertNotNull($table, 'a Table struct element must exist');

		$childTypes = [];
		foreach ($table->getChildren() as $child) {
			$childTypes[] = $child->getType();
		}
		$this->assertSame(['TBody'], $childTypes, 'group-less rows must live under a single synthesised TBody');

		// Both rows collapse into the one synthetic TBody.
		$tbody = $table->getChildren()[0];
		$rowTypes = [];
		foreach ($tbody->getChildren() as $row) {
			$rowTypes[] = $row->getType();
		}
		$this->assertSame(['TR', 'TR'], $rowTypes, 'both implicit rows nest under the synthesised TBody');

		// No TR is ever a direct child of the Table.
		foreach ($table->getChildren() as $child) {
			$this->assertNotSame('TR', $child->getType(), 'TR must not be a direct child of Table');
		}
	}

	/**
	 * A THead followed by group-less rows produces Table ▸ THead and a
	 * Table ▸ TBody (synthesised) for the trailing rows — the two groups are
	 * siblings, not nested.
	 */
	public function testHeadThenImplicitRowsSynthesiseSiblingTbody()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table>'
			. '<thead><tr><th>Head</th></tr></thead>'
			. '<tr><td>Body one</td></tr>'
			. '<tr><td>Body two</td></tr>'
			. '</table>';
		$this->getOutput($mpdf, $html);

		$table = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'Table');
		$this->assertNotNull($table);

		$childTypes = [];
		foreach ($table->getChildren() as $child) {
			$childTypes[] = $child->getType();
		}
		$this->assertSame(['THead', 'TBody'], $childTypes, 'the explicit THead and the synthesised TBody must be Table siblings');
	}

	/**
	 * A nested table (Table-in-TD) keeps its own row group: the inner table's TR
	 * nests under an inner TBody, not under the outer group.
	 */
	public function testNestedTableGetsItsOwnRowGroup()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table><tbody><tr><td>'
			. '<table><tr><td>Inner</td></tr></table>'
			. '</td></tr></tbody></table>';
		$this->getOutput($mpdf, $html);

		$outerTable = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'Table');
		$this->assertNotNull($outerTable);

		// Outer: Table ▸ TBody ▸ TR ▸ TD ▸ Table(inner) ▸ TBody ▸ TR ▸ TD
		$outerTbody = $this->firstChildOfType($outerTable, 'TBody');
		$this->assertNotNull($outerTbody, 'outer table must have a TBody');
		$outerTr = $this->firstChildOfType($outerTbody, 'TR');
		$this->assertNotNull($outerTr);
		$outerTd = $this->firstChildOfType($outerTr, 'TD');
		$this->assertNotNull($outerTd);

		$innerTable = $this->firstChildOfType($outerTd, 'Table');
		$this->assertNotNull($innerTable, 'the inner table must be a TD child');
		$innerTbody = $this->firstChildOfType($innerTable, 'TBody');
		$this->assertNotNull($innerTbody, 'the inner table must synthesise its own TBody');
		$this->assertNotNull($this->firstChildOfType($innerTbody, 'TR'), 'the inner TR must nest under the inner TBody');
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
