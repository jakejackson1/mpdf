<?php

namespace Mpdf\Fonts\XbRiyaz;

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
			'xbriyaz' => [
				'R' => 'XB Riyaz.ttf',
				'B' => 'XB RiyazBd.ttf',
				'I' => 'XB RiyazIt.ttf',
				'BI' => 'XB RiyazBdIt.ttf',
				'useOTL' => 0xFF,
				'useKashida' => 75,
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

	/**
	 * Define fonts to be used for character substitution, when the useSubstitutions configuration option enabled
	 *
	 * @return array The list of fonts to exclude using the keys found in $this->getFontData()
	 */
	public function getBackupSubsFonts()
	{
		return [
			'xbriyaz',
		];
	}
}