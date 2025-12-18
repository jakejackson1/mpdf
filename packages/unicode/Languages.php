<?php

namespace Mpdf\Fonts\Unicode;

use Mpdf\Language\LanguageToFontInterface;

class Languages implements LanguageToFontInterface
{
	public function getLanguageOptions($mode, $adobeCJK)
	{
		// @TODO - extract this logic
		$tags    = explode('-', $mode);
		$language    = strtolower($tags[0]);

		$country = '';
		$script  = '';
		if (! empty($tags[1])) {
			if (strlen($tags[1]) === 4) {
				$script = strtolower($tags[1]);
			} else {
				$country = strtolower($tags[1]);
			}
		}
		if (! empty($tags[2])) {
			$country = strtolower($tags[2]);
		}

		$unifont = '';

		switch ($language) {
			// Russian	// CYRILLIC
			case 'ru':
			case 'rus':

			// Abkhaz
			case 'ab':
			case 'abk':

			// Avaric
			case 'av':
			case 'ava':

			// Bashkir
			case 'ba':
			case 'bak':

			// Belarusian
			case 'be':
			case 'bel':

			// Bulgarian
			case 'bg':
			case 'bul':

			// Chechen
			case 'ce':
			case 'che':

			// Chuvash
			case 'cv':
			case 'chv':

			// Kazakh
			case 'kk':
			case 'kaz':

			// Komi
			case 'kv':
			case 'kom':

			// Kyrgyz
			case 'ky':
			case 'kir':

			// Macedonian
			case 'mk':
			case 'mkd':

			// Old Church Slavonic
			case 'cu':
			case 'chu':

			// Ossetian
			case 'os':
			case 'oss':

			// Serbian
			case 'sr':
			case 'srp':

			// Tajik
			case 'tg':
			case 'tgk':

			// Tatar
			case 'tt':
			case 'tat':

			// Turkmen
			case 'tk':
			case 'tuk':

			// Ukrainian
			case 'uk':
			case 'ukr':
				$unifont = 'dejavusanscondensed';
				break;

			// ARMENIAN
			case 'hy':
			case 'hye':
				$unifont = 'dejavusans';
				break;

			// GEORGIAN
			case 'ka':
			case 'kat':
				$unifont = 'dejavusans';
				break;

			// GREEK
			case 'el':
			case 'ell':
				$unifont = 'dejavusanscondensed';
				break;

			// GOTHIC
			case 'got':
				$unifont = 'freeserif';
				break;

			// N'Ko
			case 'nqo':
				$unifont = 'dejavusans';
				break;

			// Vai (Liberian, Vy or Gallinas)
			case 'vai':
				$unifont = 'freesans';
				break;

			// Assamese
			case 'as':
			case 'asm':
				$unifont = 'freeserif';
				break;

			// BENGALI; Bangla
			case 'bn':
			case 'ben':
				$unifont = 'freeserif';
				break;

			// Kashmiri
			case 'ks':
			case 'kas':
				$unifont = 'freeserif';
				break;

			// Hindi	DEVANAGARI
			case 'hi':
			case 'hin':

			// Bihari (Bhojpuri, Magahi, and Maithili)
			case 'bh':
			case 'bih':

			// Sanskrit
			case 'sa':
			case 'san':
				$unifont = 'freeserif';
				break;

			// Gujarati
			case 'gu':
			case 'guj':
				$unifont = 'freeserif';
				break;

			// Panjabi, Punjabi GURMUKHI
			case 'pa':
			case 'pan':
				$unifont = 'freeserif';
				break;

			// Marathi
			case 'mr':
			case 'mar':
				$unifont = 'freeserif';
				break;

			// MALAYALAM
			case 'ml':
			case 'mal':
				$unifont = 'freeserif';
				break;

			// Nepali
			case 'ne':
			case 'nep':
				$unifont = 'freeserif';
				break;

			// ORIYA
			case 'or':
			case 'ori':
				$unifont = 'freeserif';
				break;

			// TAMIL
			case 'ta':
			case 'tam':
				$unifont = 'freeserif';
				break;

			// Sindhi (Arabic or Devanagari)
			case 'sd':
			case 'snd':
				if ($country === 'in') {
					$unifont = 'freeserif';
				}
				break;

			// Divehi; Maldivian  THAANA
			case 'dv':
			case 'div':
				$unifont = 'freeserif';
				break;

			// VIETNAMESE
			case 'vi':
			case 'vie': // Vietnamese
				$unifont = 'dejavusanscondensed';
				break;

			// BUGINESE
			case 'bug':
				$unifont = 'freeserif';
				break;

			/* Undetermined language - script used */
			case 'und':
				$unifont = $this->fontByScript($script);
				break;
		}

		return $unifont;
	}

	protected function fontByScript($script)
	{
		switch ($script) {
			/* European */
			case 'latn': // LATIN
				return 'dejavusanscondensed';
			case 'cyrl': // CYRILLIC
				return 'dejavusanscondensed';
			case 'ogam': // OGHAM
				return 'dejavusans';

			/* African */
			case 'tfng': // TIFINAGH
				return 'dejavusans';

			/* South East Asian */
			case 'kali': // KAYAH_LI
				return 'freemono';

			/* Other */
			case 'brai': // BRAILLE
				return 'dejavusans';
		}

		return '';
	}

}
