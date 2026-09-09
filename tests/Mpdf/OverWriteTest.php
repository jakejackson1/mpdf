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

	/**
	 * The [filter, text] of each content stream in $pdf, inflating the ones that say they are compressed
	 */
	private function streams($pdf)
	{
		preg_match_all("/<<(\/Filter \/FlateDecode )?\/Length \d+>>\nstream\n(.*?)\nendstream/s", $pdf, $matches, PREG_SET_ORDER);

		return array_map(function ($match) {
			return [$match[1], $match[1] ? gzuncompress($match[2]) : $match[2]];
		}, $matches);
	}

	public function compressionProvider()
	{
		return [
			'uncompressed document, uncompressed instance' => [false, false],
			'compressed document, compressed instance' => [true, true],
			'uncompressed document, compressed instance' => [false, true],
			'compressed document, uncompressed instance' => [true, false],
		];
	}

	/**
	 * Whether a stream is compressed is read from the document, not from the instance doing the overwriting
	 *
	 * @dataProvider compressionProvider
	 */
	public function testTextIsReplacedHoweverTheDocumentAndInstanceAreCompressed($documentCompressed, $instanceCompressed)
	{
		$pdf = $this->overWrite($this->file($this->source($documentCompressed)), $instanceCompressed);
		$streams = $this->streams($pdf);
		$text = implode("\n", array_column($streams, 1));

		$this->assertSame(2, substr_count($text, 'replacement'));
		$this->assertStringNotContainsString('MAIN HEADING', $text);

		$filter = $documentCompressed ? '/Filter /FlateDecode ' : '';
		$this->assertSame([$filter, $filter], array_column($streams, 0));
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
