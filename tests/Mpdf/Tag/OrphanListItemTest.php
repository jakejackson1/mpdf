<?php

namespace Mpdf\Tag;

use Mpdf\Mpdf;

class OrphanListItemTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var string[]
	 */
	private $raised;

	protected function set_up()
	{
		parent::set_up();

		$this->raised = [];
	}

	private function render($html)
	{
		$mpdf = new Mpdf();
		$mpdf->compress = false;

		set_error_handler(function ($no, $message, $file) {
			if (false !== strpos($file, 'BlockTag.php') || false !== strpos($file, 'Mpdf.php')) {
				$this->raised[] = $message;
			}

			return true;
		});

		$mpdf->WriteHTML($html);
		$pdf = $mpdf->Output('', 'S');

		restore_error_handler();

		return $pdf;
	}

	/**
	 * A disc marker is drawn as four bezier curves
	 */
	private function markerCurves($pdf)
	{
		return preg_match_all('/ c$/m', $pdf);
	}

	private function drawnText($pdf)
	{
		$matches = [];
		preg_match_all('#\((.*?)\)\s*Tj#s', $pdf, $matches);

		return preg_replace('/[^A-Za-z0-9.]/', '', implode('', $matches[1]));
	}

	/**
	 * The list style keys are set when a UL or OL opens, so a LI with neither around it reached
	 * _setListMarker() with three undefined keys. See mpdf/mpdf#1890.
	 */
	public function testAListItemWithNoListAroundItRaisesNothing()
	{
		$this->render('<li>orphan</li>');

		$this->assertSame([], $this->raised);
	}

	public function testAListItemWithNoListAroundItGetsTheMarkerAUnorderedListWouldGiveIt()
	{
		$this->assertSame(
			$this->markerCurves($this->render('<ul><li>orphan</li></ul>')),
			$this->markerCurves($this->render('<li>orphan</li>'))
		);
	}

	public function testAListItemWithNoListAroundItStillHonoursItsOwnListStyleType()
	{
		$pdf = $this->render('<li style="list-style-type: decimal">orphan</li>');

		$this->assertSame(0, $this->markerCurves($pdf));
		$this->assertSame('orphan1.', $this->drawnText($pdf));
	}

	public function testAListItemWithNoListAroundItCanTurnItsMarkerOff()
	{
		$this->assertSame(0, $this->markerCurves($this->render('<li style="list-style-type: none">orphan</li>')));
	}

	public function testAListItemInsideAListIsUnchanged()
	{
		$this->assertSame('orphan1.', $this->drawnText($this->render('<ol><li>orphan</li></ol>')));
	}

}
