<?php

namespace Snapshots;

use Mpdf\Output\Destination;
use Mpdf\TextRecordingMpdf;

/**
 * @group snapshot
 */
class DictionaryLineBreakingSnapshotTest extends Snapshot
{
	/**
	 * Thai for "test Thai text for line breaking", led by the abbreviation full stop that used to seed a
	 * boundary of its own, and Tibetan for "Tibetan script". Neither script writes spaces between
	 * words, so the only place a line can break is where a dictionary marked a boundary.
	 *
	 * Keep the phrase long enough that its boundaries fall clear of where the line would overflow.
	 * A short one repeated into the sample box lands them on top of each other, and both halves
	 * then break in the same places whatever the settings say.
	 */
	const THAI = 'ถ.ทดสอบข้อความภาษาไทยสำหรับการตัดบรรทัด';

	const TIBETAN = 'བོད་སྐད་ཡིག་ཚོགས་';

	/**
	 * @return string A unique identifier / name for the snapshot
	 */
	public function getId()
	{
		return 'dictionary-line-breaking';
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
		$this->mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8']);

		$this->mpdf->WriteHTML($this->style() . $this->samples('With the dictionaries in use'));

		// The flags the shaper reads, so the same two paragraphs can be drawn both ways in one document
		$this->mpdf->useDictionaryLBR = false;
		$this->mpdf->useTibetanLBR = false;

		$this->mpdf->WriteHTML($this->samples('With the dictionaries turned off'));
	}

	private function style()
	{
		ob_start();
		?>
		<style>
			p.note { color: #606060; }
			p.sample { border: 0.2mm solid #b0b0b0; padding: 2mm; }
			p.thai { font-family: garuda; }
			p.tibetan { font-family: jomolhari; }
		</style>

		<h1>mPDF</h1>
		<h2>Dictionary line breaking</h2>

		<p class="note">Neither script writes a space between words, so a line can only break where the
			shaper marked a boundary with U+200B. The first pair below is drawn with the dictionaries in
			use and the second with them turned off, and turning them off has to leave the run alone -
			so the second pair has to break somewhere the first one did not. The marker is discarded
			once it has been read, so neither pair draws it.</p>
		<?php

		return ob_get_clean();
	}

	private function samples($heading)
	{
		return '<h3>' . $heading . '</h3>' . $this->thaiSample() . $this->tibetanSample();
	}

	private function thaiSample()
	{
		return '<p class="sample thai">' . str_repeat(self::THAI, 5) . '</p>';
	}

	private function tibetanSample()
	{
		return '<p class="sample tibetan">' . str_repeat(self::TIBETAN, 10) . '</p>';
	}

	/**
	 * @return string[] The text of each line the sample drew, as the drawing code received it
	 */
	private function drawnLines($sample, $config)
	{
		$mpdf = new TextRecordingMpdf(array_merge(['mode' => 'utf-8'], $config));
		$mpdf->WriteHTML($this->style() . $sample);
		$mpdf->Output('', Destination::STRING_RETURN);

		$lines = $mpdf->drawnText;
		$mpdf->cleanup();

		return $lines;
	}

	/**
	 * The document only demonstrates anything if its samples break differently once the settings
	 * are off, and it is easy to write one that does not: a phrase whose boundaries land where the
	 * line would have overflowed anyway breaks the same way either side, and the snapshot then
	 * passes while showing nothing.
	 */
	public function testTheThaiSampleBreaksDifferentlyOnceTheDictionaryIsOff()
	{
		$this->assertNotSame(
			$this->drawnLines($this->thaiSample(), []),
			$this->drawnLines($this->thaiSample(), ['useDictionaryLBR' => false])
		);
	}

	public function testTheTibetanSampleBreaksDifferentlyOnceTheAlgorithmIsOff()
	{
		$this->assertNotSame(
			$this->drawnLines($this->tibetanSample(), []),
			$this->drawnLines($this->tibetanSample(), ['useTibetanLBR' => false])
		);
	}
}
