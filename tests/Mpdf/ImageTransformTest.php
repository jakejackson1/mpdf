<?php

namespace Mpdf;

class ImageTransformTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const IMAGE = '<img src="tests/data/img/bayeux2.jpg" width="200" height="100">';

	private function render($transform)
	{
		$mpdf = new Mpdf(['mode' => 'c']);
		$mpdf->SetBasePath(__DIR__ . '/../../');
		$mpdf->compress = false;
		$mpdf->WriteHTML('<style>img { transform: ' . $transform . ' }</style>' . self::IMAGE);

		return $mpdf->Output('', 'S');
	}

	/**
	 * The transformation matrix an image is wrapped in, as "a b c d" - a and d are the two scales.
	 * _transform() writes four decimal places, which is what tells it apart from the three the
	 * image placement that follows it uses.
	 */
	private function matrix($transform)
	{
		$matches = [];
		preg_match('/q (-?\d+\.\d{4}) (-?\d+\.\d{4}) (-?\d+\.\d{4}) (-?\d+\.\d{4}) /', $this->render($transform), $matches);

		return isset($matches[1]) ? implode(' ', array_slice($matches, 1, 4)) : '';
	}

	/**
	 * scaleX() passed 0 as the vertical scale, and transformScale() refuses a scale of zero, so an
	 * image styled scaleX() aborted the whole document over a value the stylesheet never named.
	 * See mpdf/mpdf#1079.
	 */
	public function testScaleXScalesTheHorizontalAxisAndLeavesTheVerticalAlone()
	{
		$this->assertSame('2.0000 0.0000 0.0000 1.0000', $this->matrix('scaleX(2)'));
	}

	public function testScaleYScalesTheVerticalAxisAndLeavesTheHorizontalAlone()
	{
		$this->assertSame('1.0000 0.0000 0.0000 2.0000', $this->matrix('scaleY(2)'));
	}

	public function testScaleWithOneValueScalesBothAxesByIt()
	{
		$this->assertSame('2.0000 0.0000 0.0000 2.0000', $this->matrix('scale(2)'));
	}

	public function testScaleWithTwoValuesScalesEachAxisByItsOwn()
	{
		$this->assertSame('2.0000 0.0000 0.0000 3.0000', $this->matrix('scale(2, 3)'));
	}

	/**
	 * A second value that is not a number reached "$vv[1] * 100" unguarded, which is a TypeError on
	 * PHP 8. The first value has been guarded since mpdf/mpdf#2191.
	 */
	public function testScaleWithASecondValueThatIsNotANumberFallsBackToTheFirst()
	{
		$this->assertSame('2.0000 0.0000 0.0000 2.0000', $this->matrix('scale(2, none)'));
	}

	public function testScaleWithAFirstValueThatIsNotANumberIsIgnored()
	{
		$this->assertSame('', $this->matrix('scale(none)'));
	}

	/**
	 * A zero the stylesheet does ask for is still refused - only the one scaleX() and scaleY()
	 * supplied on the author's behalf has gone
	 */
	public function testAScaleOfZeroIsStillRejected()
	{
		$this->expectException(MpdfException::class);
		$this->expectExceptionMessage('Please do not use values equal to zero for scaling');

		$this->render('scaleX(0)');
	}

}
