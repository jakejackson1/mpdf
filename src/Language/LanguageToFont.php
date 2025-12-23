<?php

namespace Mpdf\Language;

class LanguageToFont implements \Mpdf\Language\LanguageToFontInterface
{

	public function getLanguageOptions($mode, $adobeCJK)
	{
		$tags = explode('-', $mode);
		$lang = strtolower($tags[0]);
		$country = '';
		$script = '';
		if (!empty($tags[1])) {
			if (strlen($tags[1]) === 4) {
				$script = strtolower($tags[1]);
			} else {
				$country = strtolower($tags[1]);
			}
		}
		if (!empty($tags[2])) {
			$country = strtolower($tags[2]);
		}

		$unifont = '';
		$coreSuitable = false;

		switch ($lang) {
			/* European */
			case 'en':
			case 'eng': // English
			case 'eu':
			case 'eus': // Basque
			case 'br':
			case 'bre': // Breton
			case 'ca':
			case 'cat': // Catalan
			case 'co':
			case 'cos': // Corsican
			case 'kw':
			case 'cor': // Cornish
			case 'cy':
			case 'cym': // Welsh
			case 'cs':
			case 'ces': // Czech
			case 'da':
			case 'dan': // Danish
			case 'nl':
			case 'nld': // Dutch
			case 'et':
			case 'est': // Estonian
			case 'fo':
			case 'fao': // Faroese
			case 'fi':
			case 'fin': // Finnish
			case 'fr':
			case 'fra': // French
			case 'gl':
			case 'glg': // Galician
			case 'de':
			case 'deu': // German
			case 'ht':
			case 'hat': // Haitian; Haitian Creole
			case 'hu':
			case 'hun': // Hungarian
			case 'ga':
			case 'gle': // Irish
			case 'is':
			case 'isl': // Icelandic
			case 'it':
			case 'ita': // Italian
			case 'la':
			case 'lat': // Latin
			case 'lb':
			case 'ltz': // Luxembourgish
			case 'li':
			case 'lim': // Limburgish
			case 'lt':
			case 'lit': // Lithuanian
			case 'lv':
			case 'lav': // Latvian
			case 'gv':
			case 'glv': // Manx
			case 'no':
			case 'nor': // Norwegian
			case 'nn':
			case 'nno': // Norwegian Nynorsk
			case 'nb':
			case 'nob': // Norwegian Bokmål
			case 'pl':
			case 'pol': // Polish
			case 'pt':
			case 'por': // Portuguese
			case 'ro':
			case 'ron': // Romanian
			case 'gd':
			case 'gla': // Scottish Gaelic
			case 'es':
			case 'spa': // Spanish
			case 'sv':
			case 'swe': // Swedish
			case 'sl':
			case 'slv': // Slovene
			case 'sk':
			case 'slk': // Slovak
				$coreSuitable = true;
				break;

			//CASE 'bax':	// BAMUM
			//CASE 'ha':  CASE 'hau':	// Hausa

			/* Middle Eastern */
			case 'he':
			case 'heb': // HEBREW
			case 'yi':
			case 'yid': // Yiddish
					$unifont = 'taameydavidclm';
				break;

			//CASE 'arc':	// IMPERIAL_ARAMAIC
			//CASE ''ae:	// AVESTAN
			//CASE 'peo':	// OLD_PERSIAN
			//CASE 'mid':	// MANDAIC
			//CASE 'smp':	// SAMARITAN

			/* Central Asian */

			//CASE 'mn':  CASE 'mon':	// MONGOLIAN	(Vertical script)
			//CASE 'ug':  CASE 'uig':	// Uyghur
			//CASE 'uz':  CASE 'uzb':	// Uzbek
			//CASE 'az':  CASE 'azb':	// South Azerbaijani

			/* South Asian */


			//CASE 'ccp':	// CHAKMA
			//CASE 'lep':	// LEPCHA

			//CASE 'sat':	// OL_CHIKI
			//CASE 'saz':	// SAURASHTRA

			//CASE 'dgo':	// TAKRI

			/* South East Asian */

			//CASE 'ms':  CASE 'msa':	// Malay
			//CASE 'ban':	// BALINESE
			//CASE 'bya':	// BATAK
			//CASE 'cjm':	// CHAM
			//CASE 'jv':	// JAVANESE

			case 'blt':  // TAI_VIET
				$unifont = 'taiheritagepro';
				break;

			/* East Asian */
			case 'zh':
			case 'zho': // Chinese
				if ($adobeCJK) {
					$unifont = 'gb';
					if ($country === 'hk' || $country === 'tw') {
						$unifont = 'big5';
					}
				}
				break;

			case 'ko':
			case 'kor': // HANGUL Korean
				if ($adobeCJK) {
					$unifont = 'uhc';
				}
				break;

			case 'ja':
			case 'jpn': // Japanese HIRAGANA KATAKANA
				if ($adobeCJK) {
					$unifont = 'sjis';
				}
				break;

			case 'ii':
			case 'iii': // Nuosu; Yi
				if ($adobeCJK) {
					$unifont = 'gb';
				}
				break;

			/* Undetermined language - script used */
			case 'und':
				$unifont = $this->fontByScript($script, $adobeCJK);
				break;
		}

		return [$coreSuitable, $unifont];
	}

	protected function fontByScript($script, $adobeCJK)
	{
		switch ($script) {
			/* European */


			/* African */
			//CASE 'merc':	// MEROITIC_CURSIVE
			//CASE 'mero':	// MEROITIC_HIEROGLYPHS


			/* Middle Eastern */
			//CASE 'sarb':	// OLD_SOUTH_ARABIAN
			//CASE 'prti':	// INSCRIPTIONAL_PARTHIAN
			//CASE 'phli':	// INSCRIPTIONAL_PAHLAVI


			/* Central Asian */
			//CASE 'orkh':	// OLD_TURKIC
			//CASE 'phag':	// PHAGS_PA		(Vertical script)

			/* South Asian */
			//CASE 'brah':	// BRAHMI
			//CASE 'kthi':	// KAITHI
			//CASE 'shrd':	// SHARADA
			//CASE 'sora':	// SORA_SOMPENG

			/* South East Asian */
			//CASE 'rjng':	// REJANG

			/* East Asian */
			case 'hans': // HAN (SIMPLIFIED)
				if ($adobeCJK) {
					return 'gb';
				}

			//CASE 'plrd':	// MIAO

			/* American */

		}

		return null;
	}

}
