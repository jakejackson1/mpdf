<?php

namespace Mpdf;

use Mpdf\Color\ColorConverter;
use Mpdf\Css\TextVars;
use Mpdf\File\StreamWrapperChecker;
use Mpdf\Http\ClientInterface;
use Mpdf\PsrHttpMessageShim\Request;
use Mpdf\Utils\Arrays;
use Mpdf\Utils\UtfString;

class CssManager
{
	// URL processing
	const URL_TEMP_MARKER = '%ZZ';

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	/**
	 * @var \Mpdf\Cache
	 */
	private $cache;

	/**
	 * @var \Mpdf\SizeConverter
	 */
	private $sizeConverter;

	/**
	 * @var \Mpdf\Color\ColorConverter
	 */
	private $colorConverter;

	/**
	 * @var \Mpdf\AssetFetcher
	 */
	private $assetFetcher;

	/**
	 * @var array CSS cascade storage for table elements
	 */
	var $tablecascadeCSS;

	/**
	 * @var array Cascading CSS property storage
	 */
	var $cascadeCSS;

	/**
	 * @var array Main CSS property storage array
	 */
	var $CSS;

	/**
	 * @var int Table CSS cascade level counter
	 */
	var $tbCSSlvl;

	/**
	 * @var int|null Border dominance level for bottom cell borders
	 */
	public $cell_border_dominance_B;

	/**
	 * @var int|null Border dominance level for left cell borders
	 */
	public $cell_border_dominance_L;

	/**
	 * @var int|null Border dominance level for right cell borders
	 */
	public $cell_border_dominance_R;

	/**
	 * @var int|null Border dominance level for top cell borders
	 */
	public $cell_border_dominance_T;

	/**
	 * CssManager constructor.
	 *
	 * Initializes the CSS manager with required dependencies and sets up
	 * internal storage structures for CSS properties and cascading.
	 *
	 * @param Mpdf $mpdf Main mPDF instance
	 * @param Cache $cache Cache instance for temporary file storage
	 * @param SizeConverter $sizeConverter Size conversion utility
	 * @param ColorConverter $colorConverter Color conversion utility
	 * @param AssetFetcher $assetFetcher Asset fetching utility for external resources
	 */
	public function __construct(Mpdf $mpdf, Cache $cache, SizeConverter $sizeConverter, ColorConverter $colorConverter, AssetFetcher $assetFetcher)
	{
		$this->mpdf = $mpdf;
		$this->cache = $cache;
		$this->sizeConverter = $sizeConverter;
		$this->assetFetcher = $assetFetcher;

		$this->tablecascadeCSS = [];
		$this->CSS = [];
		$this->cascadeCSS = [];
		$this->tbCSSlvl = 0;
		$this->colorConverter = $colorConverter;
	}

	/**
	 * Read and parse CSS from HTML content.
	 *
	 * Extracts CSS from style tags, link tags, and @import statements within HTML.
	 * Processes external stylesheets, resolves URLs, handles media queries, and
	 * parses all CSS rules into the internal CSS storage structure.
	 *
	 * @param string $html HTML content containing CSS
	 * @return string HTML with CSS tags removed
	 */
	public function ReadCSS($html)
	{
		$html = $this->filterByMediaQuery($html, '/<style[^>]*media=["\']([^"\'>]*)["\'].*?<\/style>/is');
		$html = $this->filterByMediaQuery($html, '/<link[^>]*media=["\']([^"\'>]*)["\'].*?>/is');
		$html = $this->removeCommentsFromStyleBlocks($html);
		$html = $this->removeHtmlComments($html);

		$CSSext = $this->extractExternalStylesheetUrls($html);
		$CSSstr = '';

		$match = count($CSSext);
		$ind = 0;

		if (!is_array($this->cascadeCSS)) {
			$this->cascadeCSS = [];
		}

		while ($match) {
			$path = htmlspecialchars_decode($CSSext[$ind]);
			$this->mpdf->GetFullPath($path);

			// mPDF 5.7.3
			if (strpos($path, '//') === false) {
				$path = preg_replace('/\.css\?.*$/', '.css', $path);
			}

			$CSSextblock = $this->assetFetcher->fetchDataFromPath($path);
			if (!$CSSextblock) {
				$path = $this->normalizePath($path);
				$CSSextblock = $this->assetFetcher->fetchDataFromPath($path);
			}

			if ($CSSextblock) {
				$cssBasePath = preg_replace('/\/[^\/]*$/', '', $path) . '/';

				// look for embedded @import stylesheets in other stylesheets
				// and fix url paths (including background-images) relative to stylesheet
				$regexpem = '/@import url\([\'\"]{0,1}(.*?\.css(\?\S+)?)[\'\"]{0,1}\)/si';
				if (preg_match_all($regexpem, $CSSextblock, $cxtem)) {
					foreach ($cxtem[1] as $cxtembedded) {
						// path is relative to original stylesheet!!
						$this->mpdf->GetFullPath($cxtembedded, $cssBasePath);
						$match++;
						$CSSext[] = $cxtembedded;
					}
				}

				$CSSstr .= ' ' . $this->resolveBackgroundUrls($CSSextblock, $cssBasePath);
			}

			$match--;
			$ind++;
		}

		// CSS as <style> in HTML document
		$regexp = '/<style.*?>(.*?)<\/style>/si';
		if (preg_match_all($regexp, $html, $CSSblock)) {
			$CSSstr .= ' ' . $this->resolveBackgroundUrls(implode(' ', $CSSblock[1]));
		}

		// Remove comments
		$CSSstr = preg_replace('|/\*.*?\*/|s', ' ', $CSSstr);
		$CSSstr = preg_replace('/[\s\n\r\t\f]/s', ' ', $CSSstr);
		$CSSstr = $this->processMediaQueries($CSSstr);
		$CSSstr = $this->processDataUriImages($CSSstr);
		$CSSstr = preg_replace('/(<\!\-\-|\-\->)/s', ' ', $CSSstr);
		$CSSstr = $this->processUrlsInCss($CSSstr);

		if ($CSSstr) {

			$classproperties = []; // mPDF 6
			preg_match_all('/(.*?)\{(.*?)\}/', $CSSstr, $styles);
			$styles_count = count($styles[1]);
			for ($i = 0; $i < $styles_count; $i++) {

				// SET array e.g. $classproperties['COLOR'] = '#ffffff';
				$stylestr = trim($styles[2][$i]);
				$stylearr = explode(';', $stylestr);

				foreach ($stylearr as $sta) {
					if (trim($sta)) {
						// Changed to allow style="background: url('http://www.bpm1.com/bg.jpg')"
						$tmp = explode(':', $sta, 2);
						$property = $tmp[0];
						if (isset($tmp[1])) {
							$value = $tmp[1];
						} else {
							$value = '';
						}
						$value = str_replace(self::URL_TEMP_MARKER, ';', $value); // mPDF 5.7.4 URLs
						$property = trim($property);
						$value = preg_replace('/\s*!important/i', '', $value);
						$value = trim($value);
						if ($property && ($value || $value === '0')) {
							// Ignores -webkit-gradient so doesn't override -moz-
							if ((strtoupper($property) === 'BACKGROUND-IMAGE' || strtoupper($property) === 'BACKGROUND') && false !== stripos($value, '-webkit-gradient')) {
								continue;
							}
							$classproperties[strtoupper($property)] = $value;
						}
					}
				}

				$classproperties = $this->fixCSS($classproperties);
				$tagstr = strtoupper(trim($styles[1][$i]));
				$tagarr = explode(',', $tagstr);
				$pageselectors = false; // used to turn on $this->mpdf->mirrorMargins

				foreach ($tagarr as $tg) {

					if (preg_match('/NTH-CHILD\((\s*(([\-+]?\d*)N(\s*[\-+]\s*\d+)?|[\-+]?\d+|ODD|EVEN)\s*)\)/', $tg, $m)) {
						$tg = preg_replace('/NTH-CHILD\(.*\)/', 'NTH-CHILD(' . str_replace(' ', '', $m[1]) . ')', $tg);
					}

					$tags = preg_split('/\s+/', trim($tg));
					$level = count($tags);
					$t = '';
					$t2 = '';
					$t3 = '';

					if (trim($tags[0]) === '@PAGE') {

						if (isset($tags[0])) {
							$t = trim($tags[0]);
						}

						if (isset($tags[1])) {
							$t2 = trim($tags[1]);
						}

						if (isset($tags[2])) {
							$t3 = trim($tags[2]);
						}

						$tag = '';
						if ($level === 1) {
							$tag = $t;
						} elseif ($level === 2 && preg_match('/^[:](.*)$/', $t2, $m)) {
							$tag = $t . '>>PSEUDO>>' . $m[1];
							if ($m[1] === 'LEFT' || $m[1] === 'RIGHT') {
								$pageselectors = true;
							} // used to turn on $this->mpdf->mirrorMargins
						} elseif ($level === 2) {
							$tag = $t . '>>NAMED>>' . $t2;
						} elseif ($level === 3 && preg_match('/^[:](.*)$/', $t3, $m)) {
							$tag = $t . '>>NAMED>>' . $t2 . '>>PSEUDO>>' . $m[1];
							if ($m[1] === 'LEFT' || $m[1] === 'RIGHT') {
								$pageselectors = true;
							} // used to turn on $this->mpdf->mirrorMargins
						}

						if (isset($this->CSS[$tag]) && $tag) {
							$this->CSS[$tag] = $this->array_merge_recursive_unique($this->CSS[$tag], $classproperties);
						} elseif ($tag) {
							$this->CSS[$tag] = $classproperties;
						}

					} elseif ($level === 1) {  // e.g. p or .class or #id or p.class or p#id

						if (isset($tags[0])) {
							$t = trim($tags[0]);
						}

						if ($t) {

							$tag = '';

							if (preg_match('/^[.](.*)$/', $t, $m)) {
								$classes = explode('.', $m[1]);
								sort($classes);
								$tag = 'CLASS>>' . join('.', $classes);
							} elseif (preg_match('/^[#](.*)$/', $t, $m)) {
								$tag = 'ID>>' . $m[1];
							} elseif (preg_match('/^\[LANG=[\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\]$/', $t, $m)) {
								$tag = 'LANG>>' . strtolower($m[1]);
							} elseif (preg_match('/^:LANG\([\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\)$/', $t, $m)) { // mPDF 6  Special case for lang as attribute selector
								$tag = 'LANG>>' . strtolower($m[1]);
							} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')[.](.*)$/', $t, $m)) { // mPDF 6  Special case for lang as attribute selector
								$classes = explode('.', $m[2]);
								sort($classes);
								$tag = $m[1] . '>>CLASS>>' . join('.', $classes);
							} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')\s*:NTH-CHILD\((.*)\)$/', $t, $m)) {
								$tag = $m[1] . '>>SELECTORNTHCHILD>>' . $m[2];
							} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')[#](.*)$/', $t, $m)) {
								$tag = $m[1] . '>>ID>>' . $m[2];
							} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')\[LANG=[\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\]$/', $t, $m)) {
								$tag = $m[1] . '>>LANG>>' . strtolower($m[2]);
							} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . '):LANG\([\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\)$/', $t, $m)) {  // mPDF 6  Special case for lang as attribute selector
								$tag = $m[1] . '>>LANG>>' . strtolower($m[2]);
							} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')$/', $t)) { // mPDF 6  Special case for lang as attribute selector
								$tag = $t;
							}

							if (isset($this->CSS[$tag]) && $tag) {
								$this->CSS[$tag] = $this->array_merge_recursive_unique($this->CSS[$tag], $classproperties);
							} elseif ($tag) {
								$this->CSS[$tag] = $classproperties;
							}
						}

					} else {

						$tmp = [];

						for ($n = 0; $n < $level; $n++) {

							$tag = '';

							if (isset($tags[$n])) {
								$t = trim($tags[$n]);
							} else {
								$t = '';
							}

							if ($t) {

								if (preg_match('/^[.](.*)$/', $t, $m)) {
									$classes = explode('.', $m[1]);
									sort($classes);
									$tag = 'CLASS>>' . join('.', $classes);
								} elseif (preg_match('/^[#](.*)$/', $t, $m)) {
									$tag = 'ID>>' . $m[1];
								} elseif (preg_match('/^\[LANG=[\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\]$/', $t, $m)) {
									$tag = 'LANG>>' . strtolower($m[1]);
								} elseif (preg_match('/^:LANG\([\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\)$/', $t, $m)) { // mPDF 6  Special case for lang as attribute selector
									$tag = 'LANG>>' . strtolower($m[1]);
								} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')[.](.*)$/', $t, $m)) { // mPDF 6  Special case for lang as attribute selector
									$classes = explode('.', $m[2]);
									sort($classes);
									$tag = $m[1] . '>>CLASS>>' . join('.', $classes);
								} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')\s*:NTH-CHILD\((.*)\)$/', $t, $m)) {
									$tag = $m[1] . '>>SELECTORNTHCHILD>>' . $m[2];
								} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')[#](.*)$/', $t, $m)) {
									$tag = $m[1] . '>>ID>>' . $m[2];
								} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')\[LANG=[\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\]$/', $t, $m)) {
									$tag = $m[1] . '>>LANG>>' . strtolower($m[2]);
								} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . '):LANG\([\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\)$/', $t, $m)) { // mPDF 6  Special case for lang as attribute selector
									$tag = $m[1] . '>>LANG>>' . strtolower($m[2]);
								} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')$/', $t)) { // mPDF 6  Special case for lang as attribute selector
									$tag = $t;
								}

								if ($tag) {
									$tmp[] = $tag;
								} else {
									break;
								}
							}
						}

						if ($tag) {
							$x = &$this->cascadeCSS;
							foreach ($tmp as $tp) {
								$x = &$x[$tp];
							}
							$x = $this->array_merge_recursive_unique($x, $classproperties);
							$x['depth'] = $level;
						}
					}
				}
				if ($pageselectors) {
					$this->mpdf->mirrorMargins = true;
				}
				$classproperties = [];
			}
		}

		// Remove CSS (tags and content), if any
		$regexp = '/<style.*?>(.*?)<\/style>/si'; // it can be <style> or <style type="txt/css">
		$html = preg_replace($regexp, '', $html);

		return $html;
	}

	/**
	 * Extract external stylesheet URLs from HTML.
	 *
	 * Finds all external CSS file references including:
	 * - <link rel="stylesheet" href="...">
	 * - <link href="..." rel="stylesheet">
	 * - @import url(...)
	 * - @import "..."
	 *
	 * @param string $html HTML content to scan
	 * @return array Array of CSS file URLs
	 */
	protected function extractExternalStylesheetUrls($html)
	{
		$cssUrls = [];

		// <link rel="stylesheet" href="...">
		$regexp = '/<link[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^>"\']*)["\'].*?>/si';
		if (preg_match_all($regexp, $html, $cxt)) {
			$cssUrls = $cxt[1];
		}

		// <link href="..." rel="stylesheet">
		$regexp = '/<link[^>]*href=["\']([^>"\']*)["\'][^>]*?rel=["\']stylesheet["\'].*?>/si';
		if (preg_match_all($regexp, $html, $cxt)) {
			$cssUrls = array_merge($cssUrls, $cxt[1]);
		}

		// @import url(...)
		$regexp = '/@import url\([\'\"]{0,1}(\S*?\.css(\?[^\s\'\"]+)?)[\'\"]{0,1}\)\;?/si';
		if (preg_match_all($regexp, $html, $cxt)) {
			$cssUrls = array_merge($cssUrls, $cxt[1]);
		}

		// @import "..."
		$regexp = '/@import (?!url)[\'\"]{0,1}(\S*?\.css(\?[^\s\'\"]+)?)[\'\"]{0,1}\;?/si';
		if (preg_match_all($regexp, $html, $cxt)) {
			$cssUrls = array_merge($cssUrls, $cxt[1]);
		}

		return $cssUrls;
	}

	/**
	 * Process @media queries in CSS.
	 *
	 * Filters or unwraps @media blocks based on configured media type.
	 * If media doesn't match CSSselectMedia, the entire block is removed.
	 * If it matches, the contents are unwrapped.
	 *
	 * @param string $cssStr CSS string potentially containing @media rules
	 * @return string CSS string with media queries processed
	 */
	protected function processMediaQueries($cssStr)
	{
		if (preg_match('/@media/', $cssStr)) {
			preg_match_all('/@media(.*?)\{(([^\{\}]*\{[^\{\}]*\})+)\s*\}/is', $cssStr, $m);
			$count_m = count($m[0]);
			for ($i = 0; $i < $count_m; $i++) {
				if ($this->mpdf->CSSselectMedia && !preg_match('/(' . trim($this->mpdf->CSSselectMedia) . '|all)/i', $m[1][$i])) {
					$cssStr = str_replace($m[0][$i], '', $cssStr);
				} else {
					$cssStr = str_replace($m[0][$i], ' ' . $m[2][$i] . ' ', $cssStr);
				}
			}
		}
		return $cssStr;
	}

	/**
	 * Remove mPDF-specific and general HTML comments from content.
	 *
	 * Removes <!--mpdf and mpdf--> markers and all HTML comments.
	 *
	 * @param string $html HTML content to clean
	 * @return string HTML with comments removed
	 */
	protected function removeHtmlComments($html)
	{
		$html = preg_replace('/<!--mpdf/i', '', $html);
		$html = preg_replace('/mpdf-->/i', '', $html);
		$html = preg_replace('/<\!\-\-.*?\-\->/s', ' ', $html);
		return $html;
	}

	/**
	 * Resolve background image URLs in CSS.
	 *
	 * Converts relative URLs to absolute paths using GetFullPath.
	 * Skips data URIs which are already absolute.
	 *
	 * @param string $cssStr CSS string potentially containing background URLs
	 * @param string|null $basePath Optional base path for resolving relative URLs
	 * @return string CSS string with resolved URLs
	 */
	protected function resolveBackgroundUrls($cssStr, $basePath = null)
	{
		$regexpem = '/(background[^;]*url\s*\(\s*[\'\"]{0,1})([^\)\'\"]*)([\'\"]{0,1}\s*\))/si';
		$xem = preg_match_all($regexpem, $cssStr, $cxtem);
		if ($xem) {
			$count_cxtem = count($cxtem[0]);
			for ($i = 0; $i < $count_cxtem; $i++) {
				$embedded = $cxtem[2][$i];
				if (!preg_match('/^data:image/i', $embedded)) {
					if ($basePath !== null) {
						$this->mpdf->GetFullPath($embedded, $basePath);
					} else {
						$this->mpdf->GetFullPath($embedded);
					}
					$cssStr = str_replace($cxtem[0][$i], ($cxtem[1][$i] . $embedded . $cxtem[3][$i]), $cssStr);
				}
			}
		}
		return $cssStr;
	}

	/**
	 * Process data URI images in CSS.
	 *
	 * Converts data URI images to temporary files for processing.
	 * Example: url(data:image/png;base64,...) becomes url("tempfile.png")
	 *
	 * @param string $cssStr CSS string potentially containing data URIs
	 * @return string CSS string with data URIs replaced by temp file references
	 */
	protected function processDataUriImages($cssStr)
	{
		preg_match_all("/(url\(data:image\/(jpeg|gif|png);base64,(.*?)\))/si", $cssStr, $idata);
		$count_idata = count($idata[0]);
		if ($count_idata) {
			for ($i = 0; $i < $count_idata; $i++) {
				$file = $this->cache->write('_tempCSSidata' . random_int(1, 10000) . '_' . $i . '.' . $idata[2][$i], base64_decode($idata[3][$i]));
				$cssStr = str_replace($idata[0][$i], 'url("' . $file . '")', $cssStr);
			}
		}
		return $cssStr;
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
	protected function removeCommentsFromStyleBlocks($html)
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
	 * Filter HTML elements by media query.
	 *
	 * Removes elements (style or link tags) that don't match the configured media type.
	 *
	 * @param string $html HTML content to filter
	 * @param string $pattern Regex pattern to match elements
	 * @return string Filtered HTML
	 */
	protected function filterByMediaQuery($html, $pattern)
	{
		preg_match_all($pattern, $html, $m);
		$count_m = count($m[0]);
		for ($i = 0; $i < $count_m; $i++) {
			if ($this->mpdf->CSSselectMedia && !preg_match('/(' . trim($this->mpdf->CSSselectMedia) . '|all)/i', $m[1][$i])) {
				$html = str_replace($m[0][$i], '', $html);
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
	protected function processUrlsInCss($css)
	{
		if (strpos($css, 'url(') === false) {
			return $css;
		}

		$tempMarker = self::URL_TEMP_MARKER;

		// Process urls with double quotes
		preg_match_all('/url\(\"(.*?)\"\)/', $css, $m);
		$count_m = count($m[1]);
		for ($i = 0; $i < $count_m; $i++) {
			$tmp = str_replace(['(', ')', ';'], ['%28', '%29', $tempMarker], $m[1][$i]);
			$css = str_replace($m[0][$i], 'url(\'' . $tmp . '\')', $css);
		}

		// Process urls with single quotes
		preg_match_all('/url\(\'(.*?)\'\)/', $css, $m);
		$count_m = count($m[1]);
		for ($i = 0; $i < $count_m; $i++) {
			$tmp = str_replace(['(', ')', ';'], ['%28', '%29', $tempMarker], $m[1][$i]);
			$css = str_replace($m[0][$i], 'url(\'' . $tmp . '\')', $css);
		}

		// Process urls without quotes
		preg_match_all('/url\(([^\'\"].*?[^\'\"])\)/', $css, $m);
		$count_m = count($m[1]);
		for ($i = 0; $i < $count_m; $i++) {
			$tmp = str_replace(['(', ')', ';'], ['%28', '%29', $tempMarker], $m[1][$i]);
			$css = str_replace($m[0][$i], 'url(\'' . $tmp . '\')', $css);
		}

		return $css;
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
	 * Parse inline CSS style attribute.
	 *
	 * Parses a CSS string from an HTML style attribute and returns
	 * an array of CSS properties.
	 *
	 * @param string $html CSS string from style attribute
	 * @return array Parsed CSS properties
	 */
	function readInlineCSS($html)
	{
		$html = htmlspecialchars_decode($html); // mPDF 5.7.4 URLs
		// mPDF 5.7.4 URLs
		// Characters "(", ")", and ";" in url() e.g. background-image, cause problems parsing the CSS string
		// URLencode ( and ), but change ";" to a code which can be converted back after parsing (so as not to confuse ;
		// with a segment delimiter in the URI)
		$html = $this->processUrlsInCss($html);

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

			$values[$i] = str_replace(self::URL_TEMP_MARKER, ';', $values[$i]); // mPDF 5.7.4 URLs
			$classproperties[strtoupper($properties[$i])] = trim($values[$i]);
		}

		return $this->fixCSS($classproperties);
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
	function _fix_borderStr($bd)
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
	 * Helper method for _fix_borderStr to determine width, style, and color
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
	 * Process FONT shorthand property.
	 *
	 * Expands the CSS font shorthand into individual components:
	 * font-family, font-size, line-height, font-style, font-weight, text-transform.
	 *
	 * @param string $value Font property value
	 * @param array $newProperty Properties array to populate (modified by reference)
	 * @return void
	 */
	protected function processFontProperty($value, &$newProperty)
	{
		$value = trim($value);

		// Remove quoted font names and simplify
		preg_match_all('/\"(.*?)\"/', $value, $ff);
		if (count($ff[1])) {
			foreach ($ff[1] as $ffp) {
				$w = preg_split('/\s+/', $ffp);
				$value = preg_replace('/\"' . $ffp . '\"/', $w[0], $value);
			}
		}

		preg_match_all('/\'(.*?)\'/', $value, $ff);
		if (count($ff[1])) {
			foreach ($ff[1] as $ffp) {
				$w = preg_split('/\s+/', $ffp);
				$value = preg_replace('/\'' . $ffp . '\'/', $w[0], $value);
			}
		}

		$value = preg_replace('/\s*,\s*/', ',', $value);
		$bits = preg_split('/\s+/', $value);
		$numOfBits = count($bits);

		if ($numOfBits < 2) {
			return;
		}

		// Last item is font-family
		$newProperty['FONT-FAMILY'] = $bits[($numOfBits - 1)];

		// Second to last is font-size (possibly with /line-height)
		$fs = $bits[($numOfBits - 2)];
		if (preg_match('/(.*?)\/(.*)/', $fs, $fsp)) {
			$newProperty['FONT-SIZE'] = $fsp[1];
			$newProperty['LINE-HEIGHT'] = $fsp[2];
		} else {
			$newProperty['FONT-SIZE'] = $fs;
		}

		// Check for font-style
		if (preg_match('/(italic|oblique)/i', $value)) {
			$newProperty['FONT-STYLE'] = 'italic';
		} else {
			$newProperty['FONT-STYLE'] = 'normal';
		}

		// Check for font-weight
		if (false !== stripos($value, 'bold')) {
			$newProperty['FONT-WEIGHT'] = 'bold';
		} else {
			$newProperty['FONT-WEIGHT'] = 'normal';
		}

		// Check for small-caps
		if (false !== stripos($value, 'small-caps')) {
			$newProperty['TEXT-TRANSFORM'] = 'uppercase';
		}
	}

	/**
	 * Process FONT-FAMILY property.
	 *
	 * Validates and normalizes font family names against available fonts.
	 * Checks font translations, available unifonts, core fonts, and font categories.
	 *
	 * @param string $propertyKey Property key
	 * @param string $value Font family value
	 * @param array $newProperty Properties array to populate (modified by reference)
	 * @return void
	 */
	protected function processFontFamilyProperty($propertyKey, $value, &$newProperty)
	{
		/* Normalize the font list */
		$fontList = array_map(
			function ($fontName) {
				$fontName = trim($fontName);
				$fontName = preg_replace('/["\']*(.*?)["\']*/', '\\1', $fontName);
				$fontName = preg_replace('/ /', '', $fontName);
				$fontName = strtolower(trim($fontName));

				if (!empty($this->mpdf->fonttrans[$fontName])) {
					$fontName = $this->mpdf->fonttrans[$fontName];
				}

				return $fontName;
			},
			explode(',', $value)
		);

		/* If font watches unicode, core fonts, or some CJK fonts (should use $this->mpdf->available_CJK_fonts?)*/
		foreach ($fontList as $fontName) {
			if ((!$this->mpdf->onlyCoreFonts && in_array($fontName, $this->mpdf->available_unifonts, true)) ||
				in_array($fontName, ['ccourier', 'ctimes', 'chelvetica'], true) ||
				($this->mpdf->onlyCoreFonts && in_array($fontName, ['courier', 'times', 'helvetica', 'arial'], true)) ||
				in_array($fontName, ['sjis', 'uhc', 'big5', 'gb'], true)
			) {
				$newProperty[$propertyKey] = $fontName;
				return;
			}
		}

		/* If no matches, check the default registered font families */
		foreach ($fontList as $fontName) {
			if (in_array($fontName, $this->mpdf->sans_fonts, true) ||
				in_array($fontName, $this->mpdf->serif_fonts, true) ||
				in_array($fontName, $this->mpdf->mono_fonts, true)
			) {
				$newProperty[$propertyKey] = $fontName;
				return;
			}
		}
	}

	/**
	 * Process BORDER shorthand and individual border properties.
	 *
	 * Handles BORDER, BORDER-TOP, BORDER-RIGHT, BORDER-BOTTOM, BORDER-LEFT properties
	 * by normalizing them to consistent "width style color" format.
	 *
	 * @param string $propertyKey Property key (BORDER, BORDER-TOP, etc.)
	 * @param string $value Property value
	 * @param array $newProperty Properties array to populate (modified by reference)
	 * @return void
	 */
	protected function processBorderProperty($propertyKey, $value, &$newProperty)
	{
		switch ($propertyKey) {
			case 'BORDER':
				$value = $value !== '1' ? $this->_fix_borderStr($value) : '1px solid #000000';

				$newProperty['BORDER-TOP'] = $value;
				$newProperty['BORDER-RIGHT'] = $value;
				$newProperty['BORDER-BOTTOM'] = $value;
				$newProperty['BORDER-LEFT'] = $value;
				break;

			case 'BORDER-TOP':
				$newProperty['BORDER-TOP'] = $this->_fix_borderStr($value);
				break;

			case 'BORDER-RIGHT':
				$newProperty['BORDER-RIGHT'] = $this->_fix_borderStr($value);
				break;

			case 'BORDER-BOTTOM':
				$newProperty['BORDER-BOTTOM'] = $this->_fix_borderStr($value);
				break;

			case 'BORDER-LEFT':
				$newProperty['BORDER-LEFT'] = $this->_fix_borderStr($value);
				break;
		}
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
	function fixCSS($prop)
	{
		if (!is_array($prop) || (count($prop) == 0)) {
			return [];
		}

		$newprop = [];

		foreach ($prop as $k => $v) {

			if ($k !== 'BACKGROUND-IMAGE' && $k !== 'BACKGROUND' && $k !== 'ODD-HEADER-NAME' && $k !== 'EVEN-HEADER-NAME' && $k !== 'ODD-FOOTER-NAME' && $k !== 'EVEN-FOOTER-NAME' && $k !== 'HEADER' && $k !== 'FOOTER') {
				$v = strtolower($v);
			}

			if ($k === 'FONT') {
				$this->processFontProperty($v, $newprop);
			} elseif ($k === 'FONT-FAMILY') {
				$this->processFontFamilyProperty($k, $v, $newprop);
			} elseif ($k === 'FONT-VARIANT') {

				if (preg_match('/(normal|none)/', $v, $m)) {
					$newprop['FONT-VARIANT-LIGATURES'] = $m[1];
					$newprop['FONT-VARIANT-CAPS'] = $m[1];
					$newprop['FONT-VARIANT-NUMERIC'] = $m[1];
					$newprop['FONT-VARIANT-ALTERNATES'] = $m[1];
				} else {
					if (preg_match_all('/(no-common-ligatures|\bcommon-ligatures|no-discretionary-ligatures|\bdiscretionary-ligatures|no-historical-ligatures|\bhistorical-ligatures|no-contextual|\bcontextual)/i', $v, $m)) {
						$newprop['FONT-VARIANT-LIGATURES'] = implode(' ', $m[1]);
					}
					if (preg_match('/(all-small-caps|\bsmall-caps|all-petite-caps|\bpetite-caps|unicase|titling-caps)/i', $v, $m)) {
						$newprop['FONT-VARIANT-CAPS'] = $m[1];
					}
					if (preg_match_all('/(lining-nums|oldstyle-nums|proportional-nums|tabular-nums|diagonal-fractions|stacked-fractions)/i', $v, $m)) {
						$newprop['FONT-VARIANT-NUMERIC'] = implode(' ', $m[1]);
					}
					if (preg_match('/(historical-forms)/i', $v, $m)) {
						$newprop['FONT-VARIANT-ALTERNATES'] = $m[1];
					}
				}

			} elseif ($k === 'MARGIN') {

				$tmp = $this->expandShorthandProperty($v);

				$newprop['MARGIN-TOP'] = $tmp['T'];
				$newprop['MARGIN-RIGHT'] = $tmp['R'];
				$newprop['MARGIN-BOTTOM'] = $tmp['B'];
				$newprop['MARGIN-LEFT'] = $tmp['L'];

			} elseif ($k === 'BORDER-RADIUS' || $k === 'BORDER-TOP-LEFT-RADIUS' || $k === 'BORDER-TOP-RIGHT-RADIUS' || $k === 'BORDER-BOTTOM-LEFT-RADIUS' || $k === 'BORDER-BOTTOM-RIGHT-RADIUS') {
				$this->processBorderRadiusProperty($k, $v, $newprop);

			} elseif ($k === 'PADDING') {

				$tmp = $this->expandShorthandProperty($v);

				$newprop['PADDING-TOP'] = $tmp['T'];
				$newprop['PADDING-RIGHT'] = $tmp['R'];
				$newprop['PADDING-BOTTOM'] = $tmp['B'];
				$newprop['PADDING-LEFT'] = $tmp['L'];

			} elseif (in_array($k, ['BORDER', 'BORDER-TOP', 'BORDER-RIGHT', 'BORDER-BOTTOM', 'BORDER-LEFT'], true)) {
				$this->processBorderProperty($k, $v, $newprop);
			} elseif ($k === 'BORDER-STYLE') {

				$e = $this->expandShorthandProperty($v);

				if (!empty($e)) {
					$newprop['BORDER-TOP-STYLE'] = $e['T'];
					$newprop['BORDER-RIGHT-STYLE'] = $e['R'];
					$newprop['BORDER-BOTTOM-STYLE'] = $e['B'];
					$newprop['BORDER-LEFT-STYLE'] = $e['L'];
				}

			} elseif ($k === 'BORDER-WIDTH') {

				$e = $this->expandShorthandProperty($v);
				if (!empty($e)) {
					$newprop['BORDER-TOP-WIDTH'] = $e['T'];
					$newprop['BORDER-RIGHT-WIDTH'] = $e['R'];
					$newprop['BORDER-BOTTOM-WIDTH'] = $e['B'];
					$newprop['BORDER-LEFT-WIDTH'] = $e['L'];
				}

			} elseif ($k === 'BORDER-COLOR') {

				$e = $this->expandShorthandProperty($v);
				if (!empty($e)) {
					$newprop['BORDER-TOP-COLOR'] = $e['T'];
					$newprop['BORDER-RIGHT-COLOR'] = $e['R'];
					$newprop['BORDER-BOTTOM-COLOR'] = $e['B'];
					$newprop['BORDER-LEFT-COLOR'] = $e['L'];
				}

			} elseif ($k === 'BORDER-SPACING') {

				$prop = preg_split('/\s+/', trim($v));
				if (count($prop) == 1) {
					$newprop['BORDER-SPACING-H'] = $prop[0];
					$newprop['BORDER-SPACING-V'] = $prop[0];
				} elseif (count($prop) == 2) {
					$newprop['BORDER-SPACING-H'] = $prop[0];
					$newprop['BORDER-SPACING-V'] = $prop[1];
				}

			} elseif ($k === 'TEXT-OUTLINE') {
				$this->processTextOutlineProperty($v, $newprop);

			} elseif ($k === 'SIZE' || $k === 'SHEET-SIZE') {
				$this->processPageSizeProperty($k, $v, $newprop);

			} elseif (in_array($k, ['BACKGROUND', 'BACKGROUND-IMAGE', 'BACKGROUND-REPEAT', 'BACKGROUND-POSITION'], true)) {
				$this->processBackgroundProperty($k, $v, $newprop);

			} elseif ($k === 'IMAGE-ORIENTATION') {
				$this->processImageOrientationProperty($v, $newprop);

			} elseif ($k === 'TEXT-ALIGN') {
				$this->processTextAlignProperty($k, $v, $newprop);

			} elseif ($k === 'LIST-STYLE') {
				$this->processListStyleProperty($v, $newprop);

				if (preg_match('/(inside|outside)/i', $v, $m)) {
					$newprop['LIST-STYLE-POSITION'] = strtolower(trim($m[1]));
				}

			} else {
				$newprop[$k] = $v;
			}
		}

		return $newprop;
	}

	/**
	 * Normalize shadow colors.
	 *
	 * Replaces commas in color functions (rgb, hsl, etc.) with placeholders
	 * to prevent splitting multiple shadows on those commas.
	 *
	 * @param string $value Shadow property value
	 * @return string Normalized shadow property value
	 */
	protected function normalizeShadowColors($value)
	{
		$c = preg_match_all('/(rgba|rgb|device-cmyka|cmyka|device-cmyk|cmyk|hsla|hsl)\(.*?\)/', $value, $x); // mPDF 5.6.05
		for ($i = 0; $i < $c; $i++) {
			$col = preg_replace('/,/', '*', $x[0][$i]);
			$value = str_replace($x[0][$i], $col, $value);
		}

		return $value;
	}

	/**
	 * Parse a single box-shadow definition.
	 *
	 * Helper method for setCSSboxshadow to parse individual shadow components
	 * (inset, x, y, blur, spread, color).
	 *
	 * @param string $s Shadow definition string
	 * @return array|null Parsed shadow array or null if invalid
	 */
	protected function parseSingleBoxShadow($s)
	{
		$boxShadow = [
			'inset' => false,
			'blur' => 0,
			'spread' => 0
		];

		if (stripos($s, 'inset') !== false) {
			$boxShadow['inset'] = true;
			$s = preg_replace('/\s*inset\s*/', '', $s);
		}

		$p = explode(' ', trim($s));
		if (isset($p[0])) {
			$boxShadow['x'] = $this->sizeConverter->convert(
				trim($p[0]),
				$this->mpdf->blk[$this->mpdf->blklvl - 1]['inner_width'],
				$this->mpdf->FontSize,
				false
			);
		}

		if (isset($p[1])) {
			$boxShadow['y'] = $this->sizeConverter->convert(
				trim($p[1]),
				$this->mpdf->blk[$this->mpdf->blklvl - 1]['inner_width'],
				$this->mpdf->FontSize,
				false
			);

		}

		if (isset($p[2])) {
			if (preg_match('/^\s*[\.\-0-9]/', $p[2])) {
				$boxShadow['blur'] = $this->sizeConverter->convert(
					trim($p[2]),
					$this->mpdf->blk[$this->mpdf->blklvl - 1]['inner_width'],
					$this->mpdf->FontSize,
					false
				);
			} else {
				$boxShadow['col'] = $this->colorConverter->convert(
					preg_replace('/\*/', ',', $p[2]),
					$this->mpdf->PDFAXwarnings
				);
			}
		}

		if (isset($p[3])) {
			if (preg_match('/^\s*[\.\-0-9]/', $p[3])) {
				$boxShadow['spread'] = $this->sizeConverter->convert(
					trim($p[3]),
					$this->mpdf->blk[$this->mpdf->blklvl - 1]['inner_width'],
					$this->mpdf->FontSize,
					false
				);
			} else {
				$boxShadow['col'] = $this->colorConverter->convert(
					preg_replace('/\*/', ',', $p[3]),
					$this->mpdf->PDFAXwarnings
				);
			}
		}

		if (isset($p[4])) {
			$boxShadow['col'] = $this->colorConverter->convert(
				preg_replace('/\*/', ',', $p[4]),
				$this->mpdf->PDFAXwarnings
			);
		}

		if (empty($boxShadow['col'])) {
			$boxShadow['col'] = $this->colorConverter->convert('#888888', $this->mpdf->PDFAXwarnings);
		}
		
		return isset($boxShadow['y']) ? $boxShadow : null;
	}

	/**
	 * Parse box-shadow CSS property.
	 *
	 * Converts box-shadow CSS property string into array format used internally.
	 * Handles multiple shadows, inset shadows, blur, spread, and colors.
	 *
	 * @param string $value Box-shadow property value
	 * @return array Array of shadow definitions
	 */
	function setCSSboxshadow($value)
	{
		$sh = [];
		$ss = explode(',', $this->normalizeShadowColors($value));
		foreach ($ss as $s) {
			$boxShadow = $this->parseSingleBoxShadow($s);
			if ($boxShadow) {
				array_unshift($sh, $boxShadow);
			}
		}

		return $sh;
	}

	/**
	 * Parse a single text-shadow definition.
	 *
	 * Helper method for setCSStextshadow to parse individual shadow components
	 * (x, y, blur, color).
	 *
	 * @param string $s Shadow definition string
	 * @return array|null Parsed shadow array or null if invalid
	 */
	protected function parseSingleTextShadow($s)
	{
		$textShadow = ['blur' => 0];
		$p = explode(' ', trim($s));

		if (isset($p[0])) {
			$textShadow['x'] = $this->sizeConverter->convert(
				trim($p[0]),
				$this->mpdf->FontSize,
				$this->mpdf->FontSize,
				false
			);
		}

		if (isset($p[1])) {
			$textShadow['y'] = $this->sizeConverter->convert(
				trim($p[1]),
				$this->mpdf->FontSize,
				$this->mpdf->FontSize,
				false
			);
		}

		if (isset($p[2])) {
			if (preg_match('/^\s*[\.\-0-9]/', $p[2])) {
				$textShadow['blur'] = $this->sizeConverter->convert(
					trim($p[2]),
					isset($this->mpdf->blk[$this->mpdf->blklvl]['inner_width']) ? $this->mpdf->blk[$this->mpdf->blklvl]['inner_width'] : 0,
					$this->mpdf->FontSize,
					false
				);
			} else {
				$textShadow['col'] = $this->colorConverter->convert(
					preg_replace('/\*/', ',', $p[2]),
					$this->mpdf->PDFAXwarnings
				);
			}
		}

		if (isset($p[3])) {
			$textShadow['col'] = $this->colorConverter->convert(
				preg_replace('/\*/', ',', $p[3]),
				$this->mpdf->PDFAXwarnings
			);
		}

		if (empty($textShadow['col'])) {
			$textShadow['col'] = $this->colorConverter->convert(
				'#888888',
				$this->mpdf->PDFAXwarnings
			);
		}
		
		return isset($textShadow['y']) ? $textShadow : null;
	}

	/**
	 * Parse text-shadow CSS property.
	 *
	 * Converts text-shadow CSS property string into array format used internally.
	 * Handles multiple shadows, blur, and colors.
	 *
	 * @param string $value Text-shadow property value
	 * @return array Array of text shadow definitions
	 */
	function setCSStextshadow($value)
	{
		$sh = [];
		$ss = explode(',', $this->normalizeShadowColors($value));

		foreach ($ss as $s) {
			$textShadow = $this->parseSingleTextShadow($s);
			if ($textShadow) {
				array_unshift($sh, $textShadow);
			}
		}

		return $sh;
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
	function parseCSSbackground($s)
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
	function expandShorthandProperty($value)
	{
		$property = preg_split('/\s+/', trim($value));

		switch (count($property)) {
			case 0:
				return [];
			case 1:
				return ['T' => $property[0], 'R' => $property[0], 'B' => $property[0], 'L' => $property[0]];
			case 2:
				return ['T' => $property[0], 'R' => $property[1], 'B' => $property[0], 'L' => $property[1]];
			case 3:
				return ['T' => $property[0], 'R' => $property[1], 'B' => $property[2], 'L' => $property[1]];
			default:
				// Ignore rule parts after first 4 values (most likely !important)
				return ['T' => $property[0], 'R' => $property[1], 'B' => $property[2], 'L' => $property[3]];
		}
	}

	/* -- BORDER-RADIUS -- */

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
	function border_radius_expand($val, $k)
	{
		$b = [];

		if ($k === 'BORDER-RADIUS') {
			return $this->parseBorderRadiusShorthand($val);
		}

		// Parse 2
		$prop = preg_split('/\s+/', trim($val));

		if (count($prop) == 1) {
			$h = $v = $val;
		} else {
			$h = $prop[0];
			$v = $prop[1];
		}

		if ($h == 0 || $v == 0) {
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
	/* -- END BORDER-RADIUS -- */

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
	 * Merge CSS properties into target array.
	 *
	 * Internal method to merge CSS properties from source into target.
	 * Used for CSS cascading.
	 *
	 * @param array $property Source CSS properties
	 * @param array $target Target CSS properties (modified by reference)
	 * @return void
	 */
	function _mergeCSS($property, &$target)
	{
		if (empty($property)) {
			return;
		}

		$target = $target ? $this->array_merge_recursive_unique($target, $property) : $property;
	}

	// for CSS handling
	/**
	 * Recursively merge arrays with unique handling.
	 *
	 * Custom array merge function for CSS property handling. Differs from
	 * standard array_merge_recursive in how it handles integer vs string keys.
	 *
	 * @param array $array1 First array
	 * @param array $array2 Second array
	 * @return array Merged array
	 */
	function array_merge_recursive_unique($array1, $array2)
	{
		$arrays = func_get_args();
		$narrays = count($arrays);
		$ret = $arrays[0];
		for ($i = 1; $i < $narrays; $i ++) {
			foreach ($arrays[$i] as $key => $value) {
				if (((string) $key) === ((string) ((int) $key))) { // integer or string as integer key - append
					$ret[] = $value;
				} else { // string key - merge
					if (is_array($value) && isset($ret[$key])) {
						$ret[$key] = $this->array_merge_recursive_unique($ret[$key], $value);
					} else {
						$ret[$key] = $value;
					}
				}
			}
		}
		return $ret;
	}

	/**
	 * Merge Nth-child CSS selectors.
	 *
	 * Handles :nth-child() pseudo-class logic for TR, TD, and TH tags.
	 *
	 * @param array $p Source CSS selector array
	 * @param array $t Target CSS properties (passed by reference)
	 * @param string $tag HTML tag name
	 * @return void
	 */
	protected function mergeNthChildCSS($p, &$t, $tag)
	{
		if ($tag !== 'TR' || empty($p)) {
			return;
		}

		foreach ($p as $k => $val) {
			if (preg_match('/' . $tag . '>>SELECTORNTHCHILD>>(.*)/', $k, $m)) {
				$select = false;
				if ($tag === 'TR') {
					$row = $this->mpdf->row;
					$thnr = (isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_thead']) ? count($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_thead']) : 0);
					$tfnr = (isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_tfoot']) ? count($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_tfoot']) : 0);

					if ($this->mpdf->tabletfoot) {
						$row -= $thnr;
					} elseif (!$this->mpdf->tablethead) {
						$row -= ($thnr + $tfnr);
					}

					if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
						$select = $this->_nthchild($a, $row);
					}
				} elseif ($tag === 'TD' || $tag === 'TH') {
					if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
						$select = $this->_nthchild($a, $this->mpdf->col);
					}
				}

				if ($select) {
					$this->_mergeCSS($p[$tag . '>>SELECTORNTHCHILD>>' . $m[1]], $t);
				}
			}
		}
	}

	/**
	 * Merge full CSS rules including tag, class, ID, and lang selectors.
	 *
	 * Applies CSS rules from various selector types (tag, class, ID, language)
	 * to the target CSS properties array. Handles CSS cascading and specificity.
	 *
	 * @param array $p Source CSS selector array
	 * @param array $t Target CSS properties (modified by reference)
	 * @param string $tag HTML tag name
	 * @param array $classes Array of class names
	 * @param string $id Element ID
	 * @param string $lang Language code
	 * @return void
	 */
	function _mergeFullCSS($p, &$t, $tag, $classes, $id, $lang)
	{
		// mPDF 6
		if (isset($p[$tag])) {
			$this->_mergeCSS($p[$tag], $t);
		}

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			if (isset($p['CLASS>>' . $class])) {
				$this->_mergeCSS($p['CLASS>>' . $class], $t);
			}
		}

		// STYLESHEET nth-child SELECTOR e.g. tr:nth-child(odd)  td:nth-child(2n+1)
		$this->mergeNthChildCSS($p, $t, $tag);

		// STYLESHEET CLASS e.g. [lang=fr]{} or :lang(fr)
		if (isset($lang) && isset($p['LANG>>' . $lang])) {
			$this->_mergeCSS($p['LANG>>' . $lang], $t);
		}

		// STYLESHEET CLASS e.g. #smallone{}  #redletter{}
		if (isset($id) && isset($p['ID>>' . $id])) {
			$this->_mergeCSS($p['ID>>' . $id], $t);
		}

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			if (isset($p[$tag . '>>CLASS>>' . $class])) {
				$this->_mergeCSS($p[$tag . '>>CLASS>>' . $class], $t);
			}
		}

		// STYLESHEET CLASS e.g. [lang=fr]{} or :lang(fr)
		if (isset($lang) && isset($p[$tag . '>>LANG>>' . $lang])) {
			$this->_mergeCSS($p[$tag . '>>LANG>>' . $lang], $t);
		}

		// STYLESHEET CLASS e.g. #smallone{}  #redletter{}
		if (isset($id) && isset($p[$tag . '>>ID>>' . $id])) {
			$this->_mergeCSS($p[$tag . '>>ID>>' . $id], $t);
		}
	}

	/**
	 * Set border dominance level for table cells.
	 *
	 * Used in table rendering to determine which cell borders take
	 * precedence when cells share borders.
	 *
	 * @param array $prop CSS properties containing border definitions
	 * @param int $val Dominance level value
	 * @return void
	 */
	function setBorderDominance($prop, $val)
	{
		if (!empty($prop['BORDER-LEFT'])) {
			$this->cell_border_dominance_L = $val;
		}

		if (!empty($prop['BORDER-RIGHT'])) {
			$this->cell_border_dominance_R = $val;
		}

		if (!empty($prop['BORDER-TOP'])) {
			$this->cell_border_dominance_T = $val;
		}

		if (!empty($prop['BORDER-BOTTOM'])) {
			$this->cell_border_dominance_B = $val;
		}
	}

	/**
	 * Set merged CSS properties with depth and border dominance checks.
	 *
	 * Internal method for applying cascaded CSS with optional depth checking
	 * and border dominance handling for table cells.
	 *
	 * @param array $m Source CSS properties
	 * @param array $p Target CSS properties (modified by reference)
	 * @param bool $d Check depth before merging
	 * @param int|bool $bd Border dominance level or false
	 * @return void
	 */
	function _set_mergedCSS(&$m, &$p, $d = true, $bd = false)
	{
		if (!isset($m)) {
			return;
		}

		if ((isset($m['depth']) && $m['depth'] > 1) || $d == false) {  // include check for 'depth'
			if ($bd) {
				$this->setBorderDominance($m, $bd);
			} // *TABLES*

			if (is_array($m)) {
				$p = array_merge($p, $m);
				$this->_mergeBorders($p, $m);
			}
		}
	}

	/**
	 * Merge border properties for a specific side.
	 *
	 * Helper method for _mergeBorders to handle merging of individual side properties
	 * (style, width, color) into the shorthand border property.
	 *
	 * @param array $b Target border array (modified by reference)
	 * @param string $side Side to merge (TOP, RIGHT, BOTTOM, LEFT)
	 * @param array $a Source border properties
	 * @return void
	 */
	protected function mergeSideBorder(&$b, $side, $a)
	{
		$defaults = [
			'WIDTH' => '0px',
			'STYLE' => 'none',
			'COLOR' => '#000000'
		];

		$borderKey = 'BORDER-' . $side;
		$currentBorder = isset($b[$borderKey]) ? trim($b[$borderKey]) : '';
		
		foreach (['STYLE', 'WIDTH', 'COLOR'] as $el) {
			$propertyKey = $borderKey . '-' . $el;
			
			if (isset($a[$propertyKey])) {
				$value = trim($a[$propertyKey]);
				
				if ($currentBorder) {
					// Update existing border value
					if ($el === 'STYLE') {
						$b[$borderKey] = preg_replace('/(\S+)\s+(\S+)\s+(\S+)/', '\\1 ' . $value . ' \\3', $currentBorder);
					} elseif ($el === 'WIDTH') {
						$b[$borderKey] = preg_replace('/(\S+)\s+(\S+)\s+(\S+)/', $value . ' \\2 \\3', $currentBorder);
					} else { // COLOR
						$b[$borderKey] = preg_replace('/(\S+)\s+(\S+)\s+(\S+)/', '\\1 \\2 ' . $value, $currentBorder);
					}
					$currentBorder = $b[$borderKey]; // Update current border for next iteration
				} else {
					// Build new border from scratch with defaults
					if (!isset($borderParts)) {
						$borderParts = $defaults;
					}
					$borderParts[$el] = $value;
					$b[$borderKey] = $borderParts['WIDTH'] . ' ' . $borderParts['STYLE'] . ' ' . $borderParts['COLOR'];
					$currentBorder = $b[$borderKey];
				}
			}
		}
	}

	/**
	 * Merge individual border properties into shorthand border properties.
	 *
	 * Converts individual border properties like BORDER-TOP-STYLE, BORDER-TOP-WIDTH,
	 * BORDER-TOP-COLOR into the shorthand BORDER-TOP property. This ensures consistency
	 * when CSS cascading rules apply individual border components.
	 *
	 * @param array $b Target border array (modified by reference)
	 * @param array $a Source border properties to merge
	 * @return void
	 */
	function _mergeBorders(&$b, &$a)
	{
		// Merges $a['BORDER-TOP-STYLE'] to $b['BORDER-TOP'] etc.
		$defaults = [
			'WIDTH' => '0px',
			'STYLE' => 'none',
			'COLOR' => '#000000'
		];
		
		foreach (['TOP', 'RIGHT', 'BOTTOM', 'LEFT'] as $side) {
			$this->mergeSideBorder($b, $side, $a);
		}
	}

	/**
	 * Merge CSS properties for an HTML element.
	 *
	 * Main method for applying CSS to an element. Combines CSS from multiple sources
	 * including default styles, stylesheets, inline styles, and inherited properties.
	 * Handles inheritance type (BLOCK, INLINE, TABLE, TOPTABLE) and applies
	 * appropriate cascading rules.
	 *
	 * @param string $inherit Inheritance context (BLOCK, INLINE, TABLE, TOPTABLE)
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes including CLASS, ID, STYLE
	 * @return array Merged CSS properties array
	 */
	function MergeCSS($inherit, $tag, $attr)
	{
		$p = [];

		$attr = is_array($attr) ? $attr : [];

		$classes = [];
		if (isset($attr['CLASS'])) {
			$classes = array_map(function ($combination) {
				return join('.', $combination);
			}, Arrays::allUniqueSortedCombinations(preg_split('/\s+/', $attr['CLASS'])));
		}

		if (!isset($attr['ID'])) {
			$attr['ID'] = '';
		}

		// mPDF 6
		$shortlang = '';
		if (!isset($attr['LANG'])) {
			$attr['LANG'] = '';
		} else {
			$attr['LANG'] = strtolower($attr['LANG']);
			if (strlen($attr['LANG']) == 5) {
				$shortlang = substr($attr['LANG'], 0, 2);
			}
		}

		$this->mergeTableCascadingCSS($inherit, $tag, $attr, $classes);
		$this->mergeBlockCascadingCSS($inherit, $tag, $attr, $classes, $p);
		$this->mergeInlineAttributes($tag, $attr, $p);

		// DEFAULT for this TAG set in DefaultCSS
		if (isset($this->mpdf->defaultCSS[$tag])) {
			$zp = $this->fixCSS($this->mpdf->defaultCSS[$tag]);
			if (is_array($zp)) {  // Default overwrites Inherited
				$p = array_merge($p, $zp);  // !! Note other way round !!
				$this->_mergeBorders($p, $zp);
			}
		}

		/* -- TABLES -- */
		// mPDF 5.7.3
		// cellSpacing overwrites TABLE default but not specific CSS set on table
		if ($tag === 'TABLE' && isset($attr['CELLSPACING'])) {
			$p['BORDER-SPACING-H'] = $p['BORDER-SPACING-V'] = $attr['CELLSPACING'];
		}

		// cellPadding overwrites TD/TH default but not specific CSS set on cell
		if (($tag === 'TD' || $tag === 'TH') && isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding']) && ($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'] || $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'] === '0')) {  // mPDF 5.7.3
			$p['PADDING-LEFT'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
			$p['PADDING-RIGHT'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
			$p['PADDING-TOP'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
			$p['PADDING-BOTTOM'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
		}
		/* -- END TABLES -- */

		$this->mergeStylesheetSelectors($tag, $attr, $classes, $p, $shortlang);

		// STYLESHEET CLASS e.g. p.smallone{}  div.redletter{}
		foreach ($classes as $class) {
			$zp = [];
			if (!empty($this->CSS[$tag . '>>CLASS>>' . $class])) {
				$zp = $this->CSS[$tag . '>>CLASS>>' . $class];
			}

			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			} // *TABLES*

			if (is_array($zp)) {
				$p = array_merge($p, $zp);
				$this->_mergeBorders($p, $zp);
			}
		}

		// STYLESHEET LANG e.g. [lang=fr]{} or :lang(fr)
		if (isset($attr['LANG'])) {
			if (!empty($this->CSS[$tag . '>>LANG>>' . $attr['LANG']])) {
				$zp = $this->CSS[$tag . '>>LANG>>' . $attr['LANG']];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				} // *TABLES*	// *TABLES-ADVANCED-BORDERS*
				if (is_array($zp)) {
					$p = array_merge($p, $zp);
					$this->_mergeBorders($p, $zp);
				}
			} elseif (!empty($this->CSS[$tag . '>>LANG>>' . $shortlang])) {
				$zp = $this->CSS[$tag . '>>LANG>>' . $shortlang];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				} // *TABLES*

				if (is_array($zp)) {
					$p = array_merge($p, $zp);
					$this->_mergeBorders($p, $zp);
				}
			}
		}

		// STYLESHEET CLASS e.g. p#smallone{}  div#redletter{}
		if (isset($attr['ID']) && !empty($this->CSS[$tag . '>>ID>>' . $attr['ID']])) {
			$zp = $this->CSS[$tag . '>>ID>>' . $attr['ID']];
			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			} // *TABLES*

			if (is_array($zp)) {
				$p = array_merge($p, $zp);
				$this->_mergeBorders($p, $zp);
			}
		}

		// Cascaded e.g. div.class p only works for block level
		if ($inherit === 'BLOCK' && !empty($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'])) {
			$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'][$tag], $p);
			foreach ($classes as $class) {
				$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS']['CLASS>>' . $class], $p);
			}

			$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS']['ID>>' . $attr['ID']], $p);
			foreach ($classes as $class) {
				$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'][$tag . '>>CLASS>>' . $class], $p);
			}

			$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'][$tag . '>>ID>>' . $attr['ID']], $p);
		} elseif ($inherit === 'INLINE') {
			$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'][$tag], $p);
			foreach ($classes as $class) {
				$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS']['CLASS>>' . $class], $p);
			}

			$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS']['ID>>' . $attr['ID']], $p);
			foreach ($classes as $class) {
				$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'][$tag . '>>CLASS>>' . $class], $p);
			}

			$this->_set_mergedCSS($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'][$tag . '>>ID>>' . $attr['ID']], $p);
		} elseif (!empty($this->tablecascadeCSS[$this->tbCSSlvl - 1]) && ($inherit === 'TOPTABLE' || $inherit === 'TABLE')) { // NB looks at $this->tablecascadeCSS-1 for cascading CSS

			// false, 9 = don't check for 'depth' and do set border dominance
			$this->_set_mergedCSS($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag], $p, false, 9);
			foreach ($classes as $class) {
				$this->_set_mergedCSS($this->tablecascadeCSS[$this->tbCSSlvl - 1]['CLASS>>' . $class], $p, false, 9);
			}

				// STYLESHEET nth-child SELECTOR e.g. tr:nth-child(odd)  td:nth-child(2n+1)
			if ($tag === 'TR' || $tag === 'TD' || $tag === 'TH') {
				foreach ($this->tablecascadeCSS[$this->tbCSSlvl - 1] as $k => $val) {
					if (preg_match('/' . $tag . '>>SELECTORNTHCHILD>>(.*)/', $k, $m)) {
						$select = false;
						if ($tag === 'TR') {
							$row = $this->mpdf->row;
							$thnr = (isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_thead']) ? count($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_thead']) : 0);
							$tfnr = (isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_tfoot']) ? count($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_tfoot']) : 0);
							if ($this->mpdf->tabletfoot) {
								$row -= $thnr;
							} elseif (!$this->mpdf->tablethead) {
								$row -= ($thnr + $tfnr);
							}
							if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
								$select = $this->_nthchild($a, $row);
							}
						} elseif ($tag === 'TD' || $tag === 'TH') {
							if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
								$select = $this->_nthchild($a, $this->mpdf->col);
							}
						}
						if ($select) {
							$this->_set_mergedCSS($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag . '>>SELECTORNTHCHILD>>' . $m[1]], $p, false, 9);
						}
					}
				}
			}

			$this->_set_mergedCSS($this->tablecascadeCSS[$this->tbCSSlvl - 1]['ID>>' . $attr['ID']], $p, false, 9);
			foreach ($classes as $class) {
				$this->_set_mergedCSS($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag . '>>CLASS>>' . $class], $p, false, 9);
			}

			$this->_set_mergedCSS($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag . '>>ID>>' . $attr['ID']], $p, false, 9);
		}

		// INLINE STYLE e.g. style="CSS:property"
		if (isset($attr['STYLE'])) {
			$zp = $this->readInlineCSS($attr['STYLE']);
			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			} // *TABLES*

			if (is_array($zp)) {
				$p = array_merge($p, $zp);
				$this->_mergeBorders($p, $zp);
			}
		}

		return $p;
	}

	/**
	 * Convert inline properties back to CSS.
	 *
	 * Transforms internal inline property format (used in TextVars) back into
	 * CSS property array. Used for property inheritance and cascading.
	 *
	 * @param array $bilp Inline properties array
	 * @param array $p CSS properties array (modified by reference)
	 * @return void
	 */
	function inlinePropsToCSS($bilp, &$p)
	{
		if (isset($bilp['family']) && $bilp['family']) {
			$p['FONT-FAMILY'] = $bilp['family'];
		}

		if (isset($bilp['I']) && $bilp['I']) {
			$p['FONT-STYLE'] = 'italic';
		}

		if (isset($bilp['sizePt']) && $bilp['sizePt']) {
			$p['FONT-SIZE'] = $bilp['sizePt'] . 'pt';
		}

		if (isset($bilp['B']) && $bilp['B']) {
			$p['FONT-WEIGHT'] = 'bold';
		}

		if (isset($bilp['colorarray']) && $bilp['colorarray']) {
			$cor = $bilp['colorarray'];
			$p['COLOR'] = $this->colorConverter->colAtoString($cor);
		}

		if (isset($bilp['lSpacingCSS']) && $bilp['lSpacingCSS']) {
			$p['LETTER-SPACING'] = $bilp['lSpacingCSS'];
		}

		if (isset($bilp['wSpacingCSS']) && $bilp['wSpacingCSS']) {
			$p['WORD-SPACING'] = $bilp['wSpacingCSS'];
		}

		if (isset($bilp['textparam']) && $bilp['textparam']) {
			if (isset($bilp['textparam']['hyphens'])) {
				if ($bilp['textparam']['hyphens'] == 2) {
					$p['HYPHENS'] = 'none';
				}
				if ($bilp['textparam']['hyphens'] == 1) {
					$p['HYPHENS'] = 'auto';
				}
				if ($bilp['textparam']['hyphens'] == 0) {
					$p['HYPHENS'] = 'manual';
				}
			}

			if (isset($bilp['textparam']['outline-s']) && !$bilp['textparam']['outline-s']) {
				$p['TEXT-OUTLINE'] = 'none';
			}

			if (isset($bilp['textparam']['outline-COLOR']) && $bilp['textparam']['outline-COLOR']) {
				$p['TEXT-OUTLINE-COLOR'] = $this->colorConverter->colAtoString($bilp['textparam']['outline-COLOR']);
			}

			if (isset($bilp['textparam']['outline-WIDTH']) && $bilp['textparam']['outline-WIDTH']) {
				$p['TEXT-OUTLINE-WIDTH'] = $bilp['textparam']['outline-WIDTH'] . 'mm';
			}
		}

		if (isset($bilp['textvar']) && $bilp['textvar']) {
			// CSS says text-decoration is not inherited, but IE7 does??
			if ($bilp['textvar'] & TextVars::FD_LINETHROUGH) {
				if ($bilp['textvar'] & TextVars::FD_UNDERLINE) {
					$p['TEXT-DECORATION'] = 'underline line-through';
				} else {
					$p['TEXT-DECORATION'] = 'line-through';
				}
			} elseif ($bilp['textvar'] & TextVars::FD_UNDERLINE) {
				$p['TEXT-DECORATION'] = 'underline';
			} else {
				$p['TEXT-DECORATION'] = 'none';
			}

			if ($bilp['textvar'] & TextVars::FA_SUPERSCRIPT) {
				$p['VERTICAL-ALIGN'] = 'super';
			} elseif ($bilp['textvar'] & TextVars::FA_SUBSCRIPT) {
				$p['VERTICAL-ALIGN'] = 'sub';
			} else {
				$p['VERTICAL-ALIGN'] = 'baseline';
			}

			if ($bilp['textvar'] & TextVars::FT_CAPITALIZE) {
				$p['TEXT-TRANSFORM'] = 'capitalize';
			} elseif ($bilp['textvar'] & TextVars::FT_UPPERCASE) {
				$p['TEXT-TRANSFORM'] = 'uppercase';
			} elseif ($bilp['textvar'] & TextVars::FT_LOWERCASE) {
				$p['TEXT-TRANSFORM'] = 'lowercase';
			} else {
				$p['TEXT-TRANSFORM'] = 'none';
			}

			if ($bilp['textvar'] & TextVars::FC_KERNING) {
				$p['FONT-KERNING'] = 'normal';
			} // ignore 'auto' as default already applied
			else {
				$p['FONT-KERNING'] = 'none';
			}

			if ($bilp['textvar'] & TextVars::FA_SUPERSCRIPT) {
				$p['FONT-VARIANT-POSITION'] = 'super';
			}
			elseif ($bilp['textvar'] & TextVars::FA_SUBSCRIPT) {
				$p['FONT-VARIANT-POSITION'] = 'sub';
			} else {
				$p['FONT-VARIANT-POSITION'] = 'normal';
			}

			if ($bilp['textvar'] & TextVars::FC_SMALLCAPS) {
				$p['FONT-VARIANT-CAPS'] = 'small-caps';
			}
		}

		if (isset($bilp['fontLanguageOverride'])) {
			if ($bilp['fontLanguageOverride']) {
				$p['FONT-LANGUAGE-OVERRIDE'] = $bilp['fontLanguageOverride'];
			} else {
				$p['FONT-LANGUAGE-OVERRIDE'] = 'normal';
			}
		}
		// All the variations of font-variant-* we are going to set as font-feature-settings...
		if (isset($bilp['OTLtags']) && $bilp['OTLtags']) {
			$ffs = [];
			if (isset($bilp['OTLtags']['Minus']) && $bilp['OTLtags']['Minus']) {
				$f = preg_split('/\s+/', trim($bilp['OTLtags']['Minus']));
				foreach ($f as $ff) {
					$ffs[] = "'" . $ff . "' 0";
				}
			}

			if (isset($bilp['OTLtags']['FFMinus']) && $bilp['OTLtags']['FFMinus']) {
				$f = preg_split('/\s+/', trim($bilp['OTLtags']['FFMinus']));
				foreach ($f as $ff) {
					$ffs[] = "'" . $ff . "' 0";
				}
			}

			if (isset($bilp['OTLtags']['Plus']) && $bilp['OTLtags']['Plus']) {
				$f = preg_split('/\s+/', trim($bilp['OTLtags']['Plus']));
				foreach ($f as $ff) {
					$ffs[] = "'" . $ff . "' 1";
				}
			}

			if (isset($bilp['OTLtags']['FFPlus']) && $bilp['OTLtags']['FFPlus']) { // May contain numeric value e.g. salt4
				$f = preg_split('/\s+/', trim($bilp['OTLtags']['FFPlus']));
				foreach ($f as $ff) {
					if (strlen($ff) > 4) {
						$ffs[] = "'" . substr($ff, 0, 4) . "' " . substr($ff, 4);
					} else {
						$ffs[] = "'" . $ff . "' 1";
					}
				}
			}

			$p['FONT-FEATURE-SETTINGS'] = implode(', ', $ffs);
		}
	}

	/**
	 * Preview block-level CSS without creating the block.
	 *
	 * Looks ahead to determine what CSS would be applied to a block element
	 * without actually creating it. Used for planning layout and spacing.
	 *
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes array
	 * @return array CSS properties that would be applied
	 */
	function PreviewBlockCSS($tag, $attr)
	{
		// Looks ahead from current block level to a new level
		$p = [];

		$oldcascadeCSS = $this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'];
		$classes = [];
		if (isset($attr['CLASS'])) {
			$classes = array_map(function ($combination) {
				return join('.', $combination);
			}, Arrays::allUniqueSortedCombinations(preg_split('/\s+/', $attr['CLASS'])));
		}

		// DEFAULT for this TAG set in DefaultCSS
		if (isset($this->mpdf->defaultCSS[$tag])) {
			$zp = $this->fixCSS($this->mpdf->defaultCSS[$tag]);
			if (is_array($zp)) {
				$p = array_merge($zp, $p);
			} // Inherited overwrites default
		}

		// STYLESHEET TAG e.g. h1  p  div  table
		if (isset($this->CSS[$tag])) {
			$zp = $this->CSS[$tag];
			if (is_array($zp)) {
				$p = array_merge($p, $zp);
			}
		}

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			$zp = [];
			if (isset($this->CSS['CLASS>>' . $class])) {
				$zp = $this->CSS['CLASS>>' . $class];
			}
			if (is_array($zp)) {
				$p = array_merge($p, $zp);
			}
		}

		// STYLESHEET ID e.g. #smallone{}  #redletter{}
		if (isset($attr['ID']) && isset($this->CSS['ID>>' . $attr['ID']])) {
			$zp = $this->CSS['ID>>' . $attr['ID']];
			if (is_array($zp)) {
				$p = array_merge($p, $zp);
			}
		}

		// STYLESHEET CLASS e.g. p.smallone{}  div.redletter{}
		foreach ($classes as $class) {
			$zp = [];
			if (isset($this->CSS[$tag . '>>CLASS>>' . $class])) {
				$zp = $this->CSS[$tag . '>>CLASS>>' . $class];
			}
			if (is_array($zp)) {
				$p = array_merge($p, $zp);
			}
		}

		// STYLESHEET CLASS e.g. p#smallone{}  div#redletter{}
		if (isset($attr['ID']) && isset($this->CSS[$tag . '>>ID>>' . $attr['ID']])) {
			$zp = $this->CSS[$tag . '>>ID>>' . $attr['ID']];
			if (is_array($zp)) {
				$p = array_merge($p, $zp);
			}
		}

		// STYLESHEET TAG e.g. div h1    div p
		$this->_set_mergedCSS($oldcascadeCSS[$tag], $p);
		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			$this->_set_mergedCSS($oldcascadeCSS['CLASS>>' . $class], $p);
		}

		// STYLESHEET CLASS e.g. #smallone{}  #redletter{}
		if (isset($attr['ID'])) {
			$this->_set_mergedCSS($oldcascadeCSS['ID>>' . $attr['ID']], $p);
		}

		// STYLESHEET CLASS e.g. div.smallone{}  p.redletter{}
		foreach ($classes as $class) {
			$this->_set_mergedCSS($oldcascadeCSS[$tag . '>>CLASS>>' . $class], $p);
		}

		// STYLESHEET CLASS e.g. div#smallone{}  p#redletter{}
		if (isset($attr['ID'])) {
			$this->_set_mergedCSS($oldcascadeCSS[$tag . '>>ID>>' . $attr['ID']], $p);
		}

		// INLINE STYLE e.g. style="CSS:property"
		if (isset($attr['STYLE'])) {
			$zp = $this->readInlineCSS($attr['STYLE']);
			if (is_array($zp)) {
				$p = array_merge($p, $zp);
			}
		}

		return $p;
	}

	/**
	 * Evaluate nth-child CSS selector.
	 *
	 * Determines if a given element index matches an nth-child selector formula.
	 * Supports formulas like "2n+1", "odd", "even", or specific numbers.
	 *
	 * @param array $f Formula components from preg_match
	 * @param int $c Current element index (0-based)
	 * @return bool True if element matches the nth-child selector
	 */
	function _nthchild($f, $c)
	{
		// $f is formula e.g. 2N+1 split into a preg_match array
		// $c is the comparator value e.g row or column number
		$c += 1;
		$select = false;

		$f_count = count($f);
		if ($f[0] === 'ODD') {
			$a = 2;
			$b = 1;
		} elseif ($f[0] === 'EVEN') {
			$a = 2;
			$b = 0;
		} elseif ($f_count === 2) {
			$a = 0;
			$b = $f[1] + 0;
		} // e.g. (+6)
		elseif ($f_count === 3) {  // e.g. (2N)
			if ($f[2] == '') {
				$a = 1;
			} elseif ($f[2] == '-') {
				$a = -1;
			} else {
				$a = $f[2] + 0;
			}
			$b = 0;
		} elseif ($f_count === 4) {  // e.g. (2N+6)
			if ($f[2] == '') {
				$a = 1;
			} elseif ($f[2] == '-') {
				$a = -1;
			} else {
				$a = $f[2] + 0;
			}
			$b = $f[3] + 0;
		} else {
			return false;
		}
		if ($a > 0) {
			if (((($c % $a) - $b) % $a) === 0 && $c >= $b) {
				$select = true;
			}
		} elseif ($a == 0) {
			if ($c == $b) {
				$select = true;
			}
		} else {  // if ($a<0)
			if (((($c % $a) - $b) % $a) === 0 && $c <= $b) {
				$select = true;
			}
		}
		return $select;
	}

	/**
	 * Merge table cascading CSS.
	 *
	 * Handles inheritance and cascading of CSS properties for tables.
	 *
	 * @param string $inherit Inheritance type (TOPTABLE, TABLE, BLOCK)
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes
	 * @param array $classes Array of class names
	 * @return void
	 */
	protected function mergeTableCascadingCSS($inherit, $tag, $attr, $classes)
	{
		if (! in_array($inherit, [ 'TOPTABLE', 'TABLE' ], true)) {
			return;
		}

		// $tag = TABLE
		if ($inherit === 'TOPTABLE') {
			// Save Cascading CSS e.g. "div.topic p" at this block level
			if (isset($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'])) {
				$this->tablecascadeCSS[0] = $this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'];
			} else {
				$this->tablecascadeCSS[0] = $this->cascadeCSS;
			}
		}

		// Set Inherited properties
		// Cascade everything from last level that is not an actual property, or defined by current tag/attributes
		if (isset($this->tablecascadeCSS[$this->tbCSSlvl - 1]) && is_array($this->tablecascadeCSS[$this->tbCSSlvl - 1])) {
			foreach ($this->tablecascadeCSS[$this->tbCSSlvl - 1] as $k => $v) {
				$this->tablecascadeCSS[$this->tbCSSlvl][$k] = $v;
			}
		}

		$this->_mergeFullCSS(
			$this->cascadeCSS,
			$this->tablecascadeCSS[$this->tbCSSlvl],
			$tag,
			$classes,
			$attr['ID'],
			$attr['LANG']
		);

		// Cascading forward CSS e.g. "table.topic td" for this table in $this->tablecascadeCSS
		if (isset($this->tablecascadeCSS[$this->tbCSSlvl - 1])) {
			$this->_mergeFullCSS(
				$this->tablecascadeCSS[$this->tbCSSlvl - 1],
				$this->tablecascadeCSS[$this->tbCSSlvl],
				$tag,
				$classes,
				$attr['ID'],
				$attr['LANG']
			);
		}
	}

	/**
	 * Merge block cascading CSS.
	 *
	 * Handles inheritance and cascading of CSS properties for block elements.
	 *
	 * @param string $inherit Inheritance type (TOPTABLE, TABLE, BLOCK)
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes
	 * @param array $classes Array of class names
	 * @param array $p Current CSS properties (passed by reference)
	 * @return void
	 */
	protected function mergeBlockCascadingCSS($inherit, $tag, $attr, $classes, &$p)
	{
		if ($inherit !== 'BLOCK') {
			return;
		}

		$currentBlock = isset($this->mpdf->blk[$this->mpdf->blklvl]) ? $this->mpdf->blk[$this->mpdf->blklvl] : [];
		$currentBlockHasCascade = isset($currentBlock['cascadeCSS']) && is_array($currentBlock['cascadeCSS']);
		$currentBlock['cascadeCSS'] = $currentBlockHasCascade ? $currentBlock['cascadeCSS'] : [];

		$previousBlock = isset($this->mpdf->blk[$this->mpdf->blklvl - 1]) ? $this->mpdf->blk[$this->mpdf->blklvl - 1] : [];
		$previousBlockHasCascade = isset($previousBlock['cascadeCSS']) && is_array($previousBlock['cascadeCSS']);
		$previousBlock['cascadeCSS'] = $previousBlockHasCascade ? $previousBlock['cascadeCSS'] : [];

		foreach ($previousBlock['cascadeCSS'] as $k => $v) {
			$currentBlock['cascadeCSS'][$k] = $v;
		}

		// Save Cascading CSS e.g. "div.topic p" at this block level
		$this->_mergeFullCSS(
			$this->cascadeCSS,
			$currentBlock['cascadeCSS'],
			$tag,
			$classes,
			$attr['ID'],
			$attr['LANG']
		);

		// Cascading forward CSS
		$this->_mergeFullCSS(
			$previousBlock['cascadeCSS'],
			$currentBlock['cascadeCSS'],
			$tag,
			$classes,
			$attr['ID'],
			$attr['LANG']
		);

		// Set the new block info
		$this->mpdf->blk[$this->mpdf->blklvl] = $currentBlock;

		// Block properties which are inherited
		if (!empty($previousBlock['margin_collapse'])) {
			$p['MARGIN-COLLAPSE'] = 'COLLAPSE';
		}

		// custom tag, but follows CSS principle that border-collapse is inherited
		if (!empty($previousBlock['line_height'])) {
			$p['LINE-HEIGHT'] = $previousBlock['line_height'];
		}

		// mPDF 6
		if (!empty($previousBlock['line_stacking_strategy'])) {
			$p['LINE-STACKING-STRATEGY'] = $previousBlock['line_stacking_strategy'];
		}

		if (!empty($previousBlock['line_stacking_shift'])) {
			$p['LINE-STACKING-SHIFT'] = $previousBlock['line_stacking_shift'];
		}

		if (!empty($previousBlock['direction'])) {
			$p['DIRECTION'] = $previousBlock['direction'];
		}

		// mPDF 6  Lists
		if ($tag === 'LI' && !empty($previousBlock['list_style_type'])) {
			$p['LIST-STYLE-TYPE'] = $previousBlock['list_style_type'];
		}

		if (!empty($previousBlock['list_style_image'])) {
			$p['LIST-STYLE-IMAGE'] = $previousBlock['list_style_image'];
		}

		if (!empty($previousBlock['list_style_position'])) {
			$p['LIST-STYLE-POSITION'] = $previousBlock['list_style_position'];
		}

		if (!empty($previousBlock['align'])) {
			switch ($previousBlock['align']) {
				case 'L':
					$p['TEXT-ALIGN'] = 'left';
					break;

				case 'J':
					$p['TEXT-ALIGN'] = 'justify';
					break;

				case 'R':
					$p['TEXT-ALIGN'] = 'right';
					break;

				case 'C':
					$p['TEXT-ALIGN'] = 'center';
					break;
			}
		}

		if (!empty($previousBlock['bgcolorarray']) && ($this->mpdf->ColActive || $this->mpdf->keep_block_together)) {
			// Doesn't officially inherit, but default value is transparent (?=inherited)
			$cor = $previousBlock['bgcolorarray'];
			$p['BACKGROUND-COLOR'] = $this->colorConverter->colAtoString($cor);
		}

		if (isset($previousBlock['text_indent'])) {
			$p['TEXT-INDENT'] = $previousBlock['text_indent'];
		}

		if (isset($previousBlock['InlineProperties'])) {
			$this->inlinePropsToCSS($previousBlock['InlineProperties'], $p); // mPDF 5.7.1
		}
	}

	/**
	 * Merge inline HTML attributes e.g. .. ALIGN="CENTER"
	 *
	 * Converts HTML attributes to CSS properties.
	 *
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes
	 * @param array $p Current CSS properties (passed by reference)
	 * @return void
	 */
	protected function mergeInlineAttributes($tag, $attr, &$p)
	{
		if (!empty($attr['DIR'])) {
			$p['DIRECTION'] = $attr['DIR'];
		}

		if (!empty($attr['LANG'])) {
			$p['LANG'] = $attr['LANG'];
		}

		if (!empty($attr['COLOR'])) {
			$p['COLOR'] = $attr['COLOR'];
		}

		if ($tag !== 'INPUT') {
			if (!empty($attr['WIDTH'])) {
				$p['WIDTH'] = $attr['WIDTH'];
			}
			
			if (!empty($attr['HEIGHT'])) {
				$p['HEIGHT'] = $attr['HEIGHT'];
			}
		}

		if ($tag === 'FONT') {
			if (!empty($attr['FACE'])) {
				$p['FONT-FAMILY'] = $attr['FACE'];
			}
			
			$size = isset($attr['SIZE']) ? $attr['SIZE'] : '';
			if ($size === '+1') {
				$p['FONT-SIZE'] = '120%';
			} elseif ($size === '-1') {
				$p['FONT-SIZE'] = '86%';
			} elseif ($size === '1') {
				$p['FONT-SIZE'] = 'XX-SMALL';
			} elseif ($size == '2') {
				$p['FONT-SIZE'] = 'X-SMALL';
			} elseif ($size == '3') {
				$p['FONT-SIZE'] = 'SMALL';
			} elseif ($size == '4') {
				$p['FONT-SIZE'] = 'MEDIUM';
			} elseif ($size == '5') {
				$p['FONT-SIZE'] = 'LARGE';
			} elseif ($size == '6') {
				$p['FONT-SIZE'] = 'X-LARGE';
			} elseif ($size == '7') {
				$p['FONT-SIZE'] = 'XX-LARGE';
			}

		}

		if (!empty($attr['VALIGN'])) {
			$p['VERTICAL-ALIGN'] = $attr['VALIGN'];
		}

		if (!empty($attr['VSPACE'])) {
			$p['MARGIN-TOP'] = $attr['VSPACE'];
			$p['MARGIN-BOTTOM'] = $attr['VSPACE'];
		}

		if (!empty($attr['HSPACE'])) {
			$p['MARGIN-LEFT'] = $attr['HSPACE'];
			$p['MARGIN-RIGHT'] = $attr['HSPACE'];
		}
	}

	/**
	 * Process background related CSS properties.
	 *
	 * Handles BACKGROUND, BACKGROUND-IMAGE, BACKGROUND-REPEAT, and BACKGROUND-POSITION.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @param array $newprop Target properties array (passed by reference)
	 * @return void
	 */
	protected function processBackgroundProperty($k, $v, &$newprop)
	{
		if ($k === 'BACKGROUND') {
			$bg = $this->parseCSSbackground($v);
			if ($bg['c']) {
				$newprop['BACKGROUND-COLOR'] = $bg['c'];
			} else {
				$newprop['BACKGROUND-COLOR'] = 'transparent';
			}
			if ($bg['i']) {
				$newprop['BACKGROUND-IMAGE'] = $bg['i'];
				if ($bg['r']) {
					$newprop['BACKGROUND-REPEAT'] = $bg['r'];
				}
				if ($bg['p']) {
					$newprop['BACKGROUND-POSITION'] = $bg['p'];
				}
			} else {
				$newprop['BACKGROUND-IMAGE'] = '';
			}
		} elseif ($k === 'BACKGROUND-IMAGE') {
			if (preg_match('/(-moz-)*(repeating-)*(linear|radial)-gradient\(.*\)/i', $v, $m)) {
				$newprop['BACKGROUND-IMAGE'] = $m[0];
				return;
			}
			if (preg_match('/url\([\'\"]{0,1}(.*?)[\'\"]{0,1}\)/i', $v, $m)) {
				$newprop['BACKGROUND-IMAGE'] = $m[1];
			} elseif (strtolower($v) === 'none') {
				$newprop['BACKGROUND-IMAGE'] = '';
			}
		} elseif ($k === 'BACKGROUND-REPEAT') {
			if (preg_match('/(repeat-x|repeat-y|no-repeat|repeat)/i', $v, $m)) {
				$newprop['BACKGROUND-REPEAT'] = strtolower($m[1]);
			}
		} elseif ($k === 'BACKGROUND-POSITION') {
			$s = $v;
			$bits = preg_split('/\s+/', trim($s));
			$normalizedPosition = $this->normalizeBackgroundPosition($bits);
			if ($normalizedPosition !== false) {
				$newprop['BACKGROUND-POSITION'] = $normalizedPosition;
			}
		}
	}

	/**
	 * Process border radius CSS properties.
	 *
	 * Handles BORDER-RADIUS and individual corner radii.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @param array $newprop Target properties array (passed by reference)
	 * @return void
	 */
	protected function processBorderRadiusProperty($k, $v, &$newprop)
	{
		$tmp = $this->border_radius_expand($v, $k);

		if (isset($tmp['TL-H'])) {
			$newprop['BORDER-TOP-LEFT-RADIUS-H'] = $tmp['TL-H'];
		}
		if (isset($tmp['TL-V'])) {
			$newprop['BORDER-TOP-LEFT-RADIUS-V'] = $tmp['TL-V'];
		}
		if (isset($tmp['TR-H'])) {
			$newprop['BORDER-TOP-RIGHT-RADIUS-H'] = $tmp['TR-H'];
		}
		if (isset($tmp['TR-V'])) {
			$newprop['BORDER-TOP-RIGHT-RADIUS-V'] = $tmp['TR-V'];
		}
		if (isset($tmp['BL-H'])) {
			$newprop['BORDER-BOTTOM-LEFT-RADIUS-H'] = $tmp['BL-H'];
		}
		if (isset($tmp['BL-V'])) {
			$newprop['BORDER-BOTTOM-LEFT-RADIUS-V'] = $tmp['BL-V'];
		}
		if (isset($tmp['BR-H'])) {
			$newprop['BORDER-BOTTOM-RIGHT-RADIUS-H'] = $tmp['BR-H'];
		}
		if (isset($tmp['BR-V'])) {
			$newprop['BORDER-BOTTOM-RIGHT-RADIUS-V'] = $tmp['BR-V'];
		}
	}

	/**
	 * Process text outline CSS properties.
	 *
	 * Handles TEXT-OUTLINE shorthand.
	 *
	 * @param string $v Property value
	 * @param array $newprop Target properties array (passed by reference)
	 * @return void
	 */
	protected function processTextOutlineProperty($v, &$newprop)
	{
		$prop = preg_split('/\s+/', trim($v));

		if (strtolower(trim($v)) === 'none') {
			$newprop['TEXT-OUTLINE'] = 'none';
		} elseif (count($prop) == 2) {
			$newprop['TEXT-OUTLINE-WIDTH'] = $prop[0];
			$newprop['TEXT-OUTLINE-COLOR'] = $prop[1];
		} elseif (count($prop) == 3) {
			$newprop['TEXT-OUTLINE-WIDTH'] = $prop[0];
			$newprop['TEXT-OUTLINE-COLOR'] = $prop[2];
		}
	}

	/**
	 * Process page size CSS properties.
	 *
	 * Handles SIZE and SHEET-SIZE properties.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @param array $newprop Target properties array (passed by reference)
	 * @return void
	 */
	protected function processPageSizeProperty($k, $v, &$newprop)
	{
		$prop = preg_split('/\s+/', trim($v));

		if ($k === 'SIZE') {
			if (preg_match('/(auto|portrait|landscape)/', $prop[0])) {
				$newprop['SIZE'] = strtoupper($prop[0]);
			} elseif (count($prop) == 1) {
				$newprop['SIZE']['W'] = $this->sizeConverter->convert($prop[0]);
				$newprop['SIZE']['H'] = $this->sizeConverter->convert($prop[0]);
			} elseif (count($prop) == 2) {
				$newprop['SIZE']['W'] = $this->sizeConverter->convert($prop[0]);
				$newprop['SIZE']['H'] = $this->sizeConverter->convert($prop[1]);
			}
		} elseif ($k === 'SHEET-SIZE') {
			if (count($prop) == 2) {
				$newprop['SHEET-SIZE'] = [$this->sizeConverter->convert($prop[0]), $this->sizeConverter->convert($prop[1])];
			} else {
				if (preg_match('/([0-9a-zA-Z]*)-L/i', $v, $m)) { // e.g. A4-L = A$ landscape
					$ft = PageFormat::getSizeFromName($m[1]);
					$format = [$ft[1], $ft[0]];
				} else {
					$format = PageFormat::getSizeFromName($v);
				}
				if ($format) {
					$newprop['SHEET-SIZE'] = [$format[0] / Mpdf::SCALE, $format[1] / Mpdf::SCALE];
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
	 * @param array $newprop Target properties array (passed by reference)
	 * @return void
	 */
	protected function processImageOrientationProperty($v, &$newprop)
	{
		if (preg_match('/([\-]*[0-9\.]+)(deg|grad|rad)/i', $v, $m)) {
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

			$newprop['IMAGE-ORIENTATION'] = $angle;
		}
	}

	/**
	 * Process text align CSS properties.
	 *
	 * Handles TEXT-ALIGN property including decimal alignment.
	 *
	 * @param string $k Property name
	 * @param string $v Property value
	 * @param array $newprop Target properties array (passed by reference)
	 * @return void
	 */
	protected function processTextAlignProperty($k, $v, &$newprop)
	{
		if (preg_match('/["\'](.){1}["\']/i', $v, $m)) {
			$d = array_search($m[1], $this->mpdf->decimal_align);

			if ($d !== false) {
				$newprop['TEXT-ALIGN'] = $d;
			}
			if (preg_match('/(center|left|right)/i', $v, $m)) {
				$newprop['TEXT-ALIGN'] .= strtoupper(substr($m[1], 0, 1));
			} else {
				$newprop['TEXT-ALIGN'] .= 'R';
			} // default = R

		} elseif (preg_match('/["\'](\\\[a-fA-F0-9]{1,6})["\']/i', $v, $m)) {
			$utf8 = UtfString::codeHex2utf(substr($m[1], 1, 6));
			$d = array_search($utf8, $this->mpdf->decimal_align);

			if ($d !== false) {
				$newprop['TEXT-ALIGN'] = $d;
			}

			if (preg_match('/(center|left|right)/i', $v, $m)) {
				$newprop['TEXT-ALIGN'] .= strtoupper(substr($m[1], 0, 1));
			} else {
				$newprop['TEXT-ALIGN'] .= 'R';
			} // default = R

		} else {
			$newprop[$k] = $v;
		}
	}

	/**
	 * Process list style CSS properties.
	 *
	 * Handles LIST-STYLE property.
	 *
	 * @param string $v Property value
	 * @param array $newprop Target properties array (passed by reference)
	 * @return void
	 */
	protected function processListStyleProperty($v, &$newprop)
	{
		if (preg_match('/none/i', $v, $m)) {
			$newprop['LIST-STYLE-TYPE'] = 'none';
			$newprop['LIST-STYLE-IMAGE'] = 'none';
		}

		if (preg_match('/(lower-roman|upper-roman|lower-latin|lower-alpha|upper-latin|upper-alpha|decimal|disc|circle|square|arabic-indic|bengali|devanagari|gujarati|gurmukhi|kannada|malayalam|oriya|persian|tamil|telugu|thai|urdu|cambodian|khmer|lao|cjk-decimal|hebrew)/i', $v, $m)) {
			$newprop['LIST-STYLE-TYPE'] = strtolower(trim($m[1]));
		} elseif (preg_match('/U\+([a-fA-F0-9]+)/i', $v, $m)) {
			$newprop['LIST-STYLE-TYPE'] = strtolower(trim($m[1]));
		}

		if (preg_match('/url\([\'\"]{0,1}(.*?)[\'\"]{0,1}\)/i', $v, $m)) {
			$newprop['LIST-STYLE-IMAGE'] = strtolower(trim($m[1]));
		}
	}

	/**
	 * Merge stylesheet selectors.
	 *
	 * Applies CSS rules from stylesheets based on tag, class, ID, and other selectors.
	 *
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes
	 * @param array $classes Array of class names
	 * @param array $p Current CSS properties (passed by reference)
	 * @param string $shortlang Short language code (e.g. 'en' from 'en-GB')
	 * @return void
	 */
	protected function mergeStylesheetSelectors($tag, $attr, $classes, &$p, $shortlang)
	{
		// STYLESHEET TAG e.g. h1  p  div  table
		if (!empty($this->CSS[$tag])) {
			$zp = $this->CSS[$tag];
			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			} // *TABLES*

			if (is_array($zp)) {
				$p = array_merge($p, $zp);
				$this->_mergeBorders($p, $zp);
			}
		}

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			$zp = [];
			if (!empty($this->CSS['CLASS>>' . $class])) {
				$zp = $this->CSS['CLASS>>' . $class];
			}

			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			}

			if (is_array($zp)) {
				$p = array_merge($p, $zp);
				$this->_mergeBorders($p, $zp);
			}
		}

		// STYLESHEET nth-child SELECTOR e.g. tr:nth-child(odd)  td:nth-child(2n+1)
		if ($tag === 'TR' || $tag === 'TD' || $tag === 'TH') {
			foreach ($this->CSS as $k => $val) {
				if (preg_match('/' . $tag . '>>SELECTORNTHCHILD>>(.*)/', $k, $m)) {
					$select = false;
					if ($tag === 'TR') {
						$row = $this->mpdf->row;
						$thnr = (isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_thead']) ? count($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_thead']) : 0);
						$tfnr = (isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_tfoot']) ? count($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['is_tfoot']) : 0);
						if ($this->mpdf->tabletfoot) {
							$row -= $thnr;
						} elseif (!$this->mpdf->tablethead) {
							$row -= ($thnr + $tfnr);
						}

						if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
							$select = $this->_nthchild($a, $row);
						}
					} elseif ($tag === 'TD' || $tag === 'TH') {
						if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
							$select = $this->_nthchild($a, $this->mpdf->col);
						}
					}

					if ($select) {
						$zp = $this->CSS[$tag . '>>SELECTORNTHCHILD>>' . $m[1]];
						if ($tag === 'TD' || $tag === 'TH') {
							$this->setBorderDominance($zp, 9);
						}

						if (is_array($zp)) {
							$p = array_merge($p, $zp);
							$this->_mergeBorders($p, $zp);
						}
					}
				}
			}
		}

		/* -- END TABLES -- */

		//===============================================
		// STYLESHEET LANG e.g. [lang=fr]{} or :lang(fr)
		if (isset($attr['LANG'])) {
			if (!empty($this->CSS['LANG>>' . $attr['LANG']])) {
				$zp = $this->CSS['LANG>>' . $attr['LANG']];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				} // *TABLES*

				if (is_array($zp)) {
					$p = array_merge($p, $zp);
					$this->_mergeBorders($p, $zp);
				}
			} elseif (!empty($this->CSS['LANG>>' . $shortlang])) {
				$zp = $this->CSS['LANG>>' . $shortlang];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				} // *TABLES*

				if (is_array($zp)) {
					$p = array_merge($p, $zp);
					$this->_mergeBorders($p, $zp);
				}
			}
		}

		//===============================================
		// STYLESHEET ID e.g. #smallone{}  #redletter{}
		if (!empty($attr['ID']) && !empty($this->CSS['ID>>' . $attr['ID']])) {
			$zp = $this->CSS['ID>>' . $attr['ID']];
			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			} // *TABLES*

			if (is_array($zp)) {
				$p = array_merge($p, $zp);
				$this->_mergeBorders($p, $zp);
			}
		}
	}

	/**
	 * Normalize file path for local file system access.
	 *
	 * Converts URLs to local file paths when the base path is local.
	 * Handles DOCUMENT_ROOT and relative paths.
	 *
	 * @param string $path File path or URL
	 * @return string Normalized path
	 */
	private function normalizePath($path)
	{
		if (!$this->mpdf->basepathIsLocal) {
			return $path;
		}

		$tr = parse_url($path);
		$lp = __FILE__;
		$ap = realpath($lp);
		$ap = str_replace("\\", '/', $ap);
		$docroot = substr($ap, 0, strpos($ap, $lp));

		// WriteHTML parses all paths to full URLs; may be local file name
		// DOCUMENT_ROOT is not returned on IIS
		if (!empty($tr['scheme']) && $tr['host'] && !empty($_SERVER['DOCUMENT_ROOT'])) {
			return $_SERVER['DOCUMENT_ROOT'] . $tr['path'];
		}

		if ($docroot) {
			return $docroot . $tr['path'];
		}

		return $path;
	}

}
