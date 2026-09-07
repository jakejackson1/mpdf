<?php

namespace Mpdf;

use Mpdf\Fonts\FontCache;

class OtlTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var \Mpdf\Otl
	 */
	private $otl;

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	protected function set_up()
	{
		parent::set_up();

		$this->mpdf = new Mpdf(['mode' => 'c']);
		$this->otl = new Otl($this->mpdf, new FontCache(new Cache(sys_get_temp_dir() . '/mpdf-otl-test')));
	}

	protected function tear_down()
	{
		parent::tear_down();

		$this->mpdf->cleanup();
	}

	public function testSliceOfPopulatedData()
	{
		$OTLdata = [
			'group' => 'SCCSC',
			'GPOSinfo' => [1 => ['GPOSinfo'], 3 => ['other']],
			'char_data' => [['bidi_class' => 0], ['bidi_class' => 1], ['bidi_class' => 2], ['bidi_class' => 3], ['bidi_class' => 4]],
		];

		$slice = $this->otl->sliceOTLdata($OTLdata, 1, 3);

		$this->assertSame('CCS', $slice['group']);
		$this->assertSame([0 => ['GPOSinfo'], 2 => ['other']], $slice['GPOSinfo']);
		$this->assertCount(3, $slice['char_data']);
	}

	/**
	 * applyOTL() resets OTLdata to an empty array for a blank string, and MultiCell() slices
	 * whatever it is handed. See mpdf/mpdf#2158.
	 */
	public function testSliceOfEmptyDataReturnsAnEmptyStructure()
	{
		$slice = $this->otl->sliceOTLdata([], 0, 0);

		$this->assertSame('', $slice['group']);
		$this->assertSame([], $slice['GPOSinfo']);
		$this->assertSame([], $slice['char_data']);
	}

}
