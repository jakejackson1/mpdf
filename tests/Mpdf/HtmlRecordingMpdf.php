<?php

namespace Mpdf;

/**
 * Records the HTML handed to WriteHTML() instead of rendering it.
 *
 * OtlDump reports everything it parses through 41 WriteHTML() calls, so recording those is an exact
 * account of what the dump found in a font - and a readable one, which a rendered PDF is not.
 */
class HtmlRecordingMpdf extends Mpdf
{

	/**
	 * @var string[]
	 */
	public $recordedHtml = [];

	public function WriteHTML($html, $mode = HTMLParserMode::DEFAULT_MODE, $init = true, $close = true)
	{
		$this->recordedHtml[] = $html;
	}

}
