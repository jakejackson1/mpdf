<?php

namespace Mpdf\Css;

use Symfony\Component\CssSelector\Parser\Parser;
use Symfony\Component\CssSelector\Parser\Tokenizer\Tokenizer;

/**
 * CSS Specificity Calculator using Symfony CSS Selector Component
 *
 * This class wraps Symfony's robust CSS parser to calculate selector specificity
 * according to W3C specifications (CSS Selectors Level 3 & 4).
 */
class SpecificityCalculator
{
	private $parser;

	public function __construct()
	{
		$tokenizer = new Tokenizer();
		$this->parser = new Parser($tokenizer);
	}

	/**
	 * Calculate the specificity of a CSS selector
	 *
	 * Returns an array [A, B, C] where:
	 * - A = count of ID selectors
	 * - B = count of class selectors, attribute selectors, and pseudo-classes
	 * - C = count of type selectors and pseudo-elements
	 *
	 * @param string $selector CSS selector to calculate specificity for
	 * @param int $depth Recursion depth (unused, kept for backward compatibility)
	 * @return array [A, B, C] specificity tuple
	 */
	public function calculate($selector, $depth = 0)
	{
		try {
			// Parse the selector using Symfony's Parser
			$selectorList = $this->parser->parse($selector);
			
			// If multiple selectors (comma-separated), use the first one
			// In CSS, comma-separated selectors are separate rules
			if (empty($selectorList)) {
				return [0, 0, 0];
			}
			
			$parsedSelector = $selectorList[0];
			
			// Get specificity from Symfony's parsed selector
			$specificity = $parsedSelector->getSpecificity();
			
			// Symfony's getValue() returns an integer: A*100 + B*10 + C
			// We need to convert this back to [A, B, C] format
			$value = $specificity->getValue();
			
			$a = (int) ($value / 100);
			$b = (int) (($value % 100) / 10);
			$c = $value % 10;
			
			return [$a, $b, $c];
			
		} catch (\Exception $e) {
			// If parsing fails, return zero specificity
			// This handles malformed selectors gracefully
			return [0, 0, 0];
		}
	}

	/**
	 * Compare two specificity tuples
	 *
	 * @param array $s1 First specificity [A, B, C]
	 * @param array $s2 Second specificity [A, B, C]
	 * @return int Negative if $s1 < $s2, positive if $s1 > $s2, zero if equal
	 */
	public function compare($s1, $s2)
	{
		// Compare A (ID selectors)
		if ($s1[0] !== $s2[0]) {
			return $s1[0] - $s2[0];
		}
		
		// Compare B (class/attribute/pseudo-class selectors)
		if ($s1[1] !== $s2[1]) {
			return $s1[1] - $s2[1];
		}
		
		// Compare C (type/pseudo-element selectors)
		return $s1[2] - $s2[2];
	}
}
