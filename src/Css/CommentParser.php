<?php

namespace Mpdf\Css;

class CommentParser
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

}
