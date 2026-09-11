<?php

namespace Snapshots;

/**
 * @group snapshot
 */
class ZeroFontSizeSnapshotTest extends Snapshot
{
	const ARABIC = 'مرحبا بالعالم';

	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'zero-font-size';
	}

	/**
	 * Generate a PDF document by initializing the Mpdf object on $this->mpdf and
	 * loading it with content
	 *
	 * @return   void
	 * @internal Don't call any $this->mpdf->Output*() method
	 */
	public function generatePdf()
	{
		$words = trim(str_repeat('hello world ', 12));

		ob_start();
		?>
		<style>
			p.note { color: #606060; }
			p.sample { font-family: dejavusans; border: 0.2mm solid #b0b0b0; padding: 2mm; }
			p.justified { text-align: justify; letter-spacing: 1px; }
			span.nothing { font-size: 0; }
		</style>

		<h1>mPDF</h1>
		<h2>A font size of zero</h2>

		<p class="note">Character and word spacing are written to the page as a per mille of the font
			size, so a size of nothing is a division by nothing. Each sample below holds a run set at
			zero between two runs that can be read, and the two halves have to meet with nothing between
			them.</p>

		<h3>Shaped text</h3>

		<p class="sample">Before <span class="nothing"><?= self::ARABIC ?></span> after, with the shaper
			run over a size of nothing.</p>

		<h3>Justified, and spaced out to reach both margins</h3>

		<p class="note">Justifying sets an inter-word adjustment that is a per mille of the size as
			well, so a zero size run has to survive being spaced out too.</p>

		<p class="sample justified"><?= $words ?> <span class="nothing"><?= $words ?></span>
			<?= $words ?></p>

		<h3>A whole paragraph at no size</h3>

		<p class="note">Nothing follows it, because a paragraph with no size takes no height.</p>

		<p style="font-family: dejavusans; font-size: 0"><?= self::ARABIC ?></p>
		<?php
		$html = ob_get_clean();

		$this->mpdf = $this->createMpdf(['mode' => 'utf-8']);
		$this->mpdf->WriteHTML($html);
	}
}
