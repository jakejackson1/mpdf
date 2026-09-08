<?php

namespace Mpdf\Image;

use Mpdf\AssetFetcher;
use Mpdf\Cache;
use Mpdf\Color\ColorConverter;
use Mpdf\Color\ColorModeConverter;
use Mpdf\CssManager;
use Mpdf\Gif\Gif;
use Mpdf\Language\LanguageToFontInterface;
use Mpdf\Language\ScriptToLanguageInterface;
use Mpdf\Log\Context as LogContext;
use Mpdf\Mpdf;
use Mpdf\Otl;
use Mpdf\PsrLogAwareTrait\PsrLogAwareTrait;
use Mpdf\SizeConverter;
use Psr\Log\LoggerInterface;

class ImageProcessor implements \Psr\Log\LoggerAwareInterface
{

	use PsrLogAwareTrait;

	/**
	 * The most a PNG's deflated colour profile may inflate to, in bytes: libpng's PNG_USER_CHUNK_MALLOC_MAX
	 */
	const PNG_ICC_PROFILE_MAX = 8000000;

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	/**
	 * @var \Mpdf\Otl
	 */
	private $otl;

	/**
	 * @var \Mpdf\CssManager
	 */
	private $cssManager;

	/**
	 * @var \Mpdf\SizeConverter
	 */
	private $sizeConverter;

	/**
	 * @var \Mpdf\Color\ColorConverter
	 */
	private $colorConverter;

	/**
	 * @var \Mpdf\Color\ColorModeConverter
	 */
	private $colorModeConverter;

	/**
	 * @var \Mpdf\Cache
	 */
	private $cache;

	/**
	 * @var \Mpdf\Image\ImageTypeGuesser
	 */
	private $guesser;

	/**
	 * @var string[]
	 */
	private $failedImages;

	/**
	 * @var \Mpdf\Image\Bmp
	 */
	private $bmp;

	/**
	 * @var \Mpdf\Image\Wmf
	 */
	private $wmf;

	/**
	 * @var \Mpdf\Language\LanguageToFontInterface
	 */
	private $languageToFont;

	/**
	 * @var \Mpdf\Language\ScriptToLanguageInterface
	 */
	public $scriptToLanguage;

	/**
	 * @var \Mpdf\AssetFetcher
	 */
	private $assetFetcher;

	public function __construct(
		Mpdf $mpdf,
		Otl $otl,
		CssManager $cssManager,
		SizeConverter $sizeConverter,
		ColorConverter $colorConverter,
		ColorModeConverter $colorModeConverter,
		Cache $cache,
		LanguageToFontInterface $languageToFont,
		ScriptToLanguageInterface $scriptToLanguage,
		AssetFetcher $assetFetcher,
		LoggerInterface $logger
	) {

		$this->mpdf = $mpdf;
		$this->otl = $otl;
		$this->cssManager = $cssManager;
		$this->sizeConverter = $sizeConverter;
		$this->colorConverter = $colorConverter;
		$this->colorModeConverter = $colorModeConverter;
		$this->cache = $cache;
		$this->languageToFont = $languageToFont;
		$this->scriptToLanguage = $scriptToLanguage;
		$this->assetFetcher = $assetFetcher;

		$this->logger = $logger;

		$this->guesser = new ImageTypeGuesser();

		$this->failedImages = [];
	}

	public function getImage(&$file, $firstTime = true, $allowvector = true, $orig_srcpath = false, $interpolation = false)
	{
		// mPDF 6
		// firsttime i.e. whether to add to this->images - use false when calling iteratively
		// Image Data passed directly as var:varname

		$type = null;
		$data = '';

		if (preg_match('/var:\s*(.*)/', $file, $v)) {
			if (!isset($this->mpdf->imageVars[$v[1]])) {
				return $this->imageError($file, $firstTime, 'Unknown image variable');
			}

			$data = $this->mpdf->imageVars[$v[1]];
			$file = md5($data);
		}

		if (preg_match('/data:image\/(gif|jpe?g|png|webp|svg\+xml);base64,(.*)/', $file, $v)) {
			$type = $v[1];
			$data = base64_decode($v[2]);
			$file = md5($data);
		}

		// mPDF 5.7.4 URLs
		if ($firstTime && $file && strpos($file, 'data:') !== 0) {
			$file = str_replace(' ', '%20', $file);
		}

		if ($firstTime && $orig_srcpath) {
			// If orig_srcpath is a relative file path (and not a URL), then it needs to be URL decoded
			if (strpos($orig_srcpath, 'data:') !== 0) {
				$orig_srcpath = str_replace(' ', '%20', $orig_srcpath);
			}
			if (!preg_match('/^(http|ftp)/', $orig_srcpath)) {
				$orig_srcpath = $this->urldecodeParts($orig_srcpath);
			}
		}

		if ($orig_srcpath && isset($this->mpdf->images[$orig_srcpath])) {
			$file = $orig_srcpath;
			return $this->mpdf->images[$orig_srcpath];
		}

		if (isset($this->mpdf->images[$file])) {
			return $this->mpdf->images[$file];
		}

		if ($orig_srcpath && isset($this->mpdf->formobjects[$orig_srcpath])) {
			$file = $orig_srcpath;
			return $this->mpdf->formobjects[$file];
		}

		if (isset($this->mpdf->formobjects[$file])) {
			return $this->mpdf->formobjects[$file];
		}

		if ($firstTime && isset($this->failedImages[$file])) { // Save re-trying image URL's which have already failed
			return $this->imageError($file, $firstTime, '');
		}

		if (!$data) {
			try {
				$data = $this->assetFetcher->fetchDataFromPath($file, $orig_srcpath);
			} catch (\Mpdf\Exception\AssetFetchingException $e) {
				return $this->imageError($orig_srcpath, $firstTime, $e->getMessage());
			}
		}

		if (!$data) {
			return $this->imageError($file, $firstTime, 'Could not find image file');
		}

		if ($type === null) {
			$type = $this->guesser->guess($data);
		}

		if ($type === 'svg' || $type === 'svg+xml') {
			if (!$allowvector) {
				return $this->imageError($file, $firstTime, 'SVG image file not supported in this context');
			}
			return $this->processSvg($data, $file, $firstTime);
		}

		if ($type === 'wmf') {
			if (!$allowvector) {
				return $this->imageError($file, $firstTime, 'WMF image file not supported in this context');
			}
			return $this->processWmf($data, $file, $firstTime);
		}

		if ($type === 'webp') {
			// Convert webp images to JPG and treat them as such
			$data = $this->processWebp($data, $file, $firstTime);
			$type = 'jpeg';
		}

		if ($type === 'avif') {
			// Convert avif images to JPG and treat them as such
			$data = $this->processAvif($data, $file, $firstTime);
			$type = 'jpeg';
		}

		// JPEG
		if ($type === 'jpeg' || $type === 'jpg') {
			return $this->processJpg($data, $file, $firstTime, $interpolation);
		}

		if ($type === 'png') {
			return $this->processPng($data, $file, $firstTime, $interpolation);
		}

		if ($type === 'gif') { // GIF
			return $this->processGif($data, $file, $firstTime, $interpolation);
		}

		if ($type === 'bmp') {
			return $this->processBmp($data, $file, $firstTime, $interpolation);
		}

		return $this->processUnknownType($data, $file, $firstTime, $interpolation);
	}

	private function convertImage(&$data, $colspace, $targetcs, $w, $h, $dpi, $mask, $gamma_correction = false, $pngcolortype = false)
	{
		if (!function_exists('gd_info')) {
			return $this->imageError('', false, 'GD library needed to parse image files');
		}

		if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
			$mask = false;
		}

		$im = $this->imageFromString($data, 3); // libgd's PNG reader holds a second copy while decoding, then the samples are rewritten into a string, and a mask
		$info = [];
		$bpc = ord(substr($data, 24, 1));
		$chunks = $this->pngChunksBeforeImageData($data); // Empty for the JPEG callers, which pass no mask
		$tRNS = isset($chunks['tRNS']) ? $chunks['tRNS'] : null; // PNG 11.3.1.1

		if ($im) {
			$imgdata = '';
			$mimgdata = '';
			$minfo = [];

			// mPDF 6 Gamma correction
			// Need to extract alpha channel info before imagegammacorrect (which loses the data)
			if ($mask) { // i.e. $pngalpha for PNG
				// mPDF 6
				if ($colspace === 'Indexed') { // generate Alpha channel values from tRNS - only from PNG
					// Read transparency info: PNG 11.3.1.1, one alpha value per palette entry
					$transparency = '';
					if ($tRNS) {
						$n = $tRNS['size'];
						$transparency = substr($data, $tRNS['payload'], $n);
						// ord($transparency[$index]) = the alpha value for that index
						// generate alpha channel
						for ($ypx = 0; $ypx < $h; ++$ypx) {
							for ($xpx = 0; $xpx < $w; ++$xpx) {
								$colorindex = imagecolorat($im, $xpx, $ypx);
								if ($colorindex >= $n) {
									$alpha = 255;
								} else {
									$alpha = ord($transparency[$colorindex]);
								} // 0-255
								$mimgdata .= chr($alpha);
							}
						}
					}
				} elseif ($pngcolortype === 0 || $pngcolortype === 2) { // generate Alpha channel values from tRNS
					// Get transparency as array of RGB: PNG 11.3.1.1, one sample per channel
					if ($tRNS) {
						$trns = '';
						$t = substr($data, $tRNS['payload'], $tRNS['size']);
						if ($colspace === 'DeviceGray') {  // ct===0
							$trns = [$this->translateValue(substr($t, 0, 2), $bpc)];
						} else /* $colspace=='DeviceRGB' */ {  // ct==2
							$trns = [];
							$trns[0] = $this->translateValue(substr($t, 0, 2), $bpc);
							$trns[1] = $this->translateValue(substr($t, 2, 2), $bpc);
							$trns[2] = $this->translateValue(substr($t, 4, 2), $bpc);
						}

						// generate alpha channel
						$gray = $colspace === 'DeviceGray'; // ct===0, as against ct==2
						for ($ypx = 0; $ypx < $h; ++$ypx) {
							for ($xpx = 0; $xpx < $w; ++$xpx) {
								$rgb = imagecolorat($im, $xpx, $ypx);
								$r = ($rgb >> 16) & 0xFF;
								$g = ($rgb >> 8) & 0xFF;
								$b = $rgb & 0xFF;
								if ($gray) {
									$alpha = $b == $trns[0] ? 0 : 255;
								} elseif ($r == $trns[0] && $g == $trns[1] && $b == $trns[2]) {
									$alpha = 0;
								} else {
									$alpha = 255;
								}
								$mimgdata .= chr($alpha);
							}
						}
					}
				} else {
					for ($i = 0; $i < $h; $i++) {
						for ($j = 0; $j < $w; $j++) {
							$rgb = imagecolorat($im, $j, $i);
							$alpha = ($rgb & 0x7F000000) >> 24;
							if ($alpha < 127) {
								$mimgdata .= chr(255 - ($alpha * 2));
							} else {
								$mimgdata .= chr(0);
							}
						}
					}
				}
			}

			// mPDF 6 Gamma correction
			if ($gamma_correction) {
				imagegammacorrect($im, $gamma_correction, 2.2);
			}

			// Read transparency info
			$trns = [];
			$trnsrgb = false;
			if (!$this->mpdf->PDFA && !$this->mpdf->PDFX && !$mask) {  // mPDF 6 added NOT mask
				if ($tRNS) {
					$t = substr($data, $tRNS['payload'], $tRNS['size']);
					if ($colspace === 'DeviceGray') {  // ct===0
						$trns = [$this->translateValue(substr($t, 0, 2), $bpc)];
					} elseif ($colspace === 'DeviceRGB') {  // ct==2
						$trns[0] = $this->translateValue(substr($t, 0, 2), $bpc);
						$trns[1] = $this->translateValue(substr($t, 2, 2), $bpc);
						$trns[2] = $this->translateValue(substr($t, 4, 2), $bpc);
						$trnsrgb = $trns;
						if ($targetcs === 'DeviceCMYK') {
							$col = $this->colorModeConverter->rgb2cmyk([3, $trns[0], $trns[1], $trns[2]]);
							$c1 = (int) ($col[1] * 2.55);
							$c2 = (int) ($col[2] * 2.55);
							$c3 = (int) ($col[3] * 2.55);
							$c4 = (int) ($col[4] * 2.55);
							$trns = [$c1, $c2, $c3, $c4];
						} elseif ($targetcs === 'DeviceGray') {
							$c = (int) (($trns[0] * .21) + ($trns[1] * .71) + ($trns[2] * .07));
							$trns = [$c];
						}
					} else { // Indexed
						$pos = strpos($t, chr(0));
						if (is_int($pos)) {
							$pal = imagecolorsforindex($im, $pos);
							$r = $pal['red'];
							$g = $pal['green'];
							$b = $pal['blue'];
							$trns = [$r, $g, $b]; // ****
							$trnsrgb = $trns;
							if ($targetcs === 'DeviceCMYK') {
								$col = $this->colorModeConverter->rgb2cmyk([3, $r, $g, $b]);
								$c1 = (int) ($col[1] * 2.55);
								$c2 = (int) ($col[2] * 2.55);
								$c3 = (int) ($col[3] * 2.55);
								$c4 = (int) ($col[4] * 2.55);
								$trns = [$c1, $c2, $c3, $c4];
							} elseif ($targetcs === 'DeviceGray') {
								$c = (int) (($r * .21) + ($g * .71) + ($b * .07));
								$trns = [$c];
							}
						}
					}
				}
			}

			// None of this changes from one pixel to the next, so decide it here rather than in a loop that runs
			// once per pixel: three string comparisons, and the two array literals the transparency check built
			$indexed = $colspace === 'Indexed';
			$toCmyk = $targetcs === 'DeviceCMYK';
			$toGray = $targetcs === 'DeviceGray';
			$toRgb = $targetcs === 'DeviceRGB';
			$ncols = $toCmyk ? 4 : ($toGray ? 1 : 3);
			list($tr, $tg, $tb) = $trnsrgb ? $trnsrgb : [null, null, null];

			for ($i = 0; $i < $h; $i++) {
				for ($j = 0; $j < $w; $j++) {
					$rgb = imagecolorat($im, $j, $i);
					$r = ($rgb >> 16) & 0xFF;
					$g = ($rgb >> 8) & 0xFF;
					$b = $rgb & 0xFF;
					if ($indexed) {
						$pal = imagecolorsforindex($im, $rgb);
						$r = $pal['red'];
						$g = $pal['green'];
						$b = $pal['blue'];
					}

					if ($toCmyk) {
						$col = $this->colorModeConverter->rgb2cmyk([3, $r, $g, $b]);
						$c1 = (int) ($col[1] * 2.55);
						$c2 = (int) ($col[2] * 2.55);
						$c3 = (int) ($col[3] * 2.55);
						$c4 = (int) ($col[4] * 2.55);
						// original pixel was not set as transparent but processed color does match
						if ($trnsrgb && ($r !== $tr || $g !== $tg || $b !== $tb)
							&& $c1 === $trns[0] && $c2 === $trns[1] && $c3 === $trns[2] && $c4 === $trns[3]) {
							if ($c4 === 0) {
								$c4 = 1;
							} else {
								$c4--;
							}
						}
						$imgdata .= chr($c1) . chr($c2) . chr($c3) . chr($c4);
					} elseif ($toGray) {
						$c = (int) (($r * .21) + ($g * .71) + ($b * .07));
						// original pixel was not set as transparent but processed color does match
						if ($trnsrgb && ($r !== $tr || $g !== $tg || $b !== $tb) && $c === $trns[0]) {
							if ($c === 0) {
								$c = 1;
							} else {
								$c--;
							}
						}
						$imgdata .= chr($c);
					} elseif ($toRgb) {
						$imgdata .= chr($r) . chr($g) . chr($b);
					}
				}
			}

			$imgdata = $this->gzCompress($imgdata);
			$info = ['w' => $w, 'h' => $h, 'cs' => $targetcs, 'bpc' => 8, 'f' => 'FlateDecode', 'data' => $imgdata, 'type' => 'png',
				'parms' => '/DecodeParms <</Colors ' . $ncols . ' /BitsPerComponent 8 /Columns ' . $w . '>>'];
			if ($dpi) {
				$info['set-dpi'] = $dpi;
			}
			if ($mask) {
				$mimgdata = $this->gzCompress($mimgdata);
				$minfo = ['w' => $w, 'h' => $h, 'cs' => 'DeviceGray', 'bpc' => 8, 'f' => 'FlateDecode', 'data' => $mimgdata, 'type' => 'png',
					'parms' => '/DecodeParms <</Colors ' . $ncols . ' /BitsPerComponent 8 /Columns ' . $w . '>>'];
				if ($dpi) {
					$minfo['set-dpi'] = $dpi;
				}
				$tempfile = '_tempImgPNG' . md5($data) . random_int(1, 10000) . '.png';
				$imgmask = count($this->mpdf->images) + 1;
				$minfo['i'] = $imgmask;
				$this->mpdf->images[$tempfile] = $minfo;
				$info['masked'] = $imgmask;
			} elseif ($trns) {
				$info['trns'] = $trns;
			}

			$this->destroyImage($im);
		}
		return $info;
	}

	private function jpgHeaderFromString(&$data)
	{
		foreach ($this->jpgSegments($data) as $segment) {
			// Baseline, extended sequential and progressive DCT, T.81 Table B.1
			if ($segment['marker'] >= 0xC0 && $segment['marker'] <= 0xC2) {
				// T.81 B.2.2 makes the frame header 8 bytes plus 3 a component; shorter and the dimensions
				// would be read out of whatever follows it, which is where libjpeg's get_sof() draws the line too
				return $segment['size'] < 8 ? false : substr($data, $segment['offset'] + 2, 10);
			}
		}

		return false;
	}

	/**
	 * The density a JPEG's JFIF segment gives it, in dots per inch, or 0 where it has none
	 *
	 * The APP0 segment is ITU-T T.871 6.3: the identifier, two version bytes, then units and density.
	 * Its length is 16 plus the thumbnail, so anything shorter has no density field to read.
	 *
	 * @param string $data
	 *
	 * @return int
	 */
	private function jpgDensity($data)
	{
		foreach ($this->jpgSegments($data) as $segment) {

			if ($segment['marker'] !== 0xE0 || $segment['size'] < 16 || substr($data, $segment['payload'], 5) !== "JFIF\0") { // APP0
				continue;
			}

			$unitSp = ord($data[$segment['payload'] + 7]);

			if ($unitSp === 0) { // An aspect ratio, not a density
				return 0;
			}

			$density = $this->twoBytesToInt(substr($data, $segment['payload'] + 8, 2));

			return $unitSp === 2 ? (int) round($density / 10 * 25.4) : $density; // 2 is dots per centimetre, 1 per inch
		}

		return 0;
	}

	/**
	 * The quality to hand imagejpeg(), clamped to the -1 to 100 PHP 8 insists on with an exception @ does not catch
	 *
	 * @return int
	 */
	private function jpegQuality()
	{
		return max(-1, min(100, (int) $this->mpdf->imageJpegQuality));
	}

	/**
	 * Decode an image with GD, unless its header says the result would not fit memory_limit
	 *
	 * The check is against PHP's own limit because a build with an external libgd allocates the pixels
	 * outside it, where nothing else would say no. An image PHP cannot size (AVIF before 8.2, GD2) goes
	 * to GD unchecked.
	 *
	 * @param string $data
	 * @param float $copies How many images the size of this one the caller goes on to hold at once
	 *
	 * @return resource|\GdImage|false False when GD cannot read it, or should not be asked to
	 */
	private function imageFromString($data, $copies = 1)
	{
		$size = @getimagesizefromstring($data);

		if ($size) {
			$limit = (string) ini_get('memory_limit'); // -1, or a number with an optional K, M or G shorthand
			$units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];
			$unit = strtolower(substr($limit, -1));
			$bytes = (int) $limit * (isset($units[$unit]) ? $units[$unit] : 1);
			$bytesPerPixel = $size[2] === IMAGETYPE_GIF ? 1 : 4; // A GIF decodes to a palette image, everything else to truecolor

			if ($bytes > 0 && $size[0] * $size[1] * $bytesPerPixel * $copies > $bytes - memory_get_usage()) {
				$this->logger->warning(sprintf('Not decoding a %dx%d image, which would take more than memory_limit leaves', $size[0], $size[1]), ['context' => LogContext::IMAGES]);
				return false;
			}
		}

		return @imagecreatefromstring($data);
	}

	private function jpgDataFromHeader($hdr)
	{
		$bpc = ord(substr($hdr, 2, 1));

		if (!$bpc) {
			$bpc = 8;
		}

		$h = $this->twoBytesToInt(substr($hdr, 3, 2));
		$w = $this->twoBytesToInt(substr($hdr, 5, 2));

		$channels = ord(substr($hdr, 7, 1));

		if ($channels === 3) {
			$colspace = 'DeviceRGB';
		} elseif ($channels === 4) {
			$colspace = 'DeviceCMYK';
		} else {
			$colspace = 'DeviceGray';
		}

		return [$w, $h, $colspace, $bpc, $channels];
	}

	/**
	 * Index a JPEG's marker segments
	 *
	 * Stops at the scan, past which "Exif" and "ICC_PROFILE" are as likely to be pixels as markers.
	 *
	 * Marker codes are ITU-T T.81 Table B.1, and the size field is T.81 B.1.1.4: it counts itself and
	 * excludes the two marker bytes, which is why a payload starts four bytes past the marker.
	 *
	 * @param string $data
	 *
	 * Yields rather than returns, so a file made of nothing but empty segments costs one entry at a time.
	 *
	 * @return \Generator Each entry has an offset (of the marker), a marker byte, a size (counting the two bytes
	 *                    the size itself occupies) and a payload offset, which is where the size field leaves off
	 */
	private function jpgSegments($data)
	{
		$length = strlen($data);

		if ($length < 4 || substr($data, 0, 2) !== "\xFF\xD8") { // SOI
			return;
		}

		$p = 2;

		while ($p + 4 <= $length && $data[$p] === "\xFF") {

			$marker = ord($data[$p + 1]);

			if ($marker === 0xFF) { // Fill byte
				$p++;
				continue;
			}

			// SOS, EOI, and the markers Table B.1 stars as standalone (RSTn and SOI) have no size to step over
			if (($marker >= 0xD0 && $marker <= 0xDA) || $marker === 0x01) { // TEM is X'FF01'
				break;
			}

			$size = $this->twoBytesToInt(substr($data, $p + 2, 2));

			if ($size < 2 || $p + 2 + $size > $length) {
				break;
			}

			yield ['offset' => $p, 'marker' => $marker, 'size' => $size, 'payload' => $p + 4];
			$p += 2 + $size;
		}
	}

	/**
	 * Read the Orientation tag out of a JPEG's Exif APP1 segment
	 *
	 * The segment is CIPA DC-008 (Exif 2.32) 4.7.2: the APP1 marker and size, the "Exif\0\0" identifier,
	 * then a TIFF header every offset inside is relative to.
	 *
	 * @param string $data
	 *
	 * @return int 1 to 8, 1 being the identity that images without an orientation are shown at
	 */
	private function jpgExifOrientation($data)
	{
		foreach ($this->jpgSegments($data) as $segment) {

			// A segment shorter than its own header cannot hold a TIFF structure, whatever it starts with
			if ($segment['marker'] !== 0xE1 || $segment['size'] < 8 || substr($data, $segment['payload'], 6) !== "Exif\0\0") { // APP1
				continue;
			}

			return $this->exifOrientationFromTiff(substr($data, $segment['payload'] + 6, $segment['size'] - 8));
		}

		return 1;
	}

	/**
	 * Read the Orientation tag out of the TIFF structure an Exif segment wraps
	 *
	 * Orientation is tag 274 (0x0112) of type SHORT, CIPA DC-008 4.6.4 Table 4. The IFD around it is
	 * TIFF Rev. 6.0: a byte order mark, the number 42, an offset to IFD0, then a count of 12-byte entries.
	 *
	 * @param string $tiff Starting at the byte order mark, which every offset inside is relative to
	 *
	 * @return int
	 */
	private function exifOrientationFromTiff($tiff)
	{
		$length = strlen($tiff);

		if ($length < 8) {
			return 1;
		}

		$byteOrder = substr($tiff, 0, 2);

		if ($byteOrder !== 'II' && $byteOrder !== 'MM') {
			return 1;
		}

		$bigEndian = $byteOrder === 'MM';

		if ($this->tiffValue(substr($tiff, 2, 2), $bigEndian) !== 42) {
			return 1;
		}

		$ifd = $this->tiffValue(substr($tiff, 4, 4), $bigEndian);

		if ($ifd < 8 || $ifd + 2 > $length) {
			return 1;
		}

		$entries = $this->tiffValue(substr($tiff, $ifd, 2), $bigEndian);

		for ($i = 0; $i < $entries; $i++) {

			$entry = $ifd + 2 + ($i * 12);

			if ($entry + 12 > $length) {
				break;
			}

			if ($this->tiffValue(substr($tiff, $entry, 2), $bigEndian) !== 0x0112) { // Orientation
				continue;
			}

			if ($this->tiffValue(substr($tiff, $entry + 2, 2), $bigEndian) !== 3) { // SHORT, TIFF Rev. 6.0 type 3
				break;
			}

			// A SHORT is left-justified in the four bytes the entry gives its value, either byte order
			$orientation = $this->tiffValue(substr($tiff, $entry + 8, 2), $bigEndian);

			return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
		}

		return 1;
	}

	/**
	 * Read a 2- or 4-byte TIFF integer of either byte order
	 *
	 * The bounds checks in exifOrientationFromTiff() are what guarantee a whole 2 or 4 bytes to read
	 *
	 * @param string $bytes
	 * @param bool $bigEndian
	 *
	 * @return int
	 */
	private function tiffValue($bytes, $bigEndian)
	{
		if (!$bigEndian) {
			$bytes = strrev($bytes);
		}

		return strlen($bytes) === 4 ? $this->fourBytesToInt($bytes) : $this->twoBytesToInt($bytes);
	}

	/**
	 * Re-encode a JPEG so its samples sit the way its Exif Orientation tag says they should be shown
	 *
	 * Orientation records where row 0 and column 0 of the stored samples belong on screen, which comes to
	 * a rotation, a mirror, or one of each. The eight cases are drawn in CIPA DC-008 Figure 11.
	 *
	 * GD writes a JFIF segment of its own in place of the one it read, so the density has to be handed back
	 * to it, or the re-encoded image would come out at GD's default of 96 whatever the original said.
	 *
	 * @param string $data
	 * @param int $orientation
	 * @param int $dpi 0 where the image has none
	 *
	 * @return string|null Null when the image is already the right way up, or GD cannot read or rewrite it,
	 *                     leaving the caller its original data
	 */
	private function applyJpgExifOrientation($data, $orientation, $dpi)
	{
		// The rotation and mirror each orientation needs; one missing from the table is already the right way up
		$transforms = [
			2 => [0, IMG_FLIP_HORIZONTAL],
			3 => [180, 0],
			4 => [0, IMG_FLIP_VERTICAL],
			5 => [270, IMG_FLIP_HORIZONTAL],
			6 => [270, 0],
			7 => [90, IMG_FLIP_HORIZONTAL],
			8 => [90, 0],
		];

		if (!isset($transforms[$orientation])) {
			return null;
		}

		list($rotation, $mirror) = $transforms[$orientation];

		// A quarter turn is a second image the size of the first; a flip is done in place
		$image = $this->imageFromString($data, $rotation === 90 || $rotation === 270 ? 2 : 1);

		if (!$image) {
			return null;
		}

		if ($rotation === 180) {

			// A half turn is both flips, which imageflip() does in place where imagerotate() would copy the image
			if (!@imageflip($image, IMG_FLIP_BOTH)) {
				$this->destroyImage($image);
				return null;
			}

		} elseif ($rotation) {

			$rotated = @imagerotate($image, $rotation, 0);
			$this->destroyImage($image);

			if (!$rotated) {
				return null;
			}

			$image = $rotated;
		}

		if ($mirror && !@imageflip($image, $mirror)) {
			$this->destroyImage($image);
			return null;
		}

		if ($dpi > 0 && function_exists('imageresolution')) { // PHP 7.2
			@imageresolution($image, $dpi, $dpi);
		}

		ob_start();

		try {
			$written = @imagejpeg($image, null, $this->jpegQuality());
		} finally {
			$rotatedData = ob_get_clean();
			$this->destroyImage($image);
			$image = null; // destroyImage() does nothing on PHP 8+, and the pixels are dead from here
		}

		if (!$written || !$rotatedData) {
			return null;
		}

		return $this->copyJpgIccProfile($data, $rotatedData);
	}

	/**
	 * Carry an ICC profile over to a re-encoded JPEG
	 *
	 * GD keeps the samples but drops every application segment, and the samples are still in whatever
	 * space the profile describes, so the profile has to travel with them. The APP2 chunks the profile is
	 * split into are ICC Technical Note 10-21.
	 *
	 * @param string $source
	 * @param string $target
	 *
	 * @return string
	 */
	private function copyJpgIccProfile($source, $target)
	{
		$profile = '';

		foreach ($this->jpgSegments($source) as $segment) {
			if ($segment['marker'] === 0xE2 && substr($source, $segment['payload'], 12) === "ICC_PROFILE\0") { // APP2
				$profile .= substr($source, $segment['offset'], 2 + $segment['size']);
			}
		}

		if ($profile === '') {
			return $target;
		}

		// An APP2 segment is legal anywhere before the frame, but JFIF wants its own APP0 to come
		// first, and that is what GD writes
		$p = 2;

		foreach ($this->jpgSegments($target) as $first) {
			$p = $first['marker'] === 0xE0 ? $first['offset'] + 2 + $first['size'] : 2;
			break;
		}

		return substr_replace($target, $profile, $p, 0);
	}

	/**
	 * Corrects 2-byte integer to 8-bit depth value
	 * If original image is bpc != 8, tRNS will be in this bpc
	 * $im from imagecreatefromstring will always be in bpc=8
	 * So why do we only need to correct 16-bit tRNS and NOT 2 or 4-bit???
	 */
	private function translateValue($s, $bpc)
	{
		$n = $this->twoBytesToInt($s);

		if ($bpc == 16) {
			$n = ($n >> 8);
		}

		//elseif ($bpc==4) { $n = ($n << 2); }
		//elseif ($bpc==2) { $n = ($n << 4); }

		return $n;
	}

	/**
	 * Read a 4-byte integer from string
	 */
	private function fourBytesToInt($s)
	{
		return (ord($s[0]) << 24) + (ord($s[1]) << 16) + (ord($s[2]) << 8) + ord($s[3]);
	}

	/**
	 * Equivalent to _get_ushort
	 * Read a 2-byte integer from string
	 */
	private function twoBytesToInt($s)
	{
		return (ord(substr($s, 0, 1)) << 8) + ord(substr($s, 1, 1));
	}

	private function gzCompress($data)
	{
		if (!function_exists('gzcompress')) {
			throw new \Mpdf\MpdfException('gzcompress is not available. install ext-zlib extension.');
		}

		return gzcompress($data);
	}

	/**
	 * Throw an exception and save re-trying image URL's which have already failed
	 */
	private function imageError($file, $firstTime, $msg)
	{
		$this->failedImages[$file] = true;

		if ($firstTime && ($this->mpdf->showImageErrors || $this->mpdf->debug)) {
			throw new \Mpdf\MpdfImageException(sprintf('%s (%s)', $msg, substr($file, 0, 256)));
		}

		$this->logger->warning(sprintf('%s (%s)', $msg, $file), ['context' => LogContext::IMAGES]);
	}

	/**
	 * @since mPDF 5.7.4
	 * @param string $url
	 * @return string
	 */
	private function urldecodeParts($url)
	{
		$file = $url;
		$query = '';
		if (preg_match('/[?]/', $url)) {
			$bits = preg_split('/[?]/', $url, 2);
			$file = $bits[0];
			$query = '?' . $bits[1];
		}
		$file = rawurldecode($file);
		$query = urldecode($query);

		return $file . $query;
	}

	public function processJpg($data, $file, $firstTime, $interpolation)
	{
		$ppUx = 0;

		$hdr = $this->jpgHeaderFromString($data);
		if (!$hdr) {
			return $this->imageError($file, $firstTime, 'Error parsing JPG header');
		}

		$a = $this->jpgDataFromHeader($hdr);
		$ppUx = $this->jpgDensity($data); // Read before any re-encode, which replaces the segment it lives in

		// GD reads the samples but not the colour space, so a CMYK image would come back inverted. It also
		// decodes everything else to RGB, so a greyscale image comes back with three channels
		if ($this->mpdf->useImageExifOrientation && $a[2] !== 'DeviceCMYK') {

			$orientation = $this->jpgExifOrientation($data);
			$rotated = $this->applyJpgExifOrientation($data, $orientation, $ppUx);

			if ($rotated !== null) {

				$data = $rotated;
				$hdr = $this->jpgHeaderFromString($data);

				if (!$hdr) {
					return $this->imageError($file, $firstTime, 'Error parsing JPG header after applying Exif orientation');
				}

				$a = $this->jpgDataFromHeader($hdr);

			} elseif ($orientation !== 1) {
				$this->logger->warning(sprintf('Exif orientation %d not applied, image embedded as stored (%s)', $orientation, $file), ['context' => LogContext::IMAGES]);
			}
		}

		$channels = (int) $a[4];

		if ($a[2] === 'DeviceCMYK' && ($this->mpdf->restrictColorSpace === 2 || ($this->mpdf->PDFA && $this->mpdf->restrictColorSpace !== 3))) {

			// convert to RGB image
			if (!function_exists('gd_info')) {
				throw new \Mpdf\MpdfException(sprintf('JPG image may not use CMYK color space (%s).', $file));
			}

			if ($this->mpdf->PDFA && !$this->mpdf->PDFAauto) {
				$this->mpdf->PDFAXwarnings[] = sprintf('JPG image "%s" may not use CMYK color space. Image converted to RGB. The colour profile was altered', $file);
			}

			$im = $this->imageFromString($data);

			if ($im) {

				$tempfile = $this->cache->tempFilename('_tempImgPNG' . md5($file) . random_int(1, 10000) . '.png');
				imageinterlace($im, false);

				$check = @imagepng($im, $tempfile);
				if (!$check) {
					return $this->imageError($file, $firstTime, sprintf('Error creating temporary file "%s" when using GD library to parse JPG (CMYK) image', $tempfile));
				}

				// $info = $this->getImage($tempfile, false);

				$data = file_get_contents($tempfile);
				$info = $this->processPng($data, $tempfile, false, $interpolation);

				if (!$info) {
					return $this->imageError($file, $firstTime, sprintf('Error parsing temporary file "%s" created with GD library to parse JPG (CMYK) image', $tempfile));
				}

				$this->destroyImage($im);
				unlink($tempfile);

				$info['type'] = 'jpg';
				if ($firstTime) {
					$info['i'] = count($this->mpdf->images) + 1;
					$info['interpolation'] = $interpolation; // mPDF 6
					$this->mpdf->images[$file] = $info;
				}

				return $info;
			}

			return $this->imageError($file, $firstTime, 'Error creating GD image file from JPG(CMYK) image');
		}

		if ($a[2] === 'DeviceRGB' && ($this->mpdf->PDFX || $this->mpdf->restrictColorSpace === 3)) {
			// Convert to CMYK image stream - nominally returned as type='png'
			$info = $this->convertImage($data, $a[2], 'DeviceCMYK', $a[0], $a[1], $ppUx, false);
			if (($this->mpdf->PDFA && !$this->mpdf->PDFAauto) || ($this->mpdf->PDFX && !$this->mpdf->PDFXauto)) {
				$this->mpdf->PDFAXwarnings[] = sprintf('JPG image may not use RGB color space - %s - (Image converted to CMYK. NB This will alter the colour profile of the image.)', $file);
			}

		} elseif (($a[2] === 'DeviceRGB' || $a[2] === 'DeviceCMYK') && $this->mpdf->restrictColorSpace === 1) {
			// Convert to Grayscale image stream - nominally returned as type='png'
			$info = $this->convertImage($data, $a[2], 'DeviceGray', $a[0], $a[1], $ppUx, false);

		} else {
			// mPDF 6 Detect Adobe APP14 Tag
			//$pos = strpos($data, "\xFF\xEE\x00\x0EAdobe\0");
			//if ($pos !== false) {
			//}
			// mPDF 6 ICC profile
			$icc = [];
			foreach ($this->jpgSegments($data) as $segment) {

				// APP2 profile chunks, ICC Technical Note 10-21: the identifier, this chunk's number and the count
				if ($segment['marker'] !== 0xE2 || $segment['size'] < 16 || substr($data, $segment['payload'], 12) !== "ICC_PROFILE\0") { // APP2
					continue;
				}

				// The size counts itself and the 14-byte ICC header, so the chunk of profile is what is left
				$sn = max(1, ord($data[$segment['payload'] + 12]));
				$icc[$sn - 1] = substr($data, $segment['payload'] + 14, $segment['size'] - 16);
			}
			// order and compact ICC segments
			if (count($icc) > 0) {
				ksort($icc);
				$icc = $this->usableIccProfile(implode('', $icc));
			} else {
				$icc = false;
			}

			$info = ['w' => $a[0], 'h' => $a[1], 'cs' => $a[2], 'bpc' => $a[3], 'f' => 'DCTDecode', 'data' => $data, 'type' => 'jpg', 'ch' => $channels, 'icc' => $icc];
			if ($ppUx) {
				$info['set-dpi'] = $ppUx;
			}
		}

		if (!$info) {
			return $this->imageError($file, $firstTime, 'Error parsing or converting JPG image');
		}

		if ($firstTime) {
			$info['i'] = count($this->mpdf->images) + 1;
			$info['interpolation'] = $interpolation; // mPDF 6
			$this->mpdf->images[$file] = $info;
		}

		return $info;
	}

	/**
	 * Index a PNG's chunks
	 *
	 * The signature is PNG (Third Edition) 5.2 and the chunk layout 5.3: a four-byte length, a four-byte
	 * type, that many bytes of data, then a four-byte CRC. IDAT is 11.2.3 and IEND 11.2.4.
	 *
	 * Walks the whole file, up to and including IEND. A caller after metadata wants pngChunksBeforeImageData(), which
	 * stops at the image data; because this yields, stopping early costs the caller nothing it has not already read.
	 *
	 * @param string $data
	 *
	 * Yields rather than returns, for the same reason as jpgSegments().
	 *
	 * @return \Generator Each entry has a type, a size (of the chunk's data, so not counting the length, the type
	 *                    or the CRC) and a payload offset, which is where that data starts
	 */
	private function pngChunks($data)
	{
		$length = strlen($data);

		if (substr($data, 0, 8) !== chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)) {
			return;
		}

		$p = 8;

		// Length, type and CRC come to 12 bytes around each chunk's data
		while ($p + 12 <= $length) {

			$size = $this->fourBytesToInt(substr($data, $p, 4));

			if ($size < 0 || $p + 12 + $size > $length) {
				break;
			}

			$type = substr($data, $p + 4, 4);

			yield ['type' => $type, 'size' => $size, 'payload' => $p + 8];

			if ($type === 'IEND') { // PNG 5.6: nothing follows the end of the datastream
				break;
			}

			$p += 12 + $size;
		}
	}

	/**
	 * The first chunk of each type the metadata is read from, keyed by type
	 *
	 * Gives up at the image data, which PNG 5.6 has every one of those chunks precede. That is what keeps
	 * a name appearing in a text chunk or among the compressed samples from being mistaken for a chunk header,
	 * and one walk serves every lookup where a walk a lookup would cost a file of thousands of chunks dearly.
	 *
	 * @param string $data
	 *
	 * @return array
	 */
	private function pngChunksBeforeImageData($data)
	{
		// Only the types read out of the index; a file of chunks each under a type of its own is bounded by this
		$wanted = ['tRNS' => true, 'iCCP' => true, 'pHYs' => true, 'gAMA' => true, 'sRGB' => true];
		$chunks = [];

		foreach ($this->pngChunks($data) as $chunk) {

			if ($chunk['type'] === 'IDAT') {
				break;
			}

			if (isset($wanted[$chunk['type']]) && !isset($chunks[$chunk['type']])) {
				$chunks[$chunk['type']] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * A colour profile if it is one this class can embed, false if it is not
	 *
	 * ICC.1 7.2 puts the profile's size at byte 0, the data and connection spaces at 16 and 20, and
	 * 'acsp' at 36; the header is 128 bytes and a tag count follows it. Only a profile taking RGB to the
	 * XYZ connection space is any use here; converting from CMYK or Lab is work this class does not do.
	 *
	 * @param string $icc
	 *
	 * @return string|false
	 */
	private function usableIccProfile($icc)
	{
		if (!$icc || strlen($icc) < 132 || substr($icc, 36, 4) !== 'acsp' || substr($icc, 16, 4) !== 'RGB ' || substr($icc, 20, 4) !== 'XYZ ') {
			return false;
		}

		$size = $this->fourBytesToInt(substr($icc, 0, 4));

		if ($size < 132 || $size > strlen($icc)) { // Claiming more than is there means it was cut short
			return false;
		}

		return substr($icc, 0, $size); // What the size covers is the profile; anything past it is not
	}

	/**
	 * A PNG's embedded ICC profile, where it carries one this class can use
	 *
	 * PNG 11.3.2.3: a profile name, a null, a one-byte compression method (only 0, deflate, is defined),
	 * then the zlib-compressed profile. The inflate is capped at the 8,000,000 bytes libpng allows a
	 * chunk, so a few kilobytes of zeros cannot expand into a profile that fills memory. gzuncompress()
	 * treats its cap as a buffer size and can run a little past it, so the length is checked again after.
	 *
	 * @param array $chunks Out of pngChunksBeforeImageData()
	 * @param string $data
	 *
	 * @return string|false
	 */
	private function pngIccProfile(array $chunks, $data)
	{
		if (!isset($chunks['iCCP'])) {
			return false;
		}

		$chunk = $chunks['iCCP'];
		$nullsep = strpos(substr($data, $chunk['payload'], min(80, $chunk['size'])), chr(0)); // 11.3.2.3 caps the name at 79 bytes

		if ($nullsep === false || $nullsep + 2 > $chunk['size'] || $data[$chunk['payload'] + $nullsep + 1] !== "\0") {
			return false;
		}

		$deflated = substr($data, $chunk['payload'] + $nullsep + 2, $chunk['size'] - $nullsep - 2);
		$icc = @gzuncompress($deflated, self::PNG_ICC_PROFILE_MAX); // False if the inflate fails

		if ($icc === false || strlen($icc) > self::PNG_ICC_PROFILE_MAX) {
			return false;
		}

		return $this->usableIccProfile($icc);
	}

	public function processPng($data, $file, $firstTime, $interpolation)
	{
		$ppUx = 0;

		// Check signature
		if (strpos($data, chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)) !== 0) {
			return $this->imageError($file, $firstTime, 'Error parsing PNG identifier');
		}

		// Read header chunk
		if (substr($data, 12, 4) !== 'IHDR') {
			return $this->imageError($file, $firstTime, 'Incorrect PNG file (no IHDR block found)');
		}

		$w = $this->fourBytesToInt(substr($data, 16, 4));
		$h = $this->fourBytesToInt(substr($data, 20, 4));
		$bpc = ord(substr($data, 24, 1));
		$errpng = false;
		$pngalpha = false;
		$channels = 0;
		$chunks = $this->pngChunksBeforeImageData($data); // PNG 5.6 has every chunk read below precede the image data

		//	if($bpc>8) { $errpng = 'not 8-bit depth'; }	// mPDF 6 Allow through to be handled as native PNG
		$ct = ord(substr($data, 25, 1));

		if ($ct === 0) {
			$colspace = 'DeviceGray';
			$channels = 1;
		} elseif ($ct === 2) {
			$colspace = 'DeviceRGB';
			$channels = 3;
		} elseif ($ct === 3) {
			$colspace = 'Indexed';
			$channels = 1;
		} elseif ($ct === 4) {
			$colspace = 'DeviceGray';
			$channels = 1;
			$errpng = 'alpha channel';
			$pngalpha = true;
		} else {
			$colspace = 'DeviceRGB';
			$channels = 3;
			$errpng = 'alpha channel';
			$pngalpha = true;
		}

		if ($ct < 4 && isset($chunks['tRNS'])) { // PNG 11.3.1.1, transparency without an alpha channel
			$errpng = 'transparency';
			$pngalpha = true;
		} // mPDF 6

		if ($ct === 3 && isset($chunks['iCCP'])) { // PNG 11.3.2.3, an embedded ICC profile
			$errpng = 'indexed plus ICC';
		} // mPDF 6

		// $pngalpha is used as a FLAG of any kind of transparency which COULD be tranferred to an alpha channel
		// incl. single-color tarnsparency, depending which type of handling occurs later
		if (ord(substr($data, 26, 1)) !== 0) {
			$errpng = 'compression method';
		} // only 0 should be specified

		if (ord(substr($data, 27, 1)) !== 0) {
			$errpng = 'filter method';
		} // only 0 should be specified

		if (ord(substr($data, 28, 1)) !== 0) {
			$errpng = 'interlaced file';
		}

		if (isset($chunks['pHYs'])) { // PNG 11.3.4.3: two four-byte densities, then the unit
			$pHYs = $chunks['pHYs'];
			//Read resolution
			$unitSp = ord(substr($data, $pHYs['payload'] + 8, 1));
			if ($unitSp === 1) {
				$ppUx = $this->fourBytesToInt(substr($data, $pHYs['payload'], 4)); // horizontal pixels per meter, usually set to zero
				$ppUx = round($ppUx / 1000 * 25.4);
			}
		}

		// mPDF 6 Gamma correction
		$gamma = 0;
		$gAMA = 0;
		if (isset($chunks['gAMA']) && !isset($chunks['sRGB'])) { // PNG 11.3.2.2, four bytes of gamma times 100000; sRGB (PNG 11.3.2.5) overrides it
			$gAMA = $this->fourBytesToInt(substr($data, $chunks['gAMA']['payload'], 4)); // Gamma value times 100000
			$gAMA /= 100000;

			// http://www.libpng.org/pub/png/spec/1.2/PNG-Encoders.html
			// "If the source file's gamma value is greater than 1.0, it is probably a display system exponent,..."
			// ("..and you should use its reciprocal for the PNG gamma.")
			//if ($gAMA > 1) { $gAMA = 1/$gAMA; }
			// (Some) Applications seem to ignore it... appearing how it was probably intended
			// Test Case - image(s) on http://www.w3.org/TR/CSS21/intro.html  - PNG has gAMA set as 1.45454
			// Probably unintentional as mentioned above and should be 0.45454 which is 1 / 2.2
			// Tested on Windows PC
			// Firefox and Opera display gray as 234 (correct, but looks wrong)
			// IE9 and Safari display gray as 193 (incorrect but looks right)
			// See test different gamma chunks at http://www.libpng.org/pub/png/pngsuite-all-good.html
		}

		if ($gAMA) {
			$gamma = 1 / $gAMA;
		}

		// Don't need to apply gamma correction if == default i.e. 2.2
		if ($gamma > 2.15 && $gamma < 2.25) {
			$gamma = 0;
		}

		// NOT supported at present
		//$j = strpos($data,'sRGB');	// sRGB colorspace - overrides gAMA
		//$j = strpos($data,'cHRM');	// Chromaticity and Whitepoint
		// $firstTime added mPDF 6 so when PNG Grayscale with alpha using resrtictcolorspace to CMYK
		// the alpha channel is sent through as secondtime as Indexed and should not be converted to CMYK
		if ($firstTime && ($colspace === 'DeviceRGB' || $colspace === 'Indexed') && ($this->mpdf->PDFX || $this->mpdf->restrictColorSpace === 3)) {

			// Convert to CMYK image stream - nominally returned as type='png'
			$info = $this->convertImage($data, $colspace, 'DeviceCMYK', $w, $h, $ppUx, $pngalpha, $gamma, $ct); // mPDF 5.7.2 Gamma correction
			if (($this->mpdf->PDFA && !$this->mpdf->PDFAauto) || ($this->mpdf->PDFX && !$this->mpdf->PDFXauto)) {
				$this->mpdf->PDFAXwarnings[] = sprintf('PNG image may not use RGB color space - %s - (Image converted to CMYK. NB This will alter the colour profile of the image.)', $file);
			}

		} elseif ($firstTime && ($colspace === 'DeviceRGB' || $colspace === 'Indexed') && $this->mpdf->restrictColorSpace === 1) {

			// $firstTime added mPDF 6 so when PNG Grayscale with alpha using resrtictcolorspace to CMYK
			// the alpha channel is sent through as secondtime as Indexed and should not be converted to CMYK
			// Convert to Grayscale image stream - nominally returned as type='png'
			$info = $this->convertImage($data, $colspace, 'DeviceGray', $w, $h, $ppUx, $pngalpha, $gamma, $ct); // mPDF 5.7.2 Gamma correction

		} elseif (($this->mpdf->PDFA || $this->mpdf->PDFX) && $pngalpha) {

			// Remove alpha channel
			if ($this->mpdf->restrictColorSpace === 1) { // Grayscale
				$info = $this->convertImage($data, $colspace, 'DeviceGray', $w, $h, $ppUx, $pngalpha, $gamma, $ct); // mPDF 5.7.2 Gamma correction
			} elseif ($this->mpdf->restrictColorSpace === 3) { // CMYK
				$info = $this->convertImage($data, $colspace, 'DeviceCMYK', $w, $h, $ppUx, $pngalpha, $gamma, $ct); // mPDF 5.7.2 Gamma correction
			} elseif ($this->mpdf->PDFA) { // RGB
				$info = $this->convertImage($data, $colspace, 'DeviceRGB', $w, $h, $ppUx, $pngalpha, $gamma, $ct); // mPDF 5.7.2 Gamma correction
			}
			if (($this->mpdf->PDFA && !$this->mpdf->PDFAauto) || ($this->mpdf->PDFX && !$this->mpdf->PDFXauto)) {
				$this->mpdf->PDFAXwarnings[] = sprintf('Transparency (alpha channel) not permitted in PDFA or PDFX files - %s - (Image converted to one without transparency.)', $file);
			}

		} elseif ($firstTime && ($errpng || $pngalpha || $gamma)) { // mPDF 5.7.2 Gamma correction

			$gd = function_exists('gd_info') ? gd_info() : [];
			if (!isset($gd['PNG Support'])) {
				return $this->imageError($file, $firstTime, sprintf('GD library with PNG support required for image (%s)', $errpng));
			}

			$im = $this->imageFromString($data, 3); // libgd's PNG reader holds a second copy while decoding, then the alpha channel is drawn into a palette image and both are written back out
			if (!$im) {
				return $this->imageError($file, $firstTime, sprintf('Error creating GD image from PNG file (%s)', $errpng));
			}

			$w = imagesx($im);
			$h = imagesy($im);

			$tempfile = $this->cache->tempFilename('_tempImgPNG' . md5($file) . bin2hex(random_bytes(6)) . '.png');

			// Alpha channel set (including using tRNS for Paletted images)
			if ($pngalpha) {
				if ($this->mpdf->PDFA) {
					throw new \Mpdf\MpdfException(sprintf('PDFA1-b does not permit images with alpha channel transparency (%s).', $file));
				}

				$imgalpha = imagecreate($w, $h);
				// generate gray scale pallete
				for ($c = 0; $c < 256; ++$c) {
					imagecolorallocate($imgalpha, $c, $c, $c);
				}

				// mPDF 6
				if ($colspace === 'Indexed') { // generate Alpha channel values from tRNS
					// Read transparency info: PNG 11.3.1.1, one alpha value per palette entry
					if (isset($chunks['tRNS'])) {
						$chunk = $chunks['tRNS'];
						$n = $chunk['size'];
						$transparency = substr($data, $chunk['payload'], $n);
						// ord($transparency[$index]) = the alpha value for that index
						// generate alpha channel
						for ($ypx = 0; $ypx < $h; ++$ypx) {
							for ($xpx = 0; $xpx < $w; ++$xpx) {
								$colorindex = imagecolorat($im, $xpx, $ypx);
								if ($colorindex >= $n) {
									$alpha = 255;
								} else {
									$alpha = ord($transparency[$colorindex]);
								} // 0-255
								if ($alpha > 0) {
									imagesetpixel($imgalpha, $xpx, $ypx, $alpha);
								}
							}
						}
					}
				} elseif ($ct === 0 || $ct === 2) { // generate Alpha channel values from tRNS
					// Get transparency as array of RGB: PNG 11.3.1.1, one sample per channel
					if (isset($chunks['tRNS'])) {
						$chunk = $chunks['tRNS'];
						$trns = '';
						$t = substr($data, $chunk['payload'], $chunk['size']);
						if ($colspace === 'DeviceGray') {  // ct===0
							$trns = [$this->translateValue(substr($t, 0, 2), $bpc)];
						} else /* $colspace=='DeviceRGB' */ {  // ct==2
							$trns = [];
							$trns[0] = $this->translateValue(substr($t, 0, 2), $bpc);
							$trns[1] = $this->translateValue(substr($t, 2, 2), $bpc);
							$trns[2] = $this->translateValue(substr($t, 4, 2), $bpc);
						}

						// generate alpha channel
						for ($ypx = 0; $ypx < $h; ++$ypx) {
							for ($xpx = 0; $xpx < $w; ++$xpx) {
								$rgb = imagecolorat($im, $xpx, $ypx);
								$r = ($rgb >> 16) & 0xFF;
								$g = ($rgb >> 8) & 0xFF;
								$b = $rgb & 0xFF;
								if ($colspace === 'DeviceGray') { // ct===0
									$alpha = $b == $trns[0] ? 0 : 255;
								} elseif ($r == $trns[0] && $g == $trns[1] && $b == $trns[2]) { // ct==2
									$alpha = 0;
								} else {
									$alpha = 255;
								}
								if ($alpha > 0) {
									imagesetpixel($imgalpha, $xpx, $ypx, $alpha);
								}
							}
						}
					}
				} else {
					// extract alpha channel
					for ($ypx = 0; $ypx < $h; ++$ypx) {
						for ($xpx = 0; $xpx < $w; ++$xpx) {
							$alpha = (imagecolorat($im, $xpx, $ypx) & 0x7F000000) >> 24;
							if ($alpha < 127) {
								imagesetpixel($imgalpha, $xpx, $ypx, 255 - ($alpha * 2));
							}
						}
					}
				}

				// NB This must happen after the Alpha channel is extracted
				// imagegammacorrect() removes the alpha channel data in $im - (I think this is a bug in PHP)
				if ($gamma) {
					imagegammacorrect($im, $gamma, 2.2);
				}

				$tempfile_alpha =  $this->cache->tempFilename('_tempMskPNG' . md5($file) . random_int(1, 10000) . '.png');

				$check = @imagepng($imgalpha, $tempfile_alpha);

				if (!$check) {
					return $this->imageError($file, $firstTime, 'Failed to create temporary image file (' . $tempfile_alpha . ') parsing PNG image with alpha channel (' . $errpng . ')');
				}

				$this->destroyImage($imgalpha);

				// Extract the image without its alpha channel. A truecolor one can be written out in place, rather than
				// copied into a second full-size image; a palette one still has to be copied, because writing it as it
				// stands would give processPng() a paletted image to read back where it had a truecolor one before
				if (imageistruecolor($im)) {
					$check = $this->writeFlatPng($im, $tempfile);
				} else {
					$imgplain = imagecreatetruecolor($w, $h);
					imagealphablending($imgplain, false); // mPDF 5.7.2
					imagecopy($imgplain, $im, 0, 0, 0, 0, $w, $h);
					$check = @imagepng($imgplain, $tempfile);
					$this->destroyImage($imgplain);
				}

				$this->destroyImage($im); // Both temp files are written, so the decoded pixels are no longer needed

				if (!$check) {
					return $this->imageError($file, $firstTime, 'Failed to create temporary image file (' . $tempfile . ') parsing PNG image with alpha channel (' . $errpng . ')');
				}

				// embed mask image
				//$minfo = $this->getImage($tempfile_alpha, false);
				$data = file_get_contents($tempfile_alpha);
				$minfo = $this->processPng($data, $tempfile_alpha, false, $interpolation);

				unlink($tempfile_alpha);

				if (!$minfo) {
					return $this->imageError($file, $firstTime, 'Error parsing temporary file (' . $tempfile_alpha . ') created with GD library to parse PNG image');
				}

				$imgmask = count($this->mpdf->images) + 1;
				$minfo['cs'] = 'DeviceGray';
				$minfo['i'] = $imgmask;
				$this->mpdf->images[$tempfile_alpha] = $minfo;
				// embed image, masked with previously embedded mask

				// $info = $this->getImage($tempfile, false);
				$data = file_get_contents($tempfile);
				$info = $this->processPng($data, $tempfile, false, $interpolation);

				unlink($tempfile);

				if (!$info) {
					return $this->imageError($file, $firstTime, 'Error parsing temporary file (' . $tempfile . ') created with GD library to parse PNG image');
				}

				$info['masked'] = $imgmask;
				if ($ppUx) {
					$info['set-dpi'] = $ppUx;
				}
				$info['type'] = 'png';
				if ($firstTime) {
					$info['i'] = count($this->mpdf->images) + 1;
					$info['interpolation'] = $interpolation; // mPDF 6
					$this->mpdf->images[$file] = $info;
				}

				return $info;
			}

			// No alpha/transparency set (but cannot read directly because e.g. bit-depth != 8, interlaced etc)
			// ICC profile
			$icc = false;
			if ($colspace === "Indexed") { // Cannot have ICC profile and Indexed together
				$icc = $this->pngIccProfile($chunks, $data);
				// Convert to RGB colorspace so can use ICC Profile
				if ($icc) {
					imagepalettetotruecolor($im);
					$colspace = 'DeviceRGB';
					$channels = 3;
				}
			}

			if ($gamma) {
				imagegammacorrect($im, $gamma, 2.2);
			}

			$check = $this->writeFlatPng($im, $tempfile);
			if (!$check) {
				return $this->imageError($file, $firstTime, 'Failed to create temporary image file (' . $tempfile . ') parsing PNG image (' . $errpng . ')');
			}

			$this->destroyImage($im);
			// $info = $this->getImage($tempfile, false);
			$data = file_get_contents($tempfile);
			$info = $this->processPng($data, $tempfile, false, $interpolation);
			unlink($tempfile);

			if (!$info) {
				return $this->imageError($file, $firstTime, 'Error parsing temporary file (' . $tempfile . ') created with GD library to parse PNG image');
			}

			if ($ppUx) {
				$info['set-dpi'] = $ppUx;
			}
			$info['type'] = 'png';
			if ($firstTime) {
				$info['i'] = count($this->mpdf->images) + 1;
				$info['interpolation'] = $interpolation; // mPDF 6
				if ($icc) {
					$info['ch'] = $channels;
					$info['icc'] = $icc;
				}
				$this->mpdf->images[$file] = $info;
			}

			return $info;

		} else { // PNG image with no need to convert alph channels, bpc <> 8 etc.

			$parms = '/DecodeParms <</Predictor 15 /Colors ' . $channels . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w . '>>';
			//Scan chunks looking for palette, transparency and image data
			$pal = '';
			$trns = '';
			$pngdata = '';
			// mPDF 6 cannot have ICC profile and Indexed in a PDF document as both use the colorspace tag
			$icc = $colspace === 'Indexed' ? false : $this->pngIccProfile($chunks, $data);

			foreach ($this->pngChunks($data) as $chunk) {

				$offset = $chunk['payload'];

				if ($chunk['type'] === 'PLTE') { // Read palette, PNG 11.2.2
					$pal = substr($data, $offset, $chunk['size']);
				} elseif ($chunk['type'] === 'tRNS') { // Read transparency info, PNG 11.3.1.1
					$t = substr($data, $offset, $chunk['size']);
					if ($ct === 0) {
						$trns = [ord(substr($t, 1, 1))];
					} elseif ($ct === 2) {
						$trns = [ord(substr($t, 1, 1)), ord(substr($t, 3, 1)), ord(substr($t, 5, 1))];
					} else {
						$pos = strpos($t, chr(0));
						if (is_int($pos)) {
							$trns = [$pos];
						}
					}
				} elseif ($chunk['type'] === 'IDAT') { // PNG 11.2.3 lets the image data run over as many chunks as it likes
					$pngdata .= substr($data, $offset, $chunk['size']);
				}
			}

			if (!$pngdata) {
				return $this->imageError($file, $firstTime, 'Error parsing PNG image data - no IDAT data found');
			}

			if ($colspace === 'Indexed' && empty($pal)) {
				return $this->imageError($file, $firstTime, 'Error parsing PNG image data - missing colour palette');
			}

			$info = [
				'w' => $w,
				'h' => $h,
				'cs' => $colspace,
				'bpc' => $bpc,
				'f' => 'FlateDecode',
				'parms' => $parms,
				'pal' => $pal,
				'trns' => $trns,
				'data' => $pngdata,
				'ch' => $channels,
				'icc' => $icc
			];

			$info['type'] = 'png';

			if ($ppUx) {
				$info['set-dpi'] = $ppUx;
			}
		}

		if (!$info) {
			return $this->imageError($file, $firstTime, 'Error parsing or converting PNG image');
		}

		if ($firstTime) {
			$info['i'] = count($this->mpdf->images) + 1;
			$info['interpolation'] = $interpolation; // mPDF 6
			$this->mpdf->images[$file] = $info;
		}

		return $info;
	}

	public function processWebp($data, $file, $firstTime)
	{
		return $this->jpgViaGd($data, $file, $firstTime, 'imagewebp', 'WEBP');
	}

	/**
	 * Convert a format GD can read and write, but the PDF cannot carry, into a JPEG
	 *
	 * @param string $data
	 * @param string $file
	 * @param bool $firstTime
	 * @param string $writer The GD function that writes the format, which is also how its support is told
	 * @param string $format For the error messages
	 *
	 * @return string|null
	 */
	private function jpgViaGd($data, $file, $firstTime, $writer, $format)
	{
		if (!function_exists($writer)) {
			return $this->imageError($file, $firstTime, sprintf('Missing GD support for %s images.', $format));
		}

		$im = $this->imageFromString($data);

		if (!$im) {
			return $this->imageError($file, $firstTime, sprintf('Error creating GD image from %s image', $format));
		}

		$tempfile = $this->cache->tempFilename('_tempImgPNG' . md5($file) . random_int(1, 10000) . '.jpg');
		$checkfile = $this->cache->tempFilename('_tempImgPNG' . md5($file) . random_int(1, 10000) . '.jpg');

		$check = $writer($im, $checkfile);
		if (!$check) {
			return $this->imageError($file, $firstTime, sprintf('Error creating temporary file "%s" when using GD library to parse %s image', $checkfile, $format));
		}

		@imagejpeg($im, $tempfile, $this->jpegQuality());
		$data = file_get_contents($tempfile);
		$this->destroyImage($im);
		unlink($tempfile);
		unlink($checkfile);

		return $data;
	}

	public function processAvif($data, $file, $firstTime)
	{
		return $this->jpgViaGd($data, $file, $firstTime, 'imageavif', 'AVIF');
	}

	public function processSvg($data, $file, $firstTime)
	{
		$svg = new Svg($this->mpdf, $this->otl, $this->cssManager, $this, $this->sizeConverter, $this->colorConverter, $this->languageToFont, $this->scriptToLanguage);

		$family = $this->mpdf->FontFamily;
		$style = $this->mpdf->FontStyle;
		$size = $this->mpdf->FontSizePt;

		$info = $svg->ImageSVG($data);

		// Restore font
		if ($family) {
			$this->mpdf->SetFont($family, $style, $size, false);
		}

		if (!$info) {
			return $this->imageError($file, $firstTime, 'Error parsing SVG file');
		}

		$info['type'] = 'svg';
		$info['i'] = count($this->mpdf->formobjects) + 1;
		$this->mpdf->formobjects[$file] = $info;

		return $info;
	}

	public function processGif($data, $file, $firstTime, $interpolation)
	{
		$gd = function_exists('gd_info')
			? gd_info()
			: [];

		if (isset($gd['GIF Read Support']) && $gd['GIF Read Support']) {

			$im = $this->imageFromString($data);

			if ($im) {

				$tempfile = $this->cache->tempFilename('_tempImgPNG' . md5($file) . random_int(1, 10000) . '.png');

				imagealphablending($im, false);
				imagesavealpha($im, false);
				imageinterlace($im, false);

				$check = @imagepng($im, $tempfile);
				if (!$check) {
					return $this->imageError($file, $firstTime, 'Error creating temporary file (' . $tempfile . ') when using GD library to parse GIF image');
				}

				// $info = $this->getImage($tempfile, false);
				$data = file_get_contents($tempfile);
				$info = $this->processPng($data, $tempfile, false, $interpolation);

				if (!$info) {
					return $this->imageError($file, $firstTime, 'Error parsing temporary file (' . $tempfile . ') created with GD library to parse GIF image');
				}

				$this->destroyImage($im);
				unlink($tempfile);

				$info['type'] = 'gif';
				if ($firstTime) {
					$info['i'] = count($this->mpdf->images) + 1;
					$info['interpolation'] = $interpolation; // mPDF 6
					$this->mpdf->images[$file] = $info;
				}
				return $info;
			}

			return $this->imageError($file, $firstTime, 'Error creating GD image file from GIF image');
		}

		$gif = new Gif();

		$h = 0;
		$w = 0;

		$gif->loadFile($data, 0);

		$nColors = 0;
		$bgColor = -1;
		$colspace = 'DeviceGray';
		$pal = '';

		if (isset($gif->m_img->m_gih->m_bLocalClr) && $gif->m_img->m_gih->m_bLocalClr) {
			$nColors = $gif->m_img->m_gih->m_nTableSize;
			$pal = $gif->m_img->m_gih->m_colorTable->toString();
			if ((isset($bgColor)) && $bgColor !== -1) { // mPDF 5.7.3
				$bgColor = $gif->m_img->m_gih->m_colorTable->colorIndex($bgColor);
			}
			$colspace = 'Indexed';
		} elseif (isset($gif->m_gfh->m_bGlobalClr) && $gif->m_gfh->m_bGlobalClr) {
			$nColors = $gif->m_gfh->m_nTableSize;
			$pal = $gif->m_gfh->m_colorTable->toString();
			if ((isset($bgColor)) && $bgColor != -1) {
				$bgColor = $gif->m_gfh->m_colorTable->colorIndex($bgColor);
			}
			$colspace = 'Indexed';
		}

		$trns = '';

		if (isset($gif->m_img->m_bTrans) && $gif->m_img->m_bTrans && ($nColors > 0)) {
			$trns = [$gif->m_img->m_nTrans];
		}

		$gifdata = $gif->m_img->m_data;
		$w = $gif->m_gfh->m_nWidth;
		$h = $gif->m_gfh->m_nHeight;
		$gif->ClearData();

		if ($colspace === 'Indexed' && empty($pal)) {
			return $this->imageError($file, $firstTime, 'Error parsing GIF image - missing colour palette');
		}

		if ($this->mpdf->compress) {
			$gifdata = $this->gzCompress($gifdata);
			$info = ['w' => $w, 'h' => $h, 'cs' => $colspace, 'bpc' => 8, 'f' => 'FlateDecode', 'pal' => $pal, 'trns' => $trns, 'data' => $gifdata];
		} else {
			$info = ['w' => $w, 'h' => $h, 'cs' => $colspace, 'bpc' => 8, 'pal' => $pal, 'trns' => $trns, 'data' => $gifdata];
		}

		$info['type'] = 'gif';
		if ($firstTime) {
			$info['i'] = count($this->mpdf->images) + 1;
			$info['interpolation'] = $interpolation; // mPDF 6
			$this->mpdf->images[$file] = $info;
		}

		return $info;
	}

	public function processBmp($data, $file, $firstTime, $interpolation)
	{
		if ($this->bmp === null) {
			$this->bmp = new Bmp($this->mpdf);
		}

		$info = $this->bmp->_getBMPimage($data, $file);
		if (isset($info['error'])) {
			return $this->imageError($file, $firstTime, $info['error']);
		}

		if ($firstTime) {
			$info['i'] = count($this->mpdf->images) + 1;
			$info['interpolation'] = $interpolation; // mPDF 6
			$this->mpdf->images[$file] = $info;
		}

		return $info;
	}

	public function processWmf($data, $file, $firstTime)
	{
		if ($this->wmf === null) {
			$this->wmf = new Wmf($this->mpdf, $this->colorConverter);
		}

		$wmfres = $this->wmf->_getWMFimage($data);

		if ($wmfres[0] == 0) {
			if ($wmfres[1]) {
				return $this->imageError($file, $firstTime, $wmfres[1]);
			}
			return $this->imageError($file, $firstTime, 'Error parsing WMF image');
		}

		$info = ['x' => $wmfres[2][0], 'y' => $wmfres[2][1], 'w' => $wmfres[3][0], 'h' => $wmfres[3][1], 'data' => $wmfres[1]];
		$info['i'] = count($this->mpdf->formobjects) + 1;
		$info['type'] = 'wmf';
		$this->mpdf->formobjects[$file] = $info;

		return $info;
	}

	public function processUnknownType($data, $file, $firstTime, $interpolation)
	{
		$gd = function_exists('gd_info')
			? gd_info()
			: [];

		if (isset($gd['PNG Support']) && $gd['PNG Support']) {

			$im = $this->imageFromString($data);

			if (!$im) {
				return $this->imageError($file, $firstTime, 'Error parsing image file - image type not recognised and/or not supported by GD imagecreate');
			}

			$tempfile = $this->cache->tempFilename('_tempImgPNG' . md5($file) . random_int(1, 10000) . '.png');

			imagealphablending($im, false);
			imagesavealpha($im, false);
			imageinterlace($im, false);

			$check = @imagepng($im, $tempfile);

			if (!$check) {
				return $this->imageError($file, $firstTime, sprintf('Error creating temporary file "%s" when using GD library to parse unknown image type', $tempfile));
			}

			//$info = $this->getImage($tempfile, false);
			$data = file_get_contents($tempfile);
			$info = $this->processPng($data, $tempfile, false, $interpolation);

			$this->destroyImage($im);
			unlink($tempfile);

			if (!$info) {
				return $this->imageError($file, $firstTime, sprintf('Error parsing temporary file "%s" created with GD library to parse unknown image type', $tempfile));
			}

			$info['type'] = 'png';
			if ($firstTime) {
				$info['i'] = count($this->mpdf->images) + 1;
				$info['interpolation'] = $interpolation; // mPDF 6
				$this->mpdf->images[$file] = $info;
			}

			return $info;
		}
	}

	/**
	 * Write an image out as a PNG for processPng() to read back
	 *
	 * Flat, because that is all the caller wants from it: no alpha channel, no interlacing (which processPng()
	 * cannot read back) and no transparent colour, which any mask the caller built already carries.
	 *
	 * @param resource|\GdImage $im
	 * @param string $tempfile
	 *
	 * @return bool
	 */
	private function writeFlatPng($im, $tempfile)
	{
		imagealphablending($im, false);
		imagesavealpha($im, false);
		imageinterlace($im, false);
		imagecolortransparent($im, -1);

		return (bool) @imagepng($im, $tempfile);
	}

	private function destroyImage($im)
	{
		if (PHP_VERSION_ID < 80000) {
			imagedestroy($im);
		}
	}

}
