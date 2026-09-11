<?php

namespace Mpdf\Fonts\Table;

use Mpdf\Fonts\BlobReader;

class CoverageTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * Format 1: coverageFormat, glyphCount, then the glyph IDs
	 */
	public function testFormat1ReadsTheGlyphListInOrder()
	{
		$table = pack('n*', 1, 3, 40, 41, 55);

		$this->assertSame([40, 41, 55], Coverage::glyphs(new BlobReader($table)));
	}

	/**
	 * Format 2: coverageFormat, rangeCount, then startGlyphID/endGlyphID/startCoverageIndex per range
	 */
	public function testFormat2ExpandsEachRange()
	{
		$table = pack('n*', 2, 2, 40, 42, 0, 55, 55, 3);

		$this->assertSame([40, 41, 42, 55], Coverage::glyphs(new BlobReader($table)));
	}

	/**
	 * The Coverage Index is the position in the returned list, which is what a subtable's parallel
	 * array of substitutions is indexed by. A font may state a startCoverageIndex that disagrees with
	 * where walking the ranges arrives; walking wins, as it always has here.
	 */
	public function testTheCoverageIndexComesFromWalkingNotFromTheDeclaredValue()
	{
		$honest = pack('n*', 2, 2, 40, 41, 0, 55, 56, 2);
		$lying = pack('n*', 2, 2, 40, 41, 7, 55, 56, 99);

		$this->assertSame(
			Coverage::glyphs(new BlobReader($honest)),
			Coverage::glyphs(new BlobReader($lying))
		);
		$this->assertSame([40, 41, 55, 56], Coverage::glyphs(new BlobReader($lying)));
	}

	public function testAnEmptyTableCoversNothing()
	{
		$this->assertSame([], Coverage::glyphs(new BlobReader(pack('n*', 1, 0))));
		$this->assertSame([], Coverage::glyphs(new BlobReader(pack('n*', 2, 0))));
	}

	/**
	 * A format the spec does not define yields no glyphs. A lookup whose coverage is empty applies to
	 * nothing, which is the safe reading of a table this code cannot understand.
	 */
	public function testAnUnknownFormatCoversNothing()
	{
		$this->assertSame([], Coverage::glyphs(new BlobReader(pack('n*', 3, 2, 40, 41))));
	}

	/**
	 * The reader is left after the table, so a caller walking a list of coverage offsets keeps its
	 * place
	 */
	public function testItLeavesTheReaderAfterTheTable()
	{
		$reader = new BlobReader(pack('n*', 1, 2, 40, 41, 0xBEEF));

		Coverage::glyphs($reader);

		$this->assertSame(8, $reader->tell());
		$this->assertSame(0xBEEF, $reader->readUInt16());
	}

}
