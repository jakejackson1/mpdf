<?php

namespace Issues;

class Issue2158Test extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	public function testMultiCellWithEmptyString()
	{
		$mpdf = new \Mpdf\Mpdf(['default_font' => 'dejavusans']);
		$mpdf->AddPage();
		$mpdf->MultiCell(0, 5, '');

		$output = $mpdf->OutputBinaryData();
		$this->assertStringStartsWith('%PDF-', $output);

		$mpdf->cleanup();
	}

	public function testMultiCellWithWhitespaceOnlyString()
	{
		$mpdf = new \Mpdf\Mpdf(['default_font' => 'dejavusans']);
		$mpdf->AddPage();
		$mpdf->MultiCell(0, 5, "   \n   ");

		$output = $mpdf->OutputBinaryData();
		$this->assertStringStartsWith('%PDF-', $output);

		$mpdf->cleanup();
	}

}
