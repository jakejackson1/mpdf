<?php

namespace Mpdf\Ua\Security;

use Mpdf\Ua\PdfUaTestCase;
use Mpdf\Ua\Import\FpdiStructMerger;

/**
 * Regression for UA1 audit finding M-5 — the imported-string sanity gauntlet
 * (FpdiStructMerger::stringPassesSanityGauntlet) used a strict `> 0.5`
 * suspicious-codepoint threshold, so a string that is exactly half suspicious
 * (the classic 50 legible + 50 U+FFFD ciphertext-through-PDFDocEncoding shape)
 * slipped through. The threshold is now `>= 0.5`.
 *
 * The gauntlet is a forward-compatibility guard: FPDI refuses encrypted source
 * PDFs today, so a still-encrypted /Alt cannot reach it yet — but a future FPDI
 * release that lifts that refusal must not leak ciphertext into the host tree.
 *
 * @group pdfua
 * @group security
 */
class SanityGauntletThresholdTest extends PdfUaTestCase
{

	/** @var FpdiStructMerger */
	private $merger;

	/** @var \ReflectionMethod */
	private $gauntlet;

	protected function set_up()
	{
		parent::set_up();
		$this->merger = $this->makeMpdf()->getPdfUaFpdiStructMerger();
		$this->gauntlet = new \ReflectionMethod(FpdiStructMerger::class, 'stringPassesSanityGauntlet');
		$this->gauntlet->setAccessible(true);
	}

	private function passes($decoded)
	{
		return $this->gauntlet->invoke($this->merger, $decoded);
	}

	/**
	 * 50 legible ASCII + 50 U+FFFD = exactly 50% suspicious codepoints. Under the
	 * old `> 0.5` test this passed; it must now fail.
	 */
	public function testExactlyHalfSuspiciousIsRejected()
	{
		$fiftyFifty = str_repeat('A', 50) . str_repeat("\xEF\xBF\xBD", 50);
		$this->assertFalse(
			$this->passes($fiftyFifty),
			'a string that is exactly 50% suspicious codepoints must fail the gauntlet'
		);
	}

	/**
	 * A predominantly legible string (10% suspicious) still passes.
	 */
	public function testMajorityLegibleStringPasses()
	{
		$mostlyLegible = str_repeat('A', 90) . str_repeat("\xEF\xBF\xBD", 10);
		$this->assertTrue(
			$this->passes($mostlyLegible),
			'a predominantly legible string must pass the gauntlet'
		);
	}

	/**
	 * Ordinary alternative-description text passes unchanged.
	 */
	public function testCleanTextPasses()
	{
		$this->assertTrue(
			$this->passes('A perfectly ordinary alternative description.'),
			'clean legible text must pass the gauntlet'
		);
	}
}
