<?php

namespace Mpdf\Css;

class InlineStyleParser
{
	/**
	 * @var \Mpdf\Css\CssSanitizer
	 */
	private $cssSanitizer;

	/**
	 * @var \Mpdf\Css\NormalizeProperties
	 */
	private $normalizeProperties;

	public function __construct(CssSanitizer $cssSanitizer, NormalizeProperties $normalizeProperties)
	{
		$this->cssSanitizer = $cssSanitizer;
		$this->normalizeProperties = $normalizeProperties;
	}

	/**
	 * Parse inline CSS style attribute.
	 *
	 * Parses a CSS string from an HTML style attribute and returns
	 * an array of CSS properties.
	 *
	 * @param string $html CSS string from style attribute
	 * @return array Parsed CSS properties
	 */
	public function parse($html)
	{
		$html = htmlspecialchars_decode($html); // mPDF 5.7.4 URLs
		// mPDF 5.7.4 URLs
		// Characters "(", ")", and ";" in url() e.g. background-image, cause problems parsing the CSS string
		// URLencode ( and ), but change ";" to a code which can be converted back after parsing (so as not to confuse ;
		// with a segment delimiter in the URI)
		$html = $this->cssSanitizer->processUrlsInCss($html);

		// Fix incomplete CSS code
		$size = strlen($html) - 1;
		if (substr($html, $size, 1) !== ';') {
			$html .= ';';
		}

		// Make CSS[Name-of-the-class] = array(key => value)
		$regexp = '|\\s*?(\\S+?):(.+?);|i';
		preg_match_all($regexp, $html, $styleinfo);
		$properties = $styleinfo[1];
		$values = $styleinfo[2];

		// Array-properties and Array-values must have the SAME SIZE!
		$classproperties = [];
		$properties_count = count($properties);
		for ($i = 0; $i < $properties_count; $i++) {

			// Ignores -webkit-gradient so doesn't override -moz-
			if ((strtoupper($properties[$i]) === 'BACKGROUND-IMAGE' || strtoupper($properties[$i]) === 'BACKGROUND') && false !== stripos($values[$i], '-webkit-gradient')) {
				continue;
			}

			$values[$i] = str_replace('%ZZ', ';', $values[$i]); // mPDF 5.7.4 URLs
			$classproperties[strtoupper($properties[$i])] = trim($values[$i]);
		}

		return $this->normalizeProperties->normalize($classproperties);
	}
}
