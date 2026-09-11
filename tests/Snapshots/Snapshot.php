<?php

namespace Snapshots;

use Imagick;
use Mpdf\Mpdf;

/**
 * A document rendered by the current code against its fixture in tests/data/snapshots. The bytes are compared first,
 * then the objects as PdfText reads them, both instant and needing nothing installed. Only when those differ are the
 * pages rasterised through Imagick and compared, with the document and a diff of what moved in it left in
 * tmp/artifacts.
 *
 * @group snapshot
 */
abstract class Snapshot extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	/**
	 * The moment every snapshot document is dated: 2000-01-01T00:00:00Z
	 */
	const CREATION_DATE = 946684800;

	/**
	 * The resolution the pages are rasterised at
	 */
	const DPI = 120;

	/**
	 * @var Mpdf
	 */
	protected $mpdf;

	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	abstract public function getId();

	/**
	 * Generate a PDF document by initializing the Mpdf object on $this->mpdf, through createMpdf(), and loading it
	 * with content
	 *
	 * @return   void
	 * @internal Don't call any $this->mpdf->Output*() method
	 */
	abstract public function generatePdf();

	/**
	 * An Mpdf whose output carries nothing from the machine or the moment that made it: dated at CREATION_DATE, no
	 * version in the Producer, and streams left uncompressed so a fixture's diff is readable. $config adds to that,
	 * or overrides it.
	 *
	 * @return Mpdf
	 */
	protected function createMpdf(array $config = [])
	{
		$mpdf = new Mpdf($config + ['creationDate' => self::CREATION_DATE, 'exposeVersion' => false]);
		$mpdf->SetCompression(false);

		return $mpdf;
	}

	/**
	 * @return float How far a page may differ from its snapshot before it fails: the root mean square error Imagick
	 * reports between the two, 0 for the same picture and 1 for its opposite
	 */
	protected function getComparisonFuzzyLimit()
	{
		return 0.0;
	}

	/**
	 * @return bool Whether the document's images go through GD, so its fixture carries the GD build that made it and
	 * only the pages can say whether another build drew it the same
	 */
	protected function imagesGoThroughGd()
	{
		return false;
	}

	protected function getPathToSnapshot()
	{
		return __DIR__ . '/../data/snapshots/';
	}

	/**
	 * Write the document to compare to $file. Override when the document is not the one $this->mpdf renders.
	 *
	 * @param string $file
	 * @return void
	 */
	protected function outputPdf($file)
	{
		$this->mpdf->OutputFile($file);
	}

	/**
	 * Write the document as the fixture: what composer snapshot:update does
	 *
	 * @return string The fixture's path
	 */
	public function updateSnapshot()
	{
		$this->generatePdf();
		$file = $this->getPathToSnapshot() . $this->getId() . '.pdf';
		$this->outputPdf($file);

		return $file;
	}

	public function testSnapshot()
	{
		$this->generatePdf();
		if (!$this->mpdf instanceof Mpdf) {
			$this->markTestIncomplete('$this->mpdf is not an Mpdf object.');
		}

		$snapshot = $this->getPathToSnapshot() . $this->getId() . '.pdf';
		if (!file_exists($snapshot)) {
			$this->fail(sprintf('There is no snapshot of "%1$s" yet: composer snapshot:update %1$s', $this->getId()));
		}

		$file = tempnam(sys_get_temp_dir(), 'Pdf');
		$this->outputPdf($file);
		$actual = file_get_contents($file);
		unlink($file);
		$expected = file_get_contents($snapshot);

		if ($actual === $expected) {
			$this->addToAssertionCount(1);
			return;
		}

		// The bytes differ. Keep the document and what moved in it, then let the objects and the pages decide
		$artifacts = $this->mpdf->tempDir . '/artifacts/' . $this->getId();
		if (!is_dir($artifacts)) {
			mkdir($artifacts, 0755, true);
		}
		$artifacts = realpath($artifacts) . '/';
		$document = $artifacts . $this->getId() . '.pdf';
		file_put_contents($document, $actual);
		$diff = PdfText::diff($expected, $actual);
		file_put_contents($artifacts . $this->getId() . '.diff', $diff);

		if ($diff !== '') {
			try {
				$failures = $this->comparePages($snapshot, $document, $artifacts);
			} catch (\RuntimeException $e) {
				// Without a rasteriser the pages cannot be judged. On CI a real difference in the objects fails here and the
				// snapshot job says whether it shows; a document that only the pages can judge, or a laptop, skips
				$message = sprintf('The bytes of "%s" differ from its snapshot and %s. What moved, in full in %s:', $this->getId(), $e->getMessage(), $artifacts);
				if (getenv('CI') && !$this->imagesGoThroughGd()) {
					$this->fail($message . "\n" . PdfText::head($diff, 40));
				}
				$this->markTestSkipped($message . "\n" . PdfText::head($diff, 10));
			}
			if ($failures) {
				$this->fail(implode("\n", $failures) . "\n\nWhat moved, in full in " . $artifacts . ":\n" . PdfText::head($diff, 40));
			}
		}

		$this->addToAssertionCount(1);
		fwrite(STDERR, sprintf("\nSnapshot \"%1\$s\": the bytes differ from its fixture but the pages are the same. Refresh it with composer snapshot:update %1\$s\n", $this->getId()));
	}

	/**
	 * How the pages of $actual differ from those of $expected, as messages, with Imagick's picture of each difference
	 * left in $artifacts; empty when they match
	 *
	 * @return string[]
	 * @throws \RuntimeException When Imagick is not here, or has no Ghostscript to read a PDF with
	 */
	private function comparePages($expected, $actual, $artifacts)
	{
		if (!class_exists('Imagick')) {
			throw new \RuntimeException('Imagick is not installed');
		}
		try {
			$expectedPages = $this->pages($expected);
		} catch (\ImagickException $e) {
			throw new \RuntimeException('Imagick cannot read a PDF here (' . trim($e->getMessage()) . ')', 0, $e);
		}
		$actualPages = $this->pages($actual);

		$failures = [];
		if (count($actualPages) !== count($expectedPages)) {
			$failures[] = sprintf('The document has %d pages where its snapshot has %d', count($actualPages), count($expectedPages));
		}

		foreach ($actualPages as $i => $page) {
			if (!isset($expectedPages[$i])) {
				break;
			}
			$expectedImage = new Imagick($expectedPages[$i]);
			$actualImage = new Imagick($page);
			$result = $actualImage->compareImages($expectedImage, Imagick::METRIC_ROOTMEANSQUAREDERROR);
			if ($result[1] > $this->getComparisonFuzzyLimit()) {
				$picture = $artifacts . 'page-' . ($i + 1) . '.png';
				$result[0]->setImageFormat('png');
				file_put_contents($picture, $result[0]);
				$failures[] = sprintf('Page %d differs from its snapshot by an RMSE of %.4f: see %s', $i + 1, $result[1], $picture);
			}
			$expectedImage->clear();
			$actualImage->clear();
		}

		foreach (array_merge($expectedPages, $actualPages) as $page) {
			unlink($page);
		}

		return $failures;
	}

	/**
	 * A PNG of each page of $pdf
	 *
	 * @return string[]
	 */
	private function pages($pdf)
	{
		$image = new Imagick();
		$image->setResolution(self::DPI, self::DPI);
		$image->readImage($pdf);
		$image->setImageBackgroundColor('white'); // else anything transparent comes out black

		$pages = [];
		for ($i = 0; $i < $image->getNumberImages(); $i++) {
			$page = tempnam(sys_get_temp_dir(), 'PdfImage');
			$image->setIteratorIndex($i);
			$image->setImageFormat('png');
			$image->writeImage($page);
			$pages[] = $page;
		}

		$image->clear();

		return $pages;
	}
}
