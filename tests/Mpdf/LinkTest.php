<?php

namespace Mpdf;

/**
 * Whether an href is classified as a named destination or an external target.
 *
 * Asserted against the emitted PDF because the two render identically — a misclassified link is
 * clickable and simply goes to page 1.
 */
class LinkTest extends BaseMpdfTest
{

	/**
	 * @dataProvider externalHrefProvider
	 */
	public function testAnHttpHrefIsWrittenAsAUri($href)
	{
		$this->mpdf->WriteHTML(sprintf('<p><a href="%s">link</a></p>', $href));

		$output = $this->mpdf->Output(null, 'S');

		$this->assertStringContainsString('/URI (' . $href . ')', $output);
		$this->assertStringNotContainsString('/Dest', $output);
	}

	public function externalHrefProvider()
	{
		return [
			// The regression: no dot anywhere, so each was read as a named anchor.
			'dotless host' => ['http://localhost/entry?id=1'],
			'dotless host with port' => ['http://localhost:8080/entry?id=1'],
			'single label host' => ['https://intranet/page'],
			'uppercase scheme' => ['HTTP://localhost/entry'],
			'tel' => ['tel:+441234567890'],
			'sms' => ['sms:+441234567890'],
			'dotless mailto' => ['mailto:admin@localhost'],

			// Already worked, by accident of the dot.
			'dotted host' => ['https://example.com/page'],
			'dotted mailto' => ['mailto:someone@example.com'],
		];
	}

	/** Unlisted schemes keep their old behaviour. Pinned so widening the list is a deliberate choice. */
	public function testADotlessHrefUnderAnUnlistedSchemeIsUnchanged()
	{
		$this->mpdf->WriteHTML('<p><a href="ftp://localhost/file">download</a></p>');

		$output = $this->mpdf->Output(null, 'S');

		$this->assertStringContainsString('/Dest', $output);
		$this->assertStringNotContainsString('/URI', $output);
	}

	public function testAHashHrefStillNamesADestination()
	{
		/* Only "#name" is pinned: a bare "name" is rewritten by GetFullPath, so it depends on basepath. */
		$this->mpdf->WriteHTML('<p><a href="#chapter1">link</a></p>');

		$output = $this->mpdf->Output(null, 'S');

		$this->assertStringContainsString('/Dest', $output);
		$this->assertStringNotContainsString('/URI', $output);
	}

	public function testANamedAnchorResolvesToItsOwnPage()
	{
		$this->mpdf->WriteHTML('<p><a href="#target">to target</a></p>');
		$this->mpdf->AddPage();
		$this->mpdf->WriteHTML('<p><a name="target"></a>here</p>');

		$output = $this->mpdf->Output(null, 'S');

		/* Page objects run 3, 5, 7... so page two is `5 0 R`; an unmatched anchor would be `1 0 R`. */
		$this->assertStringContainsString('/Dest [5 0 R', $output);
		$this->assertStringNotContainsString('/Dest [1 0 R', $output);
	}

	public function testAnAtPrefixedHrefStaysAPageReference()
	{
		$this->mpdf->WriteHTML('<p><a href="@1">page one</a></p>');

		$output = $this->mpdf->Output(null, 'S');

		$this->assertStringContainsString('/Dest [3 0 R', $output);
		$this->assertStringNotContainsString('/URI', $output);
	}
}
