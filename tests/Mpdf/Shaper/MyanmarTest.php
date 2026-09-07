<?php

namespace Mpdf\Shaper;

class MyanmarTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * Syllables are tagged as set_syllables() writes them: a serial in the high nibble so that
	 * neighbouring clusters can be told apart, and the cluster type in the low nibble
	 */
	private function syllable($serial, $type)
	{
		return ($serial << 4) | $type;
	}

	private function insert($info)
	{
		$dottedCircle = [['uni' => 0x25CC]];

		Myanmar::insert_dotted_circles($info, $dottedCircle);

		return array_map(function ($c) {
			return $c['uni'];
		}, $info);
	}

	public function testDottedCircleIsInsertedBeforeABrokenCluster()
	{
		$consonant = $this->syllable(1, Myanmar::CONSONANT_SYLLABLE);
		$broken = $this->syllable(2, Myanmar::BROKEN_CLUSTER);

		$out = $this->insert([
			['uni' => 0x1000, 'syllable' => $consonant],
			['uni' => 0x102F, 'syllable' => $broken],
		]);

		$this->assertSame([0x1000, 0x25CC, 0x102F], $out);
	}

	public function testEveryBrokenClusterGetsItsOwnDottedCircle()
	{
		$first = $this->syllable(1, Myanmar::BROKEN_CLUSTER);
		$consonant = $this->syllable(2, Myanmar::CONSONANT_SYLLABLE);
		$second = $this->syllable(3, Myanmar::BROKEN_CLUSTER);

		$out = $this->insert([
			['uni' => 0x102F, 'syllable' => $first],
			['uni' => 0x1000, 'syllable' => $consonant],
			['uni' => 0x103B, 'syllable' => $second],
		]);

		$this->assertSame([0x25CC, 0x102F, 0x1000, 0x25CC, 0x103B], $out);
	}

	public function testAdjacentBrokenClustersAreNotTreatedAsOne()
	{
		$first = $this->syllable(1, Myanmar::BROKEN_CLUSTER);
		$second = $this->syllable(2, Myanmar::BROKEN_CLUSTER);

		$out = $this->insert([
			['uni' => 0x102F, 'syllable' => $first],
			['uni' => 0x103B, 'syllable' => $second],
		]);

		$this->assertSame([0x25CC, 0x102F, 0x25CC, 0x103B], $out);
	}

	public function testARunWithoutBrokenClustersIsLeftAlone()
	{
		$consonant = $this->syllable(1, Myanmar::CONSONANT_SYLLABLE);

		$out = $this->insert([
			['uni' => 0x1000, 'syllable' => $consonant],
			['uni' => 0x102F, 'syllable' => $consonant],
		]);

		$this->assertSame([0x1000, 0x102F], $out);
	}

	public function testAnEmptyRunIsLeftAlone()
	{
		$this->assertSame([], $this->insert([]));
	}

}
