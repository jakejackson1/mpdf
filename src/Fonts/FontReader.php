<?php

namespace Mpdf\Fonts;

/**
 * Reads the big-endian numbers an OpenType file is made of, from a current position.
 *
 * Every method is named for the data type in the spec's own table, so that a line of parsing can be
 * read straight against the structure it is parsing: a Coverage table whose spec says
 *
 *     uint16  coverageFormat
 *     uint16  glyphCount
 *     uint16  glyphArray[glyphCount]
 *
 * is read as three readUInt16() calls, and a reader can count them off.
 *
 * @see https://learn.microsoft.com/en-us/typography/opentype/spec/otff#data-types
 */
abstract class FontReader
{

	/**
	 * The read position, in bytes from the start of whatever is being read
	 *
	 * @var int
	 */
	protected $pos = 0;

	/**
	 * @return string Exactly $length bytes from the current position, which is advanced past them
	 */
	abstract public function read($length);

	public function seek($position)
	{
		$this->pos = $position;
	}

	public function skip($delta)
	{
		$this->seek($this->pos + $delta);
	}

	/**
	 * @return int The current position, to seek back to after following an offset
	 */
	public function tell()
	{
		return $this->pos;
	}

	/**
	 * int16: a signed 16-bit integer, two's complement
	 */
	public function readInt16()
	{
		return self::int16($this->read(2));
	}

	/**
	 * uint16: an unsigned 16-bit integer. Also Offset16, which the spec measures from the start of
	 * some enclosing table, and FWORD/UFWORD, which are int16/uint16 in font design units.
	 */
	public function readUInt16()
	{
		$s = $this->read(2);

		return (ord($s[0]) << 8) + ord($s[1]);
	}

	/**
	 * Decode an int16 already held as two bytes.
	 *
	 * PHP has no signed big-endian unpack format before 7.2 and this library supports 5.6, so the
	 * sign is applied by hand: a set top bit means the value is 2^16 less than it reads as.
	 */
	public static function int16($bytes)
	{
		$a = (ord($bytes[0]) << 8) + ord($bytes[1]);

		if ($a & (1 << 15)) {
			$a = $a - (1 << 16);
		}

		return $a;
	}

	/**
	 * Decode a uint32 already held as four bytes.
	 *
	 * The top byte is multiplied rather than shifted. On a 32-bit build, shifting it into place
	 * overflows into the sign bit and a table offset past 2GB comes back negative; multiplying
	 * promotes to float instead, which stays correct for every offset a font can carry.
	 */
	public static function uint32($bytes)
	{
		return (ord($bytes[0]) * 16777216) // 1 << 24
			+ (ord($bytes[1]) << 16)
			+ (ord($bytes[2]) << 8)
			+ ord($bytes[3]);
	}
}
