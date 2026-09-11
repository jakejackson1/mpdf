<?php

namespace Mpdf\Fonts;

use Mpdf\TTFontFile;

class MetricsGenerator
{

	/**
	 * The shape of everything this class writes into the font cache.
	 *
	 * Regeneration is otherwise triggered only by the font file's size changing, so a release that
	 * changes what a cache file *means* — the unit an offset is measured in, which table a blob
	 * holds, the keys of an array — is served the old shape and reads it as the new one. Raise this
	 * whenever that happens. Mpdf::AddFont() compares it and regenerates on a mismatch.
	 */
	const CACHE_FORMAT = 1;

	private $fontCache;

	private $fontDescriptor;

	public function __construct(FontCache $fontCache, $fontDescriptor)
	{
		$this->fontCache = $fontCache;
		$this->fontDescriptor = $fontDescriptor;
	}

	public function generateMetrics($ttffile, $ttfstat, $fontkey, $TTCfontID, $debugfonts, $BMPonly, $useOTL, $fontUseOTL)
	{
		$ttf = new TTFontFile($this->fontCache, $this->fontDescriptor);
		$ttf->getMetrics($ttffile, $fontkey, $TTCfontID, $debugfonts, $BMPonly, $useOTL); // mPDF 5.7.1

		$font = [
			'name' => $this->getFontName($ttf->fullName),
			'type' => 'TTF',
			'desc' => [
				'CapHeight' => round($ttf->capHeight),
				'XHeight' => round($ttf->xHeight),
				'FontBBox' => '[' . round($ttf->bbox[0]) . " " . round($ttf->bbox[1]) . " " . round($ttf->bbox[2]) . " " . round($ttf->bbox[3]) . ']',
				/* FontBBox from head table */
				/* 		'MaxWidth' => round($ttf->advanceWidthMax),	// AdvanceWidthMax from hhea table	NB ArialUnicode MS = 31990 ! */
				'Flags' => $ttf->flags,
				'Ascent' => round($ttf->ascent),
				'Descent' => round($ttf->descent),
				'Leading' => round($ttf->lineGap),
				'ItalicAngle' => $ttf->italicAngle,
				'StemV' => round($ttf->stemV),
				'MissingWidth' => round($ttf->defaultWidth)
			],
			'unitsPerEm' => round($ttf->unitsPerEm),
			'up' => round($ttf->underlinePosition),
			'ut' => round($ttf->underlineThickness),
			'strp' => round($ttf->strikeoutPosition),
			'strs' => round($ttf->strikeoutSize),
			'ttffile' => $ttffile,
			'TTCfontID' => $TTCfontID,
			'originalsize' => $ttfstat['size'] + 0, /* cast ? */
			'sip' => ($ttf->sipset) ? true : false,
			'smp' => ($ttf->smpset) ? true : false,
			'BMPselected' => ($BMPonly) ? true : false,
			'fontkey' => $fontkey,
			'panose' => $this->getPanose($ttf),
			'haskerninfo' => ($ttf->kerninfo) ? true : false,
			'haskernGPOS' => ($ttf->haskernGPOS) ? true : false,
			'hassmallcapsGSUB' => ($ttf->hassmallcapsGSUB) ? true : false,
			'fontmetrics' => $this->fontDescriptor,
			'useOTL' => ($fontUseOTL) ? $fontUseOTL : 0,
			'rtlPUAstr' => $ttf->rtlPUAstr,
			'GSUBScriptLang' => $ttf->GSUBScriptLang,
			'GSUBFeatures' => $ttf->GSUBFeatures,
			'GSUBLookups' => $ttf->GSUBLookups,
			'GPOSScriptLang' => $ttf->GPOSScriptLang,
			'GPOSFeatures' => $ttf->GPOSFeatures,
			'GPOSLookups' => $ttf->GPOSLookups,
			'kerninfo' => $ttf->kerninfo,
			'cacheFormat' => self::CACHE_FORMAT,
		];

		$this->fontCache->jsonWrite($fontkey . '.mtx.json', $font);
		$this->fontCache->binaryWrite($fontkey . '.cw.dat', $ttf->charWidths);
		$this->fontCache->binaryWrite($fontkey . '.gid.dat', $ttf->glyphIDtoUni);

		if ($this->fontCache->has($fontkey . '.cgm')) {
			$this->fontCache->remove($fontkey . '.cgm');
		}

		if ($this->fontCache->has($fontkey . '.z')) {
			$this->fontCache->remove($fontkey . '.z');
		}

		if ($this->fontCache->jsonHas($fontkey . '.cw127.json')) {
			$this->fontCache->jsonRemove($fontkey . '.cw127.json');
		}

		if ($this->fontCache->has($fontkey . '.cw')) {
			$this->fontCache->remove($fontkey . '.cw');
		}

		unset($ttf);
	}

	protected function getFontName($fullName)
	{
		return preg_replace('/[ ()]/', '', $fullName);
	}

	protected function getPanose($ttf)
	{
		$panose = '';
		if (count($ttf->panose)) {
			$panoseArray = array_merge([$ttf->sFamilyClass, $ttf->sFamilySubClass], $ttf->panose);
			foreach ($panoseArray as $value) {
				$panose .= ' ' . dechex($value);
			}
		}

		return $panose;
	}
}
