<?php

namespace Mpdf\Image;

/**
 * Where PngChunksTest covers the chunks whose mere presence changes what mPDF does, these cover the ones
 * a value is read out of. Those are the worse case: a name matched in a comment or among the compressed
 * samples does not just set a flag, it gives a length and an offset to read a value from.
 */
class PngChunkValuesTest extends PngChunkTestCase
{

	/**
	 * PNG 11.3.1.1 gives a greyscale image one two-byte sample, the shade to leave out
	 */
	public function testTheTransparentShadeOfAGreyscaleImageIsNotReadFromAComment()
	{
		$tRNS = $this->chunk('tRNS', pack('n', 0x40));
		$samples = '';

		for ($y = 0; $y < 8; $y++) {
			$samples .= str_repeat(chr(0x40), 6) . str_repeat(chr(0xC0), 6); // Half the image is the shade above
		}

		$honest = $this->build(0, 8, $samples, 12, 8, $tRNS);
		$decoyed = $this->build(0, 8, $samples, 12, 8, $this->text('tRNS' . str_repeat('x', 30)) . $tRNS);

		$this->assertEquals($this->readImages($honest), $this->readImages($decoyed));
	}

	/**
	 * The same chunk at sixteen bits a sample, where the length matters twice over
	 */
	public function testTheTransparentShadeIsNotReadFromACommentAtSixteenBits()
	{
		$tRNS = $this->chunk('tRNS', pack('n', 0x4040));
		$samples = '';

		for ($y = 0; $y < 8; $y++) {
			$samples .= str_repeat(pack('n', 0x4040), 6) . str_repeat(pack('n', 0xC0C0), 6);
		}

		$honest = $this->build(0, 16, $samples, 12, 8, $tRNS);
		$decoyed = $this->build(0, 16, $samples, 12, 8, $this->text('tRNS' . str_repeat('x', 30)) . $tRNS);

		$this->assertEquals($this->readImages($honest), $this->readImages($decoyed));
	}

	/**
	 * PNG 11.3.1.1 gives a truecolour image three two-byte samples, the colour to leave out
	 */
	public function testTheTransparentColourOfATruecolourImageIsNotReadFromAComment()
	{
		$tRNS = $this->chunk('tRNS', pack('nnn', 0xAA, 0xBB, 0xCC));
		$samples = '';

		for ($y = 0; $y < 8; $y++) {
			$samples .= str_repeat(chr(0xAA) . chr(0xBB) . chr(0xCC), 6) . str_repeat(chr(10) . chr(20) . chr(30), 6);
		}

		$honest = $this->build(2, 8, $samples, 12, 8, $tRNS);
		$decoyed = $this->build(2, 8, $samples, 12, 8, $this->text('tRNS' . str_repeat('x', 30)) . $tRNS);

		$this->assertEquals($this->readImages($honest), $this->readImages($decoyed));
	}

	/**
	 * PNG 11.3.1.1 gives an indexed image one alpha byte per palette entry, in palette order
	 */
	public function testThePaletteAlphaIsNotReadFromAComment()
	{
		$palette = $this->indexed();
		$tRNS = $this->chunk('tRNS', chr(0) . chr(128) . str_repeat(chr(255), 14));

		// PNG 5.6 has tRNS follow PLTE for an indexed image
		$honest = $this->insertAfter($palette, 'PLTE', $tRNS);
		$decoyed = $this->insertAfter($palette, 'PLTE', $this->text('tRNS' . str_repeat('x', 30)) . $tRNS);

		$this->assertEquals($this->readImages($honest), $this->readImages($decoyed));
	}

	/**
	 * PNG 11.3.2.3: a name, a null, a compression method, then the deflated profile. A comment naming the
	 * chunk sends all three of those offsets into the middle of the comment
	 *
	 * An indexed image is where this shows: mPDF cannot embed a palette and a profile at once, so a profile
	 * it can use sends the image through GD to come out as RGB, and one it cannot read leaves it indexed
	 */
	public function testTheColourProfileIsNotReadFromAComment()
	{
		$iCCP = $this->iCCP($this->iccProfile());
		$palette = $this->indexed();

		$honest = $this->readImages($this->withChunks($iCCP, $palette));
		$decoyed = $this->readImages($this->withChunks($this->text('iCCP' . str_repeat('x', 90)) . $iCCP, $palette));

		$this->assertSame('DeviceRGB', $honest[0]['cs']);
		$this->assertEquals($honest, $decoyed);
	}

	public function testAProfileIsEmbeddedWithAnRgbImage()
	{
		$profile = $this->iccProfile(200);
		$image = $this->readImages($this->withChunks($this->iCCP($profile)));

		$this->assertSame($profile, $image[0]['icc']);
	}

	/**
	 * The chunk is deflated, so a few kilobytes can inflate to anything. 8,000,000 bytes is what libpng
	 * lets a chunk grow to (PNG_USER_CHUNK_MALLOC_MAX), and a profile that goes past it is not read
	 */
	public function testAProfileThatInflatesPastLibpngsLimitIsIgnored()
	{
		$image = $this->readImages($this->withChunks($this->iCCP($this->iccProfile(8000001))));

		$this->assertFalse($image[0]['icc']);
	}

	/**
	 * ICC.1 7.2.2 has a profile open with its own size, and that is the profile; what follows is not
	 */
	public function testAProfileIsTrimmedToTheSizeItsHeaderGives()
	{
		$profile = $this->iccProfile(200);
		$image = $this->readImages($this->withChunks($this->iCCP($profile . str_repeat('x', 40))));

		$this->assertSame($profile, $image[0]['icc']);
	}

	public function testAProfileCutShortOfTheSizeItsHeaderGivesIsIgnored()
	{
		$image = $this->readImages($this->withChunks($this->iCCP(substr($this->iccProfile(200), 0, 180))));

		$this->assertFalse($image[0]['icc']);
	}

	/**
	 * PNG 11.3.2.3 defines one compression method, 0; the bytes under any other are not a deflate stream
	 */
	public function testAProfileUnderAnUndefinedCompressionMethodIsIgnored()
	{
		$image = $this->readImages($this->withChunks($this->iCCP($this->iccProfile(), 1)));

		$this->assertFalse($image[0]['icc']);
	}

	/**
	 * PNG 11.2.3 lets an encoder split the image over as many IDAT chunks as it likes, and says nothing
	 * about them being the same size
	 */
	public function testTheImageDataIsAssembledFromEveryIdatChunk()
	{
		$whole = $this->source();
		$data = $this->deflatedSamples($whole);

		$split = substr($whole, 0, $this->chunkOffset($whole, 'IDAT'))
			. $this->chunk('IDAT', substr($data, 0, 3))
			. $this->chunk('IDAT', substr($data, 3))
			. $this->chunk('IEND', '');

		$this->assertEquals($this->readImages($whole), $this->readImages($split));
	}

	/**
	 * A chunk may carry no data at all - PNG 11.3.5.3 puts no lower bound on a comment - and finding one
	 * is no reason to stop reading the chunks that follow
	 */
	public function testAnEmptyChunkDoesNotCutTheScanShort()
	{
		$this->assertEquals(
			$this->readImages($this->source()),
			$this->readImages($this->withChunks($this->chunk('tEXt', '')))
		);
	}

	/**
	 * PNG 11.2.2: three bytes an entry, and the entry count is the chunk's length divided by three
	 */
	public function testThePaletteIsNotReadFromAComment()
	{
		$palette = $this->indexed();

		$honest = $this->readImages($palette);
		$decoyed = $this->readImages($this->withChunks($this->text('PLTE' . str_repeat('x', 60)), $palette));

		$this->assertEquals($honest, $decoyed);
	}

	private function deflatedSamples($data)
	{
		$p = $this->chunkOffset($data, 'IDAT');

		return substr($data, $p + 8, $this->fourBytesToInt(substr($data, $p, 4)));
	}

	/**
	 * A sixteen-entry indexed image, so a tRNS chunk for it is a known length
	 */
	private function indexed()
	{
		$image = imagecreate(12, 8);

		for ($c = 0; $c < 16; $c++) {
			imagecolorallocate($image, $c * 16, 255 - $c * 16, ($c * 7) % 256);
		}

		for ($y = 0; $y < 8; $y++) {
			for ($x = 0; $x < 12; $x++) {
				imagesetpixel($image, $x, $y, ($x + $y) % 16);
			}
		}

		ob_start();
		imagepng($image);

		return ob_get_clean();
	}

}
