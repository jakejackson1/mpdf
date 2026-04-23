<?php

namespace Mpdf\Ua\Security;

use Mpdf\Ua\PdfUaTestCase;

/**
 * Regression suite for UA1 audit finding H-3 — `AddCustomProperty($key, $value)`
 * concatenated `$key` raw into the /Info dict, allowing the key to smuggle
 * additional name/value entries (e.g. an attacker-controlled /Producer).
 *
 * The fix runs the key through BaseWriter::escapeName() before emission and
 * rejects empty / oversized keys at the setter.
 *
 * @group pdfua
 * @group security
 */
class CustomPropertyInjectionTest extends PdfUaTestCase
{

	public function testInjectedKeyIsEscapedToSingleNameToken()
	{
		$mpdf = $this->makeMpdf();
		// Attempt to inject extra /Info entries via the key.
		$mpdf->AddCustomProperty("good\n/Producer (pwned) /Title", 'value');
		$output = $this->getOutput($mpdf, '<p>x</p>');

		// Locate the /Info dict body.
		$startMarker = "/Producer";
		$start = strpos($output, $startMarker);
		$this->assertNotFalse($start, 'PDF must contain a /Producer line');
		$endMarker = '/CreationDate';
		$end = strpos($output, $endMarker, $start);
		$this->assertNotFalse($end, 'PDF must contain /CreationDate after /Info entries');
		$infoBlock = substr($output, $start, $end - $start);

		// Within the /Info entries written by the customProperties loop,
		// the dangerous bytes `\n`, `(`, `)`, `/` and space MUST appear
		// only in #XX-escaped form on the key side.
		$this->assertStringNotContainsString("\n/Producer (pwned)", $infoBlock);
		$this->assertStringNotContainsString("(pwned)", $infoBlock);
		$this->assertStringNotContainsString("/Title (FE", $infoBlock); // would be the smuggled entry

		// The escaped key should appear as a single token.
		$this->assertStringContainsString('good#0A#2FProducer#20#28pwned#29#20#2FTitle', $infoBlock);
	}

	public function testEmptyKeyIsRejected()
	{
		$this->expectException(\Mpdf\MpdfException::class);
		$mpdf = $this->makeMpdf();
		$mpdf->AddCustomProperty('', 'v');
	}

	public function testOversizedKeyIsRejected()
	{
		$this->expectException(\Mpdf\MpdfException::class);
		$mpdf = $this->makeMpdf();
		$mpdf->AddCustomProperty(str_repeat('A', 128), 'v');
	}

	public function testBenignKeyPassesThrough()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddCustomProperty('Department', 'Engineering');
		$output = $this->getOutput($mpdf, '<p>x</p>');

		$this->assertStringContainsString('/Department ', $output);
	}

	public function testKeyWithControlBytesIsEscaped()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->AddCustomProperty("Tab\tHere", 'v');
		$output = $this->getOutput($mpdf, '<p>x</p>');

		$this->assertStringContainsString('/Tab#09Here', $output);
		$this->assertStringNotContainsString("/Tab\tHere", $output);
	}
}
