<?php

namespace Mpdf\Fonts\DejavuFamily;

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
			'dejavusanscondensed' => [
				'R' => 'DejaVuSansCondensed.ttf',
				'B' => 'DejaVuSansCondensed-Bold.ttf',
				'I' => 'DejaVuSansCondensed-Oblique.ttf',
				'BI' => 'DejaVuSansCondensed-BoldOblique.ttf',
				'useOTL' => 0xFF,
				'useKashida' => 75,
			],

			'dejavusans' => [
				'R' => 'DejaVuSans.ttf',
				'B' => 'DejaVuSans-Bold.ttf',
				'I' => 'DejaVuSans-Oblique.ttf',
				'BI' => 'DejaVuSans-BoldOblique.ttf',
				'useOTL' => 0xFF,
				'useKashida' => 75,
			],

			'dejavuserif' => [
				'R' => 'DejaVuSerif.ttf',
				'B' => 'DejaVuSerif-Bold.ttf',
				'I' => 'DejaVuSerif-Italic.ttf',
				'BI' => 'DejaVuSerif-BoldItalic.ttf',
			],

			'dejavuserifcondensed' => [
				'R' => 'DejaVuSerifCondensed.ttf',
				'B' => 'DejaVuSerifCondensed-Bold.ttf',
				'I' => 'DejaVuSerifCondensed-Italic.ttf',
				'BI' => 'DejaVuSerifCondensed-BoldItalic.ttf',
			],

			'dejavusansmono' => [
				'R' => 'DejaVuSansMono.ttf',
				'B' => 'DejaVuSansMono-Bold.ttf',
				'I' => 'DejaVuSansMono-Oblique.ttf',
				'BI' => 'DejaVuSansMono-BoldOblique.ttf',
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
			'dejavusanscondensed',
		];
	}

	/**
	 * Get a list of fonts which contain characters in the SIP or SMP Unicode planes but is not required.
	 * This allows a more efficient form of subsetting to be used.
	 *
	 * @return array The list of fonts to exclude using the keys found in $this->getFontData()
	 */
	public function getBmpFonts()
	{
		return [
			'dejavusanscondensed',
			'dejavusans',
			'dejavuserifcondensed',
			'dejavuserif',
			'dejavusansmono',
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
				'dejavusanscondensed',
				'dejavusans',
			],

			'serif_fonts' => [
				'dejavuserifcondensed',
				'dejavuserif',
			],

			'mono_fonts' => [
				'dejavusansmono',
			],
		];
	}
}