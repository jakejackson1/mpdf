<?php

namespace Mpdf\Tag;

use Mpdf\Mpdf;

class TrBordersTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	private function render($rowStyle)
	{
		$mpdf = new Mpdf();
		$mpdf->compress = false;
		$mpdf->WriteHTML(
			'<style>table { border-collapse: collapse } td { border: 1px solid #000000 }</style>'
			. '<table>'
			. '<tr' . ($rowStyle ? ' style="' . $rowStyle . '"' : '') . '><td>a</td><td>b</td></tr>'
			. '<tr><td>c</td><td>d</td></tr>'
			. '</table>'
		);

		return $mpdf->Output('', 'S');
	}

	/**
	 * Collapsed cell borders are stroked one segment at a time
	 */
	private function segments($pdf)
	{
		$matches = [];
		preg_match_all('/([\d.]+) ([\d.]+) m ([\d.]+) ([\d.]+) l S/', $pdf, $matches, PREG_SET_ORDER);

		return $matches;
	}

	private function horizontalSegments($pdf)
	{
		$horizontal = 0;
		foreach ($this->segments($pdf) as $segment) {
			if ($segment[2] === $segment[4]) {
				$horizontal++;
			}
		}

		return $horizontal;
	}

	private function redSegments($pdf)
	{
		return substr_count($pdf, '1.000 0.000 0.000 RG');
	}

	/**
	 * A row that names one side used to be handed the other three as null, which resolved to a
	 * border of zero width and switched off the ones the cells had asked for. See mpdf/mpdf#1893.
	 */
	public function testARowBorderOnOneSideLeavesTheCellBordersAlone()
	{
		$this->assertSame(
			$this->horizontalSegments($this->render('')),
			$this->horizontalSegments($this->render('border-left: 2px solid #ff0000'))
		);
	}

	public function testARowBorderOnOneSideLeavesTheCountOfSegmentsAlone()
	{
		$this->assertSame(
			count($this->segments($this->render(''))),
			count($this->segments($this->render('border-left: 2px solid #ff0000')))
		);
	}

	public function testARowBorderOnTheLeftIsStillDrawn()
	{
		$this->assertSame(1, $this->redSegments($this->render('border-left: 2px solid #ff0000')));
	}

	/**
	 * Tr::close() reads trborder-right but tested trborder-left, so a row that named only its right
	 * side never reached the code that draws it
	 */
	public function testARowBorderOnTheRightIsDrawn()
	{
		$this->assertSame(1, $this->redSegments($this->render('border-right: 2px solid #ff0000')));
	}

	public function testARowWithNoBorderOfItsOwnDrawsNone()
	{
		$this->assertSame(0, $this->redSegments($this->render('')));
	}

}
