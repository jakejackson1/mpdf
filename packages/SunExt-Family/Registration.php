<?php

namespace Mpdf\Fonts\SunExtFamily;

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
		/* @TODO - return an array of Font classes */
		return [
			"sun-exta" => [
				'R' => "Sun-ExtA.ttf",
				'sip-ext' => 'sun-extb', /* SIP=Plane2 Unicode (extension B) */
			],
			"sun-extb" => [
				'R' => "Sun-ExtB.ttf",
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
			'sun-exta',
		];
	}
}