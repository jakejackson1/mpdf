<?php

namespace Mpdf;

class RoundedBoxTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const PAGE_HEIGHT = 100;

	/**
	 * @var RoundedBox
	 */
	private $roundedBox;

	protected function set_up()
	{
		parent::set_up();

		$this->roundedBox = new RoundedBox();
	}

	private function radii($tl, $tr = null, $br = null, $bl = null)
	{
		return ['TL' => $tl, 'TR' => $tr ?: $tl, 'BR' => $br ?: $tl, 'BL' => $bl ?: $tl];
	}

	private function borders($w)
	{
		return ['top' => $w, 'right' => $w, 'bottom' => $w, 'left' => $w];
	}

	/**
	 * A point in millimetres from the top left, as the operators write it
	 */
	private function point($x, $y)
	{
		return sprintf('%.3F %.3F', $x * Mpdf::SCALE, (self::PAGE_HEIGHT - $y) * Mpdf::SCALE);
	}

	public function testRadiiThatFitAreLeftAlone()
	{
		$this->assertSame($this->radii([5, 4]), $this->roundedBox->fit(50, 30, $this->radii([5, 4]), $this->borders(1)));
	}

	public function testACornerSmallerThanItsBorderIsDropped()
	{
		$fitted = $this->roundedBox->fit(50, 30, $this->radii([5, 5], [2, 5]), $this->borders(3));

		$this->assertSame([5, 5], $fitted['TL']);
		$this->assertSame([0, 0], $fitted['TR']);
	}

	public function testABorderOnlyOneSideOfTheCornerDoesNotDropIt()
	{
		$fitted = $this->roundedBox->fit(50, 30, $this->radii([2, 2]), ['top' => 3, 'right' => 0, 'bottom' => 3, 'left' => 0]);

		$this->assertSame([2, 2], $fitted['TL']);
	}

	public function testRadiiThatWouldOverlapAlongAnEdgeShrinkTogether()
	{
		$fitted = $this->roundedBox->fit(20, 30, $this->radii([20, 5]), $this->borders(0));

		/* Two 20s across a 20 wide box halve, and every radius follows */
		$this->assertEqualsWithDelta(10, $fitted['TL'][0], 0.001);
		$this->assertEqualsWithDelta(2.5, $fitted['TL'][1], 0.001);
		$this->assertEqualsWithDelta(10, $fitted['BR'][0], 0.001);
	}

	public function testInsetRadiiLoseWhatLiesAlongEachSideAndStopAtSquare()
	{
		$inset = $this->roundedBox->inset($this->radii([5, 5]), ['top' => 1, 'right' => 2, 'bottom' => 6, 'left' => 3]);

		$this->assertSame([2, 4], $inset['TL'], 'less the left and top');
		$this->assertSame([3, 0], $inset['BR'], 'less the right, and the bottom takes it past square');
	}

	public function testASquareBoxIsFourLines()
	{
		$path = $this->roundedBox->path(self::PAGE_HEIGHT, 10, 20, 40, 50, $this->radii([0, 0]));

		$this->assertSame(
			$this->point(10, 20) . ' m ' . $this->point(10, 50) . ' l ' . $this->point(40, 50) . ' l ' . $this->point(40, 20) . ' l ' . $this->point(10, 20) . ' l ',
			$path
		);
	}

	public function testARoundedBoxStartsAfterTheTopLeftCornerAndTurnsEachOneOnFourCurves()
	{
		$path = $this->roundedBox->path(self::PAGE_HEIGHT, 10, 20, 40, 50, $this->radii([5, 5]));

		$this->assertStringStartsWith($this->point(15, 20) . ' m ', $path);
		$this->assertSame(16, substr_count($path, ' c '));
	}

	public function testAnImageBoxIsTheOuterBoxLessItsMargins()
	{
		$box = $this->roundedBox->imageBox($this->image(), 1);

		$this->assertSame([11, 22, 49, 39], [$box['x0'], $box['y0'], $box['x1'], $box['y1']]);
	}

	public function testTheContentEdgeLosesTheBorderAndPaddingOnEachSide()
	{
		$box = $this->roundedBox->imageBox($this->image(), 1);

		$this->assertSame([8, 8], $box['radii']['TL']);
		$this->assertEqualsWithDelta([5.0, 4.5], $box['content']['TL'], 0.001, '8 less 1 border and 2 left padding, 8 less 1 border and 2.5 top padding');
	}

	public function testTheContentEdgeStopsAtSquare()
	{
		$image = $this->image();
		$image['border_radius']['TL'] = [2, 2];

		$box = $this->roundedBox->imageBox($image, 1);

		$this->assertSame([0, 0], $box['content']['TL']);
	}

	public function testATableShrinkFactorScalesTheBoxAndRadiiTogether()
	{
		$box = $this->roundedBox->imageBox($this->image(), 2);

		$this->assertSame(10.5, $box['x0'], 'the margin shrinks');
		$this->assertSame([4, 4], $box['radii']['TL'], 'so does the radius');
	}

	public function testASideBetweenSquareCornersIsOneLineToTheOuterEdge()
	{
		$borderBox = ['x0' => 10, 'y0' => 20, 'x1' => 40, 'y1' => 50, 'radii' => $this->radii([0, 0])];

		$this->assertSame(
			$this->point(40, 21) . ' m ' . $this->point(10, 21) . ' l ',
			$this->roundedBox->side(self::PAGE_HEIGHT, 'top', $borderBox, 2),
			'along the middle of a 2 wide top border, from the right edge to the left'
		);
	}

	public function testASideBetweenRoundedCornersLeavesAndArrivesOnAnArc()
	{
		$borderBox = ['x0' => 10, 'y0' => 20, 'x1' => 40, 'y1' => 50, 'radii' => $this->radii([5, 5])];

		$side = $this->roundedBox->side(self::PAGE_HEIGHT, 'left', $borderBox, 2);

		$this->assertMatchesRegularExpression('/^[-\d. ]+ m (?:[-\d. ]+ c ){2}[-\d. ]+ l (?:[-\d. ]+ c ){2}$/', $side);
	}

	/**
	 * A 40 by 20 picture at (10, 20) with 1 of margin (2 at the top), 1 of border and 2 of padding (2.5 at the top),
	 * rounded by 8
	 */
	private function image()
	{
		$image = [
			'OUTER-X' => 10,
			'OUTER-Y' => 20,
			'OUTER-WIDTH' => 40,
			'OUTER-HEIGHT' => 20,
			'border_radius' => $this->radii([8, 8]),
		];
		foreach (['top', 'right', 'bottom', 'left'] as $side) {
			$image['margin_' . $side] = 1;
			$image['padding_' . $side] = 2;
			$image['border_' . $side] = ['w' => 1];
		}
		$image['padding_top'] = 2.5;
		$image['margin_top'] = 2;

		return $image;
	}
}
