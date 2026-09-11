<?php

namespace Mpdf\Shaper;

use Mpdf\Utils\UtfString;

/**
 * Arabic and Syriac cursive joining.
 *
 * Every other script reaches its positional forms through GSUB: the font carries isol/fina/medi/init
 * lookups and Otl applies them like any other feature. Arabic and Syriac do not - mPDF resolves the
 * form here from the Unicode joining classes, then substitutes the glyph the font's rtlSUB table
 * names for it. So this stands in place of those four features rather than alongside them.
 *
 * @see https://unicode.org/Public/UNIDATA/ArabicShaping.txt
 * @see https://unicode.org/Public/UNIDATA/extracted/DerivedJoiningType.txt
 */
class Arabic
{

	// cf. http://unicode.org/Public/UNIDATA/ArabicShaping.txt
	// http://unicode.org/Public/UNIDATA/extracted/DerivedJoiningType.txt
	// JOIN TO FOLLOWING LETTER IN LOGICAL ORDER (i.e. AS INITIAL/MEDIAL FORM) = Unicode Left-Joining (+ Dual-Joining + Join_Causing 00640)
	public static $leftJoining = [
		0x0620 => 1, 0x0626 => 1, 0x0628 => 1, 0x062A => 1, 0x062B => 1, 0x062C => 1, 0x062D => 1, 0x062E => 1,
		0x0633 => 1, 0x0634 => 1, 0x0635 => 1, 0x0636 => 1, 0x0637 => 1, 0x0638 => 1, 0x0639 => 1, 0x063A => 1,
		0x063B => 1, 0x063C => 1, 0x063D => 1, 0x063E => 1, 0x063F => 1, 0x0640 => 1, 0x0641 => 1, 0x0642 => 1,
		0x0643 => 1, 0x0644 => 1, 0x0645 => 1, 0x0646 => 1, 0x0647 => 1, 0x0649 => 1, 0x064A => 1, 0x066E => 1,
		0x066F => 1, 0x0678 => 1, 0x0679 => 1, 0x067A => 1, 0x067B => 1, 0x067C => 1, 0x067D => 1, 0x067E => 1,
		0x067F => 1, 0x0680 => 1, 0x0681 => 1, 0x0682 => 1, 0x0683 => 1, 0x0684 => 1, 0x0685 => 1, 0x0686 => 1,
		0x0687 => 1, 0x069A => 1, 0x069B => 1, 0x069C => 1, 0x069D => 1, 0x069E => 1, 0x069F => 1, 0x06A0 => 1,
		0x06A1 => 1, 0x06A2 => 1, 0x06A3 => 1, 0x06A4 => 1, 0x06A5 => 1, 0x06A6 => 1, 0x06A7 => 1, 0x06A8 => 1,
		0x06A9 => 1, 0x06AA => 1, 0x06AB => 1, 0x06AC => 1, 0x06AD => 1, 0x06AE => 1, 0x06AF => 1, 0x06B0 => 1,
		0x06B1 => 1, 0x06B2 => 1, 0x06B3 => 1, 0x06B4 => 1, 0x06B5 => 1, 0x06B6 => 1, 0x06B7 => 1, 0x06B8 => 1,
		0x06B9 => 1, 0x06BA => 1, 0x06BB => 1, 0x06BC => 1, 0x06BD => 1, 0x06BE => 1, 0x06BF => 1, 0x06C1 => 1,
		0x06C2 => 1, 0x06CC => 1, 0x06CE => 1, 0x06D0 => 1, 0x06D1 => 1, 0x06FA => 1, 0x06FB => 1, 0x06FC => 1,
		0x06FF => 1,
		/* Arabic Supplement */
		0x0750 => 1, 0x0751 => 1, 0x0752 => 1, 0x0753 => 1, 0x0754 => 1, 0x0755 => 1, 0x0756 => 1, 0x0757 => 1,
		0x0758 => 1, 0x075C => 1, 0x075D => 1, 0x075E => 1, 0x075F => 1, 0x0760 => 1, 0x0761 => 1, 0x0762 => 1,
		0x0763 => 1, 0x0764 => 1, 0x0765 => 1, 0x0766 => 1, 0x0767 => 1, 0x0768 => 1, 0x0769 => 1, 0x076A => 1,
		0x076D => 1, 0x076E => 1, 0x076F => 1, 0x0770 => 1, 0x0772 => 1, 0x0775 => 1, 0x0776 => 1, 0x0777 => 1,
		0x077A => 1, 0x077B => 1, 0x077C => 1, 0x077D => 1, 0x077E => 1, 0x077F => 1,
		/* Extended Arabic */
		0x08A0 => 1, 0x08A2 => 1, 0x08A3 => 1, 0x08A4 => 1, 0x08A5 => 1, 0x08A6 => 1, 0x08A7 => 1, 0x08A8 => 1,
		0x08A9 => 1,
		/* 'syrc' Syriac */
		0x0712 => 1, 0x0713 => 1, 0x0714 => 1, 0x071A => 1, 0x071B => 1, 0x071C => 1, 0x071D => 1, 0x071F => 1,
		0x0720 => 1, 0x0721 => 1, 0x0722 => 1, 0x0723 => 1, 0x0724 => 1, 0x0725 => 1, 0x0726 => 1, 0x0727 => 1,
		0x0729 => 1, 0x072B => 1, 0x072D => 1, 0x072E => 1, 0x074E => 1, 0x074F => 1,
		/* N'Ko */
		0x07CA => 1, 0x07CB => 1, 0x07CC => 1, 0x07CD => 1, 0x07CE => 1, 0x07CF => 1, 0x07D0 => 1, 0x07D1 => 1,
		0x07D2 => 1, 0x07D3 => 1, 0x07D4 => 1, 0x07D5 => 1, 0x07D6 => 1, 0x07D7 => 1, 0x07D8 => 1, 0x07D9 => 1,
		0x07DA => 1, 0x07DB => 1, 0x07DC => 1, 0x07DD => 1, 0x07DE => 1, 0x07DF => 1, 0x07E0 => 1, 0x07E1 => 1,
		0x07E2 => 1, 0x07E3 => 1, 0x07E4 => 1, 0x07E5 => 1, 0x07E6 => 1, 0x07E7 => 1, 0x07E8 => 1, 0x07E9 => 1,
		0x07EA => 1, 0x07FA => 1,
		/* Mandaic */
		0x0841 => 1, 0x0842 => 1, 0x0843 => 1, 0x0844 => 1, 0x0845 => 1, 0x0847 => 1, 0x0848 => 1, 0x084A => 1,
		0x084B => 1, 0x084C => 1, 0x084D => 1, 0x084E => 1, 0x0850 => 1, 0x0851 => 1, 0x0852 => 1, 0x0853 => 1,
		0x0855 => 1,
		/* ZWJ U+200D */
		0x0200D => 1];

	/* JOIN TO PREVIOUS LETTER IN LOGICAL ORDER (i.e. AS FINAL/MEDIAL FORM) = Unicode Right-Joining (+ Dual-Joining + Join_Causing) */
	public static $rightJoining = [
		0x0620 => 1, 0x0622 => 1, 0x0623 => 1, 0x0624 => 1, 0x0625 => 1, 0x0626 => 1, 0x0627 => 1, 0x0628 => 1,
		0x0629 => 1, 0x062A => 1, 0x062B => 1, 0x062C => 1, 0x062D => 1, 0x062E => 1, 0x062F => 1, 0x0630 => 1,
		0x0631 => 1, 0x0632 => 1, 0x0633 => 1, 0x0634 => 1, 0x0635 => 1, 0x0636 => 1, 0x0637 => 1, 0x0638 => 1,
		0x0639 => 1, 0x063A => 1, 0x063B => 1, 0x063C => 1, 0x063D => 1, 0x063E => 1, 0x063F => 1, 0x0640 => 1,
		0x0641 => 1, 0x0642 => 1, 0x0643 => 1, 0x0644 => 1, 0x0645 => 1, 0x0646 => 1, 0x0647 => 1, 0x0648 => 1,
		0x0649 => 1, 0x064A => 1, 0x066E => 1, 0x066F => 1, 0x0671 => 1, 0x0672 => 1, 0x0673 => 1, 0x0675 => 1,
		0x0676 => 1, 0x0677 => 1, 0x0678 => 1, 0x0679 => 1, 0x067A => 1, 0x067B => 1, 0x067C => 1, 0x067D => 1,
		0x067E => 1, 0x067F => 1, 0x0680 => 1, 0x0681 => 1, 0x0682 => 1, 0x0683 => 1, 0x0684 => 1, 0x0685 => 1,
		0x0686 => 1, 0x0687 => 1, 0x0688 => 1, 0x0689 => 1, 0x068A => 1, 0x068B => 1, 0x068C => 1, 0x068D => 1,
		0x068E => 1, 0x068F => 1, 0x0690 => 1, 0x0691 => 1, 0x0692 => 1, 0x0693 => 1, 0x0694 => 1, 0x0695 => 1,
		0x0696 => 1, 0x0697 => 1, 0x0698 => 1, 0x0699 => 1, 0x069A => 1, 0x069B => 1, 0x069C => 1, 0x069D => 1,
		0x069E => 1, 0x069F => 1, 0x06A0 => 1, 0x06A1 => 1, 0x06A2 => 1, 0x06A3 => 1, 0x06A4 => 1, 0x06A5 => 1,
		0x06A6 => 1, 0x06A7 => 1, 0x06A8 => 1, 0x06A9 => 1, 0x06AA => 1, 0x06AB => 1, 0x06AC => 1, 0x06AD => 1,
		0x06AE => 1, 0x06AF => 1, 0x06B0 => 1, 0x06B1 => 1, 0x06B2 => 1, 0x06B3 => 1, 0x06B4 => 1, 0x06B5 => 1,
		0x06B6 => 1, 0x06B7 => 1, 0x06B8 => 1, 0x06B9 => 1, 0x06BA => 1, 0x06BB => 1, 0x06BC => 1, 0x06BD => 1,
		0x06BE => 1, 0x06BF => 1, 0x06C0 => 1, 0x06C1 => 1, 0x06C2 => 1, 0x06C3 => 1, 0x06C4 => 1, 0x06C5 => 1,
		0x06C6 => 1, 0x06C7 => 1, 0x06C8 => 1, 0x06C9 => 1, 0x06CA => 1, 0x06CB => 1, 0x06CC => 1, 0x06CD => 1,
		0x06CE => 1, 0x06CF => 1, 0x06D0 => 1, 0x06D1 => 1, 0x06D2 => 1, 0x06D3 => 1, 0x06D5 => 1, 0x06EE => 1,
		0x06EF => 1, 0x06FA => 1, 0x06FB => 1, 0x06FC => 1, 0x06FF => 1,
		/* Arabic Supplement */
		0x0750 => 1, 0x0751 => 1, 0x0752 => 1, 0x0753 => 1, 0x0754 => 1, 0x0755 => 1, 0x0756 => 1, 0x0757 => 1,
		0x0758 => 1, 0x0759 => 1, 0x075A => 1, 0x075B => 1, 0x075C => 1, 0x075D => 1, 0x075E => 1, 0x075F => 1,
		0x0760 => 1, 0x0761 => 1, 0x0762 => 1, 0x0763 => 1, 0x0764 => 1, 0x0765 => 1, 0x0766 => 1, 0x0767 => 1,
		0x0768 => 1, 0x0769 => 1, 0x076A => 1, 0x076B => 1, 0x076C => 1, 0x076D => 1, 0x076E => 1, 0x076F => 1,
		0x0770 => 1, 0x0771 => 1, 0x0772 => 1, 0x0773 => 1, 0x0774 => 1, 0x0775 => 1, 0x0776 => 1, 0x0777 => 1,
		0x0778 => 1, 0x0779 => 1, 0x077A => 1, 0x077B => 1, 0x077C => 1, 0x077D => 1, 0x077E => 1, 0x077F => 1,
		/* Extended Arabic */
		0x08A0 => 1, 0x08A2 => 1, 0x08A3 => 1, 0x08A4 => 1, 0x08A5 => 1, 0x08A6 => 1, 0x08A7 => 1, 0x08A8 => 1,
		0x08A9 => 1, 0x08AA => 1, 0x08AB => 1, 0x08AC => 1,
		/* 'syrc' Syriac */
		0x0710 => 1, 0x0712 => 1, 0x0713 => 1, 0x0714 => 1, 0x0715 => 1, 0x0716 => 1, 0x0717 => 1, 0x0718 => 1,
		0x0719 => 1, 0x071A => 1, 0x071B => 1, 0x071C => 1, 0x071D => 1, 0x071E => 1, 0x071F => 1, 0x0720 => 1,
		0x0721 => 1, 0x0722 => 1, 0x0723 => 1, 0x0724 => 1, 0x0725 => 1, 0x0726 => 1, 0x0727 => 1, 0x0728 => 1,
		0x0729 => 1, 0x072A => 1, 0x072B => 1, 0x072C => 1, 0x072D => 1, 0x072E => 1, 0x072F => 1, 0x074D => 1,
		// 0x074F is missing its => 1, so PHP files it as a *value* under the next free integer key and
		// U+074F SYRIAC LETTER SOGDIAN FE reads as not right-joining. Kept as it was found; changing it
		// changes what gets shaped, which is not this series' business. Reported separately.
		0x074E => 1, 0x074F,
		/* N'Ko */
		0x07CA => 1, 0x07CB => 1, 0x07CC => 1, 0x07CD => 1, 0x07CE => 1, 0x07CF => 1, 0x07D0 => 1, 0x07D1 => 1,
		0x07D2 => 1, 0x07D3 => 1, 0x07D4 => 1, 0x07D5 => 1, 0x07D6 => 1, 0x07D7 => 1, 0x07D8 => 1, 0x07D9 => 1,
		0x07DA => 1, 0x07DB => 1, 0x07DC => 1, 0x07DD => 1, 0x07DE => 1, 0x07DF => 1, 0x07E0 => 1, 0x07E1 => 1,
		0x07E2 => 1, 0x07E3 => 1, 0x07E4 => 1, 0x07E5 => 1, 0x07E6 => 1, 0x07E7 => 1, 0x07E8 => 1, 0x07E9 => 1,
		0x07EA => 1, 0x07FA => 1,
		/* Mandaic */
		0x0841 => 1, 0x0842 => 1, 0x0843 => 1, 0x0844 => 1, 0x0845 => 1, 0x0847 => 1, 0x0848 => 1, 0x084A => 1,
		0x084B => 1, 0x084C => 1, 0x084D => 1, 0x084E => 1, 0x0850 => 1, 0x0851 => 1, 0x0852 => 1, 0x0853 => 1,
		0x0855 => 1,
		0x0840 => 1, 0x0846 => 1, 0x0849 => 1, 0x084F => 1, 0x0854 => 1, /* Right joining */
		/* ZWJ U+200D */
		0x0200D => 1];

	/* VOWELS = TRANSPARENT-JOINING = Unicode Transparent-Joining type (not just vowels) */
	public static $transparent = [
		0x0610 => 1, 0x0611 => 1, 0x0612 => 1, 0x0613 => 1, 0x0614 => 1, 0x0615 => 1, 0x0616 => 1, 0x0617 => 1,
		0x0618 => 1, 0x0619 => 1, 0x061A => 1, 0x064B => 1, 0x064C => 1, 0x064D => 1, 0x064E => 1, 0x064F => 1,
		0x0650 => 1, 0x0651 => 1, 0x0652 => 1, 0x0653 => 1, 0x0654 => 1, 0x0655 => 1, 0x0656 => 1, 0x0657 => 1,
		0x0658 => 1, 0x0659 => 1, 0x065A => 1, 0x065B => 1, 0x065C => 1, 0x065D => 1, 0x065E => 1, 0x065F => 1,
		0x0670 => 1, 0x06D6 => 1, 0x06D7 => 1, 0x06D8 => 1, 0x06D9 => 1, 0x06DA => 1, 0x06DB => 1, 0x06DC => 1,
		0x06DF => 1, 0x06E0 => 1, 0x06E1 => 1, 0x06E2 => 1, 0x06E3 => 1, 0x06E4 => 1, 0x06E7 => 1, 0x06E8 => 1,
		0x06EA => 1, 0x06EB => 1, 0x06EC => 1, 0x06ED => 1,
		/* Extended Arabic */
		0x08E4 => 1, 0x08E5 => 1, 0x08E6 => 1, 0x08E7 => 1, 0x08E8 => 1, 0x08E9 => 1, 0x08EA => 1, 0x08EB => 1,
		0x08EC => 1, 0x08ED => 1, 0x08EE => 1, 0x08EF => 1, 0x08F0 => 1, 0x08F1 => 1, 0x08F2 => 1, 0x08F3 => 1,
		0x08F4 => 1, 0x08F5 => 1, 0x08F6 => 1, 0x08F7 => 1, 0x08F8 => 1, 0x08F9 => 1, 0x08FA => 1, 0x08FB => 1,
		0x08FC => 1, 0x08FD => 1, 0x08FE => 1,
		/* Arabic ligatures in presentation form (converted in 'ccmp' in e.g. Arial and Times ? need to add others in this range) */
		0xFC5E => 1, 0xFC5F => 1, 0xFC60 => 1, 0xFC61 => 1, 0xFC62 => 1,
		/*  'syrc' Syriac */
		0x070F => 1, 0x0711 => 1, 0x0730 => 1, 0x0731 => 1, 0x0732 => 1, 0x0733 => 1, 0x0734 => 1, 0x0735 => 1,
		0x0736 => 1, 0x0737 => 1, 0x0738 => 1, 0x0739 => 1, 0x073A => 1, 0x073B => 1, 0x073C => 1, 0x073D => 1,
		0x073E => 1, 0x073F => 1, 0x0740 => 1, 0x0741 => 1, 0x0742 => 1, 0x0743 => 1, 0x0744 => 1, 0x0745 => 1,
		0x0746 => 1, 0x0747 => 1, 0x0748 => 1, 0x0749 => 1, 0x074A => 1,
		/* N'Ko */
		0x07EB => 1, 0x07EC => 1, 0x07ED => 1, 0x07EE => 1, 0x07EF => 1, 0x07F0 => 1, 0x07F1 => 1, 0x07F2 => 1,
		0x07F3 => 1,
		/* Mandaic */
		0x0859 => 1, 0x085A => 1, 0x085B => 1,
		];

	public static function shape(&$info, $arabGlyphs, $glyphClassMarks, $usetags, $scriptTag)
	{
		// A GDEF mark is transparent to joining just as a vowel is, so the mark class joins the
		// Transparent-Joining table for this string. Array + keeps the left operand on collision, so a
		// codepoint in both stays as the Unicode table has it.
		$gcm = [];
		foreach (explode('| ', $glyphClassMarks) as $g) {
			$gcm[hexdec($g)] = 1;
		}
		$transparentJoin = self::$transparent + $gcm;

		$chars = [];
		for ($i = 0; $i < count($info); $i++) {
			$chars[] = $info[$i]['hex'];
		}

		$crntChar = null;
		$prevChar = null;
		$nextChar = null;
		$output = [];
		$max = count($chars);
		for ($i = $max - 1; $i >= 0; $i--) {
			$crntChar = $chars[$i];
			if ($i > 0) {
				$prevChar = hexdec($chars[$i - 1]);
			} else {
				$prevChar = null;
			}
			if ($prevChar && isset($transparentJoin[$prevChar]) && isset($chars[$i - 2])) {
				$prevChar = hexdec($chars[$i - 2]);
				if ($prevChar && isset($transparentJoin[$prevChar]) && isset($chars[$i - 3])) {
					$prevChar = hexdec($chars[$i - 3]);
					if ($prevChar && isset($transparentJoin[$prevChar]) && isset($chars[$i - 4])) {
						$prevChar = hexdec($chars[$i - 4]);
					}
				}
			}
			if ($crntChar && isset($transparentJoin[hexdec($crntChar)])) {
				// If next_char = RightJoining && prev_char = LeftJoining:
				if (isset($chars[$i + 1]) && $chars[$i + 1] && isset(self::$rightJoining[hexdec($chars[$i + 1])]) && $prevChar && isset(self::$leftJoining[$prevChar])) {
					$output[] = self::glyphs($crntChar, 1, $chars, $i, $scriptTag, $usetags, $arabGlyphs); // <final> form
				} else {
					$output[] = self::glyphs($crntChar, 0, $chars, $i, $scriptTag, $usetags, $arabGlyphs);  // <isolated> form
				}
				continue;
			}
			if (hexdec($crntChar) < 128) {
				$output[] = [$crntChar, 0];
				$nextChar = $crntChar;
				continue;
			}
			// 0=ISOLATED FORM :: 1=FINAL :: 2=INITIAL :: 3=MEDIAL
			$form = 0;
			if ($prevChar && isset(self::$leftJoining[$prevChar])) {
				$form++;
			}
			if ($nextChar && isset(self::$rightJoining[hexdec($nextChar)])) {
				$form += 2;
			}
			$output[] = self::glyphs($crntChar, $form, $chars, $i, $scriptTag, $usetags, $arabGlyphs);
			$nextChar = $crntChar;
		}
		$ra = array_reverse($output);
		for ($i = 0; $i < count($info); $i++) {
			$info[$i]['uni'] = hexdec($ra[$i][0]);
			$info[$i]['hex'] = $ra[$i][0];
			$info[$i]['form'] = $ra[$i][1]; // Actaul form substituted 0=ISOLATED FORM :: 1=FINAL :: 2=INITIAL :: 3=MEDIAL
		}
	}

	private static function glyphs($char, $type, &$chars, $i, $scriptTag, $usetags, $arabGlyphs)
	{
		// Optional Feature settings    // doesn't control Syriac at present
		if (($type === 0 && strpos($usetags, 'isol') === false) || ($type === 1 && strpos($usetags, 'fina') === false) || ($type === 2 && strpos($usetags, 'init') === false) || ($type === 3 && strpos($usetags, 'medi') === false)) {
			return [$char, 0];
		}

		// 0=ISOLATED FORM :: 1=FINAL :: 2=INITIAL :: 3=MEDIAL (:: 4=MED2 :: 5=FIN2 :: 6=FIN3)
		$retk = -1;
		// Alaph 00710 in Syriac
		if ($scriptTag == 'syrc' && $char == '00710') {
			// if there is a preceding (base?) character *** should search back to previous base - ignoring vowels and change $n
			// set $n as the position of the last base; for now we'll just do this:
			$n = $i - 1;
			// if the preceding (base) character cannot be joined to
			// not in self::$leftJoining i.e. not a char which can join to the next one
			if (isset($chars[$n]) && isset(self::$leftJoining[hexdec($chars[$n])])) {
				// if in the middle of Syriac words
				if (isset($chars[$i + 1]) && preg_match('/[\x{0700}-\x{0745}]/u', UtfString::code2utf(hexdec($chars[$n]))) && preg_match('/[\x{0700}-\x{0745}]/u', UtfString::code2utf(hexdec($chars[$i + 1]))) && isset($arabGlyphs[$char][4])) {
					$retk = 4;
				} // if at the end of Syriac words
				elseif (!isset($chars[$i + 1]) || !preg_match('/[\x{0700}-\x{0745}]/u', UtfString::code2utf(hexdec($chars[$i + 1])))) {
					// if preceding base character IS (00715|00716|0072A)
					if (strpos('0715|0716|072A', $chars[$n]) !== false && isset($arabGlyphs[$char][6])) {
						$retk = 6;
					} // elseif preceding base character is NOT (00715|00716|0072A)
					elseif (isset($arabGlyphs[$char][5])) {
						$retk = 5;
					}
				}
			}
			if ($retk != -1) {
				return [$arabGlyphs[$char][$retk], $retk];
			} else {
				return [$char, 0];
			}
		}

		if (($type > 0 || $type === 0) && isset($arabGlyphs[$char][$type])) {
			$retk = $type;
		} elseif ($type == 3 && isset($arabGlyphs[$char][1])) { // if <medial> not defined, but <final>, return <final>
			$retk = 1;
		} elseif ($type == 2 && isset($arabGlyphs[$char][0])) { // if <initial> not defined, but <isolated>, return <isolated>
			$retk = 0;
		}
		if ($retk != -1) {
			$match = true;
			// If GSUB includes a Backtrack or Lookahead condition (e.g. font ArabicTypesetting)
			if (isset($arabGlyphs[$char]['prel'][$retk]) && $arabGlyphs[$char]['prel'][$retk]) {
				$ig = 1;
				foreach ($arabGlyphs[$char]['prel'][$retk] as $k => $v) { // $k starts 0, 1...
					if (!isset($chars[$i - $ig - $k])) {
						$match = false;
					} elseif (strpos($v, $chars[$i - $ig - $k]) === false) {
						while (strpos($arabGlyphs[$char]['ignore'][$retk], $chars[$i - $ig - $k]) !== false) {  // ignore
							$ig++;
						}
						if (!isset($chars[$i - $ig - $k])) {
							$match = false;
						} elseif (strpos($v, $chars[$i - $ig - $k]) === false) {
							$match = false;
						}
					}
				}
			}
			if (isset($arabGlyphs[$char]['postl'][$retk]) && $arabGlyphs[$char]['postl'][$retk]) {
				$ig = 1;
				foreach ($arabGlyphs[$char]['postl'][$retk] as $k => $v) { // $k starts 0, 1...
					if (!isset($chars[$i + $ig + $k])) {
						$match = false;
					} elseif (strpos($v, $chars[$i + $ig + $k]) === false) {
						while (strpos($arabGlyphs[$char]['ignore'][$retk], $chars[$i + $ig + $k]) !== false) {  // ignore
							$ig++;
						}
						if (!isset($chars[$i + $ig + $k])) {
							$match = false;
						} elseif (strpos($v, $chars[$i + $ig + $k]) === false) {
							$match = false;
						}
					}
				}
			}
			if ($match) {
				return [$arabGlyphs[$char][$retk], $retk];
			} else {
				return [$char, 0];
			}
		} else {
			return [$char, 0];
		}
	}
}
