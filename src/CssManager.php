<?php

namespace Mpdf;

use Mpdf\Color\ColorConverter;
use Mpdf\Css\CssMerger;
use Mpdf\Css\NormalizeProperties;
use Mpdf\Css\ShadowParser;
use Mpdf\Css\SelectorParser;
use Mpdf\Css\InlineStyleParser;
use Mpdf\Css\InlinePropertyConverter;
use Mpdf\Exception\InvalidArgumentException;
use Mpdf\Utils\Arrays;
use Mpdf\Css\CssParser;

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
	private $normalizeProperties;

	/**
	 * @var \Mpdf\Css\ShadowParser
	 */
	private $shadowParser;

	/**
	 * @var \Mpdf\Css\InlineStyleParser
	 */
	private $inlineStyleParser;

	/**
	 * @var \Mpdf\Css\InlinePropertyConverter
	 */
	private $inlinePropertyConverter;

	/**
	 * @var \Mpdf\Css\SelectorParser
	 */
	private $selectorParser;

	/**
	 * @var \Mpdf\Css\CssParser
	 */
	private $cssParser;

	/**
	 * @var \Mpdf\Css\CssMerger
	 */
	private $cssMerger;

	/**
	 * Main CSS property storage array.
	 *
	 * Stores CSS properties for simple selectors (depth 1).
	 * Format:
	 * [
	 *   'P' => [
	 *     'COLOR' => '#FF0000',
	 *     'FONT-SIZE' => '12pt',
	 *   ],
	 *   'CLASS>>MYCLASS' => [
	 *     'BORDER' => '1px solid black',
	 *   ],
	 *   ...
	 * ]
	 *
	 * @var array
	 */
	public $CSS = [];

	/**
	 * CSS cascade storage for table elements.
	 *
	 * Stores cascaded CSS properties specifically for table elements (TABLE, THEAD, TBODY, TFOOT, TR, TH, TD).
	 * Format is a nested array mirroring the selector hierarchy.
	 * Example for "DIV TABLE TD":
	 * [
	 *   'DIV' => [
	 *     'TABLE' => [
	 *       'TD' => [
	 *         'BORDER' => '1px solid green',
	 *         'depth' => 3
	 *       ]
	 *     ]
	 *   ]
	 * ]
	 *
	 * @var array
	 */
	public $tablecascadeCSS = [];

	/**
	 * Cascading CSS property storage.
	 *
	 * Stores CSS properties for nested/cascaded selectors (depth > 1).
	 * Format is a nested array mirroring the selector hierarchy.
	 * Example for "DIV.myclass P":
	 * [
	 *   'DIV' => [
	 *     'CLASS>>MYCLASS' => [
	 *       'P' => [
	 *         'COLOR' => '#0000FF',
	 *         'depth' => 3
	 *       ]
	 *     ]
	 *   ]
	 * ]
	 *
	 * @var array
	 */
	public $cascadeCSS = [];

	/**
	 * @var int Table CSS cascade level counter
	 */
	public $tbCSSlvl = 0;

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
		$this->selectorParser = new SelectorParser($mpdf);
		$this->inlineStyleParser = new InlineStyleParser($this->normalizeProperties);
		$this->inlinePropertyConverter = new InlinePropertyConverter($colorConverter);

		$this->cssParser = new CssParser(
			$mpdf,
			$cache,
			$sizeConverter,
			$colorConverter,
			$assetFetcher
		);

		$this->cssMerger = new CssMerger(
			$mpdf,
			$this,
			$this->normalizeProperties,
			$this->inlineStyleParser,
			$this->selectorParser,
			$this->inlinePropertyConverter,
			$colorConverter
		);
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
		if (!is_array($this->cascadeCSS)) {
			$this->cascadeCSS = [];
		}

		$html = $this->cssParser->parse($html);

		$this->CSS = Arrays::uniqueRecursiveMerge($this->CSS, $this->cssParser->getCss());
		$this->cascadeCSS = Arrays::uniqueRecursiveMerge($this->cascadeCSS, $this->cssParser->getCascadeCss());

		return $html;
	}

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
		return $this->cssMerger->merge($inherit, $tag, $attr);
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
		return $this->cssMerger->previewBlockCss($tag, $attr);
	}

	/**
	 * Set border dominance level for a specific side.
	 *
	 * @param string $side T|R|B|L
	 * @param int $val Dominance value
	 * @throws InvalidArgumentException
	 * @return void
	 */
	public function setBorderDominance($side, $val)
	{
		$this->cssMerger->setBorderDominance($side, $val);
	}

	/**
	 * Get border dominance level for a specific side.
	 *
	 * @param string $side T|R|B|L
	 * @return int Dominance value
	 */
	public function getBorderDominance($side)
	{
		return $this->cssMerger->getBorderDominance($side);
	}
}
