<?php

namespace Mpdf\Fonts\AboriginalFamily;

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
			'aboriginalsans' => [
				'R' => 'AboriginalSansREGULAR.ttf',
				'B' => 'AboriginalSansBOLD.ttf',
				'I' => 'AboriginalSansITALIC.ttf',
				'BI' => 'AboriginalSansBOLDITALIC.ttf',
			],

			'aboriginalserif' => [
				'R' => 'AboriginalSerifREGULAR.ttf',
				'B' => 'AboriginalSerifBOLD.ttf',
				'I' => 'AboriginalSerifITALIC.ttf',
				'BI' => 'AboriginalSerifBOLDITALIC.ttf',
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
			'aboriginalsans',
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
				'aboriginalsans',
			],

			'serif_fonts' => [
				'aboriginalserif',
			],
		];
	}
}