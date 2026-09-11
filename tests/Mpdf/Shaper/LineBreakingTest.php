<?php

namespace Mpdf\Shaper;

class LineBreakingTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const TSHEG = 0x0F0B;

	const SHAD = 0x0F0D;

	const RIN_CHEN_SPUNGS_SHAD = 0x0F0E;

	public function testTibetanBreaksAfterATsheg()
	{
		$this->assertSame([1], $this->tibetan([0x0F40, self::TSHEG, 0x0F41]));
	}

	public function testTibetanBreaksAfterAShad()
	{
		$this->assertSame([1], $this->tibetan([0x0F40, self::SHAD, 0x0F41]));
	}

	/**
	 * A shad followed by another shad, or by U+0F0E, is one unit of punctuation. The break comes after
	 * the pair rather than between them, which would strand half of it on the next line.
	 */
	public function testTibetanBreaksAfterAPairOfShadsRatherThanBetweenThem()
	{
		$this->assertSame([2], $this->tibetan([0x0F40, self::SHAD, self::SHAD, 0x0F41]));
	}

	public function testTibetanDoesNotBreakBeforeARinChenSpungsShad()
	{
		$this->assertSame([], $this->tibetan([0x0F40, self::SHAD, self::RIN_CHEN_SPUNGS_SHAD]));
	}

	public function testTibetanFindsNoBreakWhereThereIsNoSyllableMark()
	{
		$this->assertSame([], $this->tibetan([0x0F40, 0x0F41, 0x0F42]));
	}

	/**
	 * The dictionary is a packed trie with no header and no documentation but its own reader, so a
	 * word matched out of one built here by hand is the only executable statement of its format.
	 */
	public function testAWordInTheDictionaryEndsAWord()
	{
		// Thai "ทด" = U+0E17 U+0E14, matched on low bytes 0x17 then 0x14
		$dict = $this->linear([0x17, 0x14]) . chr(0x04);

		$this->assertSame([1], $this->southEastAsian($dict, [0x0E17, 0x0E14, 0x0E2A, 0x0E2D]));
	}

	public function testTextThatIsNotInTheDictionaryEndsNoWord()
	{
		$dict = $this->linear([0x17, 0x14]) . chr(0x04);

		$this->assertSame([], $this->southEastAsian($dict, [0x0E2A, 0x0E2D, 0x0E17, 0x0E2A]));
	}

	/**
	 * A Split node picks a branch by comparing one byte: below the pivot the walk carries straight on
	 * past the six-byte node, at or above it jumps to the offset the node carries.
	 */
	public function testASplitNodeSendsTheWalkDownTheRightBranch()
	{
		$low = $this->linear([0x14]) . chr(0x04);
		$high = $this->linear([0x2A]) . chr(0x04);

		// Split on 0x20 at offset 0; the low branch follows inline, the high branch after it
		$dict = chr(0x01) . chr(0x20) . pack('N', 6 + strlen($low)) . $low . $high;

		$this->assertSame([0], $this->southEastAsian($dict, [0x0E14, 0x0E2D, 0x0E2D, 0x0E2D]));
		$this->assertSame([0], $this->southEastAsian($dict, [0x0E2A, 0x0E2D, 0x0E2D, 0x0E2D]));
		$this->assertSame([], $this->southEastAsian($dict, [0x0E2D, 0x0E2D, 0x0E2D, 0x0E2D]));
	}

	/**
	 * One Linear node per byte: the tag, then the byte that has to match
	 */
	private function linear($bytes)
	{
		$dict = '';
		foreach ($bytes as $byte) {
			$dict .= chr(0x02) . chr($byte);
		}

		return $dict;
	}

	/**
	 * @return array the indexes marked as ending a word
	 */
	private function tibetan($unicode)
	{
		$info = $this->info($unicode);
		LineBreaking::tibetan($info);

		return $this->wordEnds($info);
	}

	/**
	 * @return array the indexes marked as ending a word
	 */
	private function southEastAsian($dict, $unicode)
	{
		$info = $this->info($unicode);
		LineBreaking::southEastAsian($info, $dict, '');

		return $this->wordEnds($info);
	}

	private function info($unicode)
	{
		$info = [];
		foreach ($unicode as $char) {
			$info[] = ['uni' => $char, 'hex' => sprintf('%05X', $char)];
		}

		return $info;
	}

	private function wordEnds($info)
	{
		$ends = [];
		foreach ($info as $i => $char) {
			if (!empty($char['wordend'])) {
				$ends[] = $i;
			}
		}

		return $ends;
	}

}
