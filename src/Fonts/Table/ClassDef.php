<?php

namespace Mpdf\Fonts\Table;

use Mpdf\Fonts\FontReader;

/**
 * Class Definition table: which class each glyph belongs to.
 *
 * Used where a rule applies to a kind of glyph rather than to a list of them - the class-based
 * contextual lookups, GPOS pair positioning, and GDEF's own glyph and mark attachment classes.
 *
 * Format 1, a class per glyph over a contiguous run:
 *
 *     uint16   classFormat         set to 1
 *     uint16   startGlyphID
 *     uint16   glyphCount
 *     uint16   classValueArray[glyphCount]
 *
 * Format 2, a class per range:
 *
 *     uint16   classFormat         set to 2
 *     uint16   classRangeCount
 *     ClassRangeRecord classRangeRecords[classRangeCount]
 *
 * and each ClassRangeRecord is
 *
 *     uint16   startGlyphID
 *     uint16   endGlyphID
 *     uint16   class
 *
 * @see https://learn.microsoft.com/en-us/typography/opentype/spec/chapter2#class-definition-table
 */
class ClassDef
{

	/**
	 * Read a Class Definition table from wherever the reader is.
	 *
	 * Any glyph the table does not mention belongs to class 0, and the spec says so rather than
	 * listing them. That is not expanded here: the callers each decide what class 0 means to them,
	 * and expanding it would mean inventing every glyph in the font.
	 *
	 * Pairs rather than a glyph => class map, so that a malformed font whose ranges overlap reads back
	 * exactly as it is written - once per record, in record order - rather than silently collapsing.
	 *
	 * @return array[] One [glyphID, class] pair per glyph the table assigns, in table order
	 */
	public static function pairs(FontReader $reader)
	{
		$pairs = [];
		$format = $reader->readUInt16();

		if ($format === 1) {
			$startGlyphID = $reader->readUInt16();
			$glyphCount = $reader->readUInt16();
			for ($i = 0; $i < $glyphCount; $i++) {
				$pairs[] = [$startGlyphID + $i, $reader->readUInt16()];
			}

			return $pairs;
		}

		if ($format === 2) {
			$rangeCount = $reader->readUInt16();
			for ($r = 0; $r < $rangeCount; $r++) {
				$startGlyphID = $reader->readUInt16();
				$endGlyphID = $reader->readUInt16();
				$class = $reader->readUInt16();
				for ($glyphID = $startGlyphID; $glyphID <= $endGlyphID; $glyphID++) {
					$pairs[] = [$glyphID, $class];
				}
			}
		}

		return $pairs;
	}
}
