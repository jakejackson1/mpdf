<?php

namespace Mpdf\Utils;

class PdfDate
{

	/**
	 * PDF documents use the internal date format: (D:YYYYMMDDHHmmSSOHH'mm'). The date format has these parts:
	 *
	 *   YYYY The full four-digit year. (For example, 2004)
	 *   MM   The month from 01 to 12.
	 *   DD   The day from 01 to 31.
	 *   HH   The hour from 00 to 23.
	 *   mm   The minute from 00 to 59.
	 *   SS   The seconds from 00 to 59.
	 *   O    The relationship of local time to Universal Time (UT), as denoted by one of the characters +, -, or Z.
	 *   HH   The absolute value of the offset from UT in hours specified as 00 to 23.
	 *   mm   The absolute value of the offset from UT in minutes specified as 00 to 59.
	 *
	 * @param int|\DateTimeInterface $date A timestamp, read in the default timezone, or a date in its own
	 * @return string
	 */
	public static function format($date)
	{
		if (!$date instanceof \DateTimeInterface) {
			$date = (new \DateTime())->setTimestamp($date);
		}

		$z = $date->format('O'); // +0200
		$offset = substr($z, 0, 3) . "'" . substr($z, 3, 2) . "'"; // +02'00'
		return $date->format('YmdHis') . $offset;
	}

	/**
	 * The moment a document is dated: now, or the creationDate it was configured with. A timestamp is read in UTC,
	 * so the same date gives the same bytes wherever the document is made; a date keeps its own timezone.
	 *
	 * @param int|\DateTimeInterface|null $creationDate
	 * @return \DateTimeInterface
	 */
	public static function documentDate($creationDate)
	{
		if ($creationDate instanceof \DateTimeInterface) {
			return $creationDate;
		}

		return new \DateTime($creationDate === null ? 'now' : '@' . $creationDate);
	}

}
