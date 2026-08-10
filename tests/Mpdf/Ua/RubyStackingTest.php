<?php

namespace Mpdf\Ua;

/**
 * Ruby (rt-above-rb) visual stacking — the layout half of C1b.
 *
 * C1a delivers the PDF/UA-1 tagging (Ruby/RB/RT/RP standard struct types) that
 * veraPDF validates; this class covers the rendering-fidelity gap it left: the
 * <rt> annotation is now drawn at reduced size, raised above the base and
 * centred over it, the cluster advances by max(base, annotation) width, <rp>
 * fallback parentheses are suppressed, and the tagged output is unchanged.
 *
 * The layout is script-independent, so the fixtures use Latin text with an
 * embedded TrueType font (compress disabled) so the placed glyph runs can be
 * read straight out of the content stream. Text is emitted as 2-byte glyph IDs
 * (Identity-H), so runs() strips the NUL high bytes before matching.
 *
 * @group pdfua
 */
class RubyStackingTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @param array $config
	 * @return \Mpdf\Mpdf
	 */
	private function makeMpdf($config = [])
	{
		$mpdf = new \Mpdf\Mpdf(array_merge(['mode' => 'en-GB'], $config));
		$mpdf->compress = false;
		return $mpdf;
	}

	/**
	 * Render a paragraph and return each placed text run as
	 * ['x' => float, 'y' => float, 'text' => string] in emission order.
	 *
	 * @param  string $html  inner paragraph HTML
	 * @param  array  $config
	 * @return array<int,array{x:float,y:float,text:string}>
	 */
	private function render($html, $config = [])
	{
		$mpdf = $this->makeMpdf($config);
		$mpdf->WriteHTML('<p style="font-size:20pt">' . $html . '</p>');
		return $this->runs($mpdf->Output(null, 'S'));
	}

	/**
	 * @param  string $pdf
	 * @return array<int,array{x:float,y:float,text:string}>
	 */
	private function runs($pdf)
	{
		// Td may be followed by Tc/Tw spacing operators (justified lines) before
		// the show operator; skip them.
		preg_match_all('/BT\s+([\d.]+)\s+([\d.]+)\s+Td\s*(?:[-\d.]+\s+T[cw]\s+)*(\((?:[^)\\\\]|\\\\.)*\)|\[.*?\])\s*T[jJ]\s*ET/s', $pdf, $m, PREG_SET_ORDER);
		$out = [];
		foreach ($m as $g) {
			preg_match_all('/\((?:[^)\\\\]|\\\\.)*\)/s', $g[3], $lits);
			$text = '';
			foreach ($lits[0] as $lit) {
				$text .= substr($lit, 1, -1);
			}
			// Glyphs are 2-byte IDs with NUL high bytes; drop those and spaces.
			$out[] = [
				'x' => (float) $g[1],
				'y' => (float) $g[2],
				'text' => str_replace(["\x00", ' '], '', $text),
			];
		}
		return $out;
	}

	/**
	 * @param array $runs
	 * @param string $needle
	 * @return array{x:float,y:float,text:string}|null
	 */
	private function findRun($runs, $needle)
	{
		foreach ($runs as $r) {
			if (strpos($r['text'], $needle) !== false) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * The annotation is drawn on a higher baseline than the base (raised), and
	 * centred over the base's horizontal span rather than flowing after it.
	 */
	public function testAnnotationIsRaisedAndCentredOverBase()
	{
		$runs = $this->render('A<ruby>base<rt>note</rt></ruby>Z');
		$base = $this->findRun($runs, 'base');
		$note = $this->findRun($runs, 'note');
		$z = $this->findRun($runs, 'Z');
		$this->assertNotNull($base, 'base run missing');
		$this->assertNotNull($note, 'annotation run missing');
		$this->assertNotNull($z, 'trailing run missing');

		// PDF y grows upward: a raised annotation has the larger y.
		$this->assertGreaterThan($base['y'], $note['y'], 'rt must be raised above the base baseline');
		// Centred: annotation starts right of the base start and before the
		// trailing text (within the base span, not flush-left and not after it).
		$this->assertGreaterThan($base['x'], $note['x'], 'rt must be centred, not flush-left with the base');
		$this->assertLessThan($z['x'], $note['x'], 'rt must sit within the cluster, not after it');
	}

	/**
	 * A narrower annotation adds no horizontal advance: trailing text lands where
	 * the same base without any annotation would place it.
	 */
	public function testNarrowAnnotationCollapsesToBaseAdvance()
	{
		$withRt = $this->render('A<ruby>base<rt>x</rt></ruby>Z');
		$noRt   = $this->render('A<ruby>base</ruby>Z');
		$zr = $this->findRun($withRt, 'Z');
		$zp = $this->findRun($noRt, 'Z');
		$this->assertNotNull($zr);
		$this->assertNotNull($zp);
		$this->assertEqualsWithDelta($zp['x'], $zr['x'], 0.5, 'narrow rt must not push following text');
	}

	/**
	 * An annotation wider than the base expands the cluster advance, so trailing
	 * text is pushed past where the bare base would place it, and the base stays
	 * raised-free and centred under the annotation.
	 */
	public function testWideAnnotationExpandsClusterAdvance()
	{
		$withRt = $this->render('A<ruby>i<rt>wiiiide</rt></ruby>Z');
		$noRt   = $this->render('A<ruby>i</ruby>Z');

		$base = $this->findRun($withRt, 'i');
		$note = $this->findRun($withRt, 'wiiiide');
		$zr = $this->findRun($withRt, 'Z');
		$zp = $this->findRun($noRt, 'Z');
		$this->assertNotNull($note, 'wide annotation run missing');
		$this->assertGreaterThan($base['y'], $note['y'], 'wide rt must still be raised');
		$this->assertGreaterThan($zp['x'] + 5, $zr['x'], 'wide rt must expand the cluster advance');
	}

	/**
	 * A line containing a raised annotation reserves extra ascent, so its base
	 * baseline sits lower on the page than the same base with no annotation.
	 */
	public function testRubyLineReservesAscent()
	{
		$withRt = $this->render('A<ruby>base<rt>note</rt></ruby>Z');
		$noRt   = $this->render('A<ruby>base</ruby>Z');
		$base = $this->findRun($withRt, 'base');
		$plainBase = $this->findRun($noRt, 'base');
		$this->assertNotNull($base);
		$this->assertNotNull($plainBase);
		$this->assertLessThan($plainBase['y'], $base['y'], 'ruby line must reserve ascent above the base');
	}

	/**
	 * <rp> fallback parentheses are suppressed while stacking is active, but the
	 * annotation still renders. (The RP struct element is retained for tagging;
	 * see the veraPDF gate / StructTypeTest.)
	 */
	public function testRpParenthesesAreSuppressed()
	{
		$runs = $this->render('A<ruby>base<rp>(</rp><rt>note</rt><rp>)</rp></ruby>Z');
		$this->assertNotNull($this->findRun($runs, 'note'), 'annotation must still render');
		foreach ($runs as $r) {
			$this->assertStringNotContainsString('(', $r['text'], 'rp "(" must be suppressed');
			$this->assertStringNotContainsString(')', $r['text'], 'rp ")" must be suppressed');
		}
	}

	/**
	 * Ruby on a wrapped (non-final) line is stacked too — that line is painted
	 * by WriteFlowingBlock's flush path rather than finishFlowingBlock.
	 */
	public function testWrappedLineRubyStacks()
	{
		$mpdf = $this->makeMpdf();
		$filler = str_repeat('word ', 12);
		$mpdf->WriteHTML('<p style="font-size:14pt">' . $filler . 'HERE <ruby>base<rt>note</rt></ruby> tail tail tail</p>');
		$runs = $this->runs($mpdf->Output(null, 'S'));

		$base = $this->findRun($runs, 'base');
		$note = $this->findRun($runs, 'note');
		$this->assertNotNull($base);
		$this->assertNotNull($note);
		$this->assertGreaterThan($base['y'], $note['y'], 'ruby on a wrapped line must still stack');
	}

	/**
	 * Under justification the base is not stretched, so the painted base width
	 * matches the cluster advance and following text does not overlap it.
	 */
	public function testJustifiedRubyDoesNotOverlapFollowingText()
	{
		$mpdf = $this->makeMpdf();
		$pre = str_repeat('alpha ', 6);
		$post = str_repeat('omega ', 40);
		$mpdf->WriteHTML('<p style="font-size:14pt;text-align:justify">' . $pre . '<ruby>base<rt>note</rt></ruby> ' . $post . '</p>');
		$runs = $this->runs($mpdf->Output(null, 'S'));

		$base = $this->findRun($runs, 'base');
		$this->assertNotNull($base);
		$following = null;
		foreach ($runs as $r) {
			if (strpos($r['text'], 'omega') !== false && abs($r['y'] - $base['y']) < 0.01 && $r['x'] > $base['x']) {
				$following = $r;
				break;
			}
		}
		$this->assertNotNull($following, 'expected omega text after the cluster on the same line');
		$this->assertGreaterThanOrEqual($base['x'] + 20, $following['x'], 'following text must clear the unstretched base');
	}

	/**
	 * Stacking must not disturb the PDF/UA-1 tagging delivered by C1a: the Ruby,
	 * RB and RT standard struct types are still emitted.
	 */
	public function testStackingRetainsRubyStructTags()
	{
		$mpdf = $this->makeMpdf(['PDFUA' => true, 'title' => 'Ruby']);
		$mpdf->WriteHTML('<p><ruby><rb>base</rb><rt>note</rt></ruby></p>');
		$pdf = $mpdf->Output(null, 'S');
		$this->assertStringContainsString('/S /Ruby', $pdf);
		$this->assertStringContainsString('/S /RB', $pdf);
		$this->assertStringContainsString('/S /RT', $pdf);
	}
}
