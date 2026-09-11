<?php

namespace Mpdf;

/**
 * A "Q" puts back the graphics state that its "q" saved. The setters only write an operator when its value
 * differs from the one they last wrote, so unless what they remember goes back with it they stay quiet about
 * a value the restore has just undone, and whatever is drawn next takes the state the page was left in
 * rather than the one it asked for. See GravityPDF/mpdf#71.
 */
class GraphicsStateTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	use PageStreams;

	/**
	 * Two images asking for the same border. A CSS transform wraps each one's border in "q <matrix> cm ...
	 * Q", so the second draws its own from the state the page held before the first.
	 */
	private function twoTransformedImages()
	{
		$image = '<img src="' . $this->pngImage() . '" style="width: 40mm; height: 20mm; border: 1mm solid #c00; transform: rotate(20deg)">';

		return $image . $image;
	}

	public function testEachTransformedImageStrokesItsBorderInTheWidthItAsksFor()
	{
		$streams = $this->pages($this->render($this->twoTransformedImages()));

		$this->assertSame(2, substr_count($streams[0], '2.835 w'), 'Both borders should be stroked 1mm wide');
	}

	public function testEachTransformedImageStrokesItsBorderInTheColourItAsksFor()
	{
		$streams = $this->pages($this->render($this->twoTransformedImages()));

		$this->assertSame(2, substr_count($streams[0], '0.800 0.000 0.000 RG'), 'Both borders should be stroked in #c00');
	}

	public function testALineWidthSetInsideATransformIsSetAgainForWhatFollowsIt()
	{
		$mpdf = $this->mpdf();
		$mpdf->WriteHTML('<p>Page</p>');

		$mpdf->SetLineWidth(1);
		$mpdf->StartTransform();
		$mpdf->SetLineWidth(2);
		$mpdf->Line(10, 10, 50, 10);
		$mpdf->StopTransform();
		$mpdf->SetLineWidth(2);
		$mpdf->Line(10, 20, 50, 20);

		$streams = $this->pages($this->output($mpdf));

		$this->assertSame(2, substr_count($streams[0], '5.669 w'), 'The width the restore undid should be written again for the line after it');
	}

}
