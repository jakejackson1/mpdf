<?php

namespace Mpdf\Css;

use Mpdf\Mpdf;

class MediaQueryProcessorTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	private $mpdf;
	private $processor;

	public function set_up()
	{
		parent::set_up();

		$this->mpdf = new Mpdf();

		$this->processor = new MediaQueryProcessor($this->mpdf);
	}

	public function tear_down()
	{
		unset($this->mpdf, $this->processor);
		parent::tear_down();
	}

	public function testFilterByMediaQueryMatches()
	{
		$this->mpdf->CSSselectMedia = 'print';
		$html = '<style media="print">.print { color: black; }</style>';
		$pattern = '/<style[^>]*media=["\']([^"\'>]*)["\'].*?<\/style>/is';

		$processed = $this->processor->filterByMediaQuery($html, $pattern);
		$this->assertEquals($html, $processed);
	}

	public function testFilterByMediaQueryNoMatch()
	{
		$this->mpdf->CSSselectMedia = 'screen';
		$html = '<style media="print">.print { color: black; }</style>';
		$pattern = '/<style[^>]*media=["\']([^"\'>]*)["\'].*?<\/style>/is';

		$processed = $this->processor->filterByMediaQuery($html, $pattern);
		$this->assertEmpty($processed); // Should be removed
	}

	public function testFilterByMediaQueryAll()
	{
		$this->mpdf->CSSselectMedia = 'screen';
		$html = '<style media="all">.all { color: blue; }</style>';
		$pattern = '/<style[^>]*media=["\']([^"\'>]*)["\'].*?<\/style>/is';

		$processed = $this->processor->filterByMediaQuery($html, $pattern);
		$this->assertEquals($html, $processed);
	}

	public function testProcessMediaQueriesMatch()
	{
		$this->mpdf->CSSselectMedia = 'print';
		$css = '@media print { .print { color: black; } }';

		$processed = $this->processor->processMediaQueries($css);
		$this->assertStringContainsString(' .print { color: black; } ', $processed);
		$this->assertStringNotContainsString('@media', $processed);
	}

	public function testProcessMediaQueriesNoMatch()
	{
		$this->mpdf->CSSselectMedia = 'screen';
		$css = '@media print { .print { color: black; } }';

		$processed = $this->processor->processMediaQueries($css);
		// Should be removed (replaced with empty string in original loop logic logic seems to just remove it)
		// Check implementation: $cssStr = str_replace($m[0][$i], '', $cssStr);
		
		// Note: The original logic replaces the WHOLE @media block with empty string if no match
		
		// Wait, if no match, it removes it.
		// If match, it unwraps it: str_replace($m[0][$i], ' ' . $m[2][$i] . ' ', $cssStr);
		
		// So if I have "body { color: red; } @media print { ... }" and select "screen"
		// result should be "body { color: red; } " (with @media gone)
		
		$css = 'body { color: red; } @media print { .print { color: black; } }';
		$processed = $this->processor->processMediaQueries($css);
		
		$this->assertStringContainsString('body { color: red; }', $processed);
		$this->assertStringNotContainsString('print', $processed);
	}
}
