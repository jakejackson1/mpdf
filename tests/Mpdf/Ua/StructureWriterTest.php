<?php

namespace Mpdf\Ua;

use Mpdf\Buffer;
use Mpdf\Mpdf;

/**
 * Unit tests for StructureWriter — focused on the ParentTree NumTree emission.
 *
 * ISO 32000-1:2008 §7.9.7 (number trees) + §14.7.4.4 (ParentTree): the /Nums
 * array maps /StructParents integers to per-page value arrays indexed by MCID.
 * Both the outer key sequence and the inner MCID index MUST survive gaps — a
 * skipped key or MCID must not shift any other entry, or content maps to the
 * wrong struct element (UA1 audit E23).
 *
 * These tests drive StructureWriter directly against a hand-built StructureTree
 * so the emitted /Nums bytes can be asserted without a full document render.
 * The private BaseWriter on the host Mpdf is reached by reflection; its
 * write() calls are routed into a fresh Buffer by leaving $mpdf->state at 0.
 *
 * @group pdfua
 * @see StructureWriter::writeParentTree() code under test
 */
class StructureWriterTest extends PdfUaTestCase
{

	/**
	 * Serialise a hand-built StructureTree via StructureWriter and return the
	 * raw PDF bytes emitted into the host Mpdf's buffer.
	 *
	 * @param  StructureTree $tree
	 * @return string  Concatenated bytes written by writeStructTree().
	 */
	private function serialise(StructureTree $tree)
	{
		$mpdf = $this->makeMpdf();
		// state 0 routes BaseWriter::write() into $mpdf->buffer (not a page).
		$mpdf->state  = 0;
		$mpdf->buffer = new Buffer();

		$writerProp = new \ReflectionProperty(Mpdf::class, 'writer');
		$writerProp->setAccessible(true);
		$writer = $writerProp->getValue($mpdf);

		$structureWriter = new StructureWriter($mpdf, $writer, $tree);
		$structureWriter->writeStructTree(64);

		return $mpdf->buffer->writeToString();
	}

	/**
	 * Extract the raw contents of the ParentTree's /Nums array from PDF bytes.
	 *
	 * @param  string $pdf
	 * @return string  Whitespace-normalised text between "/Nums [" and "]>>".
	 */
	private function numsBody($pdf)
	{
		$this->assertSame(1, preg_match('/<<\/Nums \[(.*?)\]>>/s', $pdf, $m), 'ParentTree /Nums block not found');
		return trim(preg_replace('/\s+/', ' ', $m[1]));
	}

	/**
	 * A single /StructParents key whose MCID map has a gap must emit the value
	 * array with an explicit `null` at the missing index, so the surviving MCIDs
	 * still land at their own positions.
	 *
	 * Without gap preservation the two refs would be appended positionally and
	 * the second element would answer for MCID 1 instead of MCID 2.
	 */
	public function testSparseMcidGapIsPreservedWithNull()
	{
		$tree = new StructureTree();
		$tree->open('P');
		$first = $tree->getCurrent();
		$tree->close();
		$tree->open('P');
		$second = $tree->getCurrent();
		$tree->close();

		// Imported MCRs carry source-PDF MCIDs verbatim (no allocator), so a key
		// can hold a non-contiguous MCID set — here MCID 0 and MCID 2 (gap at 1).
		$tree->registerImportedMcr(0, 0, $first);
		$tree->registerImportedMcr(0, 2, $second);

		$nums = $this->numsBody($this->serialise($tree));

		$expected = '0 [' . $first->getObjNum() . ' 0 R null ' . $second->getObjNum() . ' 0 R]';
		$this->assertSame($expected, $nums);
	}

	/**
	 * Non-contiguous outer /StructParents keys must each be emitted as an
	 * explicit "key value" pair, so a skipped key does not shift later entries.
	 */
	public function testNonContiguousStructParentsKeysResolveCorrectly()
	{
		$tree = new StructureTree();
		$tree->open('P');
		$a = $tree->getCurrent();
		$tree->close();
		$tree->open('P');
		$b = $tree->getCurrent();
		$tree->close();

		// Keys 3 and 7 with a wide gap — e.g. an imported page reserving keys 4-6.
		$tree->registerImportedMcr(3, 0, $a);
		$tree->registerImportedMcr(7, 0, $b);

		$nums = $this->numsBody($this->serialise($tree));

		$this->assertSame(
			'3 [' . $a->getObjNum() . ' 0 R] 7 [' . $b->getObjNum() . ' 0 R]',
			$nums
		);
	}

	/**
	 * A dense, 0-based MCID map produces a tightly packed value array with no
	 * padding — the gap-preserving builder must be byte-identical for the
	 * common case so existing conformant output does not change.
	 */
	public function testDenseMcidMapEmitsNoNullPadding()
	{
		$tree = new StructureTree();
		$tree->open('P');
		$first = $tree->getCurrent();
		$tree->close();
		$tree->open('P');
		$second = $tree->getCurrent();
		$tree->close();

		$tree->registerImportedMcr(0, 0, $first);
		$tree->registerImportedMcr(0, 1, $second);

		$nums = $this->numsBody($this->serialise($tree));

		$this->assertStringNotContainsString('null', $nums);
		$this->assertSame(
			'0 [' . $first->getObjNum() . ' 0 R ' . $second->getObjNum() . ' 0 R]',
			$nums
		);
	}

	/**
	 * A /Layout /BBox carrying fractional user-space coordinates MUST be written
	 * with '.' decimal separators regardless of the active LC_NUMERIC locale.
	 *
	 * Under a comma-decimal locale (de_DE / nl_NL) a bare float-to-string cast
	 * emits "1,5" and corrupts the PDF number array; formatNumber()'s
	 * sprintf('%.3F', …) must keep the output locale-independent (UA1 audit E24).
	 */
	public function testBBoxIsLocaleIndependent()
	{
		$original = setlocale(LC_NUMERIC, '0');
		$applied  = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'nl_NL.UTF-8', 'nl_NL', 'German', 'Dutch');
		if ($applied === false || strpos(sprintf('%.1f', 1.5), ',') === false) {
			if ($original !== false) {
				setlocale(LC_NUMERIC, $original);
			}
			$this->markTestSkipped('No comma-decimal locale available on this host.');
		}

		try {
			$tree = new StructureTree();
			$tree->open('Figure');
			$fig = $tree->getCurrent();
			$fig->setAttribute('BBox', [10.5, 20.25, 100.125, 200.0]);
			$tree->registerImportedMcr(0, 0, $fig);
			$tree->close();

			$pdf = $this->serialise($tree);
		} finally {
			if ($original !== false) {
				setlocale(LC_NUMERIC, $original);
			}
		}

		$this->assertSame(1, preg_match('#/BBox \[([^\]]*)\]#', $pdf, $m), '/BBox array not emitted');
		$this->assertStringNotContainsString(',', $m[1], '/BBox must use "." decimal separator');
		$this->assertSame('10.5 20.25 100.125 200', trim($m[1]));
	}
}
