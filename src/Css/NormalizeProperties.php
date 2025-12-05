<?php

namespace Mpdf\Css;

use Mpdf\Color\ColorConverter;
use Mpdf\Mpdf;
use Mpdf\PageFormat;
use Mpdf\SizeConverter;
use Mpdf\Utils\Arrays;
use Mpdf\Utils\UtfString;

class NormalizeProperties
{

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	/**
	 * @var \Mpdf\SizeConverter
	 */
	private $sizeConverter;

	/**
	 * @var \Mpdf\Color\ColorConverter
	 */
	private $colorConverter;

	/**
	 * @var array
	 */
	private $properties = [];

	public function __construct(Mpdf $mpdf, SizeConverter $sizeConverter, ColorConverter $colorConverter)
	{
		$this->mpdf = $mpdf;
		$this->sizeConverter = $sizeConverter;
		$this->colorConverter = $colorConverter;
	}

	/**
	 * Process and expand CSS shorthand properties.
	 *
	 * Takes an array of CSS properties and expands shorthand properties
	 * into their individual components (e.g., margin -> margin-top, margin-right,
	 * margin-bottom, margin-left). Handles font, background, border, padding,
	 * margin, and other composite properties.
	 *
	 * @param array $prop CSS properties array
	 * @return array Expanded CSS properties array
	 */
	public function normalize($prop)
	{
		if (!is_array($prop) || count($prop) === 0) {
			return [];
		}

		$this->properties = [];

		foreach ($prop as $k => $v) {
			if ($k !== 'BACKGROUND-IMAGE' && $k !== 'BACKGROUND' && $k !== 'ODD-HEADER-NAME' && $k !== 'EVEN-HEADER-NAME' && $k !== 'ODD-FOOTER-NAME' && $k !== 'EVEN-FOOTER-NAME' && $k !== 'HEADER' && $k !== 'FOOTER') {
				$v = strtolower($v);
			}

			if ($k === 'FONT') {
				$this->processFontProperty($v);
			} elseif ($k === 'FONT-FAMILY') {
				$this->processFontFamilyProperty($k, $v);
			} elseif ($k === 'FONT-VARIANT') {
				$this->processFontVariantProperty($v);
			} elseif ($k === 'MARGIN') {
				$tmp = $this->expandShorthandProperty($v);

				$this->properties['MARGIN-TOP'] = $tmp['T'];
				$this->properties['MARGIN-RIGHT'] = $tmp['R'];
				$this->properties['MARGIN-BOTTOM'] = $tmp['B'];
				$this->properties['MARGIN-LEFT'] = $tmp['L'];
			} elseif ($k === 'BORDER-RADIUS' || $k === 'BORDER-TOP-LEFT-RADIUS' || $k === 'BORDER-TOP-RIGHT-RADIUS' || $k === 'BORDER-BOTTOM-LEFT-RADIUS' || $k === 'BORDER-BOTTOM-RIGHT-RADIUS') {
				$this->processBorderRadiusProperty($k, $v);
			} elseif ($k === 'PADDING') {
				$tmp = $this->expandShorthandProperty($v);

				$this->properties['PADDING-TOP'] = $tmp['T'];
				$this->properties['PADDING-RIGHT'] = $tmp['R'];
				$this->properties['PADDING-BOTTOM'] = $tmp['B'];
				$this->properties['PADDING-LEFT'] = $tmp['L'];
			} elseif (in_array($k, ['BORDER', 'BORDER-TOP', 'BORDER-RIGHT', 'BORDER-BOTTOM', 'BORDER-LEFT'], true)) {
				$this->processBorderProperty($k, $v);
			} elseif (in_array($k, ['BORDER-STYLE', 'BORDER-WIDTH', 'BORDER-COLOR', 'BORDER-SPACING'], true)) {
				$this->processBorderShorthandProperty($k, $v);
			} elseif ($k === 'TEXT-OUTLINE') {
				$this->processTextOutlineProperty($v);
			} elseif ($k === 'SIZE' || $k === 'SHEET-SIZE') {
				$this->processPageSizeProperty($k, $v);
			} elseif (in_array($k, ['BACKGROUND', 'BACKGROUND-IMAGE', 'BACKGROUND-REPEAT', 'BACKGROUND-POSITION'], true)) {
				$this->processBackgroundProperty($k, $v);
			} elseif ($k === 'IMAGE-ORIENTATION') {
				$this->processImageOrientationProperty($v);
			} elseif ($k === 'TEXT-ALIGN') {
				$this->processTextAlignProperty($k, $v);
			} elseif ($k === 'LIST-STYLE') {
				$this->processListStyleProperty($v);

				if (preg_match('/(inside|outside)/i', $v, $m)) {
					$this->properties['LIST-STYLE-POSITION'] = strtolower(trim($m[1]));
				}
			} else {
				$this->properties[$k] = $v;
			}
		}

		return $this->properties;
	}

	/**
	 * Process FONT shorthand property.
	 *
	 * Expands the CSS font shorthand into individual components:
	 * font-family, font-size, line-height, font-style, font-weight, text-transform.
	 *
	 * @param string $value Font property value
	 * @return void
	 */
	protected function processFontProperty($value)
	{
		$value = $this->simplifyFontNames(trim($value));
		$value = preg_replace('/\s*,\s*/', ',', $value);
		$bits = preg_split('/\s+/', $value);
		$numOfBits = count($bits);

		if ($numOfBits < 2) {
			return;
		}

		// Last item is font-family
		$this->properties['FONT-FAMILY'] = $bits[($numOfBits - 1)];

		// Second to last is font-size (possibly with /line-height)
		$fs = $bits[($numOfBits - 2)];
		if (preg_match('/(.*?)\/(.*)/', $fs, $fsp)) {
			$this->properties['FONT-SIZE'] = $fsp[1];
			$this->properties['LINE-HEIGHT'] = $fsp[2];
		} else {
			$this->properties['FONT-SIZE'] = $fs;
		}

		// Check for font-style
		if (preg_match('/(italic|oblique)/i', $value)) {
			$this->properties['FONT-STYLE'] = 'italic';
		} else {
			$this->properties['FONT-STYLE'] = 'normal';
		}

		// Check for font-weight
		if (stripos($value, 'bold') !== false) {
			$this->properties['FONT-WEIGHT'] = 'bold';
		} else {
			$this->properties['FONT-WEIGHT'] = 'normal';
		}

		// Check for small-caps
		if (stripos($value, 'small-caps') !== false) {
			$this->properties['TEXT-TRANSFORM'] = 'uppercase';
		}
	}

	/**
	 * Simplify font names by removing quotes.
	 *
	 * Helper method for processFontProperty to remove quotes from font names
	 * to simplify subsequent parsing.
	 *
	 * @param string $value Font property value
	 * @return string Simplified font property value
	 */
	protected function simplifyFontNames($value)
	{
		// Remove quoted font names and simplify
		preg_match_all('/"(.*?)"/', $value, $ff);
		if (count($ff[1])) {
			foreach ($ff[1] as $ffp) {
				$w = preg_split('/\s+/', $ffp);
				$value = preg_replace('/"' . $ffp . '"/', $w[0], $value);
			}
		}

		preg_match_all("/'(.*?)'/", $value, $ff);
		if (count($ff[1])) {
			foreach ($ff[1] as $ffp) {
				$w = preg_split('/\s+/', $ffp);
				$value = preg_replace("/'" . $ffp . "'/", $w[0], $value);
			}
		}

		return $value;
	}

	/**
	 * Process FONT-VARIANT property.
	 *
	 * @param string $value Property value
	 * @return void
	 */
	protected function processFontVariantProperty($value)
	{
		if (preg_match('/(normal|none)/', $value, $m)) {
			$this->properties['FONT-VARIANT-LIGATURES'] = $m[1];
			$this->properties['FONT-VARIANT-CAPS'] = $m[1];
			$this->properties['FONT-VARIANT-NUMERIC'] = $m[1];
			$this->properties['FONT-VARIANT-ALTERNATES'] = $m[1];

			return;
		}

		if (preg_match_all('/(no-common-ligatures|\bcommon-ligatures|no-discretionary-ligatures|\bdiscretionary-ligatures|no-historical-ligatures|\bhistorical-ligatures|no-contextual|\bcontextual)/i', $value, $m)) {
			$this->properties['FONT-VARIANT-LIGATURES'] = implode(' ', $m[1]);
		}

		if (preg_match('/(all-small-caps|\bsmall-caps|all-petite-caps|\bpetite-caps|unicase|titling-caps)/i', $value, $m)) {
			$this->properties['FONT-VARIANT-CAPS'] = $m[1];
		}

		if (preg_match_all('/(lining-nums|oldstyle-nums|proportional-nums|tabular-nums|diagonal-fractions|stacked-fractions)/i', $value, $m)) {
			$this->properties['FONT-VARIANT-NUMERIC'] = implode(' ', $m[1]);
		}

		if (preg_match('/(historical-forms)/i', $value, $m)) {
			$this->properties['FONT-VARIANT-ALTERNATES'] = $m[1];
		}
	}

	/**
	 * Process FONT-FAMILY property.
	 *
	 * @param string $propertyKey Property key
	 * @param string $value Font family value
	 * @return void
	 */
	protected function processFontFamilyProperty($propertyKey, $value)
	{
		/* Normalize the font list */
		$fontList = array_map(
			function ($fontName) {
				return trim($fontName, " \t\n\r\0\x0B\"'");
			},
			explode(',', $value)
		);

		foreach ($fontList as $fontName) {
			$fontName = str_replace(' ', '', strtolower($fontName));

			if (in_array($fontName, $this->mpdf->fontdata, true) ||
				in_array($fontName, $this->mpdf->available_unifonts, true) ||
				in_array($fontName, $this->mpdf->sans_fonts, true) ||
				in_array($fontName, $this->mpdf->serif_fonts, true) ||
				in_array($fontName, $this->mpdf->mono_fonts, true) ||
				($this->mpdf->onlyCoreFonts && in_array($fontName, ['courier', 'times', 'helvetica', 'arial'], true)) ||
				in_array($fontName, ['sjis', 'uhc', 'big5', 'gb'], true)
			) {
				$this->properties[$propertyKey] = $fontName;
				return;
			}
		}

		$this->properties[$propertyKey] = $fontList[0];
	}

	/**
	 * Process BORDER shorthand and individual border properties.
	 *
	 * Handles BORDER, BORDER-TOP, BORDER-RIGHT, BORDER-BOTTOM, BORDER-LEFT properties
	 * by normalizing them to consistent "width style color" format.
	 *
	 * @param string $propertyKey Property key (BORDER, BORDER-TOP, etc.)
	 * @param string $value Property value
	 * @return void
	 */
	protected function processBorderProperty($propertyKey, $value)
	{
		switch ($propertyKey) {
			case 'BORDER':
				$value = $value !== '1' ? $this->normalizeBorderString($value) : '1px solid #000000';

				$this->properties['BORDER-TOP'] = $value;
				$this->properties['BORDER-RIGHT'] = $value;
				$this->properties['BORDER-BOTTOM'] = $value;
				$this->properties['BORDER-LEFT'] = $value;
				break;

			case 'BORDER-TOP':
				$this->properties['BORDER-TOP'] = $this->normalizeBorderString($value);
				break;

			case 'BORDER-RIGHT':
				$this->properties['BORDER-RIGHT'] = $this->normalizeBorderString($value);
				break;

			case 'BORDER-BOTTOM':
				$this->properties['BORDER-BOTTOM'] = $this->normalizeBorderString($value);
				break;

			case 'BORDER-LEFT':
				$this->properties['BORDER-LEFT'] = $this->normalizeBorderString($value);
				break;
		}
	}

	/**
	 * Process border shorthand properties (style, width, color).
	 *
	 * Handles BORDER-STYLE, BORDER-WIDTH, BORDER-COLOR, BORDER-SPACING.
	 *
	 * @param string $key Property key
	 * @param string $value Property value
	 * @return void
	 */
	protected function processBorderShorthandProperty($key, $value)
	{
		if ($key === 'BORDER-STYLE') {
			$e = $this->expandShorthandProperty($value);
			if (!empty($e)) {
				$this->properties['BORDER-TOP-STYLE'] = $e['T'];
				$this->properties['BORDER-RIGHT-STYLE'] = $e['R'];
				$this->properties['BORDER-BOTTOM-STYLE'] = $e['B'];
				$this->properties['BORDER-LEFT-STYLE'] = $e['L'];
			}
		} elseif ($key === 'BORDER-WIDTH') {
			$e = $this->expandShorthandProperty($value);
			if (!empty($e)) {
				$this->properties['BORDER-TOP-WIDTH'] = $e['T'];
				$this->properties['BORDER-RIGHT-WIDTH'] = $e['R'];
				$this->properties['BORDER-BOTTOM-WIDTH'] = $e['B'];
				$this->properties['BORDER-LEFT-WIDTH'] = $e['L'];
			}
		} elseif ($key === 'BORDER-COLOR') {
			$e = $this->expandShorthandProperty($value);
			if (!empty($e)) {
				$this->properties['BORDER-TOP-COLOR'] = $e['T'];
				$this->properties['BORDER-RIGHT-COLOR'] = $e['R'];
				$this->properties['BORDER-BOTTOM-COLOR'] = $e['B'];
				$this->properties['BORDER-LEFT-COLOR'] = $e['L'];
			}
		} elseif ($key === 'BORDER-SPACING') {
			$prop = preg_split('/\s+/', trim($value));
			if (count($prop) === 1) {
				$this->properties['BORDER-SPACING-H'] = $prop[0];
				$this->properties['BORDER-SPACING-V'] = $prop[0];
			} elseif (count($prop) === 2) {
				$this->properties['BORDER-SPACING-H'] = $prop[0];
				$this->properties['BORDER-SPACING-V'] = $prop[1];
			}
		}
	}

	/**
	 * Parse CSS background shorthand property.
	 *
	 * Extracts background color, image, repeat, and position from the
	 * background shorthand property. Supports  gradients and url() images.
	 *
	 * @param string $s Background property value
	 * @return array Array with keys 'c' (color), 'i' (image), 'r' (repeat), 'p' (position)
	 */
	protected function parseCssBackground($s)
	{
		$bg = ['c' => false, 'i' => false, 'r' => false, 'p' => false,];
		/* -- BACKGROUNDS -- */
		if (preg_match('/(-moz-)*(repeating-)*(linear|radial)-gradient\(.*\)/i', $s, $m)) {
			$bg['i'] = $m[0];
		} else {
			if (preg_match('/url\(/i', $s)) { /* -- END BACKGROUNDS -- */
				// If color, set and strip it off
				// mPDF 5.6.05
				if (preg_match('/^\s*(#[0-9a-fA-F]{3,6}|(rgba|rgb|device-cmyka|cmyka|device-cmyk|cmyk|hsla|hsl|spot)\(.*?\)|[a-zA-Z]{3,})\s+(url\(.*)/i', $s, $m)) {
					$bg['c'] = strtolower($m[1]);
					$s = $m[3];
				}
				/* -- BACKGROUNDS -- */
				if (preg_match('/url\([\'\"]{0,1}(.*?)[\'\"]{0,1}\)\s*(.*)/i', $s, $m)) {
					$bg['i'] = $m[1];
					$s = strtolower($m[2]);
					if (preg_match('/(repeat-x|repeat-y|no-repeat|repeat)/', $s, $m)) {
						$bg['r'] = $m[1];
					}
					// Remove repeat, attachment (discarded) and also any inherit
					$s = preg_replace('/(repeat-x|repeat-y|no-repeat|repeat|scroll|fixed|inherit)/', '', $s);
					$bits = preg_split('/\s+/', trim($s));

					$normalizedPosition = $this->normalizeBackgroundPosition($bits);
					if ($normalizedPosition !== false) {
						$bg['p'] = $normalizedPosition;
					}
				}
				/* -- END BACKGROUNDS -- */
			} elseif (preg_match('/^\s*(#[0-9a-fA-F]{3,6}|(rgba|rgb|device-cmyka|cmyka|device-cmyk|cmyk|hsla|hsl|spot)\(.*?\)|[a-zA-Z]{3,})/i', $s, $m)) {
				$bg['c'] = strtolower($m[1]);
			}
		} // mPDF 5.6.05
		return ($bg);
	}

	/**
	 * Expand 1-4 value CSS property into top/right/bottom/left components.
	 *
	 * Handles CSS properties that can be specified with 1-4 values following
	 * the standard CSS clockwise pattern (top, right, bottom, left).
	 * Used for margin, padding, border-width, border-style, and border-color.
	 *
	 * @param string $value Property value(s) separated by spaces
	 * @return array Associative array with keys 'T', 'R', 'B', 'L'
	 */
	protected function expandShorthandProperty($value)
	{
		$property = preg_split('/\s+/', trim($value));

		switch (count($property)) {
			case 0:
				return [];
			case 1:
				return [
					'T' => $property[0],
					'R' => $property[0],
					'B' => $property[0],
					'L' => $property[0]
				];
			case 2:
				return [
					'T' => $property[0],
					'R' => $property[1],
					'B' => $property[0],
					'L' => $property[1]
				];
			case 3:
				return [
					'T' => $property[0],
					'R' => $property[1],
					'B' => $property[2],
					'L' => $property[1]
				];
			default:
				// Ignore rule parts after first 4 values (most likely !important)
				return [
					'T' => $property[0],
					'R' => $property[1],
					'B' => $property[2],
					'L' => $property[3]
				];
		}
	}

	/**
	 * Expand border-radius properties.
	 *
	 * Processes border-radius CSS properties and expands them into horizontal
	 * and vertical components for each corner (TL, TR, BL, BR).
	 *
	 * @param string $val Border radius value(s)
	 * @param string $k Property name (BORDER-RADIUS or specific corner)
	 * @return array Array with keys like 'TL-H', 'TL-V', etc.
	 */
	protected function expandBorderRadius($val, $k)
	{
		if ($k === 'BORDER-RADIUS') {
			return $this->parseBorderRadiusShorthand($val);
		}

		return $this->parseBorderRadiusCorner($val, $k);
	}

	/**
	 * Parse individual border-radius corner values.
	 *
	 * Helper method for expandBorderRadius to parse values for a specific corner.
	 *
	 * @param string $val Border radius value(s)
	 * @param string $k Property name (specific corner)
	 * @return array Array with keys like 'TL-H', 'TL-V', etc.
	 */
	protected function parseBorderRadiusCorner($val, $k)
	{
		$b = [];
		$prop = preg_split('/\s+/', trim($val));

		if (count($prop) === 1) {
			$h = $v = $val;
		} else {
			$h = $prop[0];
			$v = $prop[1];
		}

		if ($h === 0 || $v === 0) {
			$h = $v = 0;
		}

		if ($k === 'BORDER-TOP-LEFT-RADIUS') {
			$b['TL-H'] = $h;
			$b['TL-V'] = $v;
		} elseif ($k === 'BORDER-TOP-RIGHT-RADIUS') {
			$b['TR-H'] = $h;
			$b['TR-V'] = $v;
		} elseif ($k === 'BORDER-BOTTOM-LEFT-RADIUS') {
			$b['BL-H'] = $h;
			$b['BL-V'] = $v;
		} elseif ($k === 'BORDER-BOTTOM-RIGHT-RADIUS') {
			$b['BR-H'] = $h;
			$b['BR-V'] = $v;
		}

		return $b;
	}

	/**
	 * Parse border-radius shorthand values.
	 *
	 * Parses the slash syntax (horizontal/vertical) and expands 1-4 values
	 * into individual corner components.
	 *
	 * @param string $val Border radius value(s)
	 * @return array Array with keys 'TL-H', 'TR-H', 'BR-H', 'BL-H', 'TL-V', 'TR-V', 'BR-V', 'BL-V'
	 */
	protected function parseBorderRadiusShorthand($val)
	{
		$b = [];
		$hv = explode('/', trim($val));
		$prop = preg_split('/\s+/', trim($hv[0]));

		if (count($prop) === 1) {
			$b['TL-H'] = $b['TR-H'] = $b['BR-H'] = $b['BL-H'] = $prop[0];
		} elseif (count($prop) === 2) {
			$b['TL-H'] = $b['BR-H'] = $prop[0];
			$b['TR-H'] = $b['BL-H'] = $prop[1];
		} elseif (count($prop) === 3) {
			$b['TL-H'] = $prop[0];
			$b['TR-H'] = $b['BL-H'] = $prop[1];
			$b['BR-H'] = $prop[2];
		} elseif (count($prop) === 4) {
			$b['TL-H'] = $prop[0];
			$b['TR-H'] = $prop[1];
			$b['BR-H'] = $prop[2];
			$b['BL-H'] = $prop[3];
		}

		if (count($hv) === 2) {
			$prop = preg_split('/\s+/', trim($hv[1]));
			if (count($prop) === 1) {
				$b['TL-V'] = $b['TR-V'] = $b['BR-V'] = $b['BL-V'] = $prop[0];
			} elseif (count($prop) === 2) {
				$b['TL-V'] = $b['BR-V'] = $prop[0];
				$b['TR-V'] = $b['BL-V'] = $prop[1];
			} elseif (count($prop) === 3) {
				$b['TL-V'] = $prop[0];
				$b['TR-V'] = $b['BL-V'] = $prop[1];
				$b['BR-V'] = $prop[2];
			} elseif (count($prop) === 4) {
				$b['TL-V'] = $prop[0];
				$b['TR-V'] = $prop[1];
				$b['BR-V'] = $prop[2];
				$b['BL-V'] = $prop[3];
			}
		} else {
			$b['TL-V'] = Arrays::get($b, 'TL-H', 0);
			$b['TR-V'] = Arrays::get($b, 'TR-H', 0);
			$b['BL-V'] = Arrays::get($b, 'BL-H', 0);
			$b['BR-V'] = Arrays::get($b, 'BR-H', 0);
		}

		return $b;
	}

	/**
	 * Normalize background position values.
	 *
	 * Converts background position keywords (top, bottom, left, right, center)
	 * to percentage values and validates the format.
	 *
	 * @param array $bits Position components (1 or 2 values)
	 * @return string|false Normalized position string or false if invalid
	 */
	protected function normalizeBackgroundPosition($bits)
	{
		$position = '';

		$numOfBits = count($bits);
		if ($numOfBits === 1) {
			if (false !== strpos($bits[0], 'bottom')) {
				$position = '50% 100%';
			} elseif (false !== strpos($bits[0], 'top')) {
				$position = '50% 0%';
			} else {
				$position = $bits[0] . ' 50%';
			}
		} elseif ($numOfBits === 2) {
			// Can be either right center or center right
			if (preg_match('/(top|bottom)/', $bits[0]) || preg_match('/(left|right)/', $bits[1])) {
				$position = $bits[1] . ' ' . $bits[0];
			} else {
				$position = $bits[0] . ' ' . $bits[1];
			}
		}

		if (empty($position)) {
			return false;
		}

		$position = preg_replace('/(left|top)/', '0%', $position);
		$position = preg_replace('/(right|bottom)/', '100%', $position);
		$position = preg_replace('/(center)/', '50%', $position);

		if (!preg_match('/[\-]{0,1}\d+(in|cm|mm|pt|pc|em|ex|px|%)* [\-]{0,1}\d+(in|cm|mm|pt|pc|em|ex|px|%)*/', $position)) {
			return false;
		}

		return $position;
	}

	/**
	 * Parse and normalize border shorthand property.
	 *
	 * Converts border shorthand syntax into standardized "width style color" format.
	 * Handles various input formats and orders.
	 *
	 * @param string $bd Border property value
	 * @return string Normalized border string in format "width style color"
	 */
	protected function normalizeBorderString($bd)
	{
		preg_match_all("/\((.*?)\)/", $bd, $m);
		if (count($m[1])) {
			$m_count = count($m[1]);
			for ($i = 0; $i < $m_count; $i++) {
				$sub = str_replace(' ', '', $m[1][$i]);
				$bd = str_replace($m[1][$i], $sub, $bd);
			}
		}

		$prop = preg_split('/\s+/', trim($bd));
		if (count($prop) > 3) {
			return '';
		}

		$parts = $this->parseBorderParts($prop);
		$w = $parts['w'];
		$s = $parts['s'];
		$c = $parts['c'];

		$s = strtolower($s);

		return $w . ' ' . $s . ' ' . $c;
	}

	/**
	 * Parse border property parts (width, style, color).
	 *
	 * Helper method for normalizeBorderString to determine width, style, and color
	 * from split border property string.
	 *
	 * @param array $prop Split border property string
	 * @return array Array containing 'w' (width), 's' (style), 'c' (color)
	 */
	protected function parseBorderParts($prop)
	{
		$w = 'medium';
		$c = '#000000';
		$s = 'none';

		$prop_count = count($prop);
		if ($prop_count === 1) {
			// solid
			if (in_array($prop[0], $this->mpdf->borderstyles) || $prop[0] === 'none' || $prop[0] === 'hidden') {
				$s = $prop[0];
			} // #000000
			elseif (is_array($this->colorConverter->convert($prop[0], $this->mpdf->PDFAXwarnings))) {
				$c = $prop[0];
			} // 1px
			else {
				$w = $prop[0];
			}

		} elseif ($prop_count === 2) {
			// 1px solid
			if (in_array($prop[1], $this->mpdf->borderstyles) || $prop[1] === 'none' || $prop[1] === 'hidden') {
				$w = $prop[0];
				$s = $prop[1];
			} // solid #000000
			elseif (in_array($prop[0], $this->mpdf->borderstyles) || $prop[0] === 'none' || $prop[0] === 'hidden') {
				$s = $prop[0];
				$c = $prop[1];
			} // 1px #000000
			else {
				$w = $prop[0];
				$c = $prop[1];
			}

		} elseif ($prop_count === 3) {
			// Change #000000 1px solid to 1px solid #000000 (proper)
			if (0 === strpos($prop[0], '#')) {
				$c = $prop[0];
				$w = $prop[1];
				$s = $prop[2];
			} // Change solid #000000 1px to 1px solid #000000 (proper)
			elseif (substr($prop[0], 1, 1) === '#') {
				$s = $prop[0];
				$c = $prop[1];
				$w = $prop[2];
			} // Change solid 1px #000000 to 1px solid #000000 (proper)
			elseif (in_array($prop[0], $this->mpdf->borderstyles) || $prop[0] === 'none' || $prop[0] === 'hidden') {
				$s = $prop[0];
				$w = $prop[1];
				$c = $prop[2];
			} else {
				$w = $prop[0];
				$s = $prop[1];
				$c = $prop[2];
			}
		}

		return ['w' => $w, 's' => $s, 'c' => $c];
	}

	/**
	 * Process background related CSS properties.
	 *
	 * Handles BACKGROUND, BACKGROUND-IMAGE, BACKGROUND-REPEAT, and BACKGROUND-POSITION.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @return void
	 */
	protected function processBackgroundProperty($k, $v)
	{
		if ($k === 'BACKGROUND') {
			$bg = $this->parseCssBackground($v);
			if ($bg['c']) {
				$this->properties['BACKGROUND-COLOR'] = $bg['c'];
			} else {
				$this->properties['BACKGROUND-COLOR'] = 'transparent';
			}

			if ($bg['i']) {
				$this->properties['BACKGROUND-IMAGE'] = $bg['i'];
				if ($bg['r']) {
					$this->properties['BACKGROUND-REPEAT'] = $bg['r'];
				}
				if ($bg['p']) {
					$this->properties['BACKGROUND-POSITION'] = $bg['p'];
				}
			} else {
				$this->properties['BACKGROUND-IMAGE'] = '';
			}
		} elseif ($k === 'BACKGROUND-IMAGE') {
			if (preg_match('/(-moz-)*(repeating-)*(linear|radial)-gradient\(.*\)/i', $v, $m)) {
				$this->properties['BACKGROUND-IMAGE'] = $m[0];
				return;
			}

			if (preg_match('/url\([\'\"]{0,1}(.*?)[\'\"]{0,1}\)/i', $v, $m)) {
				$this->properties['BACKGROUND-IMAGE'] = $m[1];
			} elseif (strtolower($v) === 'none') {
				$this->properties['BACKGROUND-IMAGE'] = '';
			}
		} elseif ($k === 'BACKGROUND-REPEAT') {
			if (preg_match('/(repeat-x|repeat-y|no-repeat|repeat)/i', $v, $m)) {
				$this->properties['BACKGROUND-REPEAT'] = strtolower($m[1]);
			}
		} elseif ($k === 'BACKGROUND-POSITION') {
			$s = $v;
			$bits = preg_split('/\s+/', trim($s));
			$normalizedPosition = $this->normalizeBackgroundPosition($bits);
			if ($normalizedPosition !== false) {
				$this->properties['BACKGROUND-POSITION'] = $normalizedPosition;
			}
		}
	}

	/**
	 * Process border radius property.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @return void
	 */
	protected function processBorderRadiusProperty($k, $v)
	{
		$tmp = $this->expandBorderRadius($v, $k);

		if (isset($tmp['TL-H'])) {
			$this->properties['BORDER-TOP-LEFT-RADIUS-H'] = $tmp['TL-H'];
		}

		if (isset($tmp['TL-V'])) {
			$this->properties['BORDER-TOP-LEFT-RADIUS-V'] = $tmp['TL-V'];
		}

		if (isset($tmp['TR-H'])) {
			$this->properties['BORDER-TOP-RIGHT-RADIUS-H'] = $tmp['TR-H'];
		}

		if (isset($tmp['TR-V'])) {
			$this->properties['BORDER-TOP-RIGHT-RADIUS-V'] = $tmp['TR-V'];
		}

		if (isset($tmp['BL-H'])) {
			$this->properties['BORDER-BOTTOM-LEFT-RADIUS-H'] = $tmp['BL-H'];
		}

		if (isset($tmp['BL-V'])) {
			$this->properties['BORDER-BOTTOM-LEFT-RADIUS-V'] = $tmp['BL-V'];
		}

		if (isset($tmp['BR-H'])) {
			$this->properties['BORDER-BOTTOM-RIGHT-RADIUS-H'] = $tmp['BR-H'];
		}

		if (isset($tmp['BR-V'])) {
			$this->properties['BORDER-BOTTOM-RIGHT-RADIUS-V'] = $tmp['BR-V'];
		}
	}

	/**
	 * Process text outline CSS properties.
	 *
	 * Handles TEXT-OUTLINE shorthand.
	 *
	 * @param string $v Property value
	 * @return void
	 */
	protected function processTextOutlineProperty($v)
	{
		$prop = preg_split('/\s+/', trim($v));

		if (strtolower(trim($v)) === 'none') {
			$this->properties['TEXT-OUTLINE'] = 'none';
		} elseif (count($prop) == 2) {
			$this->properties['TEXT-OUTLINE-WIDTH'] = $prop[0];
			$this->properties['TEXT-OUTLINE-COLOR'] = $prop[1];
		} elseif (count($prop) == 3) {
			$this->properties['TEXT-OUTLINE-WIDTH'] = $prop[0];
			$this->properties['TEXT-OUTLINE-COLOR'] = $prop[2];
		}
	}

	/**
	 * Process page size CSS properties.
	 *
	 * Handles SIZE and SHEET-SIZE properties.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @return void
	 */
	protected function processPageSizeProperty($k, $v)
	{
		$prop = preg_split('/\s+/', trim($v));

		if ($k === 'SIZE') {
			if (preg_match('/(auto|portrait|landscape)/', $prop[0])) {
				$this->properties['SIZE'] = strtoupper($prop[0]);
			} elseif (count($prop) == 1) {
				$this->properties['SIZE']['W'] = $this->sizeConverter->convert($prop[0]);
				$this->properties['SIZE']['H'] = $this->sizeConverter->convert($prop[0]);
			} elseif (count($prop) == 2) {
				$this->properties['SIZE']['W'] = $this->sizeConverter->convert($prop[0]);
				$this->properties['SIZE']['H'] = $this->sizeConverter->convert($prop[1]);
			}
		} elseif ($k === 'SHEET-SIZE') {
			if (count($prop) == 2) {
				$this->properties['SHEET-SIZE'] = [$this->sizeConverter->convert($prop[0]), $this->sizeConverter->convert($prop[1])];
			} else {
				if (preg_match('/([0-9a-zA-Z]*)-L/i', $v, $m)) { // e.g. A4-L = A$ landscape
					$ft = PageFormat::getSizeFromName($m[1]);
					$format = [$ft[1], $ft[0]];
				} else {
					$format = PageFormat::getSizeFromName($v);
				}
				if ($format) {
					$this->properties['SHEET-SIZE'] = [$format[0] / Mpdf::SCALE, $format[1] / Mpdf::SCALE];
				}
			}
		}
	}

	/**
	 * Process image orientation CSS properties.
	 *
	 * Handles IMAGE-ORIENTATION property.
	 *
	 * @param string $v Property value
	 * @return void
	 */
	protected function processImageOrientationProperty($v)
	{
		if (!preg_match('/([\-]*[0-9\.]+)(deg|grad|rad)/i', $v, $m)) {
			return;
		}

		$angle = $m[1] + 0;

		if (strtolower($m[2]) === 'grad') {
			$angle *= (360 / 400);
		} elseif (strtolower($m[2]) === 'rad') {
			$angle = rad2deg($angle);
		}

		while ($angle < 0) {
			$angle += 360;
		}

		$angle %= 360;
		$angle /= 90;
		$angle = round($angle) * 90;

		$this->properties['IMAGE-ORIENTATION'] = $angle;
	}

	/**
	 * Process text align CSS properties.
	 *
	 * Handles TEXT-ALIGN property including decimal alignment.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @return void
	 */
	protected function processTextAlignProperty($k, $v)
	{
		if (preg_match('/["\'](.){1}["\']/i', $v, $m)) {
			$d = array_search($m[1], $this->mpdf->decimal_align);

			if ($d !== false) {
				$this->properties['TEXT-ALIGN'] = $d;
			}
			if (preg_match('/(center|left|right)/i', $v, $m)) {
				$this->properties['TEXT-ALIGN'] .= strtoupper(substr($m[1], 0, 1));
			} else {
				$this->properties['TEXT-ALIGN'] .= 'R';
			} // default = R
		} elseif (preg_match('/["\'](\\\\[a-fA-F0-9]{1,6})["\']/i', $v, $m)) {
			$utf8 = UtfString::codeHex2utf(substr($m[1], 1, 6));
			$d = array_search($utf8, $this->mpdf->decimal_align);

			if ($d !== false) {
				$this->properties['TEXT-ALIGN'] = $d;
			}

			if (preg_match('/(center|left|right)/i', $v, $m)) {
				$this->properties['TEXT-ALIGN'] .= strtoupper(substr($m[1], 0, 1));
			} else {
				$this->properties['TEXT-ALIGN'] .= 'R';
			} // default = R
		} else {
			$this->properties[$k] = $v;
		}
	}

	/**
	 * Process list style CSS properties.
	 *
	 * Handles LIST-STYLE property.
	 *
	 * @param string $v Property value
	 * @return void
	 */
	protected function processListStyleProperty($v)
	{
		if (preg_match('/none/i', $v, $m)) {
			$this->properties['LIST-STYLE-TYPE'] = 'none';
			$this->properties['LIST-STYLE-IMAGE'] = 'none';
		}

		if (preg_match('/(lower-roman|upper-roman|lower-latin|lower-alpha|upper-latin|upper-alpha|decimal|disc|circle|square|arabic-indic|bengali|devanagari|gujarati|gurmukhi|kannada|malayalam|oriya|persian|tamil|telugu|thai|urdu|cambodian|khmer|lao|cjk-decimal|hebrew)/i', $v, $m)) {
			$this->properties['LIST-STYLE-TYPE'] = strtolower(trim($m[1]));
		} elseif (preg_match('/U\+([a-fA-F0-9]+)/i', $v, $m)) {
			$this->properties['LIST-STYLE-TYPE'] = strtolower(trim($m[1]));
		}

		if (preg_match('/url\([\'\"]{0,1}(.*?)[\'\"]{0,1}\)/i', $v, $m)) {
			$this->properties['LIST-STYLE-IMAGE'] = strtolower(trim($m[1]));
		}
	}
}
