<?php

namespace Mpdf;

/**
 * GSUB Lookup Type 5 is a plain context - a sequence of input positions with no backtrack or
 * lookahead around it. Format 2 matches each position against a class from a ClassDef, Format 3
 * against a Coverage table of its own.
 *
 * Format 3's header is laid out differently to the Type 6 Format 3 it is otherwise a shorter
 * version of:
 * Type 5 puts both counts up front (glyphCount, then seqLookupCount, then one Coverage offset per
 * position), where Type 6 interleaves each count with the Coverage offsets it introduces. The two
 * mistakes that layout invites - reading the counts the wrong way round, and matching every input
 * position against the first position's Coverage - both leave a subtable that still parses, so the
 * Takri fixture below is chosen to fail if either is made: its three subtables count 3/2, 3/2 and
 * 2/1, and no subtable repeats a Coverage offset across positions.
 */
class ContextualSubstitutionTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * The Takri codepoints the fixture covers, as UTF-8. Spelt out in bytes because the \u{}
	 * escape needs PHP 7.0 and this suite still runs on 5.6.
	 */
	const I_MATRA = "\xF0\x91\x9A\xAE";  // U+116AE TAKRI VOWEL SIGN I

	const KA = "\xF0\x91\x9A\x8A";       // U+1168A TAKRI LETTER KA

	const BA = "\xF0\x91\x9A\xA0";       // U+116A0 TAKRI LETTER BA

	const ANUSVARA = "\xF0\x91\x9A\xAB"; // U+116AB TAKRI SIGN ANUSVARA

	/**
	 * The glyphs the lookups substitute in have no codepoint of their own, so they are mapped into
	 * the Private Use Area in the order the subset introduces them.
	 */
	const I_MATRA_ALT = "\xEE\x80\x80";    // U+E000

	const I_ANUSVARA = "\xEE\x80\x81";     // U+E001

	const NULL_MARK = "\xEE\x80\x82";      // U+E002

	const I_ANUSVARA_ALT = "\xEE\x80\x83"; // U+E003

	private function mpdf($fontkey, $file)
	{
		return new TextRecordingMpdf([
			'fontDir' => [__DIR__ . '/../data/ttf'],
			'fontdata' => [$fontkey => [
				'R' => $file,
				'useOTL' => 0xFF,
			]],
			'default_font' => $fontkey,
		]);
	}

	private function takri()
	{
		return $this->mpdf('takrisubset', 'NotoSansTakri-GSUB53-Subset.ttf');
	}

	private function music()
	{
		return $this->mpdf('musicsubset', 'NotoMusic-GSUB52-Subset.ttf');
	}

	/**
	 * A three-position context substituting at the first and last of them, which covers the step
	 * from a SubstLookupRecord's SequenceIndex back to the position it matched - the middle glyph
	 * is matched but not substituted, and comes through as it was written.
	 */
	public function testAppliesACoverageBasedContextSubstitutionAcrossThreePositions()
	{
		$mpdf = $this->takri();

		$mpdf->WriteHTML('<p>&#x116AE;&#x1168A;&#x116AB;</p>');

		$this->assertSame([self::I_ANUSVARA_ALT . self::KA . self::NULL_MARK], $mpdf->drawnText);
	}

	/**
	 * The same context with a letter drawn from a different Coverage at the middle position picks
	 * the other subtable, and so a different substitution at position 0. Matching every position
	 * against position 0's Coverage would leave both of these unsubstituted.
	 */
	public function testDistinguishesSubtablesByTheCoverageOfALaterPosition()
	{
		$mpdf = $this->takri();

		$mpdf->WriteHTML('<p>&#x116AE;&#x116A0;&#x116AB;</p>');

		$this->assertSame([self::I_ANUSVARA . self::BA . self::NULL_MARK], $mpdf->drawnText);
	}

	/**
	 * The two-position subtable, which carries a single substitution rather than two.
	 */
	public function testAppliesACoverageBasedContextSubstitutionAcrossTwoPositions()
	{
		$mpdf = $this->takri();

		$mpdf->WriteHTML('<p>&#x116AE;&#x1168A;</p>');

		$this->assertSame([self::I_MATRA_ALT . self::KA], $mpdf->drawnText);
	}

	/**
	 * The first input glyph with nothing following it is not the context, and is left alone.
	 */
	public function testLeavesTheFirstInputGlyphAloneOnItsOwn()
	{
		$mpdf = $this->takri();

		$mpdf->WriteHTML('<p>&#x116AE;</p>');

		$this->assertSame([self::I_MATRA], $mpdf->drawnText);
	}

	/**
	 * A second fixture, of a shape the Takri one does not have: two input positions whose
	 * substitutions map each of the pair onto the other, so a match swaps them. U+A8B4 is
	 * SAURASHTRA CONSONANT SIGN HAARU and U+A8C4 SAURASHTRA SIGN VIRAMA.
	 */
	public function testAppliesACoverageBasedContextSubstitutionAtEveryPosition()
	{
		$mpdf = $this->mpdf('saurashtrasubset', 'NotoSansSaurashtra-GSUB53-Subset.ttf');

		$mpdf->WriteHTML('<p>&#xA8B4;&#xA8C4;</p>');

		$this->assertSame(["\xEA\xA3\x84\xEA\xA2\xB4"], $mpdf->drawnText);
	}

	/**
	 * Format 2 picks its rule from the class of each input position rather than a Coverage table.
	 * The fixture's <ccmp> feature sorts five note glyphs into five classes and gives each its own
	 * rule for the stem that follows, so the same second codepoint is substituted differently
	 * depending only on what precedes it - which is the whole of what class-based context does.
	 *
	 * @dataProvider stemsByPrecedingNoteProvider
	 */
	public function testAppliesAClassBasedContextSubstitution($note, $expected)
	{
		$mpdf = $this->music();

		$mpdf->WriteHTML('<p>' . $note . '&#x1D165;</p>');

		$this->assertSame([$expected], $mpdf->drawnText);
	}

	/**
	 * Each note is U+1D14x MUSICAL SYMBOL NOTEHEAD, followed by U+1D165 MUSICAL SYMBOL COMBINING
	 * STEM. The substituted stems have no codepoint of their own and are mapped into the Private
	 * Use Area in the order the subset introduces them.
	 */
	public function stemsByPrecedingNoteProvider()
	{
		return [
			'class 1' => ['&#x1D148;', "\xF0\x9D\x85\x88\xEE\x80\x83"],
			'class 2' => ['&#x1D144;', "\xF0\x9D\x85\x84\xEE\x80\x82"],
			'class 3' => ['&#x1D146;', "\xF0\x9D\x85\x86\xEE\x80\x81"],
			'class 4' => ['&#x1D143;', "\xF0\x9D\x85\x83\xEE\x80\x84"],
			'class 5' => ['&#x1D1B9;', "\xF0\x9D\x86\xB9\xEE\x80\x85"],
			// With no note in front of it the stem matches none of those rules
			'no preceding note' => ['', "\xEE\x80\x80"],
		];
	}

}
