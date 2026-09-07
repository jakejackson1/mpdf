<?php

namespace Mpdf;

class BorderDetailsTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	protected function set_up()
	{
		parent::set_up();

		$this->mpdf = new Mpdf();
	}

	protected function tear_down()
	{
		$this->mpdf->cleanup();

		parent::tear_down();
	}

	public function borderValueProvider()
	{
		return [
			'width only' => ['2px'],
			'keyword width' => ['medium'],
			'zero width' => ['0'],
			'zero length' => ['0px'],
			'no border' => ['none'],
			'hidden' => ['hidden'],
			'width and style' => ['1px solid'],
			'width, style and colour' => ['1px solid #000000'],
			'colour first' => ['#000000 1px solid'],
			'zero width, style and colour' => ['0 none #000000'],
			'more parts than a border has' => ['1px solid #000000 inset'],
			'nothing at all' => [''],
		];
	}

	/**
	 * Callers replace a whole side with what this returns, so every branch has to describe a side
	 * fully or the keys the collapsing and painting code reads go missing. See mpdf/mpdf#1892.
	 *
	 * @dataProvider borderValueProvider
	 */
	public function testEveryBorderIsDescribedByTheSameKeys($value)
	{
		$this->assertSame(['s', 'w', 'c', 'style', 'dom'], array_keys($this->mpdf->border_details($value)));
	}

	/**
	 * topntail is handed to border_details() as the author wrote it, so a value of one word reached
	 * the collapsing and painting code as a side with no style, colour or dominance.
	 */
	public function testATableWhoseTopAndTailDrawNothingRaisesNothing()
	{
		$raised = [];

		set_error_handler(function ($no, $message, $file) use (&$raised) {
			if (false !== strpos($file, 'Mpdf.php')) {
				$raised[] = $message;
			}

			return true;
		});

		$this->mpdf->WriteHTML(
			'<table style="border-collapse: collapse; topntail: none">'
			. '<thead><tr><th>head</th></tr></thead>'
			. '<tbody><tr><td>body</td></tr></tbody>'
			. '</table>'
		);
		$pdf = $this->mpdf->Output('', 'S');

		restore_error_handler();

		$this->assertSame([], $raised);
		$this->assertStringContainsString('%PDF-', $pdf);
	}

}
