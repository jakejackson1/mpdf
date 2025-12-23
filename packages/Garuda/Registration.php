<?php

namespace Mpdf\Fonts\Garuda;

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
			'garuda' => [/* Thai */
				'R' => 'Garuda.ttf',
				'B' => 'Garuda-Bold.ttf',
				'I' => 'Garuda-Oblique.ttf',
				'BI' => 'Garuda-BoldOblique.ttf',
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

	/**
	 * Define fonts to be used for character substitution, when the useSubstitutions configuration option enabled
	 *
	 * @return array The list of fonts to exclude using the keys found in $this->getFontData()
	 */
	public function getBackupSubsFonts()
	{
		return [
			'garuda',
		];
	}

	/**
	 * Get a list of substituted fonts used when a font is not available in mPDF
	 *
	 * @return array Multidimensional array with keys 'sans_fonts', 'serif_fonts', and 'mono_fonts'
	 */
	public function getFontFamilySubstitution()
	{
		return [
			'sans_fonts' => [
				'garuda',
			],
		];
	}
}