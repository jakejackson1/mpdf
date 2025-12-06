<?php

namespace Mpdf;

use Mpdf\Color\ColorConverter;
use Mpdf\Css\NormalizeProperties;
use Mpdf\Css\TextVars;
use Mpdf\Css\ShadowParser;
use Mpdf\Css\CssLoader;
use Mpdf\Css\MediaQueryProcessor;
use Mpdf\Css\SelectorParser;
use Mpdf\Css\CssSanitizer;
use Mpdf\Css\InlineStyleParser;
use Mpdf\Utils\Arrays;
use Mpdf\Utils\Path;

class CssManager
{
	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	/**
	 * @var \Mpdf\Color\ColorConverter
	 */
	private $colorConverter;

	/**
	 * @var \Mpdf\Css\NormalizeProperties
	 */
	/**
	 * @var \Mpdf\Css\NormalizeProperties
	 */
	private $normalizeProperties;

	/**
	 * @var \Mpdf\Css\ShadowParser
	 */
	private $shadowParser;

	/**
	 * @var \Mpdf\Css\CssLoader
	 */
	private $cssLoader;

	/**
	 * @var \Mpdf\Css\MediaQueryProcessor
	 */
	private $mediaQueryProcessor;

	/**
	 * @var \Mpdf\Css\SelectorParser
	 */
	private $selectorParser;

	/**
	 * @var \Mpdf\Css\CssSanitizer
	 */
	private $cssSanitizer;

	/**
	 * @var \Mpdf\Css\InlineStyleParser
	 */
	private $inlineStyleParser;

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
	 */
	public function __construct(Mpdf $mpdf, Cache $cache, SizeConverter $sizeConverter, ColorConverter $colorConverter, AssetFetcher $assetFetcher)
	{
		$this->mpdf = $mpdf;
		$this->colorConverter = $colorConverter;

		$this->normalizeProperties = new NormalizeProperties($mpdf, $sizeConverter, $colorConverter);
		$this->shadowParser = new ShadowParser($mpdf, $sizeConverter, $colorConverter);
		$this->cssLoader = new CssLoader($assetFetcher, $cache);
		$this->mediaQueryProcessor = new MediaQueryProcessor($mpdf);
		$this->selectorParser = new SelectorParser($mpdf);
		$this->cssSanitizer = new CssSanitizer();
		$this->inlineStyleParser = new InlineStyleParser($this->cssSanitizer, $this->normalizeProperties);

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
		$html = $this->mediaQueryProcessor->filterByMediaQuery($html, '/<style[^>]*media=["\']([^"\'>]*)["\'].*?<\/style>/is');
		$html = $this->mediaQueryProcessor->filterByMediaQuery($html, '/<link[^>]*media=["\']([^"\'>]*)["\'].*?>/is');
		$html = $this->cssSanitizer->removeCommentsFromStyleBlocks($html);
		$html = $this->cssSanitizer->removeHtmlComments($html);

		$CSSext = $this->cssLoader->extractExternalStylesheetUrls($html);
		$CSSstr = '';

		$match = count($CSSext);
		$ind = 0;

		if (!is_array($this->cascadeCSS)) {
			$this->cascadeCSS = [];
		}

		while ($match) {
			$path = htmlspecialchars_decode($CSSext[$ind]);
			$path = Path::relativeToAbsolutePath($path);

			// mPDF 5.7.3
			if (strpos($path, '//') === false) {
				$path = preg_replace('/\.css\?.*$/', '.css', $path);
			}

			$CSSextblock = $this->cssLoader->loadStylesheet($path);

			if ($CSSextblock) {
				$CSSstr .= $this->cssLoader->processExternalCssImports($CSSextblock, $path, $CSSext, $match);
			}

			$match--;
			$ind++;
		}

		// CSS as <style> in HTML document
		$regexp = '/<style.*?>(.*?)<\/style>/si';
		if (preg_match_all($regexp, $html, $CSSblock)) {
			$CSSstr .= ' ' . $this->cssLoader->resolveBackgroundUrls(implode(' ', $CSSblock[1]));
		}

		// Remove comments
		$CSSstr = preg_replace('|/\*.*?\*/|s', ' ', $CSSstr);
		$CSSstr = preg_replace('/[\s\n\r\t\f]/s', ' ', $CSSstr);
		$CSSstr = $this->mediaQueryProcessor->processMediaQueries($CSSstr);
		$CSSstr = $this->cssLoader->processDataUriImages($CSSstr);
		$CSSstr = preg_replace('/(<\!\-\-|\-\->)/s', ' ', $CSSstr);
		$CSSstr = $this->cssSanitizer->processUrlsInCss($CSSstr);

		if ($CSSstr) {
			$this->processCssString($CSSstr);
		}

		// Remove CSS (tags and content), if any
		$regexp = '/<style.*?>(.*?)<\/style>/si'; // it can be <style> or <style type="txt/css">
		$html = preg_replace($regexp, '', $html);

		return $html;
	}



	/**
	 * @param string $cssStr
	 * @return void
	 */
	protected function processCssString($cssStr)
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
			$tag = $this->selectorParser->parsePageSelector($tags);
			if ($tag && isset($this->CSS[$tag])) {
				$this->CSS[$tag] = $this->array_merge_recursive_unique($this->CSS[$tag], $classproperties);
			} elseif ($tag) {
				$this->CSS[$tag] = $classproperties;
			}
		} elseif ($level === 1) {
			$tag = $this->selectorParser->parseSimpleSelector($tags);
			if ($tag && isset($this->CSS[$tag])) {
				$this->CSS[$tag] = $this->array_merge_recursive_unique($this->CSS[$tag], $classproperties);
			} elseif ($tag) {
				$this->CSS[$tag] = $classproperties;
			}
		} else {
			$tmp = $this->selectorParser->parseCascadedSelector($tags);
			if (empty($tmp)) {
				return;
			}

			$cascadeCSS = &$this->cascadeCSS;
			foreach ($tmp as $tp) {
				$cascadeCSS = &$cascadeCSS[$tp];
			}

			$cascadeCSS = $this->array_merge_recursive_unique($cascadeCSS, $classproperties);
			$cascadeCSS['depth'] = $level;
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
	 * @param string $html CSS string from style attribute
	 * @return array Parsed CSS properties
	 */
	public function readInlineCSS($html)
	{
		return $this->inlineStyleParser->parse($html);
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
		return $this->shadowParser->parseBoxShadow($value);
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
		return $this->shadowParser->parseTextShadow($value);
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
			$zp = $this->inlineStyleParser->parse($attr['STYLE']);
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

		$zp = $this->inlineStyleParser->parse($attr['STYLE']);
		if ($tag === 'TD' || $tag === 'TH') {
			$this->setBorderDominance($zp, 9);
		} // *TABLES*

		if (is_array($zp)) {
			$this->cssProperties = array_merge($this->cssProperties, $zp);
			$this->mergeBorderProperties($zp);
		}
	}
}
