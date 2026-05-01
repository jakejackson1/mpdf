<?php

namespace Mpdf\Ua;

/**
 * Poor-HTML auto-mode regression surface.
 *
 * Each test feeds adversarial HTML through PDFUAauto, asserts the PDF
 * generates without throwing, then runs the result through veraPDF (when
 * VERAPDF_BIN is available) to confirm ua1 isCompliant=true.
 *
 * The cases mirror the real-world poor-HTML probes from the 2026-04-30
 * expert audit. They exist precisely so the auto-mode regression surface
 * is monitored, not just hand-curated fixtures — a regression in one of
 * these cases would silently re-introduce shipping non-conformant PDFs.
 *
 * Skip behaviour:
 *   - Each test always asserts that the PDF generation does not throw.
 *   - veraPDF assertions are skipped silently when VERAPDF_BIN is unset
 *     or not executable, so the whole class still runs in CI environments
 *     that lack the validator (e.g. Windows + PHP 5.6 matrix legs).
 *
 * Group note: NOT @group verapdf — that group is excluded by phpunit.xml
 * by default, so the no-throw probe must run in @group pdfua. veraPDF
 * validation when available is a bonus assertion gated on the env var.
 *
 * @group pdfua
 */
class PoorHtmlAutoModeTest extends PdfUaTestCase
{
	/**
	 * Path to the veraPDF CLI binary, resolved once per test in set_up().
	 * Empty when unavailable; assertions auto-skip in that case.
	 *
	 * @var string
	 */
	private $veraPdfBin = '';

	/**
	 * Resolve veraPDF binary path; remember it for assertVeraPdfCompliant().
	 * Unlike VeraPdfConformanceTest, missing veraPDF does NOT skip the test
	 * — the PDF generation half of the assertion still runs.
	 *
	 * @return void
	 */
	protected function set_up()
	{
		parent::set_up();
		$bin = (string) getenv('VERAPDF_BIN');
		if ($bin !== '' && is_file($bin) && is_executable($bin)) {
			$this->veraPdfBin = $bin;
		}
	}

	// =====================================================================
	// Auto-mode probes
	// =====================================================================

	public function testTableWithoutThPasses()
	{
		$html = '<table><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></table>';
		$this->generateAndCheck($html, 'table without <th>');
	}

	public function testEmptyAnchorPasses()
	{
		$html = '<p>Before <a href="https://example.com"></a> after.</p>';
		$this->generateAndCheck($html, 'empty <a href>');
	}

	public function testImageOnlyLinkPasses()
	{
		$png = $this->onePixelPng();
		$html = '<p><a href="https://example.com/foo"><img src="' . $png . '" alt="" width="20" height="20"></a></p>';
		$this->generateAndCheck($html, 'image-only link with decorative inner image');
	}

	public function testMultipleH1Passes()
	{
		$html = '<h1>First top heading</h1><p>Body.</p><h1>Second top heading</h1><p>More body.</p>';
		$this->generateAndCheck($html, 'multiple <h1> siblings');
	}

	public function testHeadingLevelSkipPasses()
	{
		$html = '<h1>One</h1><h3>Skipped two</h3><p>Body.</p>';
		$this->generateAndCheck($html, '<h1> followed directly by <h3>');
	}

	public function testImageWithNoAltPasses()
	{
		$png = $this->onePixelPng();
		$html = '<p>Before <img src="' . $png . '" width="20" height="20"> after.</p>';
		$this->generateAndCheck($html, '<img> with no alt');
	}

	public function testDivButtonRolePasses()
	{
		$html = '<p><span role="button">Click me</span></p><p>Following body.</p>';
		$this->generateAndCheck($html, '<span role="button">');
	}

	public function testFakeListAsParagraphPasses()
	{
		$html = '<p>• item one<br>• item two<br>• item three</p>';
		$this->generateAndCheck($html, 'bullet glyphs as fake list');
	}

	public function testBodyLevelTextWithoutWrapperPasses()
	{
		// Plain text outside any block element — mPDF must wrap it implicitly.
		$html = 'Loose body text with no surrounding block element.';
		$this->generateAndCheck($html, 'unwrapped body-level text');
	}

	public function testHeadingInsideHeadingPasses()
	{
		$html = '<h1>Outer<span>middle</span>tail</h1><p>Body.</p>';
		$this->generateAndCheck($html, 'inline span inside <h1>');
	}

	public function testAnchorWithoutHrefPasses()
	{
		$html = '<p>Before <a>orphan anchor</a> after.</p>';
		$this->generateAndCheck($html, '<a> without href');
	}

	public function testFormWithInputPasses()
	{
		$html = '<form><input type="text" name="q" value=""></form><p>Following body.</p>';
		$this->generateAndCheck($html, '<form><input> (HIGH-5 path)');
	}

	public function testTableWithIllegalIdCharsPasses()
	{
		$html = '<table>'
			. '<tr><th id="col(1)">A</th><th id="col(2)">B</th></tr>'
			. '<tr><td headers="col(1)">x</td><td headers="col(2)">y</td></tr>'
			. '</table>';
		$this->generateAndCheck($html, 'table with #-escaped TH ids');
	}

	public function testNestedTablesPasses()
	{
		$html = '<table><tr><td>'
			. '<table><tr><td>'
			. '<table><tr><td>deepest</td></tr></table>'
			. '</td></tr></table>'
			. '</td></tr></table>';
		$this->generateAndCheck($html, 'nested tables 3 levels deep');
	}

	public function testDocTitleRolePasses()
	{
		$html = '<div role="doc-title">My Document Title</div><p>Body.</p>';
		$this->generateAndCheck($html, '<div role="doc-title">');
	}

	public function testInlineLangSpanPasses()
	{
		// Audit 2026-05-01 H1 — mid-paragraph foreign-language run must propagate
		// /Lang to a Span struct elem (Matterhorn 11-001/11-002).
		$html = '<p>The French word <span lang="fr">bonjour</span> means hello.</p>';
		$this->generateAndCheck($html, 'inline <span lang> mid-paragraph');
	}

	public function testInlineAriaLabelSpanPasses()
	{
		$html = '<p>An icon <span aria-label="warning sign">!</span> after text.</p>';
		$this->generateAndCheck($html, 'inline <span aria-label>');
	}

	public function testFieldsetLegendPasses()
	{
		// Audit 2026-05-01 H2 — fieldset/legend/form must not produce untagged
		// real content (rule 7.1#3).
		$html = '<fieldset><legend>Personal info</legend><p>Name: paragraph text.</p></fieldset>';
		$this->generateAndCheck($html, '<fieldset><legend>');
	}

	public function testFormContainerPasses()
	{
		$html = '<form><p>Email: paragraph text inside form.</p></form>';
		$this->generateAndCheck($html, '<form> as block container');
	}

	public function testThScopeRowGroupPasses()
	{
		// Audit 2026-05-01 M1 — scope=rowgroup must map to /Scope=Row, not Both.
		$html = '<table>'
			. '<tr><th scope="rowgroup">Group A</th><th scope="col">Col 1</th></tr>'
			. '<tr><td>data</td><td>data</td></tr>'
			. '</table>';
		$this->generateAndCheck($html, '<th scope="rowgroup">');
	}

	public function testInlineSvgWithTitleAndDescPasses()
	{
		// PDF/UA-1 M5 — inline SVG with <title>/<desc> must be tagged as a Figure
		// with /Alt populated from the SVG's own accessibility metadata, satisfying
		// Matterhorn 13-004 even though the synthesised <img> has no alt attribute.
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<title>Company logo</title>'
			 . '<desc>A blue circle with the company initial in the centre.</desc>'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</svg>';
		$this->generateAndCheck('<p>' . $svg . '</p>', 'inline SVG with title and desc');
	}

	public function testRubyAnnotationPasses()
	{
		// Audit 2026-05-01 L4 — ruby tags previously had no handlers; verify
		// they do not crash and produce conformant tagging via Span fallback
		// (plan 2026-05-01 §4a). v2 proper Ruby/RB/RT/RP layout deferred.
		$html = '<p>Word <ruby><rb>kanji</rb><rp>(</rp><rt>furigana</rt><rp>)</rp></ruby> in context.</p>';
		$this->generateAndCheck($html, '<ruby><rt> with <rp> fallbacks');
	}

	public function testRubyInsideHeadingPasses()
	{
		$html = '<h1>Title with <ruby>kanji<rt>furigana</rt></ruby> annotation</h1><p>Body.</p>';
		$this->generateAndCheck($html, '<ruby> inside <h1>');
	}

	public function testRubyInsideLinkPasses()
	{
		$html = '<p><a href="https://example.com">Link <ruby>kanji<rt>furigana</rt></ruby> text</a></p>';
		$this->generateAndCheck($html, '<ruby> inside <a href>');
	}

	public function testRubyWithoutRbPasses()
	{
		// HTML5 allows the ruby base to be bare text (no <rb>).
		$html = '<p><ruby>kanji<rt>furigana</rt></ruby></p>';
		$this->generateAndCheck($html, 'bare-text ruby base (no <rb>)');
	}

	public function testRubyNestedPasses()
	{
		// Degenerate but legal — ensure the per-tag InlineUaStruct stack
		// handles same-name nesting without corruption.
		$html = '<p><ruby><ruby>kanji<rt>inner</rt></ruby><rt>outer</rt></ruby></p>';
		$this->generateAndCheck($html, 'nested <ruby><ruby></ruby></ruby>');
	}

	public function testRubyWithRtcPasses()
	{
		// HTML5 <rtc> groups multiple <rt> for compound bases.
		$html = '<p><ruby>kanji<rtc><rt>semantic</rt><rt>phonetic</rt></rtc></ruby></p>';
		$this->generateAndCheck($html, '<rtc> grouping multiple <rt>');
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Common probe: feed HTML through PDFUAauto, assert no throw, then run
	 * veraPDF when available.
	 *
	 * @param  string $html
	 * @param  string $label  human-readable description for failure messages
	 * @return void
	 */
	private function generateAndCheck($html, $label)
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf  = $this->getOutput($mpdf, $html);
		$this->assertNotEmpty($pdf, 'PDFUAauto must produce output for ' . $label);
		$this->assertVeraPdfCompliant($pdf, $label);
	}

	/**
	 * Tiny 1×1 transparent PNG used in image probes. Same byte string as
	 * VeraPdfConformanceTest so producer behaviour is identical.
	 *
	 * @return string  data: URI
	 */
	private function onePixelPng()
	{
		return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
	}

	/**
	 * If VERAPDF_BIN is configured, run veraPDF on the PDF bytes and assert
	 * isCompliant=true. Otherwise the assertion silently passes — the
	 * PDFUAauto-doesn't-throw half of the probe still ran.
	 *
	 * @param  string $pdfBytes
	 * @param  string $label
	 * @return void
	 */
	private function assertVeraPdfCompliant($pdfBytes, $label)
	{
		if ($this->veraPdfBin === '') {
			// veraPDF not available — the no-throw assertion is the whole probe.
			$this->assertTrue(true);
			return;
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'mpdf-poor-html-');
		$pdfFile = $tmpFile . '.pdf';
		rename($tmpFile, $pdfFile);
		file_put_contents($pdfFile, $pdfBytes);

		$result = null;
		try {
			$result = $this->runVeraPdf($pdfFile);
		} finally {
			if (is_file($pdfFile)) {
				unlink($pdfFile);
			}
		}

		if (!$result['isCompliant']) {
			$errorSummary = implode("\n", $result['errors']);
			$this->fail(
				'veraPDF ua1 validation FAILED for "' . $label . "\".\n\n"
				. 'Failures (' . count($result['errors']) . "):\n" . $errorSummary
			);
		}
		$this->assertTrue(true);
	}

	/**
	 * Invoke veraPDF and parse the JSON report. Mirrors the implementation
	 * in VeraPdfConformanceTest::runVeraPdf() — kept as a near-duplicate
	 * intentionally so the poor-HTML probe is self-contained and changes
	 * to the conformance gate cannot silently break this gate.
	 *
	 * @param  string $pdfPath
	 * @return array  ['isCompliant' => bool, 'errors' => string[]]
	 */
	private function runVeraPdf($pdfPath)
	{
		$cmd = escapeshellarg($this->veraPdfBin)
			. ' --flavour ua1 --format json '
			. escapeshellarg($pdfPath)
			. ' 2>/dev/null';

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
		];

		$process = proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($process)) {
			$this->fail('Failed to launch veraPDF process: ' . $cmd);
		}

		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($process);

		return $this->parseVeraPdfJson((string) $stdout);
	}

	/**
	 * Parse the JSON report. Same schema handling as VeraPdfConformanceTest
	 * — supports both veraPDF 1.26 (validationResult is an object) and 1.30+
	 * (validationResult is a single-element array).
	 *
	 * @param  string $json
	 * @return array
	 */
	private function parseVeraPdfJson($json)
	{
		if ($json === '') {
			$this->fail('veraPDF produced no JSON output. Is VERAPDF_BIN correct?');
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			$this->fail('veraPDF output is not valid JSON: ' . substr($json, 0, 2000));
		}

		if (!isset($data['report'])
			|| !isset($data['report']['jobs'])
			|| !is_array($data['report']['jobs'])
			|| !isset($data['report']['jobs'][0])
			|| !isset($data['report']['jobs'][0]['validationResult'])
		) {
			$this->fail('veraPDF JSON structure not recognised: ' . substr($json, 0, 2000));
		}

		$validationResult = $data['report']['jobs'][0]['validationResult'];
		if (is_array($validationResult)
			&& isset($validationResult[0])
			&& is_array($validationResult[0])
			&& array_key_exists('compliant', $validationResult[0])) {
			$validationResult = $validationResult[0];
		}
		$isCompliant = !empty($validationResult['compliant']);

		$errors = [];
		if (!$isCompliant && isset($validationResult['details']['ruleSummaries'])) {
			foreach ($validationResult['details']['ruleSummaries'] as $rule) {
				if (isset($rule['failedChecks']) && (int) $rule['failedChecks'] > 0) {
					$errors[] = sprintf(
						'[%s §%s test %s] %s (%d failed check%s)',
						isset($rule['specification']) ? $rule['specification'] : '',
						isset($rule['clause']) ? $rule['clause'] : '',
						isset($rule['testNumber']) ? $rule['testNumber'] : '',
						isset($rule['description']) ? $rule['description'] : '',
						(int) $rule['failedChecks'],
						(int) $rule['failedChecks'] === 1 ? '' : 's'
					);
				}
			}
		}

		return ['isCompliant' => $isCompliant, 'errors' => $errors];
	}
}
