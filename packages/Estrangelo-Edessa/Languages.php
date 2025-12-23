<?php

namespace Mpdf\Fonts\EstrangeloEdessa;

use Mpdf\Language\LanguageToFontInterface;

class Languages implements LanguageToFontInterface
{
	public function getLanguageOptions($mode, $adobeCJK)
	{
		$tags = explode('-', $mode);
		$language = strtolower($tags[0]);

		switch ($language) {
			// SYRIAC
			case 'syr':
				return 'estrangeloedessa';
		}

		return '';
	}
}
