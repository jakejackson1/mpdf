<?php

namespace Mpdf\Fonts;

/**
 * The two backends have to agree byte for byte: the parser reads a font file and the shaper reads a
 * cached copy of two of its tables, and a lookup offset written by one is followed by the other.
 */
class FontReaderTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * Every data type, at a known offset, with the edge cases that the hand-rolled sign and overflow
	 * handling exists for
	 */
	const BYTES = "\x00\x01"      // uint16 1,        int16 1
		. "\xFF\xFF"              // uint16 65535,    int16 -1
		. "\x80\x00"              // uint16 32768,    int16 -32768
		. "\x7F\xFF"              // uint16 32767,    int16 32767
		. "\xFF\xFF\xFF\xFF"      // uint32 4294967295
		. "\x00\x01\x00\x00"      // uint32 65536 at offset 12, the version every TrueType file starts with
		. 'GSUB';                 // Tag at offset 16; 20 bytes in all

	private $file;

	protected function tear_down()
	{
		if ($this->file && file_exists($this->file)) {
			unlink($this->file);
		}

		parent::tear_down();
	}

	/**
	 * @dataProvider readerProvider
	 */
	public function testItReadsEveryDataTypeTheSpecDefines($which)
	{
		$reader = $this->reader($which);

		$this->assertSame(1, $reader->readUInt16());
		$this->assertSame(65535, $reader->readUInt16());
		$this->assertSame(32768, $reader->readUInt16());
		$this->assertSame(32767, $reader->readUInt16());
		$this->assertSame(8, $reader->tell());
	}

	/**
	 * @dataProvider readerProvider
	 */
	public function testInt16IsSignedTwosComplement($which)
	{
		$reader = $this->reader($which);

		$this->assertSame(1, $reader->readInt16());
		$this->assertSame(-1, $reader->readInt16());
		$this->assertSame(-32768, $reader->readInt16());
		$this->assertSame(32767, $reader->readInt16());
	}

	/**
	 * @dataProvider readerProvider
	 */
	public function testSeekAndSkipMoveToTheSamePlace($which)
	{
		$reader = $this->reader($which);

		$reader->seek(4);
		$this->assertSame(32768, $reader->readUInt16());
		$this->assertSame(6, $reader->tell());

		$reader->skip(-2);
		$this->assertSame(4, $reader->tell());
		$this->assertSame(32768, $reader->readUInt16());

		$reader->skip(2);
		$this->assertSame(8, $reader->tell());
	}

	/**
	 * @dataProvider readerProvider
	 */
	public function testReadReturnsTheBytesAsked($which)
	{
		$reader = $this->reader($which);

		$reader->seek(12);
		$this->assertSame("\x00\x01\x00\x00", $reader->read(4));
		$this->assertSame('GSUB', $reader->read(4));
		$this->assertSame(20, $reader->tell(), 'the fixture is 20 bytes long');
	}

	public function readerProvider()
	{
		return ['file' => ['file'], 'blob' => ['blob']];
	}

	/**
	 * A uint32 that fills all 32 bits must not come back negative. Shifting the top byte into place
	 * lands it on the sign bit of a 32-bit int, so the decoder multiplies instead, which promotes to
	 * float rather than wrapping.
	 */
	public function testAFullWidthUInt32DoesNotWrapNegative()
	{
		$reader = new FileReader($this->write());
		$reader->seek(8);

		$value = $reader->readUInt32();
		$this->assertTrue($value > 0, 'a uint32 of 0xFFFFFFFF must not read as negative');
		$this->assertEquals(4294967295, $value);
		$this->assertSame(65536, $reader->readUInt32());
		$reader->close();
	}

	public function testATagIsReadAsFourBytesOfText()
	{
		$reader = new FileReader($this->write());
		$reader->seek(16);

		$this->assertSame('GSUB', $reader->readTag());
		$reader->close();
	}

	/**
	 * The absolute readers are how the parser follows an offset without losing its place in a walk
	 */
	public function testTheAbsoluteReadersDoNotDependOnTheCurrentPosition()
	{
		$reader = new FileReader($this->write());
		$reader->seek(0);

		$this->assertSame(32768, $reader->uint16At(4));
		$this->assertEquals(4294967295, $reader->uint32At(8));
		$this->assertSame('GSUB', $reader->bytesAt(16, 4));
		$reader->close();
	}

	public function testOpeningAFontThatIsNotThereIsRefused()
	{
		$this->expectException('Mpdf\Exception\FontException');

		new FileReader(__DIR__ . '/no-such-font.ttf');
	}

	private function reader($which)
	{
		return $which === 'blob' ? new BlobReader(self::BYTES) : new FileReader($this->write());
	}

	private function write()
	{
		$this->file = tempnam(sys_get_temp_dir(), 'mpdf-reader');
		file_put_contents($this->file, self::BYTES);

		return $this->file;
	}

}
