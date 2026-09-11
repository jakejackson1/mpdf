<?php

namespace Mpdf\Shaper;

/**
 * Line breaking for the scripts that do not write word spaces.
 *
 * Not OpenType layout: no font table is read here and nothing is substituted or positioned. It sits
 * beside the other shapers because the dictionary is chosen by shaper id - K, T or L - and because
 * it runs over the same OTLdata array, marking 'wordend' on the characters mPDF may break after.
 */
class LineBreaking
{

	const NODE_TYPE_SPLIT = 0x01;

	const NODE_TYPE_LINEAR = 0x02;

	const INTERMEDIATE_MATCH = 0x03;

	const FINAL_MATCH = 0x04;

	/**
	 * Tibetan breaks at the tsheg, the syllable mark, not at spaces. No dictionary is needed: U+0F0B
	 * TSHEG and U+0F0D SHAD are themselves the opportunities, except where a shad is followed by
	 * another shad or by U+0F0E, which is one punctuation unit and must not be split.
	 *
	 * @see https://www.unicode.org/reports/tr14/ class BA
	 */
	public static function tibetan(&$info)
	{
		for ($ptr = 0; $ptr < count($info); $ptr++) {
			// Break opportunities at U+0F0B Tsheg or U=0F0D
			if (isset($info[$ptr]['uni']) && ($info[$ptr]['uni'] == 0x0F0B || $info[$ptr]['uni'] == 0x0F0D)) {
				if (isset($info[$ptr + 1]['uni']) && ($info[$ptr + 1]['uni'] == 0x0F0D || $info[$ptr + 1]['uni'] == 0xF0E)) {
					continue;
				}
				// Set end of word marker in OTLdata at matchpos
				$info[$ptr]['wordend'] = true;
			}
		}
	}

	/**
	 * Thai, Khmer and Lao write without spaces between words, so a line may only break where one word
	 * ends and the next begins. That cannot be read off the characters - it needs a dictionary, walked
	 * as a trie over the low byte of each codepoint.
	 *
	 * @param string $dict The dictionary as loaded from the font package, in the format wordMatch() walks
	 */
	public static function southEastAsian(&$info, $dict, $glyphClassMarks)
	{
		// Find all word boundaries and mark end of word $info[$i]['wordend']=true on last character
		// If Thai, allow for possible suffixes (not in Lao or Khmer)
		// repeater/ellision characters
		// (0x0E2F);        // Ellision character THAI_PAIYANNOI 0x0E2F  UTF-8 0xE0 0xB8 0xAF
		// (0x0E46);        // Repeat character THAI_MAIYAMOK 0x0E46   UTF-8 0xE0 0xB9 0x86
		// (0x0EC6);        // Repeat character LAO   UTF-8 0xE0 0xBB 0x86

		$rollover = [];
		$ptr = 0;

		while ($ptr < count($info) - 3) {
			if (count($rollover)) {
				$matches = $rollover;
				$rollover = [];
			} else {
				$matches = self::wordMatch($dict, $info, $glyphClassMarks, $ptr);
			}
			if (count($matches) == 1) {
				$matchpos = $matches[0];
				// Check for repeaters - if so $matchpos++
				if (isset($info[$matchpos + 1]['uni']) && ($info[$matchpos + 1]['uni'] == 0x0E2F || $info[$matchpos + 1]['uni'] == 0x0E46 || $info[$matchpos + 1]['uni'] == 0x0EC6)) {
					$matchpos++;
				}
				// Set end of word marker in OTLdata at matchpos
				$info[$matchpos]['wordend'] = true;
				$ptr = $matchpos + 1;
			} elseif (empty($matches)) {
				$ptr++;
				// Move past any ASCII characters
				while (isset($info[$ptr]['uni']) && ($info[$ptr]['uni'] >> 8) == 0) {
					$ptr++;
				}
			} else { // Multiple matches
				$secondmatch = false;
				for ($m = count($matches) - 1; $m >= 0; $m--) {
					//for ($m=0;$m<count($matches);$m++) {
					$firstmatch = $matches[$m];
					$matches2 = self::wordMatch($dict, $info, $glyphClassMarks, $firstmatch + 1);
					if (count($matches2)) {
						// Set end of word marker in OTLdata at matchpos
						$info[$firstmatch]['wordend'] = true;
						$ptr = $firstmatch + 1;
						$rollover = $matches2;
						$secondmatch = true;
						break;
					}
				}
				if (!$secondmatch) {
					// Set end of word marker in OTLdata at end of longest first match
					$info[$matches[count($matches) - 1]]['wordend'] = true;
					$ptr = $matches[count($matches) - 1] + 1;
					// Move past any ASCII characters
					while (isset($info[$ptr]['uni']) && ($info[$ptr]['uni'] >> 8) == 0) {
						$ptr++;
					}
				}
			}
		}
	}

	/**
	 * Walk the dictionary trie from $ptr and return the end position of every word that matches.
	 *
	 * The file is a packed trie of four node types, one byte of tag each. A Split node carries the byte
	 * to compare and a 32-bit big-endian offset to take when the character sorts at or above it; a
	 * Linear node carries one byte that must match; Intermediate and Final mark the end of a word, Final
	 * being the end of this branch. Only the low byte of each codepoint is compared, which is why the
	 * caller must be in a script whose text stays inside one 256-codepoint block.
	 */
	private static function wordMatch(&$dict, $info, $glyphClassMarks, $ptr)
	{
		/*
		  Node type: Split.
		  Divide at < 98 >= 98
		  Offset for >= 98 == 79    (long 4-byte unsigned)

		  Node type: Linear match.
		  Char = 97

		  Intermediate match

		  Final match
		 */

		$dictptr = 0;
		$ok = true;
		$matches = [];
		while ($ok) {
			$x = ord($dict[$dictptr]);
			$c = $info[$ptr]['uni'] & 0xFF;
			if ($x == self::INTERMEDIATE_MATCH) {
//echo "DICT_INTERMEDIATE_MATCH: ".dechex($c).'<br />';
				// Do not match if next character in text is a Mark
				if (isset($info[$ptr]['uni']) && strpos($glyphClassMarks, $info[$ptr]['hex']) === false) {
					$matches[] = $ptr - 1;
				}
				$dictptr++;
			} elseif ($x == self::FINAL_MATCH) {
//echo "DICT_FINAL_MATCH: ".dechex($c).'<br />';
				// Do not match if next character in text is a Mark
				if (isset($info[$ptr]['uni']) && strpos($glyphClassMarks, $info[$ptr]['hex']) === false) {
					$matches[] = $ptr - 1;
				}
				return $matches;
			} elseif ($x == self::NODE_TYPE_LINEAR) {
//echo "DICT_NODE_TYPE_LINEAR: ".dechex($c).'<br />';
				$dictptr++;
				$m = ord($dict[$dictptr]);
				if ($c == $m) {
					$ptr++;
					if ($ptr > count($info) - 1) {
						$next = ord($dict[$dictptr + 1]);
						if ($next == self::INTERMEDIATE_MATCH || $next == self::FINAL_MATCH) {
							// Do not match if next character in text is a Mark
							if (isset($info[$ptr]['uni']) && strpos($glyphClassMarks, $info[$ptr]['hex']) === false) {
								$matches[] = $ptr - 1;
							}
						}
						return $matches;
					}
					$dictptr++;
					continue;
				} else {
//echo "DICT_NODE_TYPE_LINEAR NOT: ".dechex($c).'<br />';
					return $matches;
				}
			} elseif ($x == self::NODE_TYPE_SPLIT) {
//echo "DICT_NODE_TYPE_SPLIT ON ".dechex($d).": ".dechex($c).'<br />';
				$dictptr++;
				$d = ord($dict[$dictptr]);
				if ($c < $d) {
					$dictptr += 5;
				} else {
					$dictptr++;
					// Unsigned long 32-bit offset
					$offset = (ord($dict[$dictptr]) * 16777216) + (ord($dict[$dictptr + 1]) << 16) + (ord($dict[$dictptr + 2]) << 8) + ord($dict[$dictptr + 3]);
					$dictptr = $offset;
				}
			} else {
//echo "PROBLEM: ".($x).'<br />';
				$ok = false; // Something has gone wrong
			}
		}

		return $matches;
	}
}
