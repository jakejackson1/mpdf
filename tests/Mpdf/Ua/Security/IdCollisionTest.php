<?php

namespace Mpdf\Ua\Security;

use Mpdf\Ua\PdfUaTestCase;
use Mpdf\Ua\StructureElement;

/**
 * Regression suite for UA1 audit finding M-2 — sanitiseIdForPdf() used a
 * 7-hex-char (28-bit) collision suffix, hitting the birthday bound at
 * ~2^14 distinct overlong IDs. The pen-tester demonstrated 5 collisions
 * in 58 050 random IDs sharing a 117-byte prefix; the fix widens the
 * suffix to 16 hex (64-bit) which moves the bound to ~2^32.
 *
 * @group pdfua
 * @group security
 */
class IdCollisionTest extends PdfUaTestCase
{

	public function testWidenedSuffixAvoidsBirthdayCollisionsAt60k()
	{
		$prefix = str_repeat('a', 117);
		$seen   = [];
		$collisions = 0;
		$N = 60000;

		for ($i = 0; $i < $N; $i++) {
			// Sufficiently random suffix so the inputs are distinct.
			$id  = $prefix . sprintf('-%012d-%s', $i, bin2hex(random_bytes(6)));
			$san = StructureElement::sanitiseIdForPdf($id);
			if (isset($seen[$san])) {
				$collisions++;
			}
			$seen[$san] = true;
		}

		$this->assertSame(
			0,
			$collisions,
			'Expected zero collisions in ' . $N . ' overlong IDs after the M-2 widening.'
		);
	}

	public function testSanitisedIdFitsPdfNameLengthLimit()
	{
		$long = str_repeat('xyzABC123', 200); // 1800 bytes
		$out = StructureElement::sanitiseIdForPdf($long);
		$this->assertLessThanOrEqual(127, strlen($out));
	}

	public function testTwoPrefixSharingOverlongIdsRemainDistinct()
	{
		$prefix = str_repeat('z', 200);
		$a = StructureElement::sanitiseIdForPdf($prefix . '-distinct-tail-A');
		$b = StructureElement::sanitiseIdForPdf($prefix . '-distinct-tail-B');
		$this->assertNotSame($a, $b);
	}

	public function testCaseFoldingStillNormalises()
	{
		// Existing M-2-unrelated property: A-Z → a-z so TH ID and TD headers
		// match byte-for-byte.
		$this->assertSame(
			StructureElement::sanitiseIdForPdf('My-ID'),
			StructureElement::sanitiseIdForPdf('my-id')
		);
	}
}
