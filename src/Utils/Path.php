<?php

namespace Mpdf\Utils;

class Path
{
	public static function relativeToAbsolute($relPath, $basePath)
	{
		 // Fix Windows paths
		$relPath = str_replace("\\", '/', $relPath);

		// mPDF 5.7.2
		if (strpos($relPath, '//') === 0) {
			$scheme = parse_url($basePath, PHP_URL_SCHEME);
			$scheme = $scheme ?: 'http';
			$relPath = $scheme . ':' . $relPath;
		}

		// Inadvertently corrects "./path/etc" and "//www.domain.com/etc"
		$relPath = preg_replace('|^./|', '', $relPath);
		if (strpos($relPath, '#') === 0) {
			return $relPath;
		}

		// Skip schemes not supported by installed stream wrappers
		$wrappers = stream_get_wrappers();
		$pattern = sprintf('@^(?!%s)[a-z0-9\.\-+]+:.*@i', implode('|', $wrappers));
		if (preg_match($pattern, $relPath)) {
			return $relPath;
		}

		// It is a relative link
		if (strpos($relPath, '../') === 0) {
			$backtrackamount = substr_count($relPath, '../');
			$maxbacktrack = substr_count($basePath, '/') - 3;
			$filepath = str_replace('../', '', $relPath);
			$relPath = $basePath;

			// If it is an invalid relative link, then make it go to directory root
			if ($backtrackamount > $maxbacktrack) {
				$backtrackamount = $maxbacktrack;
			}

			// Backtrack some directories
			for ($i = 0; $i < $backtrackamount + 1; $i++) {
				$relPath = substr($relPath, 0, strrpos($relPath, "/"));
			}

			// Make it an absolute path
			$relPath .= '/' . $filepath;

			return $relPath;
		}

		// It is a local link. Ignore potential file errors
		if ((strpos($relPath, ":/") === false || strpos($relPath, ":/") > 10) &&
			!@is_file($relPath)
			) {

			if (strpos($relPath, '/') !== 0) {
				return $basePath . $relPath;
			}

			$tr = parse_url($basePath);

			// mPDF 5.7.2
			$root = '';
			if (!empty($tr['scheme'])) {
				$root .= $tr['scheme'] . '://';
			}

			$root .= !empty($tr['host']) ? $tr['host'] : '';
			$root .= !empty($tr['port']) ? ':' . $tr['port'] : ''; // mPDF 5.7.3

			$relPath = $root . $relPath;
		}

		return $relPath;
	}
}
