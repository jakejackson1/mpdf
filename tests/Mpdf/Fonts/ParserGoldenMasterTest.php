<?php

namespace Mpdf\Fonts;

class ParserGoldenMasterTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var ParserGoldenMaster
	 */
	private $master;

	public function set_up()
	{
		parent::set_up();

		$this->master = new ParserGoldenMaster();
	}

	/**
	 * Every byte the parser hands the shaper, for every font in tests/data/ttf.
	 *
	 * The gate for the #81 refactor: moving the reader, collapsing the second parser or splitting the
	 * dispatchers must leave this output untouched. The two phases that change a persisted shape on
	 * purpose - table-relative offsets, and one cache file per table - are the only ones allowed to
	 * rewrite these fixtures, and they raise MetricsGenerator::CACHE_FORMAT when they do.
	 *
	 * @dataProvider fontProvider
	 */
	public function testTheParserWritesWhatTheShaperExpects($name, $expectOtl)
	{
		$capture = $this->master->capture($name);

		if (!$expectOtl) {
			$this->assertNull($capture, sprintf('%s has no GDEF table, so parsing it with OTL should be refused', $name));
			return;
		}

		$this->assertNotNull($capture, sprintf('%s parsed before now; a FontException here is a regression', $name));
		$this->assertSame(
			$this->master->loadFixture($name),
			$capture,
			sprintf('%s parses differently than its fixture. If the change is intended, run: composer fontcache:update %s', $name, $name)
		);
	}

	public function fontProvider()
	{
		return (new ParserGoldenMaster())->fonts();
	}

}
