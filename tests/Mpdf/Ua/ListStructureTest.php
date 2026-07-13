<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 list numbering structure (audit E17).
 *
 * Before this fix StructureWriter::buildAttrObject()'s /List branch only fired
 * when a struct element carried a 'ListNumbering' attribute — but no tag handler
 * ever set that key, so ordered lists never produced the
 * /A <</O /List /ListNumbering …>> attribute object PDF/UA expects for an L
 * element with an ordered marker. These tests assert that the Ol/Ul struct path
 * now sets ListNumbering from the resolved CSS list-style-type so the writer
 * branch fires.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8.5.3.3 Table 347 — /ListNumbering values
 *     (Decimal / UpperRoman / LowerRoman / UpperAlpha / LowerAlpha /
 *      Disc / Circle / Square)
 *   - ISO 14289-1:2014 §7.6 — list structure
 *
 * @group pdfua
 */
class ListStructureTest extends PdfUaTestCase
{

	/**
	 * <ol type="a"> maps list-style-type lower-latin onto /ListNumbering
	 * /LowerAlpha, and the L element carries the /A <</O /List …>> object.
	 */
	public function testOrderedListTypeAlphaCarriesLowerAlphaNumbering()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<ol type="a"><li>First</li><li>Second</li></ol>');

		$list = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'L');
		$this->assertNotNull($list, 'an L struct element must exist');
		$this->assertSame('LowerAlpha', $list->getAttributes()['ListNumbering']);

		$this->assertStringContainsString('/O /List /ListNumbering /LowerAlpha >>', $output);
	}

	/**
	 * An unstyled <ol> defaults to decimal → /ListNumbering /Decimal.
	 */
	public function testDefaultOrderedListCarriesDecimalNumbering()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<ol><li>One</li><li>Two</li></ol>');

		$list = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'L');
		$this->assertNotNull($list);
		$this->assertSame('Decimal', $list->getAttributes()['ListNumbering']);
		$this->assertStringContainsString('/O /List /ListNumbering /Decimal >>', $output);
	}

	/**
	 * Upper-roman and CSS-styled markers resolve to their Table 347 names.
	 */
	public function testUpperRomanAndCssStyledMarkers()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<ol type="I"><li>One</li></ol>'
			. '<ol style="list-style-type: upper-alpha"><li>A</li></ol>'
		);

		$this->assertStringContainsString('/O /List /ListNumbering /UpperRoman >>', $output);
		$this->assertStringContainsString('/O /List /ListNumbering /UpperAlpha >>', $output);
	}

	/**
	 * A default <ul> uses the disc glyph → /ListNumbering /Disc.
	 */
	public function testUnorderedListCarriesDiscNumbering()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput($mpdf, '<ul><li>Bullet one</li><li>Bullet two</li></ul>');

		$list = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'L');
		$this->assertNotNull($list);
		$this->assertSame('Disc', $list->getAttributes()['ListNumbering']);
		$this->assertStringContainsString('/O /List /ListNumbering /Disc >>', $output);
	}

	/**
	 * A marker style outside Table 347 (lower-greek) carries no /ListNumbering,
	 * so no /List attribute object is emitted for that list.
	 */
	public function testUnsupportedMarkerEmitsNoNumbering()
	{
		$mpdf = $this->makeMpdf();
		$output = $this->getOutput(
			$mpdf,
			'<ol style="list-style-type: lower-greek"><li>alpha</li></ol>'
		);

		$list = $this->findFirstOfType($mpdf->getPdfUaStructureTree()->getRoot(), 'L');
		$this->assertNotNull($list);
		$this->assertArrayNotHasKey('ListNumbering', $list->getAttributes());
		$this->assertStringNotContainsString('/O /List', $output);
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
}
