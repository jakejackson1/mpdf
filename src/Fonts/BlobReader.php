<?php

namespace Mpdf\Fonts;

/**
 * Reads an OpenType table held as a string.
 *
 * This is what the shaper reads: the GSUB and GPOS tables are copied out of the font at build time
 * and cached, and Otl walks that copy once per shaped run of text. Indexing a string with ord() is
 * several times faster than seeking and reading a file handle, which is why this exists separately
 * rather than everything going through FileReader.
 */
class BlobReader extends FontReader
{

	/**
	 * @var string
	 */
	private $bytes;

	/**
	 * @param string $bytes One OpenType table, as cached
	 */
	public function __construct($bytes)
	{
		$this->bytes = $bytes;
	}

	public function read($length)
	{
		$data = substr($this->bytes, $this->pos, $length);
		$this->pos += $length;

		return $data;
	}

	/**
	 * int16 straight off the string. This and readUInt16 are the hottest two methods in shaping -
	 * a chained context lookup makes one call per glyph per rule - so neither builds a substring.
	 */
	public function readInt16()
	{
		$a = (ord($this->bytes[$this->pos]) << 8) + ord($this->bytes[$this->pos + 1]);
		$this->pos += 2;

		if ($a & (1 << 15)) {
			$a = $a - (1 << 16);
		}

		return $a;
	}

	public function readUInt16()
	{
		$a = (ord($this->bytes[$this->pos]) << 8) + ord($this->bytes[$this->pos + 1]);
		$this->pos += 2;

		return $a;
	}
}
