<?php

namespace Mpdf\Css;

class CssSanitizer
{
	/**
	 * Remove mPDF-specific and general HTML comments from content.
	 *
	 * Removes <!--mpdf and mpdf--> markers and all HTML comments.
	 *
	 * @param string $html HTML content to clean
	 * @return string HTML with comments removed
	 */
	public function removeHtmlComments($html)
	{
		$html = preg_replace('/<!--mpdf/i', '', $html);
		$html = preg_replace('/mpdf-->/i', '', $html);
		$html = preg_replace('/<\!\-\-.*?\-\->/s', ' ', $html);
		return $html;
	}

	/**
	 * Remove HTML and CSS comments from style blocks.
	 *
	 * Removes both HTML comments (<!-- -->) and CSS comments from
	 * <style> tag contents while preserving the structure.
	 *
	 * @param string $html HTML content with style tags
	 * @return string HTML with cleaned style blocks
	 */
	public function removeCommentsFromStyleBlocks($html)
	{
		preg_match_all('/<style.*?>(.*?)<\/style>/si', $html, $m);
		$count_m = count($m[1]);
		if ($count_m) {
			for ($i = 0; $i < $count_m; $i++) {
				// Remove comment tags
				$sub = preg_replace('/(<\!\-\-|\-\->)/s', ' ', $m[1][$i]);
				$sub = '>'.preg_replace('|/\*.*?\*/|s', ' ', $sub).'</style>';
				$html = str_replace('>'.$m[1][$i].'</style>', $sub, $html);
			}
		}
		return $html;
	}

	/**
	 * Process URLs in CSS strings by encoding special characters.
	 *
	 * Characters "(", ")", and ";" in url() can cause problems parsing CSS.
	 * This method URLencodes ( and ), and temporarily encodes ";" to prevent
	 * confusion with CSS segment delimiters.
	 *
	 * @param string $css CSS string containing url() references
	 * @return string CSS string with processed URLs
	 */
	public function processUrlsInCss($css)
	{
		if (strpos($css, 'url(') === false) {
			return $css;
		}

		// Process urls with double quotes
		preg_match_all('/url\(\"(.*?)\"\)/', $css, $m);
		$count_m = count($m[1]);
		for ($i = 0; $i < $count_m; $i++) {
			$tmp = str_replace(['(', ')', ';'], ['%28', '%29', '%ZZ'], $m[1][$i]);
			$css = str_replace($m[0][$i], 'url(\'' . $tmp . '\')', $css);
		}

		// Process urls with single quotes
		preg_match_all('/url\(\'(.*?)\'\)/', $css, $m);
		$count_m = count($m[1]);
		for ($i = 0; $i < $count_m; $i++) {
			$tmp = str_replace(['(', ')', ';'], ['%28', '%29', '%ZZ'], $m[1][$i]);
			$css = str_replace($m[0][$i], 'url(\'' . $tmp . '\')', $css);
		}

		// Process urls without quotes
		preg_match_all('/url\(([^\'\"].*?[^\'\"])\)/', $css, $m);
		$count_m = count($m[1]);
		for ($i = 0; $i < $count_m; $i++) {
			$tmp = str_replace(['(', ')', ';'], ['%28', '%29', '%ZZ'], $m[1][$i]);
			$css = str_replace($m[0][$i], 'url(\'' . $tmp . '\')', $css);
		}

		return $css;
	}
}
