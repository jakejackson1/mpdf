<?php

namespace Mpdf\Css;

use Mpdf\Mpdf;

class SelectorParser
{
	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	public function __construct(Mpdf $mpdf)
	{
		$this->mpdf = $mpdf;
	}

	/**
	 * Parse @PAGE selector.
	 *
	 * @param array $tags Selector tags array
	 * @return string Matches tag
	 */
	public function parsePageSelector($tags)
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

		return $tag;
	}

	/**
	 * Parse simple selector (depth 1).
	 *
	 * @param array $tags Selector tags array
	 * @return string|null Parsed tag key or null if invalid
	 */
	public function parseSimpleSelector($tags)
	{
		$t = isset($tags[0]) ? trim($tags[0]) : '';
		if (empty($t)) {
			return null;
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

		return $tag ?: null;
	}

	/**
	 * Parse cascaded selector (depth > 1).
	 *
	 * @param array $tags Selector tags array
	 * @return array Array of tag levels for cascade
	 */
	public function parseCascadedSelector($tags)
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

		return $tmp;
	}
}
