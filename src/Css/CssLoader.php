<?php

namespace Mpdf\Css;

use Mpdf\AssetFetcher;
use Mpdf\Cache;
use Mpdf\Utils\Path;

class CssLoader
{

	/**
	 * @var \Mpdf\AssetFetcher
	 */
	private $assetFetcher;

	/**
	 * @var \Mpdf\Cache
	 */
	private $cache;

	public function __construct(AssetFetcher $assetFetcher, Cache $cache)
	{
		$this->assetFetcher = $assetFetcher;
		$this->cache = $cache;
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function loadStylesheet($path)
	{
		$path = preg_replace('/\.css\?.*$/', '.css', $path);
		
		$data = $this->assetFetcher->fetchDataFromPath($path);
		if (!$data) {
			$path = Path::normalizeLocalFilePath($path);
			$data = $this->assetFetcher->fetchDataFromPath($path);
		}

		return $data;
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
	public function extractExternalStylesheetUrls($html)
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
	 * @param string $cssContent
	 * @param string $path
	 * @param array $cssExt
	 * @param int $match
	 * @return string
	 */
	public function processExternalCssImports($cssContent, $path, &$cssExt, &$match)
	{
		$cssBasePath = preg_replace('/\/[^\/]*$/', '', $path) . '/';
		$cssStr = '';

		// look for embedded @import stylesheets in other stylesheets
		// and fix url paths (including background-images) relative to stylesheet
		$regexpem = '/@import url\([\'\"]{0,1}(.*?\.css(\?\S+)?)[\'\"]{0,1}\)/si';
		if (preg_match_all($regexpem, $cssContent, $cxtem)) {
			foreach ($cxtem[1] as $cxtembedded) {
				// path is relative to original stylesheet!!
				$cxtembedded = Path::relativeToAbsolutePath($cxtembedded, $cssBasePath);
				$match++;
				$cssExt[] = $cxtembedded;
			}
		}

		$cssStr .= ' ' . $this->resolveBackgroundUrls($cssContent, $cssBasePath);

		return $cssStr;
	}

	/**
	 * Resolve background image URLs in CSS.
	 *
	 * Converts relative URLs to absolute paths using Path::relativeToAbsolute.
	 * Skips data URIs which are already absolute.
	 *
	 * @param string $cssStr CSS string potentially containing background URLs
	 * @param string|null $basePath Optional base path for resolving relative URLs
	 * @return string CSS string with resolved URLs
	 */
	public function resolveBackgroundUrls($cssStr, $basePath = null)
	{
		$regexpem = '/(background[^;]*url\s*\(\s*[\'\"]{0,1})([^\)\'\"]*)([\'\"]{0,1}\s*\))/si';
		$xem = preg_match_all($regexpem, $cssStr, $cxtem);
		if ($xem) {
			$count_cxtem = count($cxtem[0]);
			for ($i = 0; $i < $count_cxtem; $i++) {
				$embedded = $cxtem[2][$i];
				if (!preg_match('/^data:image/i', $embedded)) {
					if ($basePath !== null) {
						$newPath = Path::relativeToAbsolutePath($embedded, $basePath);
					} else {
						$newPath = Path::relativeToAbsolutePath($embedded);
					}
					
					$cssStr = str_replace($cxtem[0][$i], ($cxtem[1][$i] . $newPath . $cxtem[3][$i]), $cssStr);
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
	public function processDataUriImages($cssStr)
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
}
