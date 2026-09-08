<?php

namespace Mpdf\Image;

/**
 * Pieces of an image built by hand, for the tests that check what ImageProcessor reads out of one
 */
trait BuildsImageFixtures
{

	/**
	 * Enough of an ICC profile for the checks usableIccProfile() makes: ICC.1 7.2 puts the profile's
	 * size at byte 0, the data and connection spaces at bytes 16 and 20, and 'acsp' at 36
	 *
	 * @param int $size The size to make it, and to say it is; 132 is the header and an empty tag table
	 */
	protected function iccProfile($size = 132)
	{
		$profile = str_repeat(chr(0), $size);
		$profile = substr_replace($profile, pack('N', $size), 0, 4);
		$profile = substr_replace($profile, 'RGB ', 16, 4);
		$profile = substr_replace($profile, 'XYZ ', 20, 4);

		return substr_replace($profile, 'acsp', 36, 4);
	}

	/**
	 * Rewrite the dimensions in a JPEG's frame header: T.81 B.2.2 puts the lines, then the samples per line,
	 * five bytes past the SOF0 marker
	 */
	protected function withFrameDimensions($data, $w, $h)
	{
		$sof = strpos($data, "\xFF\xC0");

		return substr_replace($data, pack('nn', $h, $w), $sof + 5, 4);
	}

	/**
	 * Put JPEG segments straight after the SOI, ahead of everything the image really carries
	 */
	protected function afterSoi($data, $segments)
	{
		return substr($data, 0, 2) . $segments . substr($data, 2);
	}

}
