<?php

namespace Mpdf\Fonts\AncientScripts;

use Mpdf\Fonts\FontRegistration;

class Registration extends FontRegistration
{
	/**
	 * Get the absolute path to the fonts directory
	 *
	 * @return string
	 */
	public function getDirectory()
	{
		return __DIR__ . '/fonts/';
	}

	/**
	 * Get the fonts to be registered with mPDF
	 *
	 * @return []
	 * @see     http://mpdf.github.io/fonts-languages/fonts-in-mpdf-7-x.html
	 */
	public function getFonts()
	{
		return [
			'aegyptus' => [
				'R' => 'Aegyptus.otf',
				'useOTL' => 0xFF,
			],

			'aegean' => [
				'R' => 'Aegean.otf',
				'useOTL' => 0xFF,
			],

			'akkadian' => [
				'R' => 'Akkadian.otf',
				'useOTL' => 0xFF,
			],
		];
	}

	/**
	 * Get the Language Package LanguageToFont implementation
	 *
	 * @return \Mpdf\Language\LanguageToFontInterface|null
	 */
	public function getLanguageToFont()
	{
		return new Languages();
	}
}