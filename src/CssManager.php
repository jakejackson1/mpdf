<?php

namespace Mpdf;

use Mpdf\Color\ColorConverter;
use Mpdf\Css\NormalizeProperties;
use Mpdf\Css\TextVars;
use Mpdf\Utils\Arrays;

class CssManager
{
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
	 * @var \Mpdf\Css\NormalizeProperties
	 */
	private $normalizeProperties;

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
	 * @var array
	 */
	protected $cssProperties = [];

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
	 * @param NormalizeProperties $normalizeProperties CSS property normalizer
	 */
	public function __construct(Mpdf $mpdf, Cache $cache, SizeConverter $sizeConverter, ColorConverter $colorConverter, AssetFetcher $assetFetcher, NormalizeProperties $normalizeProperties)
	{
		$this->mpdf = $mpdf;
		$this->cache = $cache;
		$this->sizeConverter = $sizeConverter;
		$this->assetFetcher = $assetFetcher;
		$this->colorConverter = $colorConverter;
		$this->normalizeProperties = $normalizeProperties;

		$this->tablecascadeCSS = [];
		$this->CSS = [];
		$this->cascadeCSS = [];
		$this->tbCSSlvl = 0;
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
				$CSSstr .= $this->processExternalCssImports($CSSextblock, $path, $CSSext, $match);
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
			$this->processCssString($CSSstr);
		}

		// Remove CSS (tags and content), if any
		$regexp = '/<style.*?>(.*?)<\/style>/si'; // it can be <style> or <style type="txt/css">
		$html = preg_replace($regexp, '', $html);

		return $html;
	}

	/**
	 * @param string $cssContent
	 * @param string $path
	 * @param array $cssExt
	 * @param int $match
	 * @return string
	 */
	protected function processExternalCssImports($cssContent, $path, &$cssExt, &$match)
	{
		$cssBasePath = preg_replace('/\/[^\/]*$/', '', $path) . '/';
		$cssStr = '';

		// look for embedded @import stylesheets in other stylesheets
		// and fix url paths (including background-images) relative to stylesheet
		$regexpem = '/@import url\([\'\"]{0,1}(.*?\.css(\?\S+)?)[\'\"]{0,1}\)/si';
		if (preg_match_all($regexpem, $cssContent, $cxtem)) {
			foreach ($cxtem[1] as $cxtembedded) {
				// path is relative to original stylesheet!!
				$this->mpdf->GetFullPath($cxtembedded, $cssBasePath);
				$match++;
				$cssExt[] = $cxtembedded;
			}
		}

		$cssStr .= ' ' . $this->resolveBackgroundUrls($cssContent, $cssBasePath);

		return $cssStr;
	}

	/**
	 * @param string $cssStr
	 * @return void
	 */
	private function processCssString($cssStr)
	{
		preg_match_all('/(.*?)\{(.*?)\}/', $cssStr, $styles);
		$styles_count = count($styles[1]);
		for ($i = 0; $i < $styles_count; $i++) {
			$stylestr = trim($styles[2][$i]);
			$classproperties = $this->parseCssProperties($stylestr);
			$tagstr = strtoupper(trim($styles[1][$i]));
			$tagarr = explode(',', $tagstr);
			foreach ($tagarr as $tg) {
				$this->processCssSelector($tg, $classproperties);
			}
		}
	}

	/**
	 * Process a CSS selector.
	 *
	 * Delegates processing to specific methods based on the selector type
	 * (@page, simple, or cascaded).
	 *
	 * @param string $tg Selector string
	 * @param array $classproperties CSS properties
	 * @return void
	 */
	protected function processCssSelector($tg, $classproperties)
	{
		if (preg_match('/NTH-CHILD\((\s*(([\-+]?\d*)N(\s*[\-+]\s*\d+)?|[\-+]?\d+|ODD|EVEN)\s*)\)/', $tg, $m)) {
			$tg = preg_replace('/NTH-CHILD\(.*\)/', 'NTH-CHILD(' . str_replace(' ', '', $m[1]) . ')', $tg);
		}

		$tags = preg_split('/\s+/', trim($tg));
		$level = count($tags);
		if (trim($tags[0]) === '@PAGE') {
			$this->processPageSelector($tags, $classproperties);
		} elseif ($level === 1) {  // e.g. p or .class or #id or p.class or p#id
			$this->processSimpleSelector($tags, $classproperties);
		} else {
			$this->processCascadedSelector($tags, $classproperties);
		}
	}

	/**
	 * Process @PAGE selector.
	 *
	 * @param array $tags Selector tags array
	 * @param array $classproperties CSS properties
	 * @return void
	 */
	protected function processPageSelector($tags, $classproperties)
	{
		$level = count($tags);
		$t = '';
		$t2 = '';
		$t3 = '';

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
				$this->mpdf->mirrorMargins = true;
			}
		} elseif ($level === 2) {
			$tag = $t . '>>NAMED>>' . $t2;
		} elseif ($level === 3 && preg_match('/^[:](.*)$/', $t3, $m)) {
			$tag = $t . '>>NAMED>>' . $t2 . '>>PSEUDO>>' . $m[1];
			if ($m[1] === 'LEFT' || $m[1] === 'RIGHT') {
				$this->mpdf->mirrorMargins = true;
			}
		}

		if (isset($this->CSS[$tag]) && $tag) {
			$this->CSS[$tag] = $this->array_merge_recursive_unique($this->CSS[$tag], $classproperties);
		} elseif ($tag) {
			$this->CSS[$tag] = $classproperties;
		}
	}

	/**
	 * Process simple selector (depth 1).
	 *
	 * @param array $tags Selector tags array
	 * @param array $classproperties CSS properties
	 * @return void
	 */
	protected function processSimpleSelector($tags, $classproperties)
	{
		$t = isset($tags[0]) ? trim($tags[0]) : '';
		if (empty($t)) {
			return;
		}

		$tag = '';
		if (preg_match('/^[.](.*)$/', $t, $m)) {
			$classes = explode('.', $m[1]);
			sort($classes);
			$tag = 'CLASS>>' . implode('.', $classes);
		} elseif (preg_match('/^[#](.*)$/', $t, $m)) {
			$tag = 'ID>>' . $m[1];
		} elseif (preg_match('/^\[LANG=[\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\]$/', $t, $m)) {
			$tag = 'LANG>>' . strtolower($m[1]);
		} elseif (preg_match('/^:LANG\([\'\"]{0,1}([A-Z\-]{2,11})[\'\"]{0,1}\)$/', $t, $m)) { // mPDF 6  Special case for lang as attribute selector
			$tag = 'LANG>>' . strtolower($m[1]);
		} elseif (preg_match('/^(' . $this->mpdf->allowedCSStags . ')[.](.*)$/', $t, $m)) { // mPDF 6  Special case for lang as attribute selector
			$classes = explode('.', $m[2]);
			sort($classes);
			$tag = $m[1] . '>>CLASS>>' . implode('.', $classes);
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

	/**
	 * Process cascaded selector (depth > 1).
	 *
	 * @param array $tags Selector tags array
	 * @param array $classproperties CSS properties
	 * @return void
	 */
	protected function processCascadedSelector($tags, $classproperties)
	{
		$tmp = [];
		$level = count($tags);

		for ($n = 0; $n < $level; $n++) {
			$tag = '';
			$t = isset($tags[$n]) ? trim($tags[$n]) : '';
			if (empty($t)) {
				continue;
			}

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

			if (!$tag) {
				break;
			}

			$tmp[] = $tag;
		}

		if (!empty($tag)) {
			$x = &$this->cascadeCSS;
			foreach ($tmp as $tp) {
				$x = &$x[$tp];
			}

			$x = $this->array_merge_recursive_unique($x, $classproperties);
			$x['depth'] = $level;
		}
	}

	/**
	 * Parse CSS property string into an array.
	 *
	 * @param string $stylestr CSS style string (e.g. "color: red; font-size: 12px")
	 * @return array Associative array of CSS properties
	 */
	protected function parseCssProperties($stylestr)
	{
		$classproperties = [];
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
				$value = str_replace('%ZZ', ';', $value); // mPDF 5.7.4 URLs
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

		return $this->normalizeProperties->normalize($classproperties);
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
		if (!preg_match('/@media/', $cssStr)) {
			return $cssStr;
		}

		preg_match_all('/@media(.*?)\{(([^\{\}]*\{[^\{\}]*\})+)\s*\}/is', $cssStr, $m);
		$count_m = count($m[0]);
		for ($i = 0; $i < $count_m; $i++) {
			if ($this->mpdf->CSSselectMedia && !preg_match('/(' . trim($this->mpdf->CSSselectMedia) . '|all)/i', $m[1][$i])) {
				$cssStr = str_replace($m[0][$i], '', $cssStr);
			} else {
				$cssStr = str_replace($m[0][$i], ' ' . $m[2][$i] . ' ', $cssStr);
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

	/**
	 * Normalize background position values.
	 *
	 * Converts background position keywords (top, bottom, left, right, center)
	 * to percentage values and validates the format.
	 *
	 * @param array $bits Position components (1 or 2 values)
	 * @return string|false Normalized position string or false if invalid
	 */

	/**
	 * Parse inline CSS style attribute.
	 *
	 * Parses a CSS string from an HTML style attribute and returns
	 * an array of CSS properties.
	 *
	 * @param string $html CSS string from style attribute
	 * @return array Parsed CSS properties
	 */
	public function readInlineCSS($html)
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

			$values[$i] = str_replace('%ZZ', ';', $values[$i]); // mPDF 5.7.4 URLs
			$classproperties[strtoupper($properties[$i])] = trim($values[$i]);
		}

		return $this->normalizeProperties->normalize($classproperties);
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
			$col = preg_replace('/,\s/', '*', $x[0][$i]);
			$value = str_replace($x[0][$i], $col, $value);
		}

		return $value;
	}

	/**
	 * @param array $prop
	 * @return array
	 */
	protected function normalizeCssProperties($prop)
	{
		return $this->normalizeProperties->normalize($prop);
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
	public function setCSSboxshadow($value)
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
	public function setCSStextshadow($value)
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
	 * Merge CSS properties into target array.
	 *
	 * Internal method to merge CSS properties from source into target.
	 * Used for CSS cascading.
	 *
	 * @param array $property Source CSS properties
	 * @param array $target Target CSS properties (modified by reference)
	 * @return void
	 */
	protected function mergeCssProperties($property, &$target)
	{
		if (empty($property)) {
			return;
		}

		$target = $target ? $this->array_merge_recursive_unique($target, $property) : $property;
	}

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
	public function array_merge_recursive_unique($array1, $array2)
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
	protected function mergeNthChildCss($p, &$t, $tag)
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
						$select = $this->matchesNthChild($a, $row);
					}
				} elseif ($tag === 'TD' || $tag === 'TH') {
					if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
						$select = $this->matchesNthChild($a, $this->mpdf->col);
					}
				}

				if ($select) {
					$this->mergeCssProperties($p[$tag . '>>SELECTORNTHCHILD>>' . $m[1]], $t);
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
	protected function mergeFullCssRules($p, &$t, $tag, $classes, $id, $lang)
	{
		// mPDF 6
		if (isset($p[$tag])) {
			$this->mergeCssProperties($p[$tag], $t);
		}

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			if (isset($p['CLASS>>' . $class])) {
				$this->mergeCssProperties($p['CLASS>>' . $class], $t);
			}
		}

		// STYLESHEET nth-child SELECTOR e.g. tr:nth-child(odd)  td:nth-child(2n+1)
		$this->mergeNthChildCss($p, $t, $tag);

		// STYLESHEET CLASS e.g. [lang=fr]{} or :lang(fr)
		if (isset($lang) && isset($p['LANG>>' . $lang])) {
			$this->mergeCssProperties($p['LANG>>' . $lang], $t);
		}

		// STYLESHEET CLASS e.g. #smallone{}  #redletter{}
		if (isset($id) && isset($p['ID>>' . $id])) {
			$this->mergeCssProperties($p['ID>>' . $id], $t);
		}

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			if (isset($p[$tag . '>>CLASS>>' . $class])) {
				$this->mergeCssProperties($p[$tag . '>>CLASS>>' . $class], $t);
			}
		}

		// STYLESHEET CLASS e.g. [lang=fr]{} or :lang(fr)
		if (isset($lang) && isset($p[$tag . '>>LANG>>' . $lang])) {
			$this->mergeCssProperties($p[$tag . '>>LANG>>' . $lang], $t);
		}

		// STYLESHEET CLASS e.g. #smallone{}  #redletter{}
		if (isset($id) && isset($p[$tag . '>>ID>>' . $id])) {
			$this->mergeCssProperties($p[$tag . '>>ID>>' . $id], $t);
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
	protected function setBorderDominance($prop, $val)
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
	 * Merge CSS properties with existing properties.
	 *
	 * @param array $m Source CSS properties
	 * @param bool $d Use default strict mode
	 * @param bool|int $bd Border dominance level (or false)
	 * @return void
	 */
	protected function setMergedCss(&$m, $d = true, $bd = false)
	{
		if (!isset($m)) {
			return;
		}

		if ((isset($m['depth']) && $m['depth'] > 1) || $d == false) {  // include check for 'depth'
			if ($bd) {
				$this->setBorderDominance($m, $bd);
			} // *TABLES*

			if (is_array($m)) {
				$this->cssProperties = array_merge($this->cssProperties, $m);
				$this->mergeBorderProperties($m);
			}
		}
	}

	/**
	 * Merge border properties for a specific side.
	 *
	 * Helper method for mergeBorderProperties to handle merging of individual side properties
	 * (style, width, color) into the shorthand border property.
	 *
	 * @param string $side Side to merge (TOP, RIGHT, BOTTOM, LEFT)
	 * @param array $a Source border properties
	 * @return void
	 */
	protected function mergeSideBorder($side, $a)
	{
		// Merges $a['BORDER-TOP-STYLE'] to $this->cssProperties['BORDER-TOP'] etc.
		$defaults = [
			'WIDTH' => '0px',
			'STYLE' => 'none',
			'COLOR' => '#000000'
		];

		$borderKey = 'BORDER-' . $side;
		$currentBorder = isset($this->cssProperties[$borderKey]) ? trim($this->cssProperties[$borderKey]) : '';

		foreach (['STYLE', 'WIDTH', 'COLOR'] as $el) {
			$propertyKey = $borderKey . '-' . $el;
			if (!isset($a[$propertyKey])) {
				continue;
			}

			$value = trim($a[$propertyKey]);
			if ($currentBorder) {
				// Update existing border value
				if ($el === 'STYLE') {
					$this->cssProperties[$borderKey] = preg_replace('/(\S+)\s+(\S+)\s+(\S+)/', '\\1 ' . $value . ' \\3', $currentBorder);
				} elseif ($el === 'WIDTH') {
					$this->cssProperties[$borderKey] = preg_replace('/(\S+)\s+(\S+)\s+(\S+)/', $value . ' \\2 \\3', $currentBorder);
				} else { // COLOR
					$this->cssProperties[$borderKey] = preg_replace('/(\S+)\s+(\S+)\s+(\S+)/', '\\1 \\2 ' . $value, $currentBorder);
				}

				$currentBorder = $this->cssProperties[$borderKey]; // Update current border for next iteration
			} else {
				// Build new border from scratch with defaults
				if (!isset($borderParts)) {
					$borderParts = $defaults;
				}

				$borderParts[$el] = $value;
				$this->cssProperties[$borderKey] = $borderParts['WIDTH'] . ' ' . $borderParts['STYLE'] . ' ' . $borderParts['COLOR'];
				$currentBorder = $this->cssProperties[$borderKey];
			}
		}
	}

	/**
	 * Merge borders into CSS properties.
	 *
	 * @param array $a properties to merge
	 * @return void
	 */
	protected function mergeBorderProperties(&$a)
	{
		foreach (['TOP', 'RIGHT', 'BOTTOM', 'LEFT'] as $side) {
			$this->mergeSideBorder($side, $a);
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
	public function MergeCSS($inherit, $tag, $attr)
	{
		$this->cssProperties = [];

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

		$this->mergeTableCascadingCss($inherit, $tag, $attr, $classes);
		$this->mergeBlockCascadingCss($inherit, $tag, $attr, $classes);
		$this->mergeInlineAttributes($tag, $attr);
		$this->mergeDefaultCss($tag);
		$this->mergeTableSpecificCss($tag, $attr);
		$this->mergeStylesheetSelectors($tag, $attr, $classes, $shortlang);
		$this->mergeTagSpecificSelectors($tag, $attr, $classes, $shortlang);
		$this->mergeCascadedCss($inherit, $tag, $attr, $classes);
		$this->mergeInlineStyle($tag, $attr);

		return $this->cssProperties;
	}

	/**
	 * Merge tag specific selectors (Tag.Class, Tag#ID, etc.).
	 *
	 * @param string $tag HTML tag
	 * @param array $attr HTML attributes
	 * @param array $classes Array of class names
	 * @param string $shortlang Short language code (e.g. 'en')
	 * @return void
	 */
	protected function mergeTagSpecificSelectors($tag, $attr, $classes, $shortlang)
	{
		// STYLESHEET CLASS e.g. p.smallone{}  div.redletter{}
		foreach ($classes as $class) {
			$zp = [];
			if (!empty($this->CSS[$tag . '>>CLASS>>' . $class])) {
				$zp = $this->CSS[$tag . '>>CLASS>>' . $class];
			}

			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			}

			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
				$this->mergeBorderProperties($zp);
			}
		}

		// STYLESHEET LANG e.g. [lang=fr]{} or :lang(fr)
		if (isset($attr['LANG'])) {
			if (!empty($this->CSS[$tag . '>>LANG>>' . $attr['LANG']])) {
				$zp = $this->CSS[$tag . '>>LANG>>' . $attr['LANG']];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				}

				if (is_array($zp)) {
					$this->cssProperties = array_merge($this->cssProperties, $zp);
					$this->mergeBorderProperties($zp);
				}
			} elseif (!empty($this->CSS[$tag . '>>LANG>>' . $shortlang])) {
				$zp = $this->CSS[$tag . '>>LANG>>' . $shortlang];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				}

				if (is_array($zp)) {
					$this->cssProperties = array_merge($this->cssProperties, $zp);
					$this->mergeBorderProperties($zp);
				}
			}
		}

		// STYLESHEET CLASS e.g. p#smallone{}  div#redletter{}
		if (isset($attr['ID']) && !empty($this->CSS[$tag . '>>ID>>' . $attr['ID']])) {
			$zp = $this->CSS[$tag . '>>ID>>' . $attr['ID']];
			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			}

			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
				$this->mergeBorderProperties($zp);
			}
		}
	}

	/**
	 * Merge cascaded CSS properties (BLOCK, INLINE, TABLE).
	 *
	 * @param string $inherit Inheritance context
	 * @param string $tag HTML tag
	 * @param array $attr HTML attributes
	 * @param array $classes Array of class names
	 * @return void
	 */
	protected function mergeCascadedCss($inherit, $tag, $attr, $classes)
	{
		// Cascaded e.g. div.class p only works for block level
		if ($inherit === 'BLOCK' && !empty($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'])) {
			$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'][$tag]);
			foreach ($classes as $class) {
				$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS']['CLASS>>' . $class]);
			}

			$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS']['ID>>' . $attr['ID']]);
			foreach ($classes as $class) {
				$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'][$tag . '>>CLASS>>' . $class]);
			}

			$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl - 1]['cascadeCSS'][$tag . '>>ID>>' . $attr['ID']]);
		} elseif ($inherit === 'INLINE') {
			$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'][$tag]);
			foreach ($classes as $class) {
				$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS']['CLASS>>' . $class]);
			}

			$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS']['ID>>' . $attr['ID']]);
			foreach ($classes as $class) {
				$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'][$tag . '>>CLASS>>' . $class]);
			}

			$this->setMergedCss($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'][$tag . '>>ID>>' . $attr['ID']]);
		} elseif (!empty($this->tablecascadeCSS[$this->tbCSSlvl - 1]) && ($inherit === 'TOPTABLE' || $inherit === 'TABLE')) {
			// NB looks at $this->tablecascadeCSS-1 for cascading CSS

			// false, 9 = don't check for 'depth' and do set border dominance
			$this->setMergedCss($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag], false, 9);
			foreach ($classes as $class) {
				$this->setMergedCss($this->tablecascadeCSS[$this->tbCSSlvl - 1]['CLASS>>' . $class], false, 9);
			}

			// STYLESHEET nth-child SELECTOR e.g. tr:nth-child(odd)  td:nth-child(2n+1)
			if ($tag === 'TR' || $tag === 'TD' || $tag === 'TH') {
				foreach ($this->tablecascadeCSS[$this->tbCSSlvl - 1] as $k => $val) {
					if (!preg_match('/' . $tag . '>>SELECTORNTHCHILD>>(.*)/', $k, $m)) {
						continue;
					}
					$select = false;
					if ($tag === 'TR') {
						$row = $this->mpdf->row;
						$table = isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]) ? $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]] : [];
						$tableHeadCount = isset($table['is_thead']) ? count($table['is_thead']) : 0;
						$tableFootCount = isset($table['is_tfoot']) ? count($table['is_tfoot']) : 0;

						if ($this->mpdf->tabletfoot) {
							$row -= $tableHeadCount;
						} elseif (!$this->mpdf->tablethead) {
							$row -= ($tableHeadCount + $tableFootCount);
						}

						if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
							$select = $this->matchesNthChild($a, $row);
						}
					} elseif ($tag === 'TD' || $tag === 'TH') {
						if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
							$select = $this->matchesNthChild($a, $this->mpdf->col);
						}
					}

					if ($select) {
						$this->setMergedCss($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag . '>>SELECTORNTHCHILD>>' . $m[1]], false, 9);
					}
				}
			}

			$this->setMergedCss($this->tablecascadeCSS[$this->tbCSSlvl - 1]['ID>>' . $attr['ID']], false, 9);
			foreach ($classes as $class) {
				$this->setMergedCss($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag . '>>CLASS>>' . $class], false, 9);
			}

			$this->setMergedCss($this->tablecascadeCSS[$this->tbCSSlvl - 1][$tag . '>>ID>>' . $attr['ID']], false, 9);
		}
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
	protected function convertInlinePropertiesToCss($bilp)
	{
		if (isset($bilp['family']) && $bilp['family']) {
			$this->cssProperties['FONT-FAMILY'] = $bilp['family'];
		}

		if (isset($bilp['I']) && $bilp['I']) {
			$this->cssProperties['FONT-STYLE'] = 'italic';
		}

		if (isset($bilp['sizePt']) && $bilp['sizePt']) {
			$this->cssProperties['FONT-SIZE'] = $bilp['sizePt'] . 'pt';
		}

		if (isset($bilp['B']) && $bilp['B']) {
			$this->cssProperties['FONT-WEIGHT'] = 'bold';
		}

		if (isset($bilp['colorarray']) && $bilp['colorarray']) {
			$cor = $bilp['colorarray'];
			$this->cssProperties['COLOR'] = $this->colorConverter->colAtoString($cor);
		}

		if (isset($bilp['lSpacingCSS']) && $bilp['lSpacingCSS']) {
			$this->cssProperties['LETTER-SPACING'] = $bilp['lSpacingCSS'];
		}

		if (isset($bilp['wSpacingCSS']) && $bilp['wSpacingCSS']) {
			$this->cssProperties['WORD-SPACING'] = $bilp['wSpacingCSS'];
		}

		if (isset($bilp['textparam']) && $bilp['textparam']) {
			if (isset($bilp['textparam']['hyphens'])) {
				if ($bilp['textparam']['hyphens'] == 2) {
					$this->cssProperties['HYPHENS'] = 'none';
				}
				if ($bilp['textparam']['hyphens'] == 1) {
					$this->cssProperties['HYPHENS'] = 'auto';
				}
				if ($bilp['textparam']['hyphens'] == 0) {
					$this->cssProperties['HYPHENS'] = 'manual';
				}
			}

			if (isset($bilp['textparam']['outline-s']) && !$bilp['textparam']['outline-s']) {
				$this->cssProperties['TEXT-OUTLINE'] = 'none';
			}

			if (isset($bilp['textparam']['outline-COLOR']) && $bilp['textparam']['outline-COLOR']) {
				$this->cssProperties['TEXT-OUTLINE-COLOR'] = $this->colorConverter->colAtoString($bilp['textparam']['outline-COLOR']);
			}

			if (isset($bilp['textparam']['outline-WIDTH']) && $bilp['textparam']['outline-WIDTH']) {
				$this->cssProperties['TEXT-OUTLINE-WIDTH'] = $bilp['textparam']['outline-WIDTH'] . 'mm';
			}
		}

		if (isset($bilp['textvar']) && $bilp['textvar']) {
			// CSS says text-decoration is not inherited, but IE7 does??
			if ($bilp['textvar'] & TextVars::FD_LINETHROUGH) {
				if ($bilp['textvar'] & TextVars::FD_UNDERLINE) {
					$this->cssProperties['TEXT-DECORATION'] = 'underline line-through';
				} else {
					$this->cssProperties['TEXT-DECORATION'] = 'line-through';
				}
			} elseif ($bilp['textvar'] & TextVars::FD_UNDERLINE) {
				$this->cssProperties['TEXT-DECORATION'] = 'underline';
			} else {
				$this->cssProperties['TEXT-DECORATION'] = 'none';
			}

			if ($bilp['textvar'] & TextVars::FA_SUPERSCRIPT) {
				$this->cssProperties['VERTICAL-ALIGN'] = 'super';
			} elseif ($bilp['textvar'] & TextVars::FA_SUBSCRIPT) {
				$this->cssProperties['VERTICAL-ALIGN'] = 'sub';
			} else {
				$this->cssProperties['VERTICAL-ALIGN'] = 'baseline';
			}

			if ($bilp['textvar'] & TextVars::FT_CAPITALIZE) {
				$this->cssProperties['TEXT-TRANSFORM'] = 'capitalize';
			} elseif ($bilp['textvar'] & TextVars::FT_UPPERCASE) {
				$this->cssProperties['TEXT-TRANSFORM'] = 'uppercase';
			} elseif ($bilp['textvar'] & TextVars::FT_LOWERCASE) {
				$this->cssProperties['TEXT-TRANSFORM'] = 'lowercase';
			} else {
				$this->cssProperties['TEXT-TRANSFORM'] = 'none';
			}

			if ($bilp['textvar'] & TextVars::FC_KERNING) {
				$this->cssProperties['FONT-KERNING'] = 'normal';
			} // ignore 'auto' as default already applied
			else {
				$this->cssProperties['FONT-KERNING'] = 'none';
			}

			if ($bilp['textvar'] & TextVars::FA_SUPERSCRIPT) {
				$this->cssProperties['FONT-VARIANT-POSITION'] = 'super';
			}
			elseif ($bilp['textvar'] & TextVars::FA_SUBSCRIPT) {
				$this->cssProperties['FONT-VARIANT-POSITION'] = 'sub';
			} else {
				$this->cssProperties['FONT-VARIANT-POSITION'] = 'normal';
			}

			if ($bilp['textvar'] & TextVars::FC_SMALLCAPS) {
				$this->cssProperties['FONT-VARIANT-CAPS'] = 'small-caps';
			}
		}

		if (isset($bilp['fontLanguageOverride'])) {
			if ($bilp['fontLanguageOverride']) {
				$this->cssProperties['FONT-LANGUAGE-OVERRIDE'] = $bilp['fontLanguageOverride'];
			} else {
				$this->cssProperties['FONT-LANGUAGE-OVERRIDE'] = 'normal';
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

			$this->cssProperties['FONT-FEATURE-SETTINGS'] = implode(', ', $ffs);
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
	public function PreviewBlockCSS($tag, $attr)
	{
		// Looks ahead from current block level to a new level
		$oldCssProperties = $this->cssProperties;
		$this->cssProperties = [];

		$oldcascadeCSS = $this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'];
		$classes = [];
		if (isset($attr['CLASS'])) {
			$classes = array_map(function ($combination) {
				return join('.', $combination);
			}, Arrays::allUniqueSortedCombinations(preg_split('/\s+/', $attr['CLASS'])));
		}

		// DEFAULT for this TAG set in DefaultCSS
		if (isset($this->mpdf->defaultCSS[$tag])) {
			$zp = $this->normalizeProperties->normalize($this->mpdf->defaultCSS[$tag]);
			if (is_array($zp)) {
				$this->cssProperties = array_merge($zp, $this->cssProperties);
			} // Inherited overwrites default
		}

		// STYLESHEET TAG e.g. h1  p  div  table
		if (isset($this->CSS[$tag])) {
			$zp = $this->CSS[$tag];
			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
			}
		}

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			$zp = [];
			if (isset($this->CSS['CLASS>>' . $class])) {
				$zp = $this->CSS['CLASS>>' . $class];
			}

			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
			}
		}

		// STYLESHEET ID e.g. #smallone{}  #redletter{}
		if (isset($attr['ID']) && isset($this->CSS['ID>>' . $attr['ID']])) {
			$zp = $this->CSS['ID>>' . $attr['ID']];
			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
			}
		}

		// STYLESHEET CLASS e.g. p.smallone{}  div.redletter{}
		foreach ($classes as $class) {
			$zp = [];
			if (isset($this->CSS[$tag . '>>CLASS>>' . $class])) {
				$zp = $this->CSS[$tag . '>>CLASS>>' . $class];
			}

			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
			}
		}

		// STYLESHEET CLASS e.g. p#smallone{}  div#redletter{}
		if (isset($attr['ID']) && isset($this->CSS[$tag . '>>ID>>' . $attr['ID']])) {
			$zp = $this->CSS[$tag . '>>ID>>' . $attr['ID']];
			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
			}
		}

		// STYLESHEET TAG e.g. div h1    div p
		$this->setMergedCss($oldcascadeCSS[$tag]);

		// STYLESHEET CLASS e.g. .smallone{}  .redletter{}
		foreach ($classes as $class) {
			$this->setMergedCss($oldcascadeCSS['CLASS>>' . $class]);
		}

		// STYLESHEET CLASS e.g. #smallone{}  #redletter{}
		if (isset($attr['ID'])) {
			$this->setMergedCss($oldcascadeCSS['ID>>' . $attr['ID']]);
		}

		// STYLESHEET CLASS e.g. div.smallone{}  p.redletter{}
		foreach ($classes as $class) {
			$this->setMergedCss($oldcascadeCSS[$tag . '>>CLASS>>' . $class]);
		}

		// STYLESHEET CLASS e.g. div#smallone{}  p#redletter{}
		if (isset($attr['ID'])) {
			$this->setMergedCss($oldcascadeCSS[$tag . '>>ID>>' . $attr['ID']]);
		}

		// INLINE STYLE e.g. style="CSS:property"
		if (isset($attr['STYLE'])) {
			$zp = $this->readInlineCSS($attr['STYLE']);
			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
			}
		}

		$p = $this->cssProperties;
		$this->cssProperties = $oldCssProperties;

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
	protected function matchesNthChild($f, $c)
	{
		// $f is formula e.g. 2N+1 split into a preg_match array
		// $c is the comparator value e.g row or column number
		++$c;
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
			if ($f[2] === '') {
				$a = 1;
			} elseif ($f[2] === '-') {
				$a = -1;
			} else {
				$a = $f[2] + 0;
			}
			$b = 0;
		} elseif ($f_count === 4) {  // e.g. (2N+6)
			if ($f[2] === '') {
				$a = 1;
			} elseif ($f[2] === '-') {
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
		} elseif ($a === 0) {
			if ($c === $b) {
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
	protected function mergeTableCascadingCss($inherit, $tag, $attr, $classes)
	{
		if (! in_array($inherit, [ 'TOPTABLE', 'TABLE' ], true)) {
			return;
		}

		if ($inherit === 'TOPTABLE') {
			// Save Cascading CSS e.g. "div.topic p" at this block level
			if (isset($this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'])) {
				$this->tablecascadeCSS[0] = $this->mpdf->blk[$this->mpdf->blklvl]['cascadeCSS'];
			} else {
				$this->tablecascadeCSS[0] = $this->cascadeCSS;
			}
		}

		// Cascade everything from last level that is not an actual property, or defined by current tag/attributes
		if (isset($this->tablecascadeCSS[$this->tbCSSlvl - 1]) && is_array($this->tablecascadeCSS[$this->tbCSSlvl - 1])) {
			foreach ($this->tablecascadeCSS[$this->tbCSSlvl - 1] as $k => $v) {
				$this->tablecascadeCSS[$this->tbCSSlvl][$k] = $v;
			}
		}

		$this->mergeFullCssRules(
			$this->cascadeCSS,
			$this->tablecascadeCSS[$this->tbCSSlvl],
			$tag,
			$classes,
			$attr['ID'],
			$attr['LANG']
		);

		// Cascading forward CSS
		if (isset($this->tablecascadeCSS[$this->tbCSSlvl - 1])) {
			$this->mergeFullCssRules(
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
	 * @return void
	 */
	protected function mergeBlockCascadingCss($inherit, $tag, $attr, $classes)
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
		$this->mergeFullCssRules(
			$this->cascadeCSS,
			$currentBlock['cascadeCSS'],
			$tag,
			$classes,
			$attr['ID'],
			$attr['LANG']
		);

		// Cascading forward CSS
		$this->mergeFullCssRules(
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
			$this->cssProperties['MARGIN-COLLAPSE'] = 'COLLAPSE';
		}

		// custom tag, but follows CSS principle that border-collapse is inherited
		if (!empty($previousBlock['line_height'])) {
			$this->cssProperties['LINE-HEIGHT'] = $previousBlock['line_height'];
		}

		// mPDF 6
		if (!empty($previousBlock['line_stacking_strategy'])) {
			$this->cssProperties['LINE-STACKING-STRATEGY'] = $previousBlock['line_stacking_strategy'];
		}

		if (!empty($previousBlock['line_stacking_shift'])) {
			$this->cssProperties['LINE-STACKING-SHIFT'] = $previousBlock['line_stacking_shift'];
		}

		if (!empty($previousBlock['direction'])) {
			$this->cssProperties['DIRECTION'] = $previousBlock['direction'];
		}

		// mPDF 6  Lists
		if ($tag === 'LI' && !empty($previousBlock['list_style_type'])) {
			$this->cssProperties['LIST-STYLE-TYPE'] = $previousBlock['list_style_type'];
		}

		if (!empty($previousBlock['list_style_image'])) {
			$this->cssProperties['LIST-STYLE-IMAGE'] = $previousBlock['list_style_image'];
		}

		if (!empty($previousBlock['list_style_position'])) {
			$this->cssProperties['LIST-STYLE-POSITION'] = $previousBlock['list_style_position'];
		}

		if (!empty($previousBlock['align'])) {
			switch ($previousBlock['align']) {
				case 'L':
					$this->cssProperties['TEXT-ALIGN'] = 'left';
					break;

				case 'J':
					$this->cssProperties['TEXT-ALIGN'] = 'justify';
					break;

				case 'R':
					$this->cssProperties['TEXT-ALIGN'] = 'right';
					break;

				case 'C':
					$this->cssProperties['TEXT-ALIGN'] = 'center';
					break;
			}
		}

		if (!empty($previousBlock['bgcolorarray']) && ($this->mpdf->ColActive || $this->mpdf->keep_block_together)) {
			// Doesn't officially inherit, but default value is transparent (?=inherited)
			$cor = $previousBlock['bgcolorarray'];
			$this->cssProperties['BACKGROUND-COLOR'] = $this->colorConverter->colAtoString($cor);
		}

		if (isset($previousBlock['text_indent'])) {
			$this->cssProperties['TEXT-INDENT'] = $previousBlock['text_indent'];
		}

		if (isset($previousBlock['InlineProperties'])) {
			$this->convertInlinePropertiesToCss($previousBlock['InlineProperties'], $this->cssProperties); // mPDF 5.7.1
		}
	}

	/**
	 * Merge inline HTML attributes e.g. .. ALIGN="CENTER"
	 *
	 * Converts HTML attributes to CSS properties.
	 *
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes
	 * @return void
	 */
	protected function mergeInlineAttributes($tag, $attr)
	{
		if (!empty($attr['DIR'])) {
			$this->cssProperties['DIRECTION'] = $attr['DIR'];
		}

		if (!empty($attr['LANG'])) {
			$this->cssProperties['LANG'] = $attr['LANG'];
		}

		if (!empty($attr['COLOR'])) {
			$this->cssProperties['COLOR'] = $attr['COLOR'];
		}

		if ($tag !== 'INPUT') {
			if (!empty($attr['WIDTH'])) {
				$this->cssProperties['WIDTH'] = $attr['WIDTH'];
			}
			
			if (!empty($attr['HEIGHT'])) {
				$this->cssProperties['HEIGHT'] = $attr['HEIGHT'];
			}
		}

		if ($tag === 'FONT') {
			if (!empty($attr['FACE'])) {
				$this->cssProperties['FONT-FAMILY'] = $attr['FACE'];
			}
			
			$size = isset($attr['SIZE']) ? $attr['SIZE'] : '';
			if ($size === '+1') {
				$this->cssProperties['FONT-SIZE'] = '120%';
			} elseif ($size === '-1') {
				$this->cssProperties['FONT-SIZE'] = '86%';
			} elseif ($size === '1') {
				$this->cssProperties['FONT-SIZE'] = 'XX-SMALL';
			} elseif ($size == '2') {
				$this->cssProperties['FONT-SIZE'] = 'X-SMALL';
			} elseif ($size == '3') {
				$this->cssProperties['FONT-SIZE'] = 'SMALL';
			} elseif ($size == '4') {
				$this->cssProperties['FONT-SIZE'] = 'MEDIUM';
			} elseif ($size == '5') {
				$this->cssProperties['FONT-SIZE'] = 'LARGE';
			} elseif ($size == '6') {
				$this->cssProperties['FONT-SIZE'] = 'X-LARGE';
			} elseif ($size == '7') {
				$this->cssProperties['FONT-SIZE'] = 'XX-LARGE';
			}

		}

		if (!empty($attr['VALIGN'])) {
			$this->cssProperties['VERTICAL-ALIGN'] = $attr['VALIGN'];
		}

		if (!empty($attr['VSPACE'])) {
			$this->cssProperties['MARGIN-TOP'] = $attr['VSPACE'];
			$this->cssProperties['MARGIN-BOTTOM'] = $attr['VSPACE'];
		}

		if (!empty($attr['HSPACE'])) {
			$this->cssProperties['MARGIN-LEFT'] = $attr['HSPACE'];
			$this->cssProperties['MARGIN-RIGHT'] = $attr['HSPACE'];
		}
	}

	/**
	 * Merge stylesheet selectors.
	 *
	 * Applies CSS rules from stylesheets based on tag, class, ID,
	 *
	 * @param string $tag HTML tag
	 * @param array $attr HTML attributes
	 * @param array $classes Array of class names
	 * @param string $shortlang Short language code (e.g. 'en')
	 * @return void
	 */
	protected function mergeStylesheetSelectors($tag, $attr, $classes, $shortlang)
	{
		// STYLESHEET TAG e.g. h1  p  div  table
		if (isset($this->CSS[$tag])) {
			$zp = $this->CSS[$tag];
			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			} // *TABLES*
			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
				$this->mergeBorderProperties($zp);
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
				$this->cssProperties = array_merge($this->cssProperties, $zp);
				$this->mergeBorderProperties($zp);
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
							$select = $this->matchesNthChild($a, $row);
						}
					} elseif ($tag === 'TD' || $tag === 'TH') {
						if (preg_match('/(([\-+]?\d*)?N([\-+]\d+)?|[\-+]?\d+|ODD|EVEN)/', $m[1], $a)) { // mPDF 5.7.4
							$select = $this->matchesNthChild($a, $this->mpdf->col);
						}
					}

					if ($select) {
						$zp = $this->CSS[$tag . '>>SELECTORNTHCHILD>>' . $m[1]];
						if ($tag === 'TD' || $tag === 'TH') {
							$this->setBorderDominance($zp, 9);
						}

						if (is_array($zp)) {
							$this->cssProperties = array_merge($this->cssProperties, $zp);
							$this->mergeBorderProperties($zp);
						}
					}
				}
			}
		}

		// STYLESHEET LANG e.g. [lang=fr]{} or :lang(fr)
		if (isset($attr['LANG'])) {
			if (!empty($this->CSS['LANG>>' . $attr['LANG']])) {
				$zp = $this->CSS['LANG>>' . $attr['LANG']];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				} // *TABLES*

				if (is_array($zp)) {
					$this->cssProperties = array_merge($this->cssProperties, $zp);
					$this->mergeBorderProperties($zp);
				}
			} elseif (!empty($this->CSS['LANG>>' . $shortlang])) {
				$zp = $this->CSS['LANG>>' . $shortlang];
				if ($tag === 'TD' || $tag === 'TH') {
					$this->setBorderDominance($zp, 9);
				} // *TABLES*

				if (is_array($zp)) {
					$this->cssProperties = array_merge($this->cssProperties, $zp);
					$this->mergeBorderProperties($zp);
				}
			}
		}

		// STYLESHEET ID e.g. #smallone{}  #redletter{}
		if (!empty($attr['ID']) && !empty($this->CSS['ID>>' . $attr['ID']])) {
			$zp = $this->CSS['ID>>' . $attr['ID']];
			if ($tag === 'TD' || $tag === 'TH') {
				$this->setBorderDominance($zp, 9);
			} // *TABLES*

			if (is_array($zp)) {
				$this->cssProperties = array_merge($this->cssProperties, $zp);
				$this->mergeBorderProperties($zp);
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
	protected function normalizePath($path)
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

	/**
	 * Merge table specific CSS (CELLSPACING, CELLPADDING).
	 *
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes
	 * @return void
	 */
	protected function mergeTableSpecificCss($tag, $attr)
	{
		// cellSpacing overwrites TABLE default but not specific CSS set on table
		if ($tag === 'TABLE' && isset($attr['CELLSPACING'])) {
			$this->cssProperties['BORDER-SPACING-H'] = $this->cssProperties['BORDER-SPACING-V'] = $attr['CELLSPACING'];
		}

		// cellPadding overwrites TD/TH default but not specific CSS set on cell
		if (($tag === 'TD' || $tag === 'TH') && isset($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding']) && ($this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'] || $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'] === '0')) {
			$this->cssProperties['PADDING-LEFT'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
			$this->cssProperties['PADDING-RIGHT'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
			$this->cssProperties['PADDING-TOP'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
			$this->cssProperties['PADDING-BOTTOM'] = $this->mpdf->table[$this->mpdf->tableLevel][$this->mpdf->tbctr[$this->mpdf->tableLevel]]['cell_padding'];
		}
	}

	/**
	 * Merge default CSS for the tag.
	 *
	 * @param string $tag HTML tag name
	 * @return void
	 */
	protected function mergeDefaultCss($tag)
	{
		if (!isset($this->mpdf->defaultCSS[$tag])) {
			return;
		}

		$zp = $this->normalizeProperties->normalize($this->mpdf->defaultCSS[$tag]);
		if (is_array($zp)) {  // Default overwrites Inherited
			$this->cssProperties = array_merge($this->cssProperties, $zp);  // !! Note other way round !!
			$this->mergeBorderProperties($zp);
		}
	}

	/**
	 * Merge inline style attribute CSS.
	 *
	 * @param string $tag HTML tag name
	 * @param array $attr HTML attributes
	 * @return void
	 */
	protected function mergeInlineStyle($tag, $attr)
	{
		// INLINE STYLE e.g. style="CSS:property"
		if (!isset($attr['STYLE'])) {
			return;
		}

		$zp = $this->readInlineCSS($attr['STYLE']);
		if ($tag === 'TD' || $tag === 'TH') {
			$this->setBorderDominance($zp, 9);
		} // *TABLES*

		if (is_array($zp)) {
			$this->cssProperties = array_merge($this->cssProperties, $zp);
			$this->mergeBorderProperties($zp);
		}
	}
}
