<?php

namespace Mpdf;

use Mpdf\Fonts\FontCache;

/**
 * TTFontFileAnalysis re-walks the table directory by its own route, to answer what a font *is* rather
 * than how to render it - family, style, and which scripts it covers - and it is what generates a
 * font configuration from a directory of files.
 *
 * It reads 95 of the byte offsets that #81 moved onto FileReader, and its three callers in utils/
 * have all been unrunnable since the Strict trait landed ($mpdf->fontTempDir is not declared), so
 * nothing else exercises it at all.
 */
class TTFontFileAnalysisTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @dataProvider fontProvider
	 */
	public function testWhatACoreInfoWalkFindsInAFont($file, $family, $type, $indic)
	{
		list($name, $bold, $italic, $ftype, $ttcId, $rtl, $isIndic, $cjk) = $this->analyse($file);

		$this->assertSame($family, $name, 'family name, read from the name table');
		$this->assertSame($type, $ftype, 'sans/serif/mono, read from the OS/2 panose bytes');
		$this->assertSame($indic, (bool) $isIndic, 'covers an Indic script, read from cmap');
		$this->assertFalse((bool) $bold);
		$this->assertFalse((bool) $italic);
		$this->assertFalse((bool) $rtl);
		$this->assertFalse((bool) $cjk);
		$this->assertSame(0, $ttcId);
	}

	public function fontProvider()
	{
		return [
			// file, family name, sans/serif/mono, covers an Indic script
			'wide latin' => ['NotoSans-Regular.ttf', 'Noto Sans', 'sans', true],
			'monospace' => ['NotoSansMono-GDEF13-Subset.ttf', 'Noto Sans Mono', 'mono', false],
			'malayalam' => ['Manjari-Regular.ttf', 'Manjari', '', true],
			'devanagari' => ['Poppins-Regular.ttf', 'Poppins', '', true],
			'no OTL tables' => ['angerthas.ttf', 'Angerthas', '', false],
		];
	}

	/**
	 * The two classes reach the name table by different routes - extractCoreInfo walks the table
	 * directory itself, getMetrics goes through extractInfo - so agreeing on what they found there is
	 * the thing worth asserting
	 */
	public function testItReadsTheSameFamilyNameTheParserDoes()
	{
		list($name) = $this->analyse('NotoSansSinhala-Subset.ttf');

		$ttf = new TTFontFile(new FontCache(new Cache(__DIR__ . '/tmp/mpdf/analysis')), 'win');
		$ttf->getMetrics(__DIR__ . '/../data/ttf/NotoSansSinhala-Subset.ttf', uniqid('', true), 0, false, false, 0xFF);

		$this->assertSame($ttf->familyName, $name);
	}

	private function analyse($file)
	{
		$ttf = new TTFontFileAnalysis(
			new FontCache(new Cache(__DIR__ . '/tmp/mpdf/analysis')),
			'win'
		);

		return $ttf->extractCoreInfo(__DIR__ . '/../data/ttf/' . $file);
	}

}
