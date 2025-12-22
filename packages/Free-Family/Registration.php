<?php

namespace Mpdf\Fonts\FreeFamily;

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
			'freesans' => [
				'R' => 'FreeSans.ttf',
				'B' => 'FreeSansBold.ttf',
				'I' => 'FreeSansOblique.ttf',
				'BI' => 'FreeSansBoldOblique.ttf',
				'useOTL' => 0xFF,
			],

			'freeserif' => [
				'R' => 'FreeSerif.ttf',
				'B' => 'FreeSerifBold.ttf',
				'I' => 'FreeSerifItalic.ttf',
				'BI' => 'FreeSerifBoldItalic.ttf',
				'useOTL' => 0xFF,
				'useKashida' => 75,
			],

			'freemono' => [
				'R' => 'FreeMono.ttf',
				'B' => 'FreeMonoBold.ttf',
				'I' => 'FreeMonoOblique.ttf',
				'BI' => 'FreeMonoBoldOblique.ttf',
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
			'freesans',
		];
	}

	/**
	 * Get a list of substituted fonts used when a font is not available in mPDF. Define 'sans_fonts', 'serif_fonts', and 'mono_fonts'
	 * fallback fonts as necessary.
	 *
	 * @return array Multidimensional array with keys 'sans', 'serif', and 'mono'. Each array should use the keys found
	 * in $this->getFontData()
	 */
	public function getFontFamilySubstitution()
	{
		return [
			'sans_fonts' => [
				'freesans',
			],

			'serif_fonts' => [
				'freeserif',
			],

			'mono_fonts' => [
				'freemono',
			],
		];
	}
}