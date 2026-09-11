<?php

namespace Mpdf\Fonts;

use Mpdf\Cache;
use Mpdf\HtmlRecordingMpdf;
use Mpdf\OtlDump;

/**
 * What the dump does with a request it cannot fully answer.
 *
 * A font's two layout tables need not carry the same scripts and language systems, and the report of
 * one script is worth having even when only one table has anything to say about it.
 */
class OtlDumpTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const FONT_DIR = __DIR__ . '/../../data/ttf';

	/**
	 * @var HtmlRecordingMpdf
	 */
	private $mpdf;

	public function set_up()
	{
		parent::set_up();

		$this->mpdf = new HtmlRecordingMpdf(['mode' => 'utf-8', 'tempDir' => __DIR__ . '/../tmp/mpdf/otldump']);
	}

	/**
	 * Noto Sans Mono lists the Catalan language system under latn in GSUB and not in GPOS.
	 */
	public function testAScriptOnlyOneTableCarriesIsStillReported()
	{
		$report = $this->dump('NotoSansMono-GDEF13-Subset', 'latn', 'CAT ');

		$this->assertContains(
			'<div class="notoffered">This font\'s GPOS script "latn" offers no language system "CAT". It has: DFLT</div>',
			$report
		);
		$this->assertStringContainsString('<bookmark level="0" content="GSUB features">', implode('', $report));
	}

	/**
	 * Manjari positions Latin and substitutes nothing for it, so GSUB has no latn script at all.
	 */
	public function testATableWithoutTheScriptSaysWhatItHasInstead()
	{
		$report = $this->dump('Manjari-Regular', 'latn', 'DFLT');

		$this->assertContains(
			'<div class="notoffered">This font\'s GSUB table offers no script "latn". It has: DFLT, mlm2, mlym</div>',
			$report
		);
		$this->assertStringContainsString('<bookmark level="0" content="GPOS features">', implode('', $report));
	}

	/**
	 * A script neither table carries is a mistake in the tag rather than a one-sided font, and the
	 * message names what both tables do carry.
	 */
	public function testAScriptNeitherTableCarriesFails()
	{
		try {
			$this->dump('Manjari-Regular', 'arab', 'DFLT');
			$this->fail('Dumping a script the font does not carry should have thrown');
		} catch (\Mpdf\MpdfException $e) {
			$this->assertSame(
				'This font\'s GSUB table offers no script "arab". It has: DFLT, mlm2, mlym' . "\n"
				. 'This font\'s GPOS table offers no script "arab". It has: DFLT, latn, mlm2, mlym',
				$e->getMessage()
			);
		}
	}

	/**
	 * The summary links each script and language system it lists to its own detail report, and the
	 * caller says how it named the font so that the link comes back to the same one.
	 */
	public function testTheSummaryLinksEachScriptToItsOwnReport()
	{
		$dump = $this->dumper();
		$dump->detailReportQuery = ['family' => 'manjari', 'style' => ''];
		$dump->getMetrics(self::FONT_DIR . '/Manjari-Regular.ttf', 'manjari', 0, false, false, 0xFF, 'summary');

		$this->assertStringContainsString(
			'<a href="font_dump_otl.php?family=manjari&amp;style=&amp;script=mlym&amp;lang=DFLT">',
			implode('', $this->mpdf->recordedHtml)
		);
	}

	/**
	 * @return string[] The HTML the report was written in
	 */
	private function dump($font, $script, $language)
	{
		$this->dumper()->getMetrics(
			self::FONT_DIR . '/' . $font . '.ttf',
			$font,
			0,
			false,
			false,
			0xFF,
			'detail',
			str_pad($script, 4, ' '),
			str_pad($language, 4, ' ')
		);

		return $this->mpdf->recordedHtml;
	}

	/**
	 * @return OtlDump
	 */
	private function dumper()
	{
		return new OtlDump($this->mpdf, new FontCache(new Cache(__DIR__ . '/../tmp/mpdf/otldump/cache')), 'win');
	}

}
