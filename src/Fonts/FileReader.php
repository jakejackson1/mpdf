<?php

namespace Mpdf\Fonts;

/**
 * Reads an OpenType font file.
 *
 * Kept separate from BlobReader because a font file is megabytes of glyf outlines that the subsetter
 * walks but never needs in memory at once, while the shaper's copy of GSUB and GPOS is small enough
 * to hold as a string and much faster to read that way.
 */
class FileReader extends FontReader
{

	/**
	 * @var resource
	 */
	private $handle;

	/**
	 * @param string $filename
	 *
	 * @throws \Mpdf\Exception\FontException
	 */
	public function __construct($filename)
	{
		// Checked before opening rather than after: fopen raises a warning of its own on the way to
		// returning false, and the exception is the report that matters
		if (!is_file($filename) || !is_readable($filename)) {
			throw new \Mpdf\Exception\FontException(sprintf('Unable to open font file "%s"', $filename));
		}

		// 'rb' rather than 'r': on Windows the text mode would translate CR LF pairs inside glyph data
		$this->handle = fopen($filename, 'rb');

		if (!$this->handle) {
			throw new \Mpdf\Exception\FontException(sprintf('Unable to open font file "%s"', $filename));
		}
	}

	public function read($length)
	{
		$this->pos += $length;
		$data = fread($this->handle, $length);

		// fix for mpdf/mpdf#1504: reading from a compressed or buffered stream (phar://, say) ignores
		// the length asked for and stops at the stream's own buffer size, so the read has to be
		// finished off by hand rather than trusted to arrive whole
		$read = strlen($data);
		while ($read < $length && !feof($this->handle)) {
			$data .= fread($this->handle, $length - $read);
			$read = strlen($data);
		}

		return $data;
	}

	public function seek($position)
	{
		parent::seek($position);
		fseek($this->handle, $position);
	}

	public function skip($delta)
	{
		$this->pos += $delta;
		fseek($this->handle, $delta, SEEK_CUR);
	}

	/**
	 * int16, read without building an intermediate string. The parser makes tens of thousands of
	 * these per font, so the direct form is worth keeping.
	 */
	public function readInt16()
	{
		$this->pos += 2;

		return self::int16(fread($this->handle, 2));
	}

	public function readUInt16()
	{
		$this->pos += 2;
		$s = fread($this->handle, 2);

		return (ord($s[0]) << 8) + ord($s[1]);
	}

	/**
	 * uint32, and Offset32, and Version16Dot16 where the parser only compares it against known values
	 */
	public function readUInt32()
	{
		$this->pos += 4;

		return self::uint32(fread($this->handle, 4));
	}

	/**
	 * Tag: four bytes, read as text. Table and feature names - 'GSUB', 'liga', 'DFLT' - are tags.
	 */
	public function readTag()
	{
		$this->pos += 4;

		return fread($this->handle, 4);
	}

	/**
	 * @return int A uint16 at an absolute position, leaving the read position where it was in the
	 *             file but not in this object's count of it - the callers all seek again afterwards
	 */
	public function uint16At($position)
	{
		fseek($this->handle, $position);
		$s = fread($this->handle, 2);

		return (ord($s[0]) << 8) + ord($s[1]);
	}

	/**
	 * @return int A uint32 at an absolute position. See uint16At about the position.
	 */
	public function uint32At($position)
	{
		fseek($this->handle, $position);

		return self::uint32(fread($this->handle, 4));
	}

	/**
	 * @return string $length bytes from an absolute position. See uint16At about the position.
	 */
	public function bytesAt($position, $length)
	{
		fseek($this->handle, $position);
		$data = fread($this->handle, $length);

		$read = strlen($data);
		while ($read < $length && !feof($this->handle)) {
			$data .= fread($this->handle, $length - $read);
			$read = strlen($data);
		}

		return $data;
	}

	public function close()
	{
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}
	}
}
