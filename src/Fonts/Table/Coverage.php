<?php

namespace Mpdf\Fonts\Table;

use Mpdf\Fonts\FontReader;

/**
 * Coverage table: which glyphs a lookup applies to, and in what order.
 *
 * Nearly every GSUB and GPOS subtable begins with an offset to one of these, and the *position* of a
 * glyph in it - its Coverage Index - is what indexes the subtable's parallel array of substitutions
 * or positions. So the order this returns is as load-bearing as the membership.
 *
 * Format 1, a sorted list:
 *
 *     uint16   coverageFormat      set to 1
 *     uint16   glyphCount
 *     uint16   glyphArray[glyphCount]
 *
 * Format 2, sorted ranges:
 *
 *     uint16   coverageFormat      set to 2
 *     uint16   rangeCount
 *     RangeRecord rangeRecords[rangeCount]
 *
 * and each RangeRecord is
 *
 *     uint16   startGlyphID
 *     uint16   endGlyphID
 *     uint16   startCoverageIndex
 *
 * @see https://learn.microsoft.com/en-us/typography/opentype/spec/chapter2#coverage-table
 */
class Coverage
{

	/**
	 * Read a Coverage table from wherever the reader is.
	 *
	 * startCoverageIndex is read and discarded. The spec defines it as the Coverage Index of the
	 * range's first glyph, which lets a reader index into the middle of a table without walking it;
	 * expanding every range in order arrives at the same numbering, and this is the third field of
	 * the record either way, so it has to be read to get past it.
	 *
	 * A format the spec does not define yields no glyphs rather than an error, which is what every
	 * caller here has always done with one.
	 *
	 * @return int[] Glyph IDs in coverage order. The array index is the glyph's Coverage Index.
	 */
	public static function glyphs(FontReader $reader)
	{
		$glyphs = [];
		$format = $reader->readUInt16();

		if ($format === 1) {
			$glyphCount = $reader->readUInt16();
			for ($i = 0; $i < $glyphCount; $i++) {
				$glyphs[] = $reader->readUInt16();
			}

			return $glyphs;
		}

		if ($format === 2) {
			$rangeCount = $reader->readUInt16();
			for ($r = 0; $r < $rangeCount; $r++) {
				$startGlyphID = $reader->readUInt16();
				$endGlyphID = $reader->readUInt16();
				$reader->readUInt16(); // startCoverageIndex, recomputed by walking - see above
				for ($glyphID = $startGlyphID; $glyphID <= $endGlyphID; $glyphID++) {
					$glyphs[] = $glyphID;
				}
			}
		}

		return $glyphs;
	}
}
