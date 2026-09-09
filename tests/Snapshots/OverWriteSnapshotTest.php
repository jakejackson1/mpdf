<?php

namespace Snapshots;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * @group snapshot
 */
class OverWriteSnapshotTest extends Snapshot
{
	/**
	 * @var string
	 */
	private $source;

	public function getId()
	{
		return 'overwrite';
	}

	/**
	 * Whether the source document is written compressed, which is not how the instance overwriting it is set
	 */
	protected function sourceCompressed()
	{
		return true;
	}

	protected function tear_down()
	{
		parent::tear_down();

		if ($this->source && is_file($this->source)) {
			unlink($this->source);
		}
	}

	/**
	 * A three page letter with a placeholder wherever a name, policy number or date belongs, written with core
	 * fonts so its text sits in the content streams as typed. The snapshot is what OverWrite() makes of it: the
	 * name is longer than its placeholder and carries a character outside ASCII, the policy number is shorter,
	 * and the last page has nothing to replace. The instance overwriting the source has the opposite compression
	 * setting to the one that wrote it, so each stream has to be read the way the document says.
	 */
	public function generatePdf()
	{
		ob_start();
		?>
		<h1>mPDF</h1>
		<h2>OverWrite()</h2>

		<p>Every name, policy number and date below stood in for a placeholder in square brackets until
			OverWrite() replaced it.</p>

		<p>Dear [NAME],</p>
		<p>Your policy [POLICY] renews on [DATE]. Nothing changes unless you tell us otherwise before then.</p>

		<pagebreak />

		<h2>Schedule</h2>
		<p>Prepared for [NAME] against policy [POLICY].</p>

		<pagebreak />

		<h2>Notes</h2>
		<p>Nothing on this page is replaced.</p>
		<?php
		$html = ob_get_clean();

		$this->mpdf = new Mpdf(['mode' => 'c']);
		$this->mpdf->compress = $this->sourceCompressed();
		$this->mpdf->WriteHTML($html);

		$this->source = tempnam(sys_get_temp_dir(), 'OverWriteSource');
		$this->mpdf->OutputFile($this->source);
	}

	protected function outputPdf($file)
	{
		$this->mpdf->compress = !$this->sourceCompressed();
		$this->mpdf->OverWrite(
			$this->source,
			['[NAME]', '[POLICY]', '[DATE]'],
			['Zoë Citizen', 'A7', '1 March 2027'],
			Destination::FILE,
			$file
		);
	}
}
