<?php

namespace Mpdf\Fonts;

abstract class FontRegistration implements FontRegistrationInterface
{
	/**
	 * Get the absolute path to the fonts directory
	 *
	 * @return string
	 */
	abstract public function getDirectory();

	/**
	 * Get the fonts to be registered with mPDF
	 *
	 * @return  []
	 * @see http://mpdf.github.io/fonts-languages/fonts-in-mpdf-7-x.html
	 */
	abstract public function getFonts();

	/**
	 * Get the Language Package LanguageToFont implementation
	 *
	 * @return \Mpdf\Language\LanguageToFontInterface|null
	 */
	public function getLanguageToFont()
	{
		return null;
	}

	/**
	 * Define fonts to be used for character substitution, when the useSubstitutions configuration option enabled
	 *
	 * @return array The list of fonts to exclude using the keys found in $this->getFontData()
	 */
	public function getBackupSubsFonts()
	{
		return [];
	}

	/**
	 * Get a list of fonts which contain characters in the SIP or SMP Unicode planes but is not required.
	 * This allows a more efficient form of subsetting to be used.
	 *
	 * @return array The list of fonts to exclude using the keys found in $this->getFontData()
	 */
	public function getBmpFonts()
	{
		return [];
	}

	/**
	 * Get a list of substituted fonts used when a font is not available in mPDF
	 *
	 * @return array Multidimensional array with keys 'sans_fonts', 'serif_fonts', and 'mono_fonts'
	 */
	public function getFontFamilySubstitution()
	{
		return [
			'sans_fonts' => [],
			'serif_fonts' => [],
			'mono_fonts' => [],
		];
	}

}
