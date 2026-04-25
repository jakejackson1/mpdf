<?php

namespace Mpdf\Ua;

/**
 * Base class for all PDF/UA-1 test classes.
 *
 * Extends Yoast's PHPUnit polyfill TestCase so set_up/tear_down are used
 * rather than setUp/tearDown — matching the mPDF test suite convention.
 *
 * Must NOT use mode='c' (core fonts) because PDF/UA-1 requires all fonts to
 * be embedded (ISO 14289-1:2014 §7.21 / Matterhorn Protocol 1.1 condition 14-002).
 * BaseMpdfTest defaults to ['mode' => 'c'] so we do not extend it.
 *
 * Content stream compression is disabled ($mpdf->compress = false) so that
 * content-stream assertions can match raw operator bytes without needing to
 * decompress FlateDecode streams. XMP metadata streams are never compressed
 * and remain directly string-matchable.
 *
 * @see MetadataTest  Phase 1 metadata and catalog assertions
 */
abstract class PdfUaTestCase extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * Create a minimal PDFUA-enabled Mpdf instance with embedded TrueType fonts.
	 *
	 * ISO 14289-1:2014 §7.1 — a non-empty document title is required.
	 * The 'title' config key is added by §1a of the Phase 1 plan so it flows
	 * through the config-merge loop and populates $mpdf->title before Output() runs.
	 *
	 * @param  array $config  Additional config keys to merge over the defaults.
	 * @return \Mpdf\Mpdf
	 */
	protected function makeMpdf($config = [])
	{
		// 'mode' => 'en-GB' sets currentLang and default_lang so the /Lang catalog
		// entry is populated — required by ISO 14289-1:2014 §7.2 / Matterhorn 04-001.
		// Tests that specifically check lang-missing behaviour construct Mpdf directly.
		$defaults = ['PDFUA' => true, 'title' => 'Test Document', 'mode' => 'en-GB'];
		$mpdf = new \Mpdf\Mpdf(array_merge($defaults, $config));
		// Disable FlateDecode compression so content-stream assertions work against
		// plain text bytes (XMP assertions are unaffected — XMP is never compressed).
		$mpdf->compress = false;
		return $mpdf;
	}

	/**
	 * Write HTML into the document and return the raw PDF bytes.
	 *
	 * @param  \Mpdf\Mpdf $mpdf
	 * @param  string     $html
	 * @return string  Raw PDF output bytes.
	 */
	protected function getOutput(\Mpdf\Mpdf $mpdf, $html)
	{
		$mpdf->WriteHTML($html);
		return $mpdf->Output(null, 'S');
	}
}
