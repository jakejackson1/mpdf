<?php

namespace Mpdf\Barcode;

/**
 * @group unit
 */
class EanUpcTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	public function testInit()
	{
		$barcode = new EanUpc('9783161484100', 13, 11, 7, 0.33, 25.93);
		$array = $barcode->getData();
		$this->assertIsArray($array);
		$this->assertArrayHasKey('bcode', $array);
		$this->assertIsArray($array['bcode']);
	}

	public function invalidCodeProvider()
	{
		return [
			['foo'],
			['foo11bar'],
		];
	}

	/**
	 * @dataProvider invalidCodeProvider
	 */
	public function testInvalidCode($code)
	{
		$this->expectException(\Mpdf\Barcode\BarcodeException::class);
		$this->expectExceptionMessage('Invalid EAN UPC barcode value');

		new EanUpc($code, 13, 11, 7, 0.33, 25.93);
	}

	/**
	 * Symbol length, and a code with one digit too many for it. Only as many digits as the symbol
	 * holds were encoded, and the rest were dropped from the bars but kept in the number printed
	 * underneath. See mpdf/mpdf#1332.
	 */
	public function overlongCodeProvider()
	{
		return [
			'UPC-A given an EAN-13 code' => [12, '0048200115438'],
			'EAN-13'                     => [13, '50123456789000'],
			'EAN-8'                      => [8, '123456789'],
		];
	}

	/**
	 * @dataProvider overlongCodeProvider
	 */
	public function testACodeTooLongForTheSymbolIsRefused($length, $code)
	{
		$this->expectException(\Mpdf\Barcode\BarcodeException::class);
		$this->expectExceptionMessage('Invalid EAN UPC barcode value "' . $code . '"');

		new EanUpc($code, $length, 11, 7, 0.33, 25.93);
	}

	/**
	 * Symbol length, the code as given, and the code as it ends up. A code one digit short of the
	 * symbol is still the documented way to have mPDF work the check digit out.
	 */
	public function acceptedCodeProvider()
	{
		return [
			'UPC-A without its check digit' => [12, '09827721123', '0098277211236'],
			'UPC-A with its check digit'    => [12, '098277211236', '0098277211236'],
			'EAN-13 without its check digit' => [13, '978316148410', '9783161484100'],
			'EAN-13 with its check digit'   => [13, '9783161484100', '9783161484100'],
			'EAN-8 without its check digit' => [8, '2468123', '24681230'],
			'EAN-8 with its check digit'    => [8, '24681230', '24681230'],
		];
	}

	/**
	 * @dataProvider acceptedCodeProvider
	 */
	public function testACodeTheSymbolHoldsIsStillAccepted($length, $code, $expected)
	{
		$barcode = new EanUpc($code, $length, 11, 7, 0.33, 25.93);
		$data = $barcode->getData();

		$this->assertSame($expected, $data['code']);
	}

	/**
	 * UPC-E is asked for as the twelve-digit UPC-A it is made from, and the length it is constructed
	 * with is 6. The check has to be made after that has been resolved to 12 or the documented way
	 * of writing a UPC-E would be refused.
	 */
	public function testAFullUpcACodeStillMakesAUpcESymbol()
	{
		$barcode = new EanUpc('042100005264', 6, 9, 7, 0.33, 25.93);

		$this->assertSame('425261', $barcode->getData()['code']);
	}

	public function testAUpcECodeWithoutItsCheckDigitStillMakesAUpcESymbol()
	{
		$barcode = new EanUpc('04210000526', 6, 9, 7, 0.33, 25.93);

		$this->assertSame('425261', $barcode->getData()['code']);
	}

}
