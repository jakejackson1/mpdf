<?php

namespace Mpdf\Shaper;

/**
 * Arabic and Syriac positional forms are resolved here rather than by GSUB, so until this moved out
 * of Otl the only way to ask what form a character got was to render a PDF and read it back.
 */
class ArabicTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const ALL_FORMS = 'isol fina fin2 fin3 medi med2 init';

	/** U+0628 BEH, dual-joining */
	const BEH = '00628';

	/** U+062F DAL, right-joining only: it joins to the letter before it but not to the one after */
	const DAL = '0062F';

	/** U+064E FATHA, a transparent-joining mark */
	const FATHA = '0064E';

	/**
	 * The font's rtlSUB table, as TTFontFile builds it: replacement hex per form, indexed
	 * 0=isolated 1=final 2=initial 3=medial
	 */
	private function glyphs()
	{
		return [
			self::BEH => ['B_ISOL', 'B_FINA', 'B_INIT', 'B_MEDI'],
			self::DAL => ['D_ISOL', 'D_FINA'],
		];
	}

	/**
	 * Two dual-joining letters: the first can only join forward, the last only backward
	 */
	public function testADualJoiningPairTakesInitialThenFinal()
	{
		$forms = $this->shape([self::BEH, self::BEH]);

		$this->assertSame([['B_INIT', 2], ['B_FINA', 1]], $forms);
	}

	public function testAThreeLetterRunTakesMedialInTheMiddle()
	{
		$forms = $this->shape([self::BEH, self::BEH, self::BEH]);

		$this->assertSame([['B_INIT', 2], ['B_MEDI', 3], ['B_FINA', 1]], $forms);
	}

	/**
	 * DAL joins to the preceding letter but not the following one, so the letter after it has to start
	 * a new run rather than continue the one DAL ends
	 */
	public function testARightJoiningLetterBreaksTheRunAfterIt()
	{
		$forms = $this->shape([self::BEH, self::DAL, self::BEH]);

		$this->assertSame([['B_INIT', 2], ['D_FINA', 1], ['B_ISOL', 0]], $forms);
	}

	public function testALoneLetterIsIsolated()
	{
		$this->assertSame([['B_ISOL', 0]], $this->shape([self::BEH]));
	}

	/**
	 * A transparent-joining mark is invisible to joining: the letters either side of it still see
	 * each other, and the mark itself is left alone
	 */
	public function testAMarkBetweenTwoLettersDoesNotBreakTheirJoin()
	{
		$forms = $this->shape([self::BEH, self::FATHA, self::BEH]);

		$this->assertSame([['B_INIT', 2], [self::FATHA, 0], ['B_FINA', 1]], $forms);
	}

	/**
	 * The four form features can be switched off through OTLtags, in which case the character is left
	 * as it came in rather than substituted
	 */
	public function testAFormWhoseFeatureIsNotRequestedIsLeftAlone()
	{
		$forms = $this->shape([self::BEH, self::BEH], 'isol fina');

		$this->assertSame([[self::BEH, 0], ['B_FINA', 1]], $forms);
	}

	/**
	 * @return array one [hex, form] pair per character, in logical order
	 */
	private function shape($hexes, $usetags = self::ALL_FORMS)
	{
		$info = [];
		foreach ($hexes as $hex) {
			$info[] = ['hex' => $hex, 'uni' => hexdec($hex)];
		}

		Arabic::shape($info, $this->glyphs(), ' ' . self::FATHA, $usetags, 'arab');

		$forms = [];
		foreach ($info as $char) {
			$forms[] = [$char['hex'], $char['form']];
		}

		return $forms;
	}

}
