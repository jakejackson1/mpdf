<?php

namespace Mpdf;

/**
 * Records the positioning OpenType layout worked out for every line, as it is handed to the drawing
 * code.
 *
 * GPOS moves glyphs rather than replacing them, so the seam TextRecordingMpdf uses cannot see it -
 * the text is the same either way. Cell() is handed the OTLdata alongside that text, and its
 * 'GPOSinfo' is the adjustment each glyph in the line has been given, keyed by its position. The
 * signature is restated in full because a variadic tail only absorbs the parameters it replaces
 * from PHP 8.0, and warns about the declaration on every version before that.
 */
class PositionRecordingMpdf extends Mpdf
{

	public $drawnPositions = [];

	function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = 0, $link = '', $currentx = 0, $lcpaddingL = 0, $lcpaddingR = 0, $valign = 'M', $spanfill = 0, $exactWidth = false, $OTLdata = false, $textvar = 0, $lineBox = false)
	{
		if (is_array($OTLdata) && !empty($OTLdata['GPOSinfo'])) {
			$this->drawnPositions[] = $OTLdata['GPOSinfo'];
		}

		return parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link, $currentx, $lcpaddingL, $lcpaddingR, $valign, $spanfill, $exactWidth, $OTLdata, $textvar, $lineBox);
	}

}
