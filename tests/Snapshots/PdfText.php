<?php

namespace Snapshots;

use SebastianBergmann\Diff\Differ;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfBoolean;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfReader\PdfReader;

/**
 * A PDF as text a diff can read, parsed by FPDI: one section per object, dictionaries one entry a line, streams
 * inflated, and the binary ones (fonts, images) reduced to their size and hash. Sections are named for what they
 * hold, so a difference is placed on its page.
 */
class PdfText
{
	/**
	 * Unchanged lines shown either side of a change
	 */
	const CONTEXT = 3;

	/**
	 * The document's sections, name => lines
	 *
	 * @param string $pdf
	 * @return string[][]
	 */
	public static function sections($pdf)
	{
		$parser = new PdfParser(StreamReader::createByString($pdf));
		$reader = new PdfReader($parser);

		// Number the pages and their content streams
		$pages = [];
		$contents = [];
		for ($number = 1; $number <= $reader->getPageCount(); $number++) {
			$page = $reader->getPage($number);
			$pages[$page->getPageObject()->objectNumber] = $number;
			$streams = PdfDictionary::get($page->getPageDictionary(), 'Contents');
			foreach ($streams instanceof PdfArray ? $streams->value : [$streams] as $reference) {
				if ($reference instanceof PdfIndirectObjectReference) {
					$contents[$reference->value] = $number;
				}
			}
		}

		$sections = [];
		$counts = [];
		$crossReference = $parser->getCrossReference();
		for ($number = 1; $number < $crossReference->getSize(); $number++) {
			if ($crossReference->getOffsetFor($number) === false) {
				continue;
			}
			$value = $parser->getIndirectObject($number)->value;

			if (isset($contents[$number])) {
				$name = 'page ' . $contents[$number] . ' content';
			} elseif (isset($pages[$number])) {
				$name = 'page ' . $pages[$number];
			} else {
				$kind = self::kind($value);
				$counts[$kind] = isset($counts[$kind]) ? $counts[$kind] + 1 : 1;
				$name = $kind . ' ' . $counts[$kind];
			}

			$sections[$name] = $value instanceof PdfStream
				? self::streamLines($value, isset($contents[$number]))
				: self::lines($value);
		}

		// The trailer, less the file ID: a hash of the bytes, so it says nothing the objects do not
		$trailer = clone $crossReference->getTrailer();
		unset($trailer->value['ID']);
		$sections['trailer'] = self::lines($trailer);

		return $sections;
	}

	/**
	 * What an object is, by its Type, or its Subtype for an XObject or an object with no Type
	 */
	private static function kind(PdfType $value)
	{
		if ($value instanceof PdfStream) {
			$value = $value->value;
		}
		if (!$value instanceof PdfDictionary) {
			return 'object';
		}
		$type = PdfDictionary::get($value, 'Type');
		if (!$type instanceof PdfName || $type->value === 'XObject') {
			$type = PdfDictionary::get($value, 'Subtype');
		}

		return $type instanceof PdfName ? $type->value : 'object';
	}

	/**
	 * The lines of a stream object: its dictionary without the length, and the filter it was read through, then
	 * the stream itself, or a line standing for it when it is binary. Page content is always shown, as mPDF writes
	 * text in it as raw UTF-16, and a form or XML stream likewise; the rest is shown when it reads as text.
	 */
	private static function streamLines(PdfStream $stream, $isContent)
	{
		$dictionary = clone $stream->value;
		unset($dictionary->value['Length']);
		try {
			$data = $stream->getUnfilteredStream();
			unset($dictionary->value['Filter']);
		} catch (\Exception $e) {
			// A filter FPDI has no decoder for. DCTDecode is shown as it is; Flate under a PNG predictor, which is how
			// mPDF embeds a PNG, is inflated here and its DecodeParms kept, as the predictor is part of the content
			$data = $stream->getStream();
			$filter = PdfDictionary::get($dictionary, 'Filter');
			$inflated = $filter instanceof PdfName && $filter->value === 'FlateDecode' ? @gzuncompress($data) : false;
			if ($inflated !== false) {
				$data = $inflated;
				unset($dictionary->value['Filter']);
			}
		}

		$subtype = PdfDictionary::get($dictionary, 'Subtype');
		$text = $isContent || ($subtype instanceof PdfName && in_array($subtype->value, ['Form', 'XML'], true))
			|| preg_match_all('/[^\x09\x0A\x0D\x20-\x7E]/', $data) <= strlen($data) / 20;

		$lines = $dictionary->value ? self::lines($dictionary) : [];
		if ($text) {
			foreach (explode("\n", $data) as $line) {
				$lines[] = addcslashes($line, "\0..\10\13..\37\177..\377");
			}
		} else {
			$lines[] = sprintf('[binary stream: %d bytes, md5 %s]', strlen($data), md5($data));
		}

		return $lines;
	}

	/**
	 * A value as lines: a dictionary one entry a line, anything else on one
	 */
	private static function lines(PdfType $value)
	{
		if (!$value instanceof PdfDictionary) {
			return [self::inline($value)];
		}

		return array_merge(['<<'], self::entries($value), ['>>']);
	}

	private static function entries(PdfDictionary $value)
	{
		$entries = [];
		foreach ($value->value as $key => $entry) {
			$entries[] = '/' . $key . ' ' . self::inline($entry);
		}

		return $entries;
	}

	private static function inline(PdfType $value)
	{
		if ($value instanceof PdfDictionary) {
			return '<< ' . implode(' ', self::entries($value)) . ' >>';
		}
		if ($value instanceof PdfArray) {
			return '[' . implode(' ', array_map([__CLASS__, 'inline'], $value->value)) . ']';
		}
		if ($value instanceof PdfIndirectObjectReference) {
			return $value->value . ' ' . $value->generationNumber . ' R';
		}
		if ($value instanceof PdfName) {
			return '/' . $value->value;
		}
		if ($value instanceof PdfString) {
			return '(' . addcslashes($value->value, "\0..\10\13..\37\177..\377") . ')';
		}
		if ($value instanceof PdfHexString) {
			return '<' . $value->value . '>';
		}
		if ($value instanceof PdfBoolean) {
			return $value->value ? 'true' : 'false';
		}
		if ($value instanceof PdfNull) {
			return 'null';
		}

		return (string) $value->value; // a number, or a bare token
	}

	/**
	 * What changed from $expected to $actual, as hunks each headed by the section and line they fall in. Empty when
	 * the two read the same, however they were compressed or laid out.
	 *
	 * @param string $expected
	 * @param string $actual
	 * @return string
	 */
	public static function diff($expected, $actual)
	{
		$from = self::sections($expected);
		$to = self::sections($actual);

		$differ = new Differ();
		$diff = '';
		foreach (array_keys($from + $to) as $name) {
			$before = isset($from[$name]) ? $from[$name] : [];
			$after = isset($to[$name]) ? $to[$name] : [];
			if ($before === $after) {
				continue;
			}
			$heading = $name . (!$before ? ' (added)' : (!$after ? ' (removed)' : ''));
			$diff .= self::hunks($heading, $differ->diffToArray($before, $after));
		}

		return $diff;
	}

	/**
	 * The changes in $ops, [line, 0 kept | 1 added | 2 removed] as the Differ gives them, as hunks with CONTEXT
	 * unchanged lines around each, headed by where in the expected document it starts
	 */
	private static function hunks($name, array $ops)
	{
		$lineAt = [];
		$changed = [];
		$line = 1;
		foreach ($ops as $index => $op) {
			$lineAt[$index] = $line;
			if ($op[1] !== 0) {
				$changed[] = $index;
			}
			if ($op[1] !== 1) {
				$line++;
			}
		}

		$hunks = '';
		while ($changed) {
			$first = array_shift($changed);
			$last = $first;
			while ($changed && $changed[0] - $last <= 2 * self::CONTEXT) {
				$last = array_shift($changed);
			}
			$start = max(0, $first - self::CONTEXT);
			$stop = min(count($ops), $last + self::CONTEXT + 1);
			$hunks .= self::hunk(sprintf('%s, line %d', $name, $lineAt[$start]), array_slice($ops, $start, $stop - $start));
		}

		return $hunks;
	}

	private static function hunk($heading, array $ops)
	{
		$marks = [' ', '+', '-'];
		$lines = [];
		foreach ($ops as $op) {
			$lines[] = $marks[$op[1]] . $op[0];
		}

		return '@@ ' . $heading . " @@\n" . self::head(implode("\n", $lines), 200) . "\n";
	}

	/**
	 * The first $count lines of $text, and a note of how many more there are
	 */
	public static function head($text, $count)
	{
		$lines = explode("\n", rtrim($text));
		$head = implode("\n", array_slice($lines, 0, $count));

		return count($lines) > $count ? sprintf("%s\n... %d more lines", $head, count($lines) - $count) : $head;
	}
}
