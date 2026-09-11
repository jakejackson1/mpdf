<?php

namespace Mpdf;

use Mpdf\Utils\UtfString;

/**
 * Bidi algorithm
 *
 * Called from Mpdf after GSUB/GPOS has taken place, so the bidi class of each character is already
 * resolved and in string form. Nothing here reads a font table - this is the Unicode algorithm over
 * the classes Ucdn supplies, which is why it sits beside Ucdn rather than under Fonts.
 *
 * prepare() takes an Otl because resolving the explicit embedding controls means deleting them from
 * the text afterwards, and removing a character from text and its OTLdata together is Otl's job.
 *
 * @see https://www.unicode.org/reports/tr9/
 *
 * Bidirectional Character Types
 * =============================
 * Type  Description     General Scope
 * Strong
 * L     Left-to-Right       LRM, most alphabetic, syllabic, Han ideographs, non-European or non-Arabic digits, ...
 * LRE   Left-to-Right Embedding LRE
 * LRO   Left-to-Right Override  LRO
 * R     Right-to-Left       RLM, Hebrew alphabet, and related punctuation
 * AL    Right-to-Left Arabic    Arabic, Thaana, and Syriac alphabets, most punctuation specific to those scripts, ...
 * RLE   Right-to-Left Embedding RLE
 * RLO   Right-to-Left Override  RLO
 * Weak
 * PDF   Pop Directional Format      PDF
 * EN    European Number             European digits, Eastern Arabic-Indic digits, ...
 * ES    European Number Separator   Plus sign, minus sign
 * ET    European Number Terminator  Degree sign, currency symbols, ...
 * AN    Arabic Number           Arabic-Indic digits, Arabic decimal and thousands separators, ...
 * CS    Common Number Separator     Colon, comma, full stop (period), No-break space, ...
 * NSM   Nonspacing Mark             Characters marked Mn (Nonspacing_Mark) and Me (Enclosing_Mark) in the Unicode Character Database
 * BN    Boundary Neutral            Default ignorables, non-characters, and control characters, other than those explicitly given other types.
 * Neutral
 * B     Paragraph Separator     Paragraph separator, appropriate Newline Functions, higher-level protocol paragraph determination
 * S     Segment Separator   Tab
 * WS    Whitespace          Space, figure space, line separator, form feed, General Punctuation spaces, ...
 * ON    Other Neutrals      All other characters, including OBJECT REPLACEMENT CHARACTER
 */
class Bidi
{

	public static function sort($ta, $str, $dir, &$chunkOTLdata, $useGPOS)
	{

		$pel = 0; // paragraph embedding level
		$maxlevel = 0;
		$numchars = count($chunkOTLdata['char_data']);

		// Set the initial paragraph embedding level
		if ($dir == 'rtl') {
			$pel = 1;
		} else {
			$pel = 0;
		}

		// X1. Begin by setting the current embedding level to the paragraph embedding level. Set the directional override status to neutral.
		// Current Embedding Level
		$cel = $pel;
		// directional override status (-1 is Neutral)
		$dos = -1;
		$remember = [];

		// Array of characters data
		$chardata = [];

		// Process each character iteratively, applying rules X2 through X9. Only embedding levels from 0 to 61 are valid in this phase.
		// In the resolution of levels in rules I1 and I2, the maximum embedding level of 62 can be reached.
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chunkOTLdata['char_data'][$i]['uni'] == 8235) { // RLE
				// X2. With each RLE, compute the least greater odd embedding level.
				//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to neutral.
				//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
				$next_level = $cel + ($cel % 2) + 1;
				if ($next_level < 62) {
					$remember[] = ['num' => 8235, 'cel' => $cel, 'dos' => $dos];
					$cel = $next_level;
					$dos = -1;
				}
			} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8234) { // LRE
				// X3. With each LRE, compute the least greater even embedding level.
				//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to neutral.
				//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
				$next_level = $cel + 2 - ($cel % 2);
				if ($next_level < 62) {
					$remember[] = ['num' => 8234, 'cel' => $cel, 'dos' => $dos];
					$cel = $next_level;
					$dos = -1;
				}
			} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8238) { // RLO
				// X4. With each RLO, compute the least greater odd embedding level.
				//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to right-to-left.
				//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
				$next_level = $cel + ($cel % 2) + 1;
				if ($next_level < 62) {
					$remember[] = ['num' => 8238, 'cel' => $cel, 'dos' => $dos];
					$cel = $next_level;
					$dos = Ucdn::BIDI_CLASS_R;
				}
			} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8237) { // LRO
				// X5. With each LRO, compute the least greater even embedding level.
				//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to left-to-right.
				//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
				$next_level = $cel + 2 - ($cel % 2);
				if ($next_level < 62) {
					$remember[] = ['num' => 8237, 'cel' => $cel, 'dos' => $dos];
					$cel = $next_level;
					$dos = Ucdn::BIDI_CLASS_L;
				}
			} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8236) { // PDF
				// X7. With each PDF, determine the matching embedding or override code. If there was a valid matching code, restore (pop) the last remembered (pushed) embedding level and directional override.
				if (count($remember)) {
					$last = count($remember) - 1;
					if (($remember[$last]['num'] == 8235) || ($remember[$last]['num'] == 8234) || ($remember[$last]['num'] == 8238) ||
						($remember[$last]['num'] == 8237)) {
						$match = array_pop($remember);
						$cel = $match['cel'];
						$dos = $match['dos'];
					}
				}
			} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 10) { // NEW LINE
				// Reset to start values
				$cel = $pel;
				$dos = -1;
				$remember = [];
			} else {
				// X6. For all types besides RLE, LRE, RLO, LRO, and PDF:
				//  a. Set the level of the current character to the current embedding level.
				//  b. When the directional override status is not neutral, reset the current character type to directional override status.
				if ($dos != -1) {
					$chardir = $dos;
				} else {
					$chardir = $chunkOTLdata['char_data'][$i]['bidi_class'];
				}
				// stores string characters and other information
				if (isset($chunkOTLdata['GPOSinfo'][$i])) {
					$gpos = $chunkOTLdata['GPOSinfo'][$i];
				} else {
					$gpos = '';
				}
				$chardata[] = ['char' => $chunkOTLdata['char_data'][$i]['uni'], 'level' => $cel, 'type' => $chardir, 'group' => $chunkOTLdata['group'][$i], 'GPOSinfo' => $gpos];
			}
		}

		$numchars = count($chardata);

		// X8. All explicit directional embeddings and overrides are completely terminated at the end of each paragraph.
		// Paragraph separators are not included in the embedding.
		// X9. Remove all RLE, LRE, RLO, LRO, and PDF codes.
		// This is effectively done by only saving other codes to chardata
		// X10. Determine the start-of-sequence (sor) and end-of-sequence (eor) types, either L or R, for each isolating run sequence. These depend on the higher of the two levels on either side of the sequence boundary:
		// For sor, compare the level of the first character in the sequence with the level of the character preceding it in the paragraph or if there is none, with the paragraph embedding level.
		// For eor, compare the level of the last character in the sequence with the level of the character following it in the paragraph or if there is none, with the paragraph embedding level.
		// If the higher level is odd, the sor or eor is R; otherwise, it is L.

		$prelevel = $pel;
		$postlevel = $pel;
		$cel = $prelevel; // current embedding level
		for ($i = 0; $i < $numchars; ++$i) {
			$level = $chardata[$i]['level'];
			if ($i == 0) {
				$left = $prelevel;
			} else {
				$left = $chardata[$i - 1]['level'];
			}
			if ($i == ($numchars - 1)) {
				$right = $postlevel;
			} else {
				$right = $chardata[$i + 1]['level'];
			}
			$chardata[$i]['sor'] = max($left, $level) % 2 ? Ucdn::BIDI_CLASS_R : Ucdn::BIDI_CLASS_L;
			$chardata[$i]['eor'] = max($right, $level) % 2 ? Ucdn::BIDI_CLASS_R : Ucdn::BIDI_CLASS_L;
		}



		// 3.3.3 Resolving Weak Types
		// Weak types are now resolved one level run at a time. At level run boundaries where the type of the character on the other side of the boundary is required, the type assigned to sor or eor is used.
		// Nonspacing marks are now resolved based on the previous characters.
		// W1. Examine each nonspacing mark (NSM) in the level run, and change the type of the NSM to the type of the previous character. If the NSM is at the start of the level run, it will get the type of sor.
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_NSM) {
				if ($i == 0 || $chardata[$i]['level'] != $chardata[$i - 1]['level']) {
					$chardata[$i]['type'] = $chardata[$i]['sor'];
				} else {
					$chardata[$i]['type'] = $chardata[($i - 1)]['type'];
				}
			}
		}

		// W2. Search backward from each instance of a European number until the first strong type (R, L, AL, or sor) is found. If an AL is found, change the type of the European number to Arabic number.
		$prevlevel = -1;
		$levcount = 0;
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN) {
				$found = false;
				for ($j = $levcount; $j >= 0; $j--) {
					if ($chardata[$j]['type'] == Ucdn::BIDI_CLASS_AL) {
						$chardata[$i]['type'] = Ucdn::BIDI_CLASS_AN;
						$found = true;
						break;
					} elseif (($chardata[$j]['type'] == Ucdn::BIDI_CLASS_L) || ($chardata[$j]['type'] == Ucdn::BIDI_CLASS_R)) {
						$found = true;
						break;
					}
				}
			}
			if ($chardata[$i]['level'] != $prevlevel) {
				$levcount = 0;
			} else {
				++$levcount;
			}
			$prevlevel = $chardata[$i]['level'];
		}

		// W3. Change all ALs to R.
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_AL) {
				$chardata[$i]['type'] = Ucdn::BIDI_CLASS_R;
			}
		}

		// W4. A single European separator between two European numbers changes to a European number. A single common separator between two numbers of the same type changes to that type.
		for ($i = 1; $i < $numchars; ++$i) {
			if (($i + 1) < $numchars && $chardata[($i)]['level'] == $chardata[($i + 1)]['level'] && $chardata[($i)]['level'] == $chardata[($i - 1)]['level']) {
				if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ES && $chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_EN && $chardata[($i + 1)]['type'] == Ucdn::BIDI_CLASS_EN) {
					$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
				} elseif ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_CS && $chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_EN && $chardata[($i + 1)]['type'] == Ucdn::BIDI_CLASS_EN) {
					$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
				} elseif ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_CS && $chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_AN && $chardata[($i + 1)]['type'] == Ucdn::BIDI_CLASS_AN) {
					$chardata[$i]['type'] = Ucdn::BIDI_CLASS_AN;
				}
			}
		}

		// W5. A sequence of European terminators adjacent to European numbers changes to all European numbers.
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ET) {
				if ($i > 0 && $chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_EN && $chardata[($i)]['level'] == $chardata[($i - 1)]['level']) {
					$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
				} else {
					$j = $i + 1;
					while ($j < $numchars && $chardata[$j]['level'] == $chardata[$i]['level']) {
						if ($chardata[$j]['type'] == Ucdn::BIDI_CLASS_EN) {
							$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
							break;
						} elseif ($chardata[$j]['type'] != Ucdn::BIDI_CLASS_ET) {
							break;
						}
						++$j;
					}
				}
			}
		}

		// W6. Otherwise, separators and terminators change to Other Neutral.
		for ($i = 0; $i < $numchars; ++$i) {
			if (($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ET) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ES) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_CS)) {
				$chardata[$i]['type'] = Ucdn::BIDI_CLASS_ON;
			}
		}

		//W7. Search backward from each instance of a European number until the first strong type (R, L, or sor) is found. If an L is found, then change the type of the European number to L.
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN) {
				if ($i == 0) { // Start of Level run
					if ($chardata[$i]['sor'] == Ucdn::BIDI_CLASS_L) {
						$chardata[$i]['type'] = $chardata[$i]['sor'];
					}
				} else {
					for ($j = $i - 1; $j >= 0; $j--) {
						if ($chardata[$j]['level'] != $chardata[$i]['level']) { // Level run boundary
							if ($chardata[$j + 1]['sor'] == Ucdn::BIDI_CLASS_L) {
								$chardata[$i]['type'] = $chardata[$j + 1]['sor'];
							}
							break;
						} elseif ($chardata[$j]['type'] == Ucdn::BIDI_CLASS_L) {
							$chardata[$i]['type'] = Ucdn::BIDI_CLASS_L;
							break;
						} elseif ($chardata[$j]['type'] == Ucdn::BIDI_CLASS_R) {
							break;
						}
					}
				}
			}
		}

		// N1. A sequence of neutrals takes the direction of the surrounding strong text if the text on both sides has the same direction. European and Arabic numbers act as if they were R in terms of their influence on neutrals. Start-of-level-run (sor) and end-of-level-run (eor) are used at level run boundaries.
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ON || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_WS) {
				$left = -1;
				// LEFT
				if ($i == 0) {  // first char
					$left = $chardata[($i)]['sor'];
				} elseif ($chardata[($i - 1)]['level'] != $chardata[($i)]['level']) {  // run boundary
					$left = $chardata[($i)]['sor'];
				} elseif ($chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_L) {
					$left = Ucdn::BIDI_CLASS_L;
				} elseif ($chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_R || $chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_EN || $chardata[($i - 1)]['type'] == Ucdn::BIDI_CLASS_AN) {
					$left = Ucdn::BIDI_CLASS_R;
				}
				// RIGHT
				$right = -1;
				$j = $i;
				// move to the right of any following neutrals OR hit a run boundary
				while (($chardata[$j]['type'] == Ucdn::BIDI_CLASS_ON || $chardata[$j]['type'] == Ucdn::BIDI_CLASS_WS) && $j <= ($numchars - 1)) {
					if ($j == ($numchars - 1)) {  // last char
						$right = $chardata[($j)]['eor'];
						break;
					} elseif ($chardata[($j + 1)]['level'] != $chardata[($j)]['level']) {  // run boundary
						$right = $chardata[($j)]['eor'];
						break;
					} elseif ($chardata[($j + 1)]['type'] == Ucdn::BIDI_CLASS_L) {
						$right = Ucdn::BIDI_CLASS_L;
						break;
					} elseif ($chardata[($j + 1)]['type'] == Ucdn::BIDI_CLASS_R || $chardata[($j + 1)]['type'] == Ucdn::BIDI_CLASS_EN || $chardata[($j + 1)]['type'] == Ucdn::BIDI_CLASS_AN) {
						$right = Ucdn::BIDI_CLASS_R;
						break;
					}
					$j++;
				}
				if ($left > -1 && $left == $right) {
					$chardata[$i]['orig_type'] = $chardata[$i]['type']; // Need to store the original 'WS' for reference in L1 below
					$chardata[$i]['type'] = $left;
				}
			}
		}

		// N2. Any remaining neutrals take the embedding direction
		for ($i = 0; $i < $numchars; ++$i) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ON || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_WS) {
				$chardata[$i]['type'] = ($chardata[$i]['level'] % 2) ? Ucdn::BIDI_CLASS_R : Ucdn::BIDI_CLASS_L;
				$chardata[$i]['orig_type'] = $chardata[$i]['type']; // Need to store the original 'WS' for reference in L1 below
			}
		}

		// I1. For all characters with an even (left-to-right) embedding direction, those of type R go up one level and those of type AN or EN go up two levels.
		// I2. For all characters with an odd (right-to-left) embedding direction, those of type L, EN or AN go up one level.
		for ($i = 0; $i < $numchars; ++$i) {
			$odd = $chardata[$i]['level'] % 2;
			if ($odd) {
				if (($chardata[$i]['type'] == Ucdn::BIDI_CLASS_L) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_AN) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN)) {
					$chardata[$i]['level'] += 1;
				}
			} else {
				if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_R) {
					$chardata[$i]['level'] += 1;
				} elseif (($chardata[$i]['type'] == Ucdn::BIDI_CLASS_AN) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN)) {
					$chardata[$i]['level'] += 2;
				}
			}
			$maxlevel = max($chardata[$i]['level'], $maxlevel);
		}

		// NB
		//  Separate into lines at this point************
		//
		// L1. On each line, reset the embedding level of the following characters to the paragraph embedding level:
		//  1. Segment separators (Tab) 'S',
		//  2. Paragraph separators 'B',
		//  3. Any sequence of whitespace characters 'WS' preceding a segment separator or paragraph separator, and
		//  4. Any sequence of whitespace characters 'WS' at the end of the line.
		//  The types of characters used here are the original types, not those modified by the previous phase cf N1 and N2*******
		//  Because a Paragraph Separator breaks lines, there will be at most one per line, at the end of that line.

		for ($i = ($numchars - 1); $i > 0; $i--) {
			if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_WS || (isset($chardata[$i]['orig_type']) && $chardata[$i]['orig_type'] == Ucdn::BIDI_CLASS_WS)) {
				$chardata[$i]['level'] = $pel;
			} else {
				break;
			}
		}


		// L2. From the highest level found in the text to the lowest odd level on each line, including intermediate levels not actually present in the text, reverse any contiguous sequence of characters that are at that level or higher.
		for ($j = $maxlevel; $j > 0; $j--) {
			$ordarray = [];
			$revarr = [];
			$onlevel = false;
			for ($i = 0; $i < $numchars; ++$i) {
				if ($chardata[$i]['level'] >= $j) {
					$onlevel = true;

					// L4. A character is depicted by a mirrored glyph if and only if (a) the resolved directionality of that character is R, and (b) the Bidi_Mirrored property value of that character is true.
					if (isset(Ucdn::$mirror_pairs[$chardata[$i]['char']]) && $chardata[$i]['type'] == Ucdn::BIDI_CLASS_R) {
						$chardata[$i]['char'] = Ucdn::$mirror_pairs[$chardata[$i]['char']];
					}

					$revarr[] = $chardata[$i];
				} else {
					if ($onlevel) {
						$revarr = array_reverse($revarr);
						$ordarray = array_merge($ordarray, $revarr);
						$revarr = [];
						$onlevel = false;
					}
					$ordarray[] = $chardata[$i];
				}
			}
			if ($onlevel) {
				$revarr = array_reverse($revarr);
				$ordarray = array_merge($ordarray, $revarr);
			}
			$chardata = $ordarray;
		}

		$group = '';
		$e = '';
		$GPOS = [];
		$cctr = 0;
		$rtl_content = 0x0;
		foreach ($chardata as $cd) {
			$e .= UtfString::code2utf($cd['char']);
			$group .= $cd['group'];
			if ($useGPOS && is_array($cd['GPOSinfo'])) {
				$GPOS[$cctr] = $cd['GPOSinfo'];
				$GPOS[$cctr]['wDir'] = ($cd['level'] % 2) ? 'RTL' : 'LTR';
			}
			if ($cd['type'] == Ucdn::BIDI_CLASS_L) {
				$rtl_content |= 1;
			} elseif ($cd['type'] == Ucdn::BIDI_CLASS_R) {
				$rtl_content |= 2;
			}
			$cctr++;
		}


		$chunkOTLdata['group'] = $group;
		if ($useGPOS) {
			$chunkOTLdata['GPOSinfo'] = $GPOS;
		}

		return [$e, $rtl_content];
	}

	/**
	 * Resolve levels across a whole paragraph, where sort() works on one chunk.
	 *
	 * Two halves, run at different points: prepare() sets the level on each character in the OTLdata,
	 * from Mpdf::printbuffer(); reorder() then rearranges, from WriteFlowingBlock() and
	 * finishFlowingBlock(), once line breaks have divided the paragraph into lines.
	 */
	public static function prepare(&$para, $dir, Otl $otl)
	{

		// Set the initial paragraph embedding level
		$pel = 0; // paragraph embedding level
		if ($dir == 'rtl') {
			$pel = 1;
		}

		// X1. Begin by setting the current embedding level to the paragraph embedding level. Set the directional override status to neutral.
		// Current Embedding Level
		$cel = $pel;
		// directional override status (-1 is Neutral)
		$dos = -1;
		$remember = [];
		$controlchars = false;
		$strongrtl = false;
		$diid = 0; // direction isolate ID
		$dictr = 0; // direction isolate counter
		// Process each character iteratively, applying rules X2 through X9. Only embedding levels from 0 to 61 are valid in this phase.
		// In the resolution of levels in rules I1 and I2, the maximum embedding level of 62 can be reached.
		$numchunks = count($para);
		for ($nc = 0; $nc < $numchunks; $nc++) {
			$chunkOTLdata = & $para[$nc][18];

			$numchars = count($chunkOTLdata['char_data']);
			for ($i = 0; $i < $numchars; ++$i) {
				if ($chunkOTLdata['char_data'][$i]['uni'] == 8235) { // RLE
					// X2. With each RLE, compute the least greater odd embedding level.
					//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to neutral.
					//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
					$next_level = $cel + ($cel % 2) + 1;
					if ($next_level < 62) {
						$remember[] = ['num' => 8235, 'cel' => $cel, 'dos' => $dos];
						$cel = $next_level;
						$dos = -1;
						$controlchars = true;
					}
				} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8234) { // LRE
					// X3. With each LRE, compute the least greater even embedding level.
					//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to neutral.
					//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
					$next_level = $cel + 2 - ($cel % 2);
					if ($next_level < 62) {
						$remember[] = ['num' => 8234, 'cel' => $cel, 'dos' => $dos];
						$cel = $next_level;
						$dos = -1;
						$controlchars = true;
					}
				} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8238) { // RLO
					// X4. With each RLO, compute the least greater odd embedding level.
					//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to right-to-left.
					//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
					$next_level = $cel + ($cel % 2) + 1;
					if ($next_level < 62) {
						$remember[] = ['num' => 8238, 'cel' => $cel, 'dos' => $dos];
						$cel = $next_level;
						$dos = Ucdn::BIDI_CLASS_R;
						$controlchars = true;
					}
				} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8237) { // LRO
					// X5. With each LRO, compute the least greater even embedding level.
					//  a. If this new level would be valid, then this embedding code is valid. Remember (push) the current embedding level and override status. Reset the current level to this new level, and reset the override status to left-to-right.
					//  b. If the new level would not be valid, then this code is invalid. Do not change the current level or override status.
					$next_level = $cel + 2 - ($cel % 2);
					if ($next_level < 62) {
						$remember[] = ['num' => 8237, 'cel' => $cel, 'dos' => $dos];
						$cel = $next_level;
						$dos = Ucdn::BIDI_CLASS_L;
						$controlchars = true;
					}
				} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8236) { // PDF
					// X7. With each PDF, determine the matching embedding or override code. If there was a valid matching code, restore (pop) the last remembered (pushed) embedding level and directional override.
					if (count($remember)) {
						$last = count($remember) - 1;
						if (($remember[$last]['num'] == 8235) || ($remember[$last]['num'] == 8234) || ($remember[$last]['num'] == 8238) ||
							($remember[$last]['num'] == 8237)) {
							$match = array_pop($remember);
							$cel = $match['cel'];
							$dos = $match['dos'];
						}
					}
				} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8294 || $chunkOTLdata['char_data'][$i]['uni'] == 8295 ||
					$chunkOTLdata['char_data'][$i]['uni'] == 8296) { // LRI // RLI // FSI
					// X5a. With each RLI:
					// X5b. With each LRI:
					// X5c. With each FSI, apply rules P2 and P3 for First Strong character
					//  Set the RLI/LRI/FSI embedding level to the embedding level of the last entry on the directional status stack.
					if ($dos != -1) {
						$chardir = $dos;
					} else {
						$chardir = $chunkOTLdata['char_data'][$i]['bidi_class'];
					}
					$chunkOTLdata['char_data'][$i]['level'] = $cel;
					$chunkOTLdata['char_data'][$i]['type'] = $chardir;
					$chunkOTLdata['char_data'][$i]['diid'] = $diid;

					$fsi = '';
					// X5c. With each FSI, apply rules P2 and P3 within the isolate run for First Strong character
					if ($chunkOTLdata['char_data'][$i]['uni'] == 8296) { // FSI
						$lvl = 0;
						$nc2 = $nc;
						$i2 = $i;
						while (!($nc2 == ($numchunks - 1) && $i2 == ((count($para[$nc2][18]['char_data'])) - 1))) {  // while not at end of last chunk
							$i2++;
							if ($i2 >= count($para[$nc2][18]['char_data'])) {
								$nc2++;
								$i2 = 0;
							}
							if ($lvl > 0) {
								continue;
							}
							if ($para[$nc2][18]['char_data'][$i2]['uni'] == 8294 || $para[$nc2][18]['char_data'][$i2]['uni'] == 8295 || $para[$nc2][18]['char_data'][$i2]['uni'] == 8296) {
								$lvl++;
								continue;
							}
							if ($para[$nc2][18]['char_data'][$i2]['uni'] == 8297) {
								$lvl--;
								if ($lvl < 0) {
									break;
								}
							}
							if ($para[$nc2][18]['char_data'][$i2]['bidi_class'] === Ucdn::BIDI_CLASS_L || $para[$nc2][18]['char_data'][$i2]['bidi_class'] == Ucdn::BIDI_CLASS_AL || $para[$nc2][18]['char_data'][$i2]['bidi_class'] === Ucdn::BIDI_CLASS_R) {
								$fsi = $para[$nc2][18]['char_data'][$i2]['bidi_class'];
								break;
							}
						}
						// if fsi not found, fsi is same as paragraph embedding level
						if (!$fsi && $fsi !== 0) {
							if ($pel == 1) {
								$fsi = Ucdn::BIDI_CLASS_R;
							} else {
								$fsi = Ucdn::BIDI_CLASS_L;
							}
						}
					}

					if ($chunkOTLdata['char_data'][$i]['uni'] == 8294 || $fsi === Ucdn::BIDI_CLASS_L) { // LRI or FSI-L
						//  Compute the least even embedding level greater than the embedding level of the last entry on the directional status stack.
						$next_level = $cel + 2 - ($cel % 2);
					} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8295 || $fsi == Ucdn::BIDI_CLASS_R || $fsi == Ucdn::BIDI_CLASS_AL) { // RLI or FSI-R
						//  Compute the least odd embedding level greater than the embedding level of the last entry on the directional status stack.
						$next_level = $cel + ($cel % 2) + 1;
					}


					//  Increment the isolate count by one, and push an entry consisting of the new embedding level,
					//  neutral directional override status, and true directional isolate status onto the directional status stack.
					$remember[] = ['num' => $chunkOTLdata['char_data'][$i]['uni'], 'cel' => $cel, 'dos' => $dos, 'diid' => $diid];
					$cel = $next_level;
					$dos = -1;
					$diid = ++$dictr; // Set new direction isolate ID after incrementing direction isolate counter

					$controlchars = true;
				} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 8297) { // PDI
					// X6a. With each PDI, perform the following steps:
					//  Pop the last entry from the directional status stack and decrement the isolate count by one.
					while (count($remember)) {
						$last = count($remember) - 1;
						if (($remember[$last]['num'] == 8294) || ($remember[$last]['num'] == 8295) || ($remember[$last]['num'] == 8296)) {
							$match = array_pop($remember);
							$cel = $match['cel'];
							$dos = $match['dos'];
							$diid = $match['diid'];
							break;
						} // End/close any open embedding states not explicitly closed during the isolate
						elseif (($remember[$last]['num'] == 8235) || ($remember[$last]['num'] == 8234) || ($remember[$last]['num'] == 8238) ||
							($remember[$last]['num'] == 8237)) {
							$match = array_pop($remember);
						}
					}
					//  In all cases, set the PDI’s level to the embedding level of the last entry on the directional status stack left after the steps above.
					//  NB The level assigned to an isolate initiator is always the same as that assigned to the matching PDI.
					if ($dos != -1) {
						$chardir = $dos;
					} else {
						$chardir = $chunkOTLdata['char_data'][$i]['bidi_class'];
					}
					$chunkOTLdata['char_data'][$i]['level'] = $cel;
					$chunkOTLdata['char_data'][$i]['type'] = $chardir;
					$chunkOTLdata['char_data'][$i]['diid'] = $diid;
					$controlchars = true;
				} elseif ($chunkOTLdata['char_data'][$i]['uni'] == 10) { // NEW LINE
					// Reset to start values
					$cel = $pel;
					$dos = -1;
					$remember = [];
				} else {
					// X6. For all types besides RLE, LRE, RLO, LRO, and PDF:
					//  a. Set the level of the current character to the current embedding level.
					//  b. When the directional override status is not neutral, reset the current character type to directional override status.
					if ($dos != -1) {
						$chardir = $dos;
					} else {
						$chardir = $chunkOTLdata['char_data'][$i]['bidi_class'];
						if ($chardir == Ucdn::BIDI_CLASS_R || $chardir == Ucdn::BIDI_CLASS_AL) {
							$strongrtl = true;
						}
					}
					$chunkOTLdata['char_data'][$i]['level'] = $cel;
					$chunkOTLdata['char_data'][$i]['type'] = $chardir;
					$chunkOTLdata['char_data'][$i]['diid'] = $diid;
				}
			}
			// X8. All explicit directional embeddings and overrides are completely terminated at the end of each paragraph.
			// Paragraph separators are not included in the embedding.
			// X9. Remove all RLE, LRE, RLO, LRO, and PDF codes.
			if ($controlchars) {
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x80\xaa");
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x80\xab");
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x80\xac");
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x80\xad");
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x80\xae");
				preg_replace("/\x{202a}-\x{202e}/u", '', $para[$nc][0]);
			}
		}

		// Remove any blank chunks made by removing directional codes
		$numchunks = count($para);
		for ($nc = ($numchunks - 1); $nc >= 0; $nc--) {
			if (count($para[$nc][18]['char_data']) == 0) {
				array_splice($para, $nc, 1);
			}
		}
		if ($dir != 'rtl' && !$strongrtl && !$controlchars) {
			return;
		}

		$numchunks = count($para);

		// X10. Determine the start-of-sequence (sor) and end-of-sequence (eor) types, either L or R, for each isolating run sequence. These depend on the higher of the two levels on either side of the sequence boundary:
		// For sor, compare the level of the first character in the sequence with the level of the character preceding it in the paragraph or if there is none, with the paragraph embedding level.
		// For eor, compare the level of the last character in the sequence with the level of the character following it in the paragraph or if there is none, with the paragraph embedding level.
		// If the higher level is odd, the sor or eor is R; otherwise, it is L.

		for ($ir = 0; $ir <= $dictr; $ir++) {
			$prelevel = $pel;
			$postlevel = $pel;
			$firstchar = true;
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$chardata = & $para[$nc][18]['char_data'];
				$numchars = count($chardata);
				for ($i = 0; $i < $numchars; ++$i) {
					if (!isset($chardata[$i]['diid']) || $chardata[$i]['diid'] != $ir) {
						continue;
					} // Ignore characters in a different isolate run
					$right = $postlevel;
					$nc2 = $nc;
					$i2 = $i;
					while (!($nc2 == ($numchunks - 1) && $i2 == ((count($para[$nc2][18]['char_data'])) - 1))) {  // while not at end of last chunk
						$i2++;
						if ($i2 >= count($para[$nc2][18]['char_data'])) {
							$nc2++;
							$i2 = 0;
						}

						if (isset($para[$nc2][18]['char_data'][$i2]['diid']) && $para[$nc2][18]['char_data'][$i2]['diid'] == $ir) {
							$right = $para[$nc2][18]['char_data'][$i2]['level'];
							break;
						}
					}

					$level = $chardata[$i]['level'];
					if ($firstchar || $level != $prelevel) {
						$chardata[$i]['sor'] = max($prelevel, $level) % 2 ? Ucdn::BIDI_CLASS_R : Ucdn::BIDI_CLASS_L;
					}
					if (($nc == ($numchunks - 1) && $i == ($numchars - 1)) || $level != $right) {
						$chardata[$i]['eor'] = max($right, $level) % 2 ? Ucdn::BIDI_CLASS_R : Ucdn::BIDI_CLASS_L;
					}
					$prelevel = $level;
					$firstchar = false;
				}
			}
		}


		// 3.3.3 Resolving Weak Types
		// Weak types are now resolved one level run at a time. At level run boundaries where the type of the character on the other side of the boundary is required, the type assigned to sor or eor is used.
		// Nonspacing marks are now resolved based on the previous characters.
		// W1. Examine each nonspacing mark (NSM) in the level run, and change the type of the NSM to the type of the previous character. If the NSM is at the start of the level run, it will get the type of sor.
		for ($ir = 0; $ir <= $dictr; $ir++) {
			$prevtype = 0;
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$chardata = & $para[$nc][18]['char_data'];
				$numchars = count($chardata);
				for ($i = 0; $i < $numchars; ++$i) {
					if (!isset($chardata[$i]['diid']) || $chardata[$i]['diid'] != $ir) {
						continue;
					} // Ignore characters in a different isolate run
					if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_NSM) {
						if (isset($chardata[$i]['sor'])) {
							$chardata[$i]['type'] = $chardata[$i]['sor'];
						} else {
							$chardata[$i]['type'] = $prevtype;
						}
					}
					$prevtype = $chardata[$i]['type'];
				}
			}
		}

		// W2. Search backward from each instance of a European number until the first strong type (R, L, AL or sor) is found. If an AL is found, change the type of the European number to Arabic number.
		for ($ir = 0; $ir <= $dictr; $ir++) {
			$laststrongtype = -1;
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$chardata = & $para[$nc][18]['char_data'];
				$numchars = count($chardata);
				for ($i = 0; $i < $numchars; ++$i) {
					if (!isset($chardata[$i]['diid']) || $chardata[$i]['diid'] != $ir) {
						continue;
					} // Ignore characters in a different isolate run
					if (isset($chardata[$i]['sor'])) {
						$laststrongtype = $chardata[$i]['sor'];
					}
					if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN && $laststrongtype == Ucdn::BIDI_CLASS_AL) {
						$chardata[$i]['type'] = Ucdn::BIDI_CLASS_AN;
					}
					if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_L || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_R || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_AL) {
						$laststrongtype = $chardata[$i]['type'];
					}
				}
			}
		}


		// W3. Change all ALs to R.
		for ($nc = 0; $nc < $numchunks; $nc++) {
			$chardata = & $para[$nc][18]['char_data'];
			$numchars = count($chardata);
			for ($i = 0; $i < $numchars; ++$i) {
				if (isset($chardata[$i]['type']) && $chardata[$i]['type'] == Ucdn::BIDI_CLASS_AL) {
					$chardata[$i]['type'] = Ucdn::BIDI_CLASS_R;
				}
			}
		}


		// W4. A single European separator between two European numbers changes to a European number. A single common separator between two numbers of the same type changes to that type.
		for ($ir = 0; $ir <= $dictr; $ir++) {
			$prevtype = -1;
			$nexttype = -1;
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$chardata = & $para[$nc][18]['char_data'];
				$numchars = count($chardata);
				for ($i = 0; $i < $numchars; ++$i) {
					if (!isset($chardata[$i]['diid']) || $chardata[$i]['diid'] != $ir) {
						continue;
					} // Ignore characters in a different isolate run
					// Get next type
					$nexttype = -1;
					$nc2 = $nc;
					$i2 = $i;
					while (!($nc2 == ($numchunks - 1) && $i2 == ((count($para[$nc2][18]['char_data'])) - 1))) {  // while not at end of last chunk
						$i2++;
						if ($i2 >= count($para[$nc2][18]['char_data'])) {
							$nc2++;
							$i2 = 0;
						}

						if (isset($para[$nc2][18]['char_data'][$i2]['diid']) && $para[$nc2][18]['char_data'][$i2]['diid'] == $ir) {
							$nexttype = $para[$nc2][18]['char_data'][$i2]['type'];
							break;
						}
					}

					if (!isset($chardata[$i]['sor']) && !isset($chardata[$i]['eor'])) {
						if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ES && $prevtype == Ucdn::BIDI_CLASS_EN && $nexttype == Ucdn::BIDI_CLASS_EN) {
							$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
						} elseif ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_CS && $prevtype == Ucdn::BIDI_CLASS_EN && $nexttype == Ucdn::BIDI_CLASS_EN) {
							$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
						} elseif ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_CS && $prevtype == Ucdn::BIDI_CLASS_AN && $nexttype == Ucdn::BIDI_CLASS_AN) {
							$chardata[$i]['type'] = Ucdn::BIDI_CLASS_AN;
						}
					}
					$prevtype = $chardata[$i]['type'];
				}
			}
		}

		// W5. A sequence of European terminators adjacent to European numbers changes to all European numbers.
		for ($ir = 0; $ir <= $dictr; $ir++) {
			$prevtype = -1;
			$nexttype = -1;
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$chardata = & $para[$nc][18]['char_data'];
				$numchars = count($chardata);
				for ($i = 0; $i < $numchars; ++$i) {
					if (!isset($chardata[$i]['diid']) || $chardata[$i]['diid'] != $ir) {
						continue;
					} // Ignore characters in a different isolate run
					if (isset($chardata[$i]['sor'])) {
						$prevtype = $chardata[$i]['sor'];
					}

					if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ET) {
						if ($prevtype == Ucdn::BIDI_CLASS_EN) {
							$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
						} elseif (!isset($chardata[$i]['eor'])) {
							$nexttype = -1;
							$nc2 = $nc;
							$i2 = $i;
							while (!($nc2 == ($numchunks - 1) && $i2 == ((count($para[$nc2][18]['char_data'])) - 1))) { // while not at end of last chunk
								$i2++;
								if ($i2 >= count($para[$nc2][18]['char_data'])) {
									$nc2++;
									$i2 = 0;
								}
								if (!isset($para[$nc2][18]['char_data'][$i2]['diid']) || $para[$nc2][18]['char_data'][$i2]['diid'] != $ir) {
									continue;
								}
								$nexttype = $para[$nc2][18]['char_data'][$i2]['type'];
								if (isset($para[$nc2][18]['char_data'][$i2]['sor'])) {
									break;
								}
								if ($nexttype == Ucdn::BIDI_CLASS_EN) {
									$chardata[$i]['type'] = Ucdn::BIDI_CLASS_EN;
									break;
								} elseif ($nexttype != Ucdn::BIDI_CLASS_ET) {
									break;
								}
							}
						}
					}
					$prevtype = $chardata[$i]['type'];
				}
			}
		}

		// W6. Otherwise, separators and terminators change to Other Neutral.
		for ($nc = 0; $nc < $numchunks; $nc++) {
			$chardata = & $para[$nc][18]['char_data'];
			$numchars = count($chardata);
			for ($i = 0; $i < $numchars; ++$i) {
				if (isset($chardata[$i]['type']) && (($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ET) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ES) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_CS))) {
					$chardata[$i]['type'] = Ucdn::BIDI_CLASS_ON;
				}
			}
		}

		//W7. Search backward from each instance of a European number until the first strong type (R, L, or sor) is found. If an L is found, then change the type of the European number to L.
		for ($ir = 0; $ir <= $dictr; $ir++) {
			$laststrongtype = -1;
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$chardata = & $para[$nc][18]['char_data'];
				$numchars = count($chardata);
				for ($i = 0; $i < $numchars; ++$i) {
					if (!isset($chardata[$i]['diid']) || $chardata[$i]['diid'] != $ir) {
						continue;
					} // Ignore characters in a different isolate run
					if (isset($chardata[$i]['sor'])) {
						$laststrongtype = $chardata[$i]['sor'];
					}
					if (isset($chardata[$i]['type']) && $chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN && $laststrongtype == Ucdn::BIDI_CLASS_L) {
						$chardata[$i]['type'] = Ucdn::BIDI_CLASS_L;
					}
					if (isset($chardata[$i]['type']) && ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_L || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_R || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_AL)) {
						$laststrongtype = $chardata[$i]['type'];
					}
				}
			}
		}

		// N1. A sequence of neutrals takes the direction of the surrounding strong text if the text on both sides has the same direction. European and Arabic numbers act as if they were R in terms of their influence on neutrals. Start-of-level-run (sor) and end-of-level-run (eor) are used at level run boundaries.
		for ($ir = 0; $ir <= $dictr; $ir++) {
			$laststrongtype = -1;
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$chardata = & $para[$nc][18]['char_data'];
				$numchars = count($chardata);
				for ($i = 0; $i < $numchars; ++$i) {
					if (!isset($chardata[$i]['diid']) || $chardata[$i]['diid'] != $ir) {
						continue;
					} // Ignore characters in a different isolate run
					if (isset($chardata[$i]['sor'])) {
						$laststrongtype = $chardata[$i]['sor'];
					}
					if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ON || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_WS) {
						$left = -1;
						// LEFT
						if ($laststrongtype == Ucdn::BIDI_CLASS_R || $laststrongtype == Ucdn::BIDI_CLASS_EN || $laststrongtype == Ucdn::BIDI_CLASS_AN) {
							$left = Ucdn::BIDI_CLASS_R;
						} elseif ($laststrongtype == Ucdn::BIDI_CLASS_L) {
							$left = Ucdn::BIDI_CLASS_L;
						}
						// RIGHT
						$right = -1;
						// move to the right of any following neutrals OR hit a run boundary

						if (isset($chardata[$i]['eor'])) {
							$right = $chardata[$i]['eor'];
						} else {
							$nexttype = -1;
							$nc2 = $nc;
							$i2 = $i;
							while (!($nc2 == ($numchunks - 1) && $i2 == ((count($para[$nc2][18]['char_data'])) - 1))) { // while not at end of last chunk
								$i2++;
								if ($i2 >= count($para[$nc2][18]['char_data'])) {
									$nc2++;
									$i2 = 0;
								}
								if (!isset($para[$nc2][18]['char_data'][$i2]['diid']) || $para[$nc2][18]['char_data'][$i2]['diid'] != $ir) {
									continue;
								}
								$nexttype = $para[$nc2][18]['char_data'][$i2]['type'];
								if ($nexttype == Ucdn::BIDI_CLASS_R || $nexttype == Ucdn::BIDI_CLASS_EN || $nexttype == Ucdn::BIDI_CLASS_AN) {
									$right = Ucdn::BIDI_CLASS_R;
									break;
								} elseif ($nexttype == Ucdn::BIDI_CLASS_L) {
									$right = Ucdn::BIDI_CLASS_L;
									break;
								} elseif (isset($para[$nc2][18]['char_data'][$i2]['eor'])) {
									$right = $para[$nc2][18]['char_data'][$i2]['eor'];
									break;
								}
							}
						}

						if ($left > -1 && $left == $right) {
							$chardata[$i]['orig_type'] = $chardata[$i]['type']; // Need to store the original 'WS' for reference in L1 below
							$chardata[$i]['type'] = $left;
						}
					} elseif ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_L || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_R || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_AN) {
						$laststrongtype = $chardata[$i]['type'];
					}
				}
			}
		}

		// N2. Any remaining neutrals take the embedding direction
		for ($nc = 0; $nc < $numchunks; $nc++) {
			$chardata = & $para[$nc][18]['char_data'];
			$numchars = count($chardata);
			for ($i = 0; $i < $numchars; ++$i) {
				if (isset($chardata[$i]['type']) && ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_ON || $chardata[$i]['type'] == Ucdn::BIDI_CLASS_WS)) {
					$chardata[$i]['orig_type'] = $chardata[$i]['type']; // Need to store the original 'WS' for reference in L1 below
					$chardata[$i]['type'] = ($chardata[$i]['level'] % 2) ? Ucdn::BIDI_CLASS_R : Ucdn::BIDI_CLASS_L;
				}
			}
		}

		// I1. For all characters with an even (left-to-right) embedding direction, those of type R go up one level and those of type AN or EN go up two levels.
		// I2. For all characters with an odd (right-to-left) embedding direction, those of type L, EN or AN go up one level.
		for ($nc = 0; $nc < $numchunks; $nc++) {
			$chardata = & $para[$nc][18]['char_data'];
			$numchars = count($chardata);
			for ($i = 0; $i < $numchars; ++$i) {
				if (isset($chardata[$i]['level'])) {
					$odd = $chardata[$i]['level'] % 2;
					if ($odd) {
						if (($chardata[$i]['type'] == Ucdn::BIDI_CLASS_L) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_AN) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN)) {
							$chardata[$i]['level'] += 1;
						}
					} else {
						if ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_R) {
							$chardata[$i]['level'] += 1;
						} elseif (($chardata[$i]['type'] == Ucdn::BIDI_CLASS_AN) || ($chardata[$i]['type'] == Ucdn::BIDI_CLASS_EN)) {
							$chardata[$i]['level'] += 2;
						}
					}
				}
			}
		}

		// Remove Isolate formatters
		$numchunks = count($para);
		if ($controlchars) {
			for ($nc = 0; $nc < $numchunks; $nc++) {
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x81\xa6");
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x81\xa7");
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x81\xa8");
				$otl->removeChar($para[$nc][0], $para[$nc][18], "\xe2\x81\xa9");
				preg_replace("/\x{2066}-\x{2069}/u", '', $para[$nc][0]);
			}
			// Remove any blank chunks made by removing directional codes
			for ($nc = ($numchunks - 1); $nc >= 0; $nc--) {
				if (count($para[$nc][18]['char_data']) == 0) {
					array_splice($para, $nc, 1);
				}
			}
		}
	}

	/**
	 * Reorder, once divided into lines
	 */
	public static function reorder(&$chunkorder, &$content, &$cOTLdata, $blockdir)
	{
		$bidiData = [];

		// First combine into one array (and get the highest level in use)
		$numchunks = count($content);
		$maxlevel = 0;

		for ($nc = 0; $nc < $numchunks; $nc++) {

			$numchars = isset($cOTLdata[$nc]['char_data']) ? count($cOTLdata[$nc]['char_data']) : 0;
			for ($i = 0; $i < $numchars; ++$i) {

				$carac = [
					'level' => 0,
				];

				if (isset($cOTLdata[$nc]['GPOSinfo'][$i])) {
					$carac['GPOSinfo'] = $cOTLdata[$nc]['GPOSinfo'][$i];
				}

				$carac['uni'] = $cOTLdata[$nc]['char_data'][$i]['uni'];

				if (isset($cOTLdata[$nc]['char_data'][$i]['type'])) {
					$carac['type'] = $cOTLdata[$nc]['char_data'][$i]['type'];
				}

				if (isset($cOTLdata[$nc]['char_data'][$i]['level'])) {
					$carac['level'] = $cOTLdata[$nc]['char_data'][$i]['level'];
				}

				if (isset($cOTLdata[$nc]['char_data'][$i]['orig_type'])) {
					$carac['orig_type'] = $cOTLdata[$nc]['char_data'][$i]['orig_type'];
				}

				$carac['group'] = $cOTLdata[$nc]['group'][$i];
				$carac['chunkid'] = $chunkorder[$nc]; // gives font id and/or object ID

				$maxlevel = max((isset($carac['level']) ? $carac['level'] : 0), $maxlevel);
				$bidiData[] = $carac;
			}
		}
		if ($maxlevel === 0) {
			return;
		}

		$numchars = count($bidiData);

		// L1. On each line, reset the embedding level of the following characters to the paragraph embedding level:
		//  1. Segment separators (Tab) 'S',
		//  2. Paragraph separators 'B',
		//  3. Any sequence of whitespace characters 'WS' preceding a segment separator or paragraph separator, and
		//  4. Any sequence of whitespace characters 'WS' at the end of the line.
		//  The types of characters used here are the original types, not those modified by the previous phase cf N1 and N2*******
		//  Because a Paragraph Separator breaks lines, there will be at most one per line, at the end of that line.
		// Set the initial paragraph embedding level
		if ($blockdir === 'rtl') {
			$pel = 1;
		} else {
			$pel = 0;
		}

		for ($i = ($numchars - 1); $i > 0; $i--) {
			if ($bidiData[$i]['type'] == Ucdn::BIDI_CLASS_WS || (isset($bidiData[$i]['orig_type']) && $bidiData[$i]['orig_type'] == Ucdn::BIDI_CLASS_WS)) {
				$bidiData[$i]['level'] = $pel;
			} else {
				break;
			}
		}

		// L2. From the highest level found in the text to the lowest odd level on each line, including intermediate levels not actually present in the text, reverse any contiguous sequence of characters that are at that level or higher.
		for ($j = $maxlevel; $j > 0; $j--) {
			$ordarray = [];
			$revarr = [];
			$onlevel = false;
			for ($i = 0; $i < $numchars; ++$i) {

				if ($bidiData[$i]['level'] >= $j) {
					$onlevel = true;
					// L4. A character is depicted by a mirrored glyph if and only if (a) the resolved directionality of that character is R, and (b) the Bidi_Mirrored property value of that character is true.
					if (isset(Ucdn::$mirror_pairs[$bidiData[$i]['uni']]) && $bidiData[$i]['type'] == Ucdn::BIDI_CLASS_R) {
						$bidiData[$i]['uni'] = Ucdn::$mirror_pairs[$bidiData[$i]['uni']];
					}

					$revarr[] = $bidiData[$i];

				} else {

					if ($onlevel) {
						$revarr = array_reverse($revarr);
						$ordarray = array_merge($ordarray, $revarr);
						$revarr = [];
						$onlevel = false;
					}

					$ordarray[] = $bidiData[$i];
				}
			}

			if ($onlevel) {
				$revarr = array_reverse($revarr);
				$ordarray = array_merge($ordarray, $revarr);
			}

			$bidiData = $ordarray;
		}

		$content = [];
		$cOTLdata = [];
		$chunkorder = [];

		$nc = -1; // New chunk order ID
		$chunkid = -1;

		foreach ($bidiData as $carac) {
			if ($carac['chunkid'] != $chunkid) {
				$nc++;
				$chunkorder[$nc] = $carac['chunkid'];
				$cctr = 0;
				$content[$nc] = '';
				$cOTLdata[$nc]['group'] = '';
			}
			if ($carac['uni'] != 0xFFFC) {   // Object replacement character (65532)
				$content[$nc] .= UtfString::code2utf($carac['uni']);
				$cOTLdata[$nc]['group'] .= $carac['group'];
				if (!empty($carac['GPOSinfo'])) {
					if (isset($carac['GPOSinfo'])) {
						$cOTLdata[$nc]['GPOSinfo'][$cctr] = $carac['GPOSinfo'];
					}
					$cOTLdata[$nc]['GPOSinfo'][$cctr]['wDir'] = ($carac['level'] % 2) ? 'RTL' : 'LTR';
				}
			}
			$chunkid = $carac['chunkid'];
			$cctr++;
		}
	}
}
