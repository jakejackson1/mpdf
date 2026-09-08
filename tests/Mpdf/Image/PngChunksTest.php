<?php

namespace Mpdf\Image;

/**
 * A PNG's metadata lives in its chunks. Searching the whole file for a chunk's name finds it just as
 * readily in a text chunk, or among the compressed samples.
 */
class PngChunksTest extends PngChunkTestCase
{

	public function testTheDensityComesFromThePhysChunkAndNotATextChunkThatSaysPhys()
	{
		// The decoy claims 99999 pixels per metre and comes first, so a search finds it before the real one
		$decoy = $this->text('pHYs' . pack('NN', 99999, 99999) . chr(1));
		$pHYs = $this->chunk('pHYs', pack('NN', 11811, 11811) . chr(1)); // 11811 per metre is 300 dpi

		$image = $this->render($this->withChunks($decoy . $pHYs));

		$this->assertSame(300.0, $image['set-dpi']);
	}

	/**
	 * An sRGB chunk overrides gAMA, so finding those four letters in a comment suppresses a gamma
	 * correction that should have happened
	 */
	public function testGammaSurvivesATextChunkThatMentionsSrgb()
	{
		$corrected = $this->render($this->withChunks($this->gama() . $this->text('and this one mentions sRGB')));

		$this->assertNotSame($this->plain(), $corrected['data']);
	}

	public function testARealSrgbChunkStillOverridesTheGamma()
	{
		$image = $this->render($this->withChunks($this->gama() . $this->chunk('sRGB', chr(0))));

		$this->assertSame($this->plain(), $image['data']);
	}

	/**
	 * A tRNS chunk is what gives a PNG without an alpha channel its transparency, and taking a comment
	 * for one costs the document a soft mask built out of an image that never had any
	 */
	public function testTransparencyIsNotInferredFromATextChunkThatMentionsTrns()
	{
		$this->render($this->withChunks($this->text('and this one mentions tRNS')));

		$this->assertCount(1, $this->mpdf->images);
	}

	/**
	 * An indexed PNG carrying a colour profile is one mPDF cannot embed as it stands, so it decodes and
	 * re-encodes the image through GD to be rid of it. A comment is not a colour profile
	 */
	public function testAnIndexedImageIsNotReEncodedForATextChunkThatMentionsIccp()
	{
		$palette = $this->palette();

		$untouched = $this->render($palette);
		$commented = $this->render($this->withChunks($this->text('and this one mentions iCCP'), $palette));

		$this->assertSame($untouched['data'], $commented['data']);
	}

	public function testTheDimensionsStillComeOffTheHeaderChunk()
	{
		$image = $this->render($this->source());

		$this->assertSame(24, $image['w']);
		$this->assertSame(16, $image['h']);
	}

	/**
	 * A gamma of 1.0, far enough from the 2.2 that processPng() treats as nothing to do
	 */
	private function gama()
	{
		return $this->chunk('gAMA', pack('N', 100000));
	}

	/**
	 * What the image comes out as when nothing has been added to it
	 */
	private function plain()
	{
		$image = $this->render($this->source());

		return $image['data'];
	}

	/**
	 * An indexed image, written at a compression level GD does not re-encode at, so a needless trip
	 * through GD shows up in the bytes
	 */
	private function palette()
	{
		$image = imagecreatetruecolor(64, 48);

		for ($y = 0; $y < 48; $y++) {
			for ($x = 0; $x < 64; $x++) {
				imagesetpixel($image, $x, $y, imagecolorallocate($image, ($x * 4) % 256, ($y * 5) % 256, ($x * $y) % 256));
			}
		}

		imagetruecolortopalette($image, false, 64);

		ob_start();
		imagepng($image, null, 9);

		return ob_get_clean();
	}

}
