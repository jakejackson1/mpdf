<?php

namespace Mpdf;

use Mpdf\Output\Destination;

class OverWriteTest extends BaseMpdfTest
{

	/**
	 * @var string[]
	 */
	private $files = [];

	protected function tear_down()
	{
		parent::tear_down();

		foreach ($this->files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}

		$this->files = [];
	}

	private function file($contents)
	{
		$file = tempnam(sys_get_temp_dir(), 'OverWrite');
		file_put_contents($file, $contents);
		$this->files[] = $file;

		return $file;
	}

	/**
	 * A two page document, written the way OverWrite() expects to find one
	 */
	private function source($compress = false)
	{
		$mpdf = new Mpdf(['mode' => 'c']);
		$mpdf->compress = $compress;
		$mpdf->WriteHTML('<p>MAIN HEADING one</p><pagebreak /><p>MAIN HEADING two</p>');

		$pdf = $mpdf->Output('', Destination::STRING_RETURN);
		$mpdf->cleanup();

		return $pdf;
	}

	/**
	 * The same document with its line endings changed in transit, as mpdf/mpdf#626 had it
	 */
	private function crlfSource()
	{
		return $this->file(str_replace("\n", "\r\n", $this->source()));
	}

	private function overWrite($file, $compress = false)
	{
		$this->mpdf->compress = $compress;

		return $this->mpdf->OverWrite($file, ['MAIN HEADING'], ['replacement'], Destination::STRING_RETURN);
	}

	public function testTextIsReplacedOnEveryPage()
	{
		$pdf = $this->overWrite($this->file($this->source()));

		$this->assertSame(2, substr_count($pdf, 'replacement'));
		$this->assertStringNotContainsString('MAIN HEADING', $pdf);
	}

	public function testTextIsReplacedInACompressedDocument()
	{
		$pdf = $this->overWrite($this->file($this->source(true)), true);

		$this->assertStringNotContainsString('MAIN HEADING', $pdf);
	}

	/**
	 * The offsets the method keeps have to still add up, or the reader has to repair the file
	 */
	public function testTheCrossReferenceTableStillPointsAtItself()
	{
		$pdf = $this->overWrite($this->file($this->source()));

		$matches = [];
		preg_match("/startxref\n(\d+)\n%%EOF/", $pdf, $matches);

		$this->assertNotEmpty($matches);
		$this->assertSame("xref\n0 ", substr($pdf, (int) $matches[1], 7));
	}

	/**
	 * mpdf/mpdf#626: none of the patterns matched, every $m[1] and $m[2] read raised an undefined
	 * key, and what came back was a document whose cross-reference table had been rewritten from
	 * nothing - readers report it as damaged and repair it, and none of the text was replaced.
	 */
	public function testADocumentWrittenWithOtherLineEndingsIsRefused()
	{
		$this->expectException(MpdfException::class);
		$this->expectExceptionMessage('no cross-reference table of the kind mPDF writes was found in it');

		$this->overWrite($this->crlfSource());
	}

	public function testADocumentFromSomewhereElseIsRefused()
	{
		$this->expectException(MpdfException::class);
		$this->expectExceptionMessage('Cannot overwrite');

		$this->overWrite(__DIR__ . '/../data/pdfs/compressed-xref.pdf');
	}

	/**
	 * The content streams are matched the way this instance would write them, so a document
	 * compressed the other way used to come back untouched with no word of why
	 */
	public function testADocumentCompressedTheOtherWayIsRefused()
	{
		$this->expectException(MpdfException::class);
		$this->expectExceptionMessage('no page content of the kind mPDF writes with compression on was found in it');

		$this->overWrite($this->file($this->source()), true);
	}

	public function testRefusingRaisesNothingOfItsOwn()
	{
		$crlf = $this->crlfSource();

		$raised = [];
		set_error_handler(function ($number, $message) use (&$raised) {
			$raised[] = $message;

			return true;
		});

		try {
			$this->overWrite($crlf);
		} catch (MpdfException $e) {
			// the point of the test is what was raised on the way, not that it was
		}

		restore_error_handler();

		$this->assertSame([], $raised);
	}

}
