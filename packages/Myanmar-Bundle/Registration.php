<?php

namespace Mpdf\Fonts\MyanmarBundle;

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
			'ayar' => [
				'R' => 'ayar.ttf',
				'useOTL' => 0xFF,
			],

			'padaukbook' => [
				'R' => 'Padauk-book.ttf',
				'useOTL' => 0xFF,
			],

			'tharlon' => [
				'R' => 'Tharlon-Regular.ttf',
				'useOTL' => 0xFF,
			],

			'zawgyi-one' => [
				'R' => 'ZawgyiOne.ttf',
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