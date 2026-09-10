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
	/**
	 * A kept block of $lines lines, with $inner ahead of them
	 */
	private function keptBlock($lines, $inner = '', $style = '')
	{
		return '<div style="page-break-inside: avoid; ' . $style . '">' . $inner . str_repeat('<p>Kept</p>', $lines) . '</div>';
	}

	/**
	 * An opaque JPEG, which a cell or block tiles as a pattern; the trait's PNG has an alpha channel and is not
	 */
	private function backgroundImage()
	{
		return __DIR__ . '/../data/img/bg.jpg';
	}

	/**
	 * The object numbers of the pages, in page order
	 */
	private function pageObjects($pdf)
	{
		preg_match_all('/(\d+) 0 obj\n<<\/Type \/Page\n/', $pdf, $matches);

		return $matches[1];
	}

	private function object($pdf, $number)
	{
		preg_match('/\n' . $number . ' 0 obj\n(.*?)endobj/s', $pdf, $match);

		return $match[1];
	}

	/**
	 * The annotation objects listed by each page, as one string per page
	 */
	private function annotations($pdf)
	{
		$annotations = [];
		foreach ($this->pageObjects($pdf) as $i => $number) {
			$annotations[$i] = '';
			if (preg_match('/\/Annots \[([^\]]*)\]/', $this->object($pdf, $number), $list)) {
				preg_match_all('/(\d+) 0 R/', $list[1], $refs);
				foreach ($refs[1] as $ref) {
					$annotations[$i] .= $this->object($pdf, $ref);
				}
			}
		}

		return $annotations;
	}

	private function pngImage()
	{
		return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAFCAYAAACNbyblAAAAHElEQVQI12P4//8/w38GIAXDIBKE0DHxgljNBAAO9TXL0Y4OHwAAAABJRU5ErkJggg==';
	}

	private function images($stream)
	{
		return preg_match_all('/\/I\d+ Do/', $stream);
	}

	/**
	 * $needle appears $count times in the string for page $page and not at all in the others
	 */
	private function assertOnlyOnPage($page, $count, $needle, array $strings, $what)
	{
		foreach ($strings as $i => $string) {
			$expected = $i === $page ? $count : 0;
			$this->assertSame($expected, substr_count($string, $needle), 'Page ' . ($i + 1) . " should carry $what $expected time(s)");
		}
	}

	/**
	 * The text drawn on a page, joined up: a linked index list is written a piece at a time
	 */
	private function drawnText($stream)
	{
		preg_match_all('/\((.*?)\)\s*Tj/', $stream, $chunks);

		return implode('', $chunks[1]);
	}

	/**
	 * The index in $stream lists $term once, against $pages: a page number, or a list such as "1-3, 5"
	 */
	private function assertIndexLists($pages, $term, $stream)
	{
		$this->assertSame(1, preg_match_all('/' . preg_quote($term, '/') . '\s+([\d, -]+)/', $this->drawnText($stream), $listed), "The index should list '$term' once");
		$this->assertSame((string) $pages, trim($listed[1][0]), "The index should list '$term' against $pages");
	}

	private function assertTextCount($expected, $text, $stream, $message = '')
	{
		$this->assertSame($expected, substr_count($stream, '(' . $text . ')'), $message ?: "'$text' should appear $expected time(s)");
	}
}
