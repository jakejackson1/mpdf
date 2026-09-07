<?php

namespace Mpdf\Tag;

use Mpdf\Mpdf;

class EmptyTextAreaTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	private function render($html, $config = [])
	{
		$mpdf = new Mpdf($config);
		$mpdf->compress = false;
		$mpdf->WriteHTML($html);

		return $mpdf->Output('', 'S');
	}

	/**
	 * A textarea that is not an active form field is drawn as a filled and stroked rectangle
	 */
	private function boxes($pdf)
	{
		return substr_count($pdf, ' re B');
	}

	private function formFields($pdf)
	{
		return substr_count($pdf, '/FT /Tx');
	}

	private function drawnText($pdf)
	{
		$matches = [];
		preg_match_all('#\((.*?)\)\s*Tj#s', $pdf, $matches);

		return str_replace("\0", '', implode('|', $matches[1]));
	}

	/**
	 * An empty textarea is given a single space to stand in for the text node that draws the field,
	 * and that space used to be swallowed wherever leading whitespace is ignored. See
	 * mpdf/mpdf#1735 and mpdf/mpdf#838.
	 */
	public function testAnEmptyTextAreaOpeningTheDocumentIsDrawn()
	{
		$this->assertSame(1, $this->boxes($this->render('<textarea name="t"></textarea>')));
	}

	public function testAnEmptyTextAreaInATableCellIsDrawn()
	{
		$pdf = $this->render('<table><tr><td><textarea name="t"></textarea></td><td>x</td></tr></table>');

		$this->assertSame(1, $this->boxes($pdf));
	}

	public function testTwoEmptyTextAreasAreBothDrawn()
	{
		$this->assertSame(
			2,
			$this->boxes($this->render('<textarea name="a"></textarea><textarea name="b"></textarea>'))
		);
	}

	public function testAnEmptyTextAreaBecomesAnActiveFormField()
	{
		$pdf = $this->render('<textarea name="t"></textarea>', ['useActiveForms' => true]);

		$this->assertSame(1, $this->formFields($pdf));
	}

	public function testAnEmptyTextAreaIsDrawnJustLikeOneHoldingASpace()
	{
		$this->assertSame(
			$this->boxes($this->render('before<textarea name="t">&nbsp;</textarea>after')),
			$this->boxes($this->render('before<textarea name="t"></textarea>after'))
		);
	}

	/**
	 * The space is kept by letting a form element through the leading whitespace check rather than
	 * by putting the parser into pre mode, which would outlive the tag and unindent the block it
	 * sits in
	 */
	public function testATextAreaInsideAPreLeavesTheIndentationAlone()
	{
		$pdf = $this->render("<pre>  a\n  b<textarea name=\"t\"></textarea>  c\n  d</pre>");

		$this->assertSame('  a|  b|  c|  d', $this->drawnText($pdf));
	}

}
