<?php

namespace Mpdf;

/**
 * border-radius on an image: the picture is clipped to the curve, and a background or border follows it.
 */
class ImageBorderRadiusTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	use PageStreams;

	const IMAGE = '<img src="' . __DIR__ . '/../data/img/bayeux2.jpg" width="200" height="100">';

	/**
	 * The page's drawing operators for an image styled with the given CSS.
	 */
	private function page($css, $html = self::IMAGE)
	{
		return $this->pages($this->render('<style>img { ' . $css . ' }</style>' . $html))[0];
	}

	/**
	 * The clip path cut before the picture is placed, and the placement itself, keyed w/h/x/y in points.
	 */
	private function clipAndPlacement($page)
	{
		preg_match('/([-\d. ]+ m (?:[-\d. ]+ [lc] )+)W n ([-\d. ]+) cm \/I1 Do Q/', $page, $matches);
		$cm = explode(' ', $matches[2]);

		return [$matches[1], ['w' => $cm[0], 'h' => $cm[3], 'x' => $cm[4], 'y' => $cm[5]]];
	}

	public function testARadiusClipsThePictureToTheCurve()
	{
		list($clip) = $this->clipAndPlacement($this->page('border-radius: 5mm'));

		$this->assertSame(16, substr_count($clip, ' c '), 'four corners of four curves each');
	}

	public function testWithoutARadiusThePictureIsNotClipped()
	{
		$this->assertStringNotContainsString('W n', $this->page(''));
	}

	public function testOneCornerCanBeRoundedOnItsOwn()
	{
		list($clip) = $this->clipAndPlacement($this->page('border-top-left-radius: 8px'));

		$this->assertSame(4, substr_count($clip, ' c '));
	}

	public function testAPercentageIsOfTheImageBox()
	{
		list($clip, $placement) = $this->clipAndPlacement($this->page('border-radius: 50%'));

		/* The path starts at the top tangent point of the top-left corner, then lines up to the bottom-left one */
		$this->assertEqualsWithDelta($placement['x'] + $placement['w'] / 2, (float) strtok($clip, ' '), 0.01, 'horizontal radius is half the width');
		preg_match('/([-\d.]+) ([-\d.]+) l /', $clip, $line);
		$this->assertEqualsWithDelta($placement['y'] + $placement['h'] / 2, (float) $line[2], 0.01, 'vertical radius is half the height');
	}

	public function testTheBorderAndPaddingAreTakenOffTheCurveThatClipsThePicture()
	{
		list($clip, $placement) = $this->clipAndPlacement($this->page('border: 4px solid red; padding: 2px; border-radius: 10px'));

		$this->assertEqualsWithDelta($placement['x'] + 4 * 72 / 96, (float) strtok($clip, ' '), 0.01, '10px less 4px border and 2px padding, in points');
	}

	public function testTheBorderIsStrokedAlongTheCurve()
	{
		$page = $this->page('border: 4px solid red; border-radius: 10px');

		/* Each side leaves one corner on an arc, runs straight, and arrives at the next on an arc */
		$this->assertSame(4, preg_match_all('/m (?:[-\d. ]+ c ){2}[-\d. ]+ l (?:[-\d. ]+ c ){2}S/', $page));
	}

	/**
	 * A dot is a zero-length dash made visible by its round cap, so the butt cap a curved side takes must not follow it.
	 */
	public function testADottedBorderKeepsItsRoundCapsAroundTheCurve()
	{
		$page = $this->page('border: 3px dotted red; border-radius: 10px');

		$this->assertSame(4, preg_match_all('/\[[\d. ]+\] 0 d\s[^S]*S/', $page), 'four dotted sides');
		$this->assertDoesNotMatchRegularExpression('/\[[\d. ]+\] 0 d\s[^S]*0 J[^S]*S/', $page, 'none of them squared off');
	}

	public function testARadiusSmallerThanTheBorderStaysSquare()
	{
		$this->assertStringNotContainsString(' c ', $this->page('border: 6px solid red; border-radius: 2px'));
	}

	public function testABackgroundIsFilledInsideTheCurve()
	{
		preg_match('/m (?:[-\d. ]+ [lc] )+f/', $this->page('padding: 4px; background-color: blue; border-radius: 8px'), $matches);

		$this->assertSame(16, substr_count($matches[0], ' c '));
	}

	public function testACssTransformCarriesTheClipWithIt()
	{
		$page = $this->page('transform: scale(1.5); border-radius: 5mm');

		/* The clip sits inside the transform's "q ... cm", ahead of the placement */
		$this->assertMatchesRegularExpression('/q (?:-?\d+\.\d{4} ){6}cm [-\d. ]+ m .*? W n [-\d. ]+ cm \/I1 Do Q/', $page);
	}

	public function testAnImageInATableCellIsClippedToo()
	{
		list($clip) = $this->clipAndPlacement($this->page('border-radius: 5mm', '<table><tr><td>' . self::IMAGE . '</td></tr></table>'));

		$this->assertSame(16, substr_count($clip, ' c '));
	}
}
