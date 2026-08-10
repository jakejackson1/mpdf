<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 header-cell (`<th>`) association tests (audit E12).
 *
 * Before this fix Th::open() replayed Td::open(), which registered the HTML id
 * against a TEMPORARY TD struct element and parsed headers="", then discarded
 * that TD (discardTop()) and rebuilt a TH keeping only Scope/ColSpan/RowSpan.
 * Net bugs: (a) the id bound to an orphaned TD (registerId is first-wins, so the
 * real TH never got it) whose objNum stayed 0; (b) headers="…" associations were
 * dropped on the rebuilt TH; (c) Th::open()'s PDFUA block had no artifact-scope
 * guard, so a `<th>` in a running header/footer attached to the Document root.
 *
 * The fix builds the TH element ONCE via Td's overridable struct hooks and
 * registers its id / Headers / Scope against IT, behind the isInArtifact() guard
 * the other cell tags use.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — TH table element
 *   - ISO 32000-1:2008 Table 349 — /Scope, /Headers attributes
 *   - ISO 14289-1:2014 §7.5 — Matterhorn 09-004/005 header associations
 *
 * @group pdfua
 */
class TableHeadersTest extends PdfUaTestCase
{

	/**
	 * A `<th id scope>` carries /A <</O /Table /Scope /Column>> and its /ID, and a
	 * `<td headers>` in the body carries the matching /Headers reference.
	 */
	public function testHeaderCellCarriesScopeAndHeaderAssociation()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table>'
			. '<tr><th id="h1" scope="col">Head</th></tr>'
			. '<tr><td headers="h1">Data</td></tr>'
			. '</table>';
		$output = $this->getOutput($mpdf, $html);

		// TH struct element with a /Scope table attribute.
		$this->assertStringContainsString('/S /TH', $output);
		$this->assertStringContainsString('/O /Table /Scope /Column', $output);

		// The TH carries its /ID and the body TD references it via /Headers, both
		// normalised to the same bytes ('h1') by sanitiseIdForPdf().
		$this->assertStringContainsString('/ID (h1)', $output);
		$this->assertStringContainsString('/Headers [/h1]', $output);

		$th = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'TH');
		$this->assertNotNull($th, 'a TH struct element must exist');
		$attrs = $th->getAttributes();
		$this->assertSame('Column', $attrs['Scope'], 'the TH must carry Scope=Column');
		$this->assertSame(
			\Mpdf\Ua\StructureElement::sanitiseIdForPdf('h1'),
			$th->getId(),
			'the id must be bound to the TH, not a discarded TD'
		);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * The TH is a real element in the written tree, so it is serialised and gets a
	 * non-zero PDF object number (bug (a): the orphaned TD's objNum stayed 0).
	 */
	public function testHeaderCellObjNumIsAssigned()
	{
		$mpdf = $this->makeMpdf();
		$this->getOutput(
			$mpdf,
			'<table><tr><th id="h1" scope="col">Head</th></tr>'
			. '<tr><td headers="h1">Data</td></tr></table>'
		);

		$th = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'TH');
		$this->assertNotNull($th);
		$this->assertNotSame(0, $th->getObjNum(), 'the TH must be serialised with a real object number');
		$this->assertGreaterThan(0, $th->getObjNum());
	}

	/**
	 * The HTML id resolves to the TH in the AriaIdResolver's id map — not to a
	 * throwaway TD (bug (a): registerId is first-wins, so the round-trip bound the
	 * id to the discarded TD and the real TH never got it).
	 */
	public function testIdResolvesToHeaderCell()
	{
		$mpdf = $this->makeMpdf();
		$this->getOutput(
			$mpdf,
			'<table><tr><th id="h1" scope="col">Head</th></tr>'
			. '<tr><td headers="h1">Data</td></tr></table>'
		);

		$idMap = $this->readIdMap($mpdf);
		$this->assertArrayHasKey('h1', $idMap, 'the id must be registered');
		$this->assertSame('TH', $idMap['h1']->getType(), 'the id must resolve to the TH struct element');

		$th = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'TH');
		$this->assertSame($th, $idMap['h1'], 'the registered element must be the TH in the tree');
	}

	/**
	 * A `<th>` inside a running header renders as pagination artifact: it produces
	 * no TH struct element and never binds its id onto the Document root
	 * (bug (c): the missing artifact-scope guard).
	 */
	public function testHeaderCellInRunningHeaderRendersAsArtifact()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->SetHTMLHeader(
			'<table><tr><th id="hdr" scope="col">Header cell</th></tr></table>'
		);
		$output = $this->getOutput($mpdf, '<h1>Doc</h1><p>Body text.</p>');

		// The header was rendered inside a Pagination artifact scope.
		$this->assertStringContainsString('/Artifact <</Type /Pagination /Subtype /Header>>', $output);

		// No TH struct element leaked into the document tree...
		$root = $mpdf->getPdfUaStructureTree()->getRoot();
		$this->assertNull($this->findFirstOfType($root, 'TH'), 'a <th> in a header must not create a TH element');

		// ...and its id never attached to the Document root or the id map.
		$this->assertNull($root->getId(), 'the header cell id must not bind to the Document root');
		$idMap = $this->readIdMap($mpdf);
		$this->assertArrayNotHasKey('hdr', $idMap, 'the header cell id must not be registered as document content');
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * A `<th headers>` that itself references another header cell keeps its
	 * /Headers association (the shared Td attribute builder now applies to TH too),
	 * alongside its own /Scope.
	 */
	public function testHeaderCellCanAlsoCarryHeadersReference()
	{
		$mpdf = $this->makeMpdf();
		$html = '<table>'
			. '<tr><th id="top" scope="col">Top</th></tr>'
			. '<tr><th id="sub" headers="top" scope="col">Sub</th></tr>'
			. '<tr><td headers="sub">Data</td></tr>'
			. '</table>';
		$output = $this->getOutput($mpdf, $html);

		// The "sub" TH carries both its own /ID and a /Headers reference to "top".
		$this->assertStringContainsString('/ID (sub)', $output);
		$this->assertStringContainsString('/Headers [/top]', $output);
		$this->assertBdcEmcBalanced($output);
	}

	/**
	 * Read the AriaIdResolver's private id map for a given Mpdf instance.
	 *
	 * @param  \Mpdf\Mpdf $mpdf
	 * @return array<string, \Mpdf\Ua\StructureElement>
	 */
	private function readIdMap(\Mpdf\Mpdf $mpdf)
	{
		$uaProp = new \ReflectionProperty(\Mpdf\Mpdf::class, 'ua');
		$uaProp->setAccessible(true);
		$resolver = $uaProp->getValue($mpdf)->getAriaIdResolver();

		$idMapProp = new \ReflectionProperty(\Mpdf\Ua\AriaIdResolver::class, 'idMap');
		$idMapProp->setAccessible(true);
		return $idMapProp->getValue($resolver);
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
