<?php

namespace Mpdf\Fonts;

class OtlDumpGoldenMasterTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var OtlDumpGoldenMaster
	 */
	private $master;

	public function set_up()
	{
		parent::set_up();

		$this->master = new OtlDumpGoldenMaster();
	}

	/**
	 * @dataProvider fontProvider
	 */
	public function testTheDumpReportsWhatItReportedBefore($name)
	{
		$this->assertSame(
			$this->master->loadFixture($name),
			$this->master->capture($name),
			sprintf('%s dumps differently than its fixture. If the change is intended, run: composer otldump:update %s', $name, $name)
		);
	}

	public function fontProvider()
	{
		return (new OtlDumpGoldenMaster())->fonts();
	}

}
