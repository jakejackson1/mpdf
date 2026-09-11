<?php

namespace Snapshots;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class PdfTextTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	private function pdf($html, $compress = false)
	{
		$mpdf = new Mpdf(['mode' => 'c', 'creationDate' => 0, 'exposeVersion' => false]);
		$mpdf->SetCompression($compress);
		$mpdf->WriteHTML($html);

		return $mpdf->Output(null, Destination::STRING_RETURN);
	}

	public function testTheSameDocumentHasNothingToSay()
	{
		$pdf = $this->pdf('<p>one</p>');

		$this->assertSame('', PdfText::diff($pdf, $pdf));
	}

	public function testAChangedWordIsPlacedOnItsPage()
	{
		$diff = PdfText::diff($this->pdf('<p>one</p>'), $this->pdf('<p>two</p>'));

		$this->assertMatchesRegularExpression('/^@@ page 1 content, line \d+ @@$/m', $diff);
		$this->assertMatchesRegularExpression('/^-.*\(one\) Tj/m', $diff);
		$this->assertMatchesRegularExpression('/^\+.*\(two\) Tj/m', $diff);
		$this->assertStringNotContainsString('@@ Font', $diff, 'the same font subset is not a change');
	}

	public function testCompressionIsSeenThrough()
	{
		$plain = $this->pdf('<p>one</p>');
		$compressed = $this->pdf('<p>one</p>', true);

		$this->assertNotSame($plain, $compressed);
		$this->assertSame(PdfText::sections($plain), PdfText::sections($compressed));
	}

	public function testAnAddedPageIsANewSection()
	{
		$diff = PdfText::diff($this->pdf('<p>one</p>'), $this->pdf('<p>one</p><pagebreak /><p>two</p>'));

		$this->assertStringContainsString("@@ page 2 content (added), line 1 @@\n", $diff);
		$this->assertStringContainsString("@@ page 2 (added), line 1 @@\n", $diff);
	}

	public function testABinaryStreamIsItsSizeAndHash()
	{
		$sections = PdfText::sections($this->pdf('<img src="' . __DIR__ . '/../data/img/bayeux2.jpg">'));

		$this->assertArrayHasKey('Image 1', $sections);
		$this->assertMatchesRegularExpression('/^\[binary stream: \d+ bytes, md5 [0-9a-f]{32}\]$/', end($sections['Image 1']));
	}

	public function testAChangeDeepInALongStreamIsShownWithItsContextOnly()
	{
		$lines = '';
		for ($i = 1; $i <= 300; $i++) {
			$lines .= '<p>Line ' . $i . '</p>';
		}
		$diff = PdfText::diff($this->pdf($lines), $this->pdf(str_replace('Line 150<', 'Line 150!<', $lines)));

		$this->assertSame(1, preg_match_all('/^@@ page \d+ content/m', $diff), 'one hunk, on the one page that changed');
		$this->assertLessThan(12, substr_count($diff, "\n"), 'a handful of lines around the change');
	}
}
