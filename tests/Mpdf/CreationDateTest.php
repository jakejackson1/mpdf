<?php

namespace Mpdf;

use Mpdf\Output\Destination;

/**
 * creationDate dates the document at a given moment, so the same content makes the same bytes
 */
class CreationDateTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	private function render(array $config)
	{
		$mpdf = new Mpdf($config + ['mode' => 'c', 'useActiveForms' => true]);
		$mpdf->WriteHTML('<p>Dated <a href="https://example.com">once</a></p><form><input type="text" name="field" /></form>');

		return $mpdf->Output(null, Destination::STRING_RETURN);
	}

	public function testTheSameContentDatedTheSameIsTheSameBytes()
	{
		$this->assertSame($this->render(['creationDate' => 946684800]), $this->render(['creationDate' => 946684800]));
	}

	public function testATimestampDatesTheDocumentInUtc()
	{
		$pdf = $this->render(['creationDate' => 946684800]);

		$this->assertStringContainsString("/CreationDate (D:20000101000000+00'00')", $pdf);
		$this->assertStringContainsString("/ModDate (D:20000101000000+00'00')", $pdf);
		$this->assertSame(2, substr_count($pdf, "/M (D:20000101000000+00'00')"), 'the link annotation and the form widget too');
	}

	public function testADateKeepsItsOwnTimezone()
	{
		$pdf = $this->render(['creationDate' => new \DateTime('2021-06-01 12:00:00', new \DateTimeZone('+10:00'))]);

		$this->assertStringContainsString("/CreationDate (D:20210601120000+10'00')", $pdf);
	}

	public function testTheFileIdFollowsTheDate()
	{
		preg_match('#/ID \[<([0-9a-f]{32})>#', $this->render(['creationDate' => 946684800]), $first);
		preg_match('#/ID \[<([0-9a-f]{32})>#', $this->render(['creationDate' => 946684801]), $second);

		$this->assertNotSame($first[1], $second[1]);
	}

	public function testThePdfaMetadataIsDatedAndIdentifiedTheSameWay()
	{
		$config = ['creationDate' => 946684800, 'mode' => 'utf-8', 'PDFA' => true, 'PDFAauto' => true];
		$pdf = $this->render($config);

		$this->assertStringContainsString('<xmp:CreateDate>2000-01-01T00:00:00+00:00</xmp:CreateDate>', $pdf);
		$this->assertMatchesRegularExpression('/rdf:about="uuid:[0-9a-f]{8}-[0-9a-f]{4}-3[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"/', $pdf);
		$this->assertSame($pdf, $this->render($config));
	}
}
