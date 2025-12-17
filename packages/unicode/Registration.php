<?php

namespace Mpdf\Fonts\Unicode;

use Mpdf\Fonts\FontRegistration;

class Registration extends FontRegistration
{
	/**
	 * Get the unique name of the Language Package
	 *
	 * @return string
	 * @version 1.0
	 */
	public function getName()
	{
		return 'Unicode';
	}

	/**
	 * Get path to the registered font file directory
	 *
	 * @return string The full path to the font directory
	 * @version 1.0
	 */
	public function getFontDirectory()
	{
		return __DIR__ . '/fonts/';
	}

	/**
	 * Get the fonts to be registered with mPDF
	 *
	 * @return array A valid 'fontdata' configuration array
	 * @see     http://mpdf.github.io/fonts-languages/fonts-in-mpdf-7-x.html
	 * @version 1.0
	 */
	public function getFontData()
	{
		return [
			'dejavusanscondensed' => [
				'R'          => 'DejaVuSansCondensed.ttf',
				'B'          => 'DejaVuSansCondensed-Bold.ttf',
				'I'          => 'DejaVuSansCondensed-Oblique.ttf',
				'BI'         => 'DejaVuSansCondensed-BoldOblique.ttf',
				'useOTL'     => 0xFF,
				'useKashida' => 75,
			],

			'dejavusans' => [
				'R'          => 'DejaVuSans.ttf',
				'B'          => 'DejaVuSans-Bold.ttf',
				'I'          => 'DejaVuSans-Oblique.ttf',
				'BI'         => 'DejaVuSans-BoldOblique.ttf',
				'useOTL'     => 0xFF,
				'useKashida' => 75,
			],

			'dejavuserif' => [
				'R'  => 'DejaVuSerif.ttf',
				'B'  => 'DejaVuSerif-Bold.ttf',
				'I'  => 'DejaVuSerif-Italic.ttf',
				'BI' => 'DejaVuSerif-BoldItalic.ttf',
			],

			'dejavuserifcondensed' => [
				'R'  => 'DejaVuSerifCondensed.ttf',
				'B'  => 'DejaVuSerifCondensed-Bold.ttf',
				'I'  => 'DejaVuSerifCondensed-Italic.ttf',
				'BI' => 'DejaVuSerifCondensed-BoldItalic.ttf',
			],

			'dejavusansmono' => [
				'R'          => 'DejaVuSansMono.ttf',
				'B'          => 'DejaVuSansMono-Bold.ttf',
				'I'          => 'DejaVuSansMono-Oblique.ttf',
				'BI'         => 'DejaVuSansMono-BoldOblique.ttf',
				'useOTL'     => 0xFF,
				'useKashida' => 75,
			],

			'freesans' => [
				'R'      => 'FreeSans.ttf',
				'B'      => 'FreeSansBold.ttf',
				'I'      => 'FreeSansOblique.ttf',
				'BI'     => 'FreeSansBoldOblique.ttf',
				'useOTL' => 0xFF,
			],

			'freeserif' => [
				'R'          => 'FreeSerif.ttf',
				'B'          => 'FreeSerifBold.ttf',
				'I'          => 'FreeSerifItalic.ttf',
				'BI'         => 'FreeSerifBoldItalic.ttf',
				'useOTL'     => 0xFF,
				'useKashida' => 75,
			],

			'freemono' => [
				'R'  => 'FreeMono.ttf',
				'B'  => 'FreeMonoBold.ttf',
				'I'  => 'FreeMonoOblique.ttf',
				'BI' => 'FreeMonoBoldOblique.ttf',
			],
		];
	}

	/**
	 * Get the Language Package LanguageToFont implementation
	 *
	 * @return LanguageToFontInterface|null
	 * @since 1.0
	 */
	public function getLanguageToFont()
	{
		return new Languages();
	}

	/**
	 * Define fonts to be used for character substitution, when the useSubstitutions configuration option enabled
	 *
	 * @return array The list of fonts to exclude using the keys found in $this->getFontData()
	 * @since 1.0
	 */
	public function getBackupSubsFont()
	{
		return [
			'dejavusanscondensed',
			'freesans',
		];
	}

	/**
	 * Get a list of fonts which contain characters in the SIP or SMP Unicode planes but is not required.
	 * This allows a more efficient form of subsetting to be used.
	 *
	 * @return array The list of fonts to exclude using the keys found in $this->getFontData()
	 * @since 1.0
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
	 * @since 1.0
	 */
	public function getFontFamilySubstitution()
	{
		return [
			'sans_fonts' => [
				'dejavusanscondensed',
				'dejavusans',
				'freesans',
			],

			'serif_fonts' => [
				'dejavuserifcondensed',
				'dejavuserif',
				'freeserif',
			],

			'mono_fonts' => [
				'dejavusansmono',
				'freemono',
			],
		];
	}
}