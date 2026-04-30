<?php

namespace Mpdf\Ua;

/**
 * Legacy-form artifact tagging — `useActiveForms=false` under PDFUA.
 *
 * When mPDF renders form widgets without active forms it draws inert chrome
 * (rectangles, text via Cell, ZapfDingbats glyphs) directly into the page
 * content stream. Pre-fix, that drawing produced "untagged real content"
 * which veraPDF rule 7.1#3 (ISO 14289-1:2014 §7.1) flags as a UA violation.
 *
 * The fix wraps every legacy `print_ob_*` else-branch in a /Artifact BMC ...
 * EMC bracket and a StructureTree artifact-suppression scope. The drawn
 * chrome is then a marked-content artifact (ISO 32000-1 §14.8.2.2) — outside
 * logical structure, conformant under PDF/UA-1.
 *
 * This file asserts both the conformance contract for the legacy path AND
 * cohabitation with the active-forms path (added in Phase 4).
 *
 * @group pdfua
 * @see   Form.php  print_ob_text/textarea/select/checkbox/radio/button/imageinput
 */
class LegacyFormArtifactTaggingTest extends PdfUaTestCase
{

	/**
	 * Build a PDFUA mPDF in PDFUAauto mode WITHOUT useActiveForms.
	 *
	 * Bypasses PdfUaTestCase::makeMpdf() because that helper carries
	 * useActiveForms=true to satisfy the HIGH-5 guard. After Phase 3 of
	 * the legacy-form artifact-tagging plan removes that guard, this
	 * direct construction becomes the canonical legacy-mode setup.
	 *
	 * @param  array $extraConfig
	 * @return \Mpdf\Mpdf
	 */
	private function makeLegacyMpdf($extraConfig = [])
	{
		$defaults = [
			'PDFUA' => true,
			'PDFUAauto' => true,
			'title' => 'Legacy Form Test',
			'mode' => 'en-GB',
			'useActiveForms' => false,
		];
		$mpdf = new \Mpdf\Mpdf(array_merge($defaults, $extraConfig));
		$mpdf->compress = false;
		return $mpdf;
	}

	/**
	 * Render $html and return raw PDF bytes.
	 *
	 * @param  \Mpdf\Mpdf $mpdf
	 * @param  string     $html
	 * @return string
	 */
	private function render(\Mpdf\Mpdf $mpdf, $html)
	{
		$mpdf->WriteHTML($html);
		return $mpdf->Output(null, 'S');
	}

	/**
	 * Apply the four legacy-mode contract assertions to one widget render.
	 *
	 *   1. PDF was produced (non-empty bytes).
	 *   2. Output contains at least one /Artifact BMC marker — the legacy
	 *      drawing path emitted at least one artifact bracket.
	 *   3. Output contains a matching EMC.
	 *   4. The struct tree carries NO /S /Form or /S /Annot — the legacy
	 *      path has no AcroForm widget annotation behind the chrome, so
	 *      no Form/Annot struct kid should reference one.
	 *
	 * @param  string $output  Raw PDF bytes
	 * @param  string $widget  Human-readable widget label for assertion messages
	 * @return void
	 */
	private function assertLegacyArtifactContract($output, $widget)
	{
		$this->assertNotEmpty($output, $widget . ': PDF must be produced');
		$this->assertStringContainsString(
			'/Artifact BMC',
			$output,
			$widget . ': legacy chrome must be wrapped in /Artifact BMC'
		);
		$this->assertStringContainsString(
			'EMC',
			$output,
			$widget . ': /Artifact BMC must be closed with EMC'
		);
		$this->assertStringNotContainsString(
			'/S /Form',
			$output,
			$widget . ': legacy path must not emit a Form struct kid'
		);
		$this->assertStringNotContainsString(
			'/S /Annot',
			$output,
			$widget . ': legacy path must not emit an Annot struct kid'
		);
	}

	// =================================================================
	// Phase 1 — one method per widget type, legacy mode
	// =================================================================

	public function testInputTextWrappedInArtifact()
	{
		$mpdf = $this->makeLegacyMpdf();
		$out = $this->render($mpdf, '<p>Name: <input type="text" name="x" value="Jane" /></p>');
		$this->assertLegacyArtifactContract($out, '<input type=text>');
	}

	public function testTextareaWrappedInArtifact()
	{
		$mpdf = $this->makeLegacyMpdf();
		$out = $this->render(
			$mpdf,
			'<p>Notes:</p><textarea name="n" rows="3" cols="20">First line</textarea>'
		);
		$this->assertLegacyArtifactContract($out, '<textarea>');
	}

	public function testSelectWrappedInArtifact()
	{
		$mpdf = $this->makeLegacyMpdf();
		$out = $this->render(
			$mpdf,
			'<p>Pick: <select name="s"><option>Alpha</option><option selected>Beta</option></select></p>'
		);
		$this->assertLegacyArtifactContract($out, '<select>');
	}

	public function testCheckboxWrappedInArtifact()
	{
		$mpdf = $this->makeLegacyMpdf();
		$out = $this->render(
			$mpdf,
			'<p><input type="checkbox" name="c" checked /> agree</p>'
		);
		$this->assertLegacyArtifactContract($out, '<input type=checkbox>');
	}

	public function testRadioWrappedInArtifact()
	{
		$mpdf = $this->makeLegacyMpdf();
		$out = $this->render(
			$mpdf,
			'<p><input type="radio" name="r" value="a" checked /> option A</p>'
		);
		$this->assertLegacyArtifactContract($out, '<input type=radio>');
	}

	public function testButtonWrappedInArtifact()
	{
		$mpdf = $this->makeLegacyMpdf();
		$out = $this->render(
			$mpdf,
			'<p><input type="submit" name="b" value="Send" /></p>'
		);
		$this->assertLegacyArtifactContract($out, '<input type=submit>');
	}

	public function testImageButtonWrappedInArtifact()
	{
		$mpdf = $this->makeLegacyMpdf();
		// Tiny inline PNG so the test does not need network or filesystem fixtures.
		$png = 'data:image/png;base64,'
			. 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
		$out = $this->render(
			$mpdf,
			'<p><input type="image" name="ib" src="' . $png . '" width="20" height="20" /></p>'
		);
		$this->assertLegacyArtifactContract($out, '<input type=image>');
	}

	/**
	 * Strict mode (PDFUAauto=false) with useActiveForms=false must NOT throw.
	 * Once Phase 3 removes the HIGH-5 guard, the combination is a first-class
	 * legitimate config and the rendering path tags itself correctly.
	 *
	 * Plan §3e, §6 risk note.
	 */
	public function testStrictModeLegacyFormsDoNotThrow()
	{
		$mpdf = $this->makeLegacyMpdf(['PDFUAauto' => false]);
		$out = $this->render($mpdf, '<p><input type="text" name="x" /></p>');
		$this->assertLegacyArtifactContract($out, 'strict-mode <input type=text>');
	}

	/**
	 * No useActiveForms warning should appear in PDFUAauto mode either —
	 * after Phase 3, the auto-flip is gone and the legacy path is silent.
	 */
	public function testAutoModeRecordsNoUseActiveFormsWarning()
	{
		$mpdf = $this->makeLegacyMpdf();
		$this->render($mpdf, '<p><input type="text" name="x" /></p>');
		foreach ($mpdf->getPdfUaWarnings() as $w) {
			$this->assertStringNotContainsString(
				'useActiveForms',
				$w,
				'No useActiveForms warning should be emitted by the legacy path'
			);
		}
	}
}
