<?php

namespace Mpdf;

/**
 * Renders a document uncompressed and hands back its page content streams, with the fixtures and assertions the
 * page-break tests share
 */
trait PageStreams
{

	private function mpdf($config = [])
	{
		$mpdf = new Mpdf($config + ['mode' => 'c']);
		$mpdf->compress = false;

		return $mpdf;
	}

	private function render($html, $config = [])
	{
		$mpdf = $this->mpdf($config);
		$mpdf->WriteHTML($html);

		return $this->output($mpdf);
	}

	private function output(Mpdf $mpdf)
	{
		$pdf = $mpdf->Output('', 'S');
		$mpdf->cleanup();

		return $pdf;
	}

	/**
	 * The content stream of each page, in order
	 */
	private function pages($pdf)
	{
		preg_match_all('/\d+ 0 obj\s*<<\/Length \d+>>\s*stream\n(.*?)\nendstream/s', $pdf, $matches);

		return $matches[1];
	}

	/**
	 * A page takes about twenty-nine of these, so twenty-two leave room for a few more but not for a block
	 */
	private function filler($paragraphs)
	{
		return str_repeat('<p>Filler</p>', $paragraphs);
	}

	/**
	 * A 5x5 PNG, for an image whose size comes from its style
	 */
	private function pngImage()
	{
		return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAFCAYAAACNbyblAAAAHElEQVQI12P4//8/w38GIAXDIBKE0DHxgljNBAAO9TXL0Y4OHwAAAABJRU5ErkJggg==';
	}

	private function images($stream)
	{
		return preg_match_all('/\/I\d+ Do/', $stream);
	}

	private function assertTextCount($expected, $text, $stream, $message = '')
	{
		$this->assertSame($expected, substr_count($stream, '(' . $text . ')'), $message ?: "'$text' should appear $expected time(s)");
	}
}
