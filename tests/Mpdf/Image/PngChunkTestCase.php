<?php

namespace Mpdf\Image;

/**
 * Building PNGs a chunk at a time, for the tests that check which chunk a value was read out of
 */
abstract class PngChunkTestCase extends \Mpdf\BaseMpdfTest
{

	use BuildsImageFixtures;

	/**
	 * A chunk, wrapped as PNG (Third Edition) 5.3 lays one out
	 */
	protected function chunk($type, $data)
	{
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	/**
	 * A comment, which is where a decoy goes: PNG 11.3.5.3 puts arbitrary text in a chunk of its own
	 */
	protected function text($comment)
	{
		return $this->chunk('tEXt', "Comment\0" . $comment);
	}

	/**
	 * Where a chunk of this type starts, walking the file the way PNG 5.3 lays it out
	 */
	protected function chunkOffset($data, $type)
	{
		$p = 8; // Past the signature

		while (substr($data, $p + 4, 4) !== $type) {
			$p += 12 + $this->fourBytesToInt(substr($data, $p, 4));
		}

		return $p;
	}

	/**
	 * Put chunks in behind one that is already there
	 */
	protected function insertAfter($data, $type, $chunks)
	{
		$p = $this->chunkOffset($data, $type);
		$end = $p + 12 + $this->fourBytesToInt(substr($data, $p, 4));

		return substr($data, 0, $end) . $chunks . substr($data, $end);
	}

	/**
	 * Put chunks straight after the header, ahead of everything the image really carries
	 */
	protected function withChunks($chunks, $data = null)
	{
		return $this->insertAfter($data === null ? $this->source() : $data, 'IHDR', $chunks);
	}

	protected function fourBytesToInt($bytes)
	{
		$value = unpack('N', $bytes);

		return $value[1];
	}

	/**
	 * A PNG assembled by hand, for the colour types and bit depths GD will not write
	 *
	 * @param int $ct PNG 11.2.2 colour type
	 * @param int $bitDepth
	 * @param string $samples Unfiltered rows, without their filter byte
	 * @param int $w
	 * @param int $h
	 * @param string $chunks Anything to carry between the header and the image data
	 */
	protected function build($ct, $bitDepth, $samples, $w, $h, $chunks = '')
	{
		$stride = strlen($samples) / $h;
		$raw = '';

		for ($y = 0; $y < $h; $y++) {
			$raw .= chr(0) . substr($samples, $y * $stride, $stride); // Filter type 0, PNG 9.2
		}

		return chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)
			. $this->chunk('IHDR', pack('NN', $w, $h) . chr($bitDepth) . chr($ct) . chr(0) . chr(0) . chr(0))
			. $chunks
			. $this->chunk('IDAT', gzcompress($raw))
			. $this->chunk('IEND', '');
	}

	/**
	 * A profile wrapped the way PNG 11.3.2.3 lays the chunk out: a name, a null, the compression method,
	 * then the deflated profile
	 */
	protected function iCCP($profile, $method = 0)
	{
		return $this->chunk('iCCP', 'p' . chr(0) . chr($method) . gzcompress($profile));
	}

	protected function source()
	{
		$image = imagecreatetruecolor(24, 16);
		imagefilledrectangle($image, 0, 0, 11, 15, imagecolorallocate($image, 200, 30, 30));
		imagefilledrectangle($image, 12, 0, 23, 15, imagecolorallocate($image, 30, 30, 200));

		ob_start();
		imagepng($image);

		return ob_get_clean();
	}

	/**
	 * Every image a document ends up carrying for this PNG, read in a document of its own so that the
	 * positions mPDF hands out do not depend on what the test rendered before
	 *
	 * A soft mask is an image in its own right, so a mask that should not be there shows up as an extra entry
	 */
	protected function readImages($data)
	{
		$mpdf = new \Mpdf\Mpdf(['mode' => 'c']);
		$mpdf->WriteHTML('<img src="data:image/png;base64,' . base64_encode($data) . '">');

		$images = array_values($mpdf->images);
		$mpdf->cleanup();

		foreach ($images as &$image) {
			unset($image['i'], $image['masked']); // Both are positions in the collection this is rebuilding
		}

		return $images;
	}

	protected function render($data)
	{
		$this->mpdf->WriteHTML('<img src="data:image/png;base64,' . base64_encode($data) . '">');

		return end($this->mpdf->images);
	}

}
