<?php

namespace Mpdf\Fonts\Table;

use Mpdf\Fonts\BlobReader;

class ClassDefTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * Format 1: classFormat, startGlyphID, glyphCount, then one class per glyph from there on
	 */
	public function testFormat1AssignsAClassPerGlyphFromTheStart()
	{
		$table = pack('n*', 1, 40, 3, 2, 0, 1);

		$this->assertSame([[40, 2], [41, 0], [42, 1]], ClassDef::pairs(new BlobReader($table)));
	}

	/**
	 * Format 2: classFormat, classRangeCount, then startGlyphID/endGlyphID/class per range
	 */
	public function testFormat2AssignsAClassPerRange()
	{
		$table = pack('n*', 2, 2, 40, 42, 1, 55, 55, 3);

		$this->assertSame([[40, 1], [41, 1], [42, 1], [55, 3]], ClassDef::pairs(new BlobReader($table)));
	}

	/**
	 * Class 0 is the class of every glyph the table does not mention, so it is not expanded - doing so
	 * would mean inventing every glyph in the font. A table that assigns it explicitly still reports
	 * it, and each caller decides what that means.
	 */
	public function testClassZeroIsReportedWhereStatedAndNeverInvented()
	{
		$pairs = ClassDef::pairs(new BlobReader(pack('n*', 2, 1, 40, 41, 0)));

		$this->assertSame([[40, 0], [41, 0]], $pairs);
	}

	/**
	 * The spec requires ranges to be sorted and not to overlap. A font that overlaps them anyway
	 * reads back once per record, in record order, rather than collapsing to one class per glyph -
	 * which is what a glyph => class map would have done silently.
	 */
	public function testOverlappingRangesAreReportedAsWritten()
	{
		$table = pack('n*', 2, 2, 40, 41, 1, 41, 42, 2);

		$this->assertSame([[40, 1], [41, 1], [41, 2], [42, 2]], ClassDef::pairs(new BlobReader($table)));
	}

	public function testAnEmptyTableAssignsNothing()
	{
		$this->assertSame([], ClassDef::pairs(new BlobReader(pack('n*', 1, 40, 0))));
		$this->assertSame([], ClassDef::pairs(new BlobReader(pack('n*', 2, 0))));
	}

	public function testAnUnknownFormatAssignsNothing()
	{
		$this->assertSame([], ClassDef::pairs(new BlobReader(pack('n*', 7, 2, 40, 41))));
	}

	public function testItLeavesTheReaderAfterTheTable()
	{
		$reader = new BlobReader(pack('n*', 1, 40, 2, 1, 2, 0xBEEF));

		ClassDef::pairs($reader);

		$this->assertSame(10, $reader->tell());
		$this->assertSame(0xBEEF, $reader->readUInt16());
	}

}
