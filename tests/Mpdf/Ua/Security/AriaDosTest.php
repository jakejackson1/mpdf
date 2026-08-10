<?php

namespace Mpdf\Ua\Security;

use Mpdf\Ua\PdfUaTestCase;
use Mpdf\Ua\AriaIdResolver;

/**
 * Regression suite for UA1 audit finding M-1 — `aria-labelledby` (and the
 * equivalent ARIA / TD `headers=""` attributes) used to be unbounded:
 * `preg_split('/\s+/', $value)` on a 1 MB input produced 500 000 pending
 * tuples in $pending[], pushing peak memory above the default php.ini
 * memory_limit.
 *
 * The fix imposes two caps in AriaIdResolver::queue() and the equivalent
 * caller in Tag\Td: a 16 KiB byte cap and a 256-token split cap, with
 * truncation surfaced as a getPdfUaWarnings() entry.
 *
 * @group pdfua
 * @group security
 */
class AriaDosTest extends PdfUaTestCase
{

	public function testOversizedAriaLabelledbyIsRejected()
	{
		// 1 MiB worth of "a " tokens would have pushed peak memory past
		// 300 MB before the M-1 fix.
		$payload = str_repeat('a ', AriaIdResolver::MAX_ARIA_IDS_LENGTH);
		$mpdf = $this->makeMpdf();

		$startMem = memory_get_usage(true);
		$this->getOutput($mpdf, '<p aria-labelledby="' . $payload . '">x</p>');
		$peak = memory_get_peak_usage(true);

		// Peak memory should remain bounded — well under 64 MiB even with
		// the rest of the document fixtures.
		$this->assertLessThan(
			64 * 1024 * 1024,
			$peak - $startMem,
			'Bounded aria-labelledby parsing must not amplify memory.'
		);

		$found = false;
		foreach ($mpdf->getPdfUaWarnings() as $w) {
			if (stripos($w, 'M-1') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Expected an M-1 truncation/rejection warning.');
	}

	public function testTooManyTokensAreTruncated()
	{
		// MAX_ARIA_IDS_TOKENS + 50 individual single-character ids — under the
		// byte-length cap, but past the token cap.
		$ids = [];
		$count = AriaIdResolver::MAX_ARIA_IDS_TOKENS + 50;
		for ($i = 0; $i < $count; $i++) {
			$ids[] = 'i' . $i;
		}
		$value = implode(' ', $ids);
		// PDFUAauto: none of the synthetic ids resolve, and audit E8 makes a
		// dangling aria-labelledby throw in strict mode. The token-cap behaviour
		// under test lives in queue() and is mode-independent, so auto mode lets
		// this DoS regression inspect the truncation warning without the throw.
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$this->getOutput($mpdf, '<p aria-labelledby="' . $value . '">x</p>');

		$found = false;
		foreach ($mpdf->getPdfUaWarnings() as $w) {
			if (stripos($w, 'truncated') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Expected a token-cap truncation warning.');
	}

	public function testNormalAriaLabelledbyIsUnaffected()
	{
		// PDFUAauto: the inline <span> targets do not register a struct-tree id,
		// so the reference is unresolved and audit E8 makes that throw in strict
		// mode. This test only asserts the M-1 cap did NOT fire (mode-independent),
		// so auto mode is sufficient and avoids the unrelated strict throw.
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$output = $this->getOutput($mpdf, '<p aria-labelledby="a b">x</p><span id="a">A</span><span id="b">B</span>');

		$truncationWarning = false;
		foreach ($mpdf->getPdfUaWarnings() as $w) {
			if (stripos($w, 'M-1') !== false || stripos($w, 'truncated') !== false) {
				$truncationWarning = true;
				break;
			}
		}
		$this->assertFalse($truncationWarning, 'Normal aria-labelledby must not trigger the M-1 cap.');
	}
}
