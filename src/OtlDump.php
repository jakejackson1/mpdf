<?php

namespace Mpdf;

use Mpdf\Fonts\FileReader;
use Mpdf\Fonts\FontCache;

/**
 * A readable report of the OpenType layout tables in a font, for working on OTL support.
 *
 * Extends the parser the renderer uses, rather than being a second copy of it. That is the whole
 * point: a debugging tool that parses independently is free to disagree with the thing it is meant
 * to explain, and is useless exactly when it is needed. What it overrides here is reporting - the
 * four table readers emit HTML as they go - not reading.
 */
class OtlDump extends TTFontFile
{

	var $GPOSFeatures; // mPDF 5.7.1

	var $GPOSLookups;  // mPDF 5.7.1

	var $GPOSScriptLang; // mPDF 5.7.1

	var $ignoreStrings; // mPDF 5.7.1

	var $MarkAttachmentType; // mPDF 5.7.1

	var $MarkGlyphSets; // mPDF 7.5.1

	var $GlyphClassMarks; // mPDF 5.7.1

	var $GlyphClassLigatures; // mPDF 5.7.1

	var $GlyphClassBases; // mPDF 5.7.1

	var $GlyphClassComponents; // mPDF 5.7.1

	var $GSUBScriptLang; // mPDF 5.7.1

	var $rtlPUAstr; // mPDF 5.7.1

	var $rtlPUAarr; // mPDF 5.7.1

	var $fontkey; // mPDF 5.7.1

	var $useOTL; // mPDF 5.7.1

	var $panose;

	var $maxUni;

	var $sFamilyClass;

	var $sFamilySubClass;

	var $sipset;

	var $smpset;

	var $numTables;

	var $searchRange;

	var $entrySelector;

	var $rangeShift;

	var $tables;

	var $otables;

	var $filename;

	var $glyphPos;

	var $charToGlyph;

	var $ascent;

	var $descent;

	var $name;

	var $familyName;

	var $styleName;

	var $fullName;

	var $uniqueFontID;

	var $unitsPerEm;

	var $bbox;

	var $capHeight;

	var $stemV;

	var $italicAngle;

	var $flags;

	var $underlinePosition;

	var $underlineThickness;

	var $charWidths;

	var $defaultWidth;

	var $maxStrLenRead;

	var $numTTCFonts;

	var $TTCFonts;

	var $maxUniChar;

	var $kerninfo;

	var $mode;

	/**
	 * The script and language whose lookups detail mode reports on.
	 *
	 * These used to be read as $this->mpdf->OTLscript and ->OTLlang. Mpdf declares neither, and it
	 * uses the Strict trait, so every read threw - which is why detail mode has never run. They are
	 * arguments now, because they are arguments.
	 */
	private $script;

	private $language;

	/**
	 * What the summary report's links should carry to reach a detail report of the same font.
	 *
	 * The summary lists every script and language system a font offers and links each to its own
	 * detail report. Only the caller knows how it named the font it handed over, so it says here,
	 * and the link gets the script and language appended.
	 *
	 * @var array query terms, e.g. ['family' => 'freeserif', 'style' => '']
	 */
	public $detailReportQuery = [];

	/**
	 * What each of GSUB and GPOS had to say when it did not carry the script or language system asked
	 * for. Two entries means neither table did, which is a mistake in the tag rather than a font that
	 * only positions or only substitutes.
	 *
	 * @var string[]
	 */
	private $notOffered = [];

	var $glyphToChar;

	var $fontRevision;

	var $glyphdata;

	var $glyphIDtoUn;

	var $restrictedUse;

	var $GSUBFeatures;

	var $GSUBLookups;

	var $glyphIDtoUni;

	var $GSLuCoverage;

	var $version;

	private $mpdf;

	public function __construct(Mpdf $mpdf, FontCache $fontCache, $fontDescriptor = 'win')
	{
		parent::__construct($fontCache, $fontDescriptor);

		$this->mpdf = $mpdf;
	}

	/**
	 * @param string $mode     'summary' lists the scripts, languages and features a font offers;
	 *                         'detail' walks the lookups of one script and language
	 * @param string $script   OpenType script tag, e.g. 'deva'. Required by detail mode
	 * @param string $language OpenType language system tag, e.g. 'DFLT'. Required by detail mode
	 */
	public function getMetrics($file, $fontkey, $TTCfontID = 0, $debug = false, $BMPonly = false, $useOTL = 0, $mode = null, $script = '', $language = '')
	{
		if ($mode === 'detail' && (!$script || !$language)) {
			throw new \Mpdf\MpdfException('Dumping the lookups of a font in detail needs a script and a language system to dump');
		}

		$this->mode = $mode;
		$this->script = $script;
		$this->language = $language;
		$this->notOffered = [];
		$this->useOTL = $useOTL; // mPDF 5.7.1
		$this->fontkey = $fontkey; // mPDF 5.7.1
		$this->filename = $file;
		$this->reader = new FileReader($file);

		$this->charWidths = '';
		$this->glyphPos = [];
		$this->charToGlyph = [];
		$this->tables = [];
		$this->otables = [];
		$this->kerninfo = [];
		$this->ascent = 0;
		$this->descent = 0;
		$this->numTTCFonts = 0;
		$this->TTCFonts = [];
		$this->version = $version = $this->reader->readUInt32();
		$this->panose = [];

		if ($version == 0x4F54544F) {
			throw new \Mpdf\Exception\FontException(sprintf('Fonts with postscript outlines are not supported (%s)', $file));
		}

		if ($version == 0x74746366 && !$TTCfontID) {
			throw new \Mpdf\Exception\FontException("TTCfontID for a TrueType Collection has to be defined in ttfontdata configuration key (" . $file . ")");
		}

		if (!in_array($version, [0x00010000, 0x74727565]) && !$TTCfontID) {
			throw new \Mpdf\Exception\FontException("Not a TrueType font: version=" . $version);
		}

		if ($TTCfontID > 0) {
			$this->version = $version = $this->reader->readUInt32(); // TTC Header version now
			if (!in_array($version, [0x00010000, 0x00020000])) {
				throw new \Mpdf\Exception\FontException("Error parsing TrueType Collection: version=" . $version . " - " . $file);
			}
			$this->numTTCFonts = $this->reader->readUInt32();
			for ($i = 1; $i <= $this->numTTCFonts; $i++) {
				$this->TTCFonts[$i]['offset'] = $this->reader->readUInt32();
			}
			$this->reader->seek($this->TTCFonts[$TTCfontID]['offset']);
			$this->version = $version = $this->reader->readUInt32(); // TTFont version again now
		}
		$this->readTableDirectory($debug);
		$this->extractInfo($debug, $BMPonly, $useOTL);
		$this->reader->close();
	}

	/////////////////////////////////////////////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////////////////

	function extractInfo($debug = false, $BMPonly = false, $useOTL = 0)
	{
		$this->panose = [];
		$this->sFamilyClass = 0;
		$this->sFamilySubClass = 0;
		///////////////////////////////////
		// name - Naming table
		///////////////////////////////////
		$name_offset = $this->seek_table("name");
		$format = $this->reader->readUInt16();
		if ($format != 0 && $format != 1) {
			throw new \Mpdf\Exception\FontException("Error loading font: Unknown name table format " . $format);
		}
		$numRecords = $this->reader->readUInt16();
		$string_data_offset = $name_offset + $this->reader->readUInt16();
		$names = [1 => '', 2 => '', 3 => '', 4 => '', 6 => ''];
		$K = array_keys($names);
		$nameCount = count($names);
		for ($i = 0; $i < $numRecords; $i++) {
			$platformId = $this->reader->readUInt16();
			$encodingId = $this->reader->readUInt16();
			$languageId = $this->reader->readUInt16();
			$nameId = $this->reader->readUInt16();
			$length = $this->reader->readUInt16();
			$offset = $this->reader->readUInt16();
			if (!in_array($nameId, $K)) {
				continue;
			}
			$N = '';
			if ($platformId == 3 && $encodingId == 1 && $languageId == 0x409) { // Microsoft, Unicode, US English, PS Name
				$opos = $this->reader->tell();
				$this->reader->seek($string_data_offset + $offset);
				if ($length % 2 != 0) {
					throw new \Mpdf\Exception\FontException("Error loading font: PostScript name is UTF-16BE string of odd length");
				}
				$length /= 2;
				$N = '';
				while ($length > 0) {
					$char = $this->reader->readUInt16();
					$N .= (chr($char));
					$length -= 1;
				}
				$this->reader->seek($opos);
			} else {
				if ($platformId == 1 && $encodingId == 0 && $languageId == 0) { // Macintosh, Roman, English, PS Name
					$opos = $this->reader->tell();
					$N = $this->reader->bytesAt($string_data_offset + $offset, $length);
					$this->reader->seek($opos);
				}
			}
			if ($N && $names[$nameId] == '') {
				$names[$nameId] = $N;
				$nameCount -= 1;
				if ($nameCount == 0) {
					break;
				}
			}
		}
		if ($names[6]) {
			$psName = $names[6];
		} else {
			if ($names[4]) {
				$psName = preg_replace('/ /', '-', $names[4]);
			} else {
				if ($names[1]) {
					$psName = preg_replace('/ /', '-', $names[1]);
				} else {
					$psName = '';
				}
			}
		}
		if (!$psName) {
			throw new \Mpdf\Exception\FontException("Error loading font: Could not find PostScript font name: " . $this->filename);
		}
		if ($debug) {
			for ($i = 0; $i < count($psName); $i++) {
				$c = $psName[$i];
				$oc = ord($c);
				if ($oc > 126 || strpos(' [](){}<>/%', $c) !== false) {
					throw new \Mpdf\Exception\FontException("psName=" . $psName . " contains invalid character " . $c . " ie U+" . ord($c));
				}
			}
		}
		$this->name = $psName;
		if ($names[1]) {
			$this->familyName = $names[1];
		} else {
			$this->familyName = $psName;
		}
		if ($names[2]) {
			$this->styleName = $names[2];
		} else {
			$this->styleName = 'Regular';
		}
		if ($names[4]) {
			$this->fullName = $names[4];
		} else {
			$this->fullName = $psName;
		}
		if ($names[3]) {
			$this->uniqueFontID = $names[3];
		} else {
			$this->uniqueFontID = $psName;
		}

		if ($names[6]) {
			$this->fullName = $names[6];
		}

		///////////////////////////////////
		// head - Font header table
		///////////////////////////////////
		$this->seek_table("head");
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			$ver_min = $this->reader->readUInt16();
			if ($ver_maj != 1) {
				throw new \Mpdf\Exception\FontException('Error loading font: Unknown head table version ' . $ver_maj . '.' . $ver_min);
			}
			$this->fontRevision = $this->reader->readUInt16() . $this->reader->readUInt16();

			$this->reader->skip(4);
			$magic = $this->reader->readUInt32();
			if ($magic != 0x5F0F3CF5) {
				throw new \Mpdf\Exception\FontException('Error loading font: Invalid head table magic ' . $magic);
			}
			$this->reader->skip(2);
		} else {
			$this->reader->skip(18);
		}
		$this->unitsPerEm = $unitsPerEm = $this->reader->readUInt16();
		$scale = 1000 / $unitsPerEm;
		$this->reader->skip(16);
		$xMin = $this->reader->readInt16();
		$yMin = $this->reader->readInt16();
		$xMax = $this->reader->readInt16();
		$yMax = $this->reader->readInt16();
		$this->bbox = [($xMin * $scale), ($yMin * $scale), ($xMax * $scale), ($yMax * $scale)];
		$this->reader->skip(3 * 2);
		$indexToLocFormat = $this->reader->readUInt16();
		$glyphDataFormat = $this->reader->readUInt16();
		if ($glyphDataFormat != 0) {
			throw new \Mpdf\Exception\FontException('Error loading font: Unknown glyph data format ' . $glyphDataFormat);
		}

		///////////////////////////////////
		// hhea metrics table
		///////////////////////////////////
		// ttf2t1 seems to use this value rather than the one in OS/2 - so put in for compatibility
		if (isset($this->tables["hhea"])) {
			$this->seek_table("hhea");
			$this->reader->skip(4);
			$hheaAscender = $this->reader->readInt16();
			$hheaDescender = $this->reader->readInt16();
			$this->ascent = ($hheaAscender * $scale);
			$this->descent = ($hheaDescender * $scale);
		}

		///////////////////////////////////
		// OS/2 - OS/2 and Windows metrics table
		///////////////////////////////////
		if (isset($this->tables["OS/2"])) {
			$this->seek_table("OS/2");
			$version = $this->reader->readUInt16();
			$this->reader->skip(2);
			$usWeightClass = $this->reader->readUInt16();
			$this->reader->skip(2);
			$fsType = $this->reader->readUInt16();
			if ($fsType == 0x0002 || ($fsType & 0x0300) != 0) {
				global $overrideTTFFontRestriction;
				if (!$overrideTTFFontRestriction) {
					throw new \Mpdf\Exception\FontException('Font file ' . $this->filename . ' cannot be embedded due to copyright restrictions.');
				}
				$this->restrictedUse = true;
			}
			$this->reader->skip(20);
			$sF = $this->reader->readInt16();
			$this->sFamilyClass = ($sF >> 8);
			$this->sFamilySubClass = ($sF & 0xFF);
			// PANOSE, 10 bytes, per the OS/2 table
			$panose = $this->reader->read(10);
			$this->panose = [];
			for ($p = 0; $p < strlen($panose); $p++) {
				$this->panose[] = ord($panose[$p]);
			}
			$this->reader->skip(26);
			$sTypoAscender = $this->reader->readInt16();
			$sTypoDescender = $this->reader->readInt16();
			if (!$this->ascent) {
				$this->ascent = ($sTypoAscender * $scale);
			}
			if (!$this->descent) {
				$this->descent = ($sTypoDescender * $scale);
			}
			if ($version > 1) {
				$this->reader->skip(16);
				$sCapHeight = $this->reader->readInt16();
				$this->capHeight = ($sCapHeight * $scale);
			} else {
				$this->capHeight = $this->ascent;
			}
		} else {
			$usWeightClass = 500;
			if (!$this->ascent) {
				$this->ascent = ($yMax * $scale);
			}
			if (!$this->descent) {
				$this->descent = ($yMin * $scale);
			}
			$this->capHeight = $this->ascent;
		}
		$this->stemV = 50 + intval(pow(($usWeightClass / 65.0), 2));

		///////////////////////////////////
		// post - PostScript table
		///////////////////////////////////
		$this->seek_table("post");
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			$ver_min = $this->reader->readUInt16();
			if ($ver_maj < 1 || $ver_maj > 4) {
				throw new \Mpdf\Exception\FontException('Error loading font: Unknown post table version ' . $ver_maj);
			}
		} else {
			$this->reader->skip(4);
		}
		$this->italicAngle = $this->reader->readInt16() + $this->reader->readUInt16() / 65536.0;
		$this->underlinePosition = $this->reader->readInt16() * $scale;
		$this->underlineThickness = $this->reader->readInt16() * $scale;
		$isFixedPitch = $this->reader->readUInt32();

		$this->flags = 4;

		if ($this->italicAngle != 0) {
			$this->flags = $this->flags | 64;
		}
		if ($usWeightClass >= 600) {
			$this->flags = $this->flags | 262144;
		}
		if ($isFixedPitch) {
			$this->flags = $this->flags | 1;
		}

		///////////////////////////////////
		// hhea - Horizontal header table
		///////////////////////////////////
		$this->seek_table("hhea");
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			$ver_min = $this->reader->readUInt16();
			if ($ver_maj != 1) {
				throw new \Mpdf\Exception\FontException(sprintf('Error loading font: Unknown hhea table version %s', $ver_maj));
			}
			$this->reader->skip(28);
		} else {
			$this->reader->skip(32);
		}
		$metricDataFormat = $this->reader->readUInt16();
		if ($metricDataFormat != 0) {
			throw new \Mpdf\Exception\FontException('Error loading font: Unknown horizontal metric data format ' . $metricDataFormat);
		}
		$numberOfHMetrics = $this->reader->readUInt16();
		if ($numberOfHMetrics == 0) {
			throw new \Mpdf\Exception\FontException('Error loading font: Number of horizontal metrics is 0');
		}

		///////////////////////////////////
		// maxp - Maximum profile table
		///////////////////////////////////
		$this->seek_table("maxp");
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			$ver_min = $this->reader->readUInt16();
			if ($ver_maj != 1) {
				throw new \Mpdf\Exception\FontException('Error loading font: Unknown maxp table version ' . $ver_maj);
			}
		} else {
			$this->reader->skip(4);
		}
		$numGlyphs = $this->reader->readUInt16();

		///////////////////////////////////
		// cmap - Character to glyph index mapping table
		///////////////////////////////////
		$cmap_offset = $this->seek_table("cmap");
		$this->reader->skip(2);
		$cmapTableCount = $this->reader->readUInt16();
		$unicode_cmap_offset = 0;
		for ($i = 0; $i < $cmapTableCount; $i++) {
			$platformID = $this->reader->readUInt16();
			$encodingID = $this->reader->readUInt16();
			$offset = $this->reader->readUInt32();
			$save_pos = $this->reader->tell();
			if (($platformID == 3 && $encodingID == 1) || $platformID == 0) { // Microsoft, Unicode
				$format = $this->reader->uint16At($cmap_offset + $offset);
				if ($format == 4) {
					if (!$unicode_cmap_offset) {
						$unicode_cmap_offset = $cmap_offset + $offset;
					}
					if ($BMPonly) {
						break;
					}
				}
			} // Microsoft, Unicode Format 12 table HKCS
			else {
				if ((($platformID == 3 && $encodingID == 10) || $platformID == 0) && !$BMPonly) {
					$format = $this->reader->uint16At($cmap_offset + $offset);
					if ($format == 12) {
						$unicode_cmap_offset = $cmap_offset + $offset;
						break;
					}
				}
			}
			$this->reader->seek($save_pos);
		}

		if (!$unicode_cmap_offset) {
			throw new \Mpdf\Exception\FontException('Font (' . $this->filename . ') does not have cmap for Unicode (platform 3, encoding 1, format 4, or platform 0, any encoding, format 4)');
		}

		$sipset = false;
		$smpset = false;

		// mPDF 5.7.1
		$this->GSUBScriptLang = [];
		$this->rtlPUAstr = '';
		$this->rtlPUAarr = [];
		$this->GSUBFeatures = [];
		$this->GSUBLookups = [];
		$this->GPOSScriptLang = [];
		$this->GPOSFeatures = [];
		$this->GPOSLookups = [];
		$this->glyphIDtoUni = '';

		// Format 12 CMAP does characters above Unicode BMP i.e. some HKCS characters U+20000 and above
		if ($format == 12 && !$BMPonly) {
			$this->maxUniChar = 0;
			$this->reader->seek($unicode_cmap_offset + 4);
			$length = $this->reader->readUInt32();
			$limit = $unicode_cmap_offset + $length;
			$this->reader->skip(4);

			$nGroups = $this->reader->readUInt32();

			$glyphToChar = [];
			$charToGlyph = [];
			for ($i = 0; $i < $nGroups; $i++) {
				$startCharCode = $this->reader->readUInt32();
				$endCharCode = $this->reader->readUInt32();
				$startGlyphCode = $this->reader->readUInt32();
				if ($endCharCode > 0x20000 && $endCharCode < 0x2FFFF) {
					$sipset = true;
				} else {
					if ($endCharCode > 0x10000 && $endCharCode < 0x1FFFF) {
						$smpset = true;
					}
				}
				$offset = 0;
				for ($unichar = $startCharCode; $unichar <= $endCharCode; $unichar++) {
					$glyph = $startGlyphCode + $offset;
					$offset++;
					if ($unichar < 0x30000) {
						$charToGlyph[$unichar] = $glyph;
						$this->maxUniChar = max($unichar, $this->maxUniChar);
						$glyphToChar[$glyph][] = $unichar;
					}
				}
			}
		} else {
			$glyphToChar = [];
			$charToGlyph = [];
			$this->getCMAP4($unicode_cmap_offset, $glyphToChar, $charToGlyph);
		}
		$this->sipset = $sipset;
		$this->smpset = $smpset;

		///////////////////////////////////
		// mPDF 5.7.1
		// Map Unmapped glyphs - from $numGlyphs
		if ($this->useOTL) {
			$bctr = 0xE000;
			for ($gid = 1; $gid < $numGlyphs; $gid++) {
				if (!isset($glyphToChar[$gid])) {
					while (isset($charToGlyph[$bctr])) {
						$bctr++;
					} // Avoid overwriting a glyph already mapped in PUA
					if (($bctr > 0xF8FF) && ($bctr < 0x2CEB0)) {
						if (!$BMPonly) {
							$bctr = 0x2CEB0;  // Use unassigned area 0x2CEB0 to 0x2F7FF (space for 10,000 characters)
							$this->sipset = $sipset = true; // forces subsetting; also ensure charwidths are saved
							while (isset($charToGlyph[$bctr])) {
								$bctr++;
							}
						} else {
							throw new \Mpdf\Exception\FontException(sprintf('Font "%s" does not have cmap for Unicode (platform 3, encoding 1, format 4, or platform 0, any encoding, format 4)', $this->filename));
						}
					}
					$glyphToChar[$gid][] = $bctr;
					$charToGlyph[$bctr] = $gid;
					$this->maxUniChar = max($bctr, $this->maxUniChar);
					$bctr++;
				}
			}
		}
		$this->glyphToChar = $glyphToChar;
		$this->charToGlyph = $charToGlyph;
		///////////////////////////////////
		// mPDF 5.7.1	OpenType Layout tables
		$this->GSUBScriptLang = [];
		$this->rtlPUAstr = '';
		$this->rtlPUAarr = [];
		if ($useOTL) {
			$this->_getGDEFtables();
			list($this->GSUBScriptLang, $this->GSUBFeatures, $this->GSUBLookups, $this->rtlPUAstr, $this->rtlPUAarr) = $this->_getGSUBtables();
			list($this->GPOSScriptLang, $this->GPOSFeatures, $this->GPOSLookups) = $this->_getGPOStables();
			$this->failIfNeitherTableOffers();
			$this->glyphIDtoUni = str_pad('', 256 * 256 * 3, "\x00");
			foreach ($glyphToChar as $gid => $arr) {
				if (isset($glyphToChar[$gid][0])) {
					$char = $glyphToChar[$gid][0];
					if ($char != 0 && $char != 65535) {
						$this->glyphIDtoUni[$gid * 3] = chr($char >> 16);
						$this->glyphIDtoUni[$gid * 3 + 1] = chr(($char >> 8) & 0xFF);
						$this->glyphIDtoUni[$gid * 3 + 2] = chr($char & 0xFF);
					}
				}
			}
		}
		///////////////////////////////////
		///////////////////////////////////
		// hmtx - Horizontal metrics table
		///////////////////////////////////
		$this->getHMTX($numberOfHMetrics, $numGlyphs, $glyphToChar, $scale);
	}

	/////////////////////////////////////////////////////////////////////////////////////////
	function _getGDEFtables()
	{
		///////////////////////////////////
		// GDEF - Glyph Definition
		///////////////////////////////////
		// https://learn.microsoft.com/en-us/typography/opentype/spec/gdef
		if (isset($this->tables["GDEF"])) {
			if ($this->mode == 'summary') {
				$this->mpdf->WriteHTML('<h1>GDEF table</h1>');
			}
			$gdef_offset = $this->seek_table("GDEF");
			// ULONG Version of the GDEF table-currently 0x00010000
			$ver_maj = $this->reader->readUInt16();
			$ver_min = $this->reader->readUInt16();
			$GlyphClassDef_offset = $this->reader->readUInt16();
			$AttachList_offset = $this->reader->readUInt16();
			$LigCaretList_offset = $this->reader->readUInt16();
			$MarkAttachClassDef_offset = $this->reader->readUInt16();

			// GDEF 1.2 added the MarkGlyphSetsDef offset; 1.3 keeps it and appends an ItemVarStore after it
			if ($ver_min >= 2) {
				$MarkGlyphSetsDef_offset = $this->reader->readUInt16();
			}

			// GlyphClassDef
			$this->reader->seek($gdef_offset + $GlyphClassDef_offset);
			/*
			  1	Base glyph (single character, spacing glyph)
			  2	Ligature glyph (multiple character, spacing glyph)
			  3	Mark glyph (non-spacing combining glyph)
			  4	Component glyph (part of single character, spacing glyph)
			 */
			$GlyphByClass = $this->_getClassDefinitionTable();

			if ($this->mode == 'summary') {
				$this->mpdf->WriteHTML('<h2>Glyph classes</h2>');
			}

			if (isset($GlyphByClass[1]) && count($GlyphByClass[1]) > 0) {
				$this->GlyphClassBases = $this->formatClassArr($GlyphByClass[1]);
				if ($this->mode == 'summary') {
					$this->mpdf->WriteHTML('<h3>Glyph class 1</h3>');
					$this->mpdf->WriteHTML('<h5>Base glyph (single character, spacing glyph)</h5>');
					$html = '';
					$html .= '<div class="glyphs">';
					foreach ($GlyphByClass[1] as $g) {
						$html .= '&#x' . $g . '; ';
					}
					$html .= '</div>';
					$this->mpdf->WriteHTML($html);
				}
			} else {
				$this->GlyphClassBases = '';
			}
			if (isset($GlyphByClass[2]) && count($GlyphByClass[2]) > 0) {
				$this->GlyphClassLigatures = $this->formatClassArr($GlyphByClass[2]);
				if ($this->mode == 'summary') {
					$this->mpdf->WriteHTML('<h3>Glyph class 2</h3>');
					$this->mpdf->WriteHTML('<h5>Ligature glyph (multiple character, spacing glyph)</h5>');
					$html = '';
					$html .= '<div class="glyphs">';
					foreach ($GlyphByClass[2] as $g) {
						$html .= '&#x' . $g . '; ';
					}
					$html .= '</div>';
					$this->mpdf->WriteHTML($html);
				}
			} else {
				$this->GlyphClassLigatures = '';
			}
			if (isset($GlyphByClass[3]) && count($GlyphByClass[3]) > 0) {
				$this->GlyphClassMarks = $this->formatClassArr($GlyphByClass[3]);
				if ($this->mode == 'summary') {
					$this->mpdf->WriteHTML('<h3>Glyph class 3</h3>');
					$this->mpdf->WriteHTML('<h5>Mark glyph (non-spacing combining glyph)</h5>');
					$html = '';
					$html .= '<div class="glyphs">';
					foreach ($GlyphByClass[3] as $g) {
						$html .= '&#x25cc;&#x' . $g . '; ';
					}
					$html .= '</div>';
					$this->mpdf->WriteHTML($html);
				}
			} else {
				$this->GlyphClassMarks = '';
			}
			if (isset($GlyphByClass[4]) && count($GlyphByClass[4]) > 0) {
				$this->GlyphClassComponents = $this->formatClassArr($GlyphByClass[4]);
				if ($this->mode == 'summary') {
					$this->mpdf->WriteHTML('<h3>Glyph class 4</h3>');
					$this->mpdf->WriteHTML('<h5>Component glyph (part of single character, spacing glyph)</h5>');
					$html = '';
					$html .= '<div class="glyphs">';
					foreach ($GlyphByClass[4] as $g) {
						$html .= '&#x' . $g . '; ';
					}
					$html .= '</div>';
					$this->mpdf->WriteHTML($html);
				}
			} else {
				$this->GlyphClassComponents = '';
			}

			// to use for MarkAttachmentType. A font need not define any mark glyphs, and the parser
			// already allows for that; this copy did not
			$Marks = isset($GlyphByClass[3]) ? $GlyphByClass[3] : [];

			/* Required for GPOS
			  // Attachment List
			  if ($AttachList_offset) {
			  $this->reader->seek($gdef_offset+$AttachList_offset );
			  }
			  The Attachment Point List table (AttachmentList) identifies all the attachment points defined in the GPOS table and their associated glyphs so a client can quickly access coordinates for each glyph's attachment points. As a result, the client can cache coordinates for attachment points along with glyph bitmaps and avoid recalculating the attachment points each time it displays a glyph. Without this table, processing speed would be slower because the client would have to decode the GPOS lookups that define attachment points and compile the points in a list.

			  The Attachment List table (AttachList) may be used to cache attachment point coordinates along with glyph bitmaps.

			  The table consists of an offset to a Coverage table (Coverage) listing all glyphs that define attachment points in the GPOS table, a count of the glyphs with attachment points (GlyphCount), and an array of offsets to AttachPoint tables (AttachPoint). The array lists the AttachPoint tables, one for each glyph in the Coverage table, in the same order as the Coverage Index.
			  AttachList table
			  Type 	Name 	Description
			  Offset 	Coverage 	Offset to Coverage table - from beginning of AttachList table
			  uint16 	GlyphCount 	Number of glyphs with attachment points
			  Offset 	AttachPoint[GlyphCount] 	Array of offsets to AttachPoint tables-from beginning of AttachList table-in Coverage Index order

			  An AttachPoint table consists of a count of the attachment points on a single glyph (PointCount) and an array of contour indices of those points (PointIndex), listed in increasing numerical order.

			  AttachPoint table
			  Type 	Name 	Description
			  uint16 	PointCount 	Number of attachment points on this glyph
			  uint16 	PointIndex[PointCount] 	Array of contour point indices -in increasing numerical order

			  See Example 3 - https://learn.microsoft.com/en-us/typography/opentype/spec/gdef
			 */

			// Ligature Caret List
			// The Ligature Caret List table (LigCaretList) defines caret positions for all the ligatures in a font.
			// Not required for mDPF
			// MarkAttachmentType
			if ($MarkAttachClassDef_offset) {
				if ($this->mode == 'summary') {
					$this->mpdf->WriteHTML('<h1>Mark Attachment Types</h1>');
				}
				$this->reader->seek($gdef_offset + $MarkAttachClassDef_offset);
				$MarkAttachmentTypes = $this->_getClassDefinitionTable();
				foreach ($MarkAttachmentTypes as $class => $glyphs) {
					if (is_array($Marks) && count($Marks)) {
						$mat = array_diff($Marks, $MarkAttachmentTypes[$class]);
						sort($mat, SORT_STRING);
					} else {
						$mat = [];
					}

					$this->MarkAttachmentType[$class] = $this->formatClassArr($mat);

					if ($this->mode == 'summary') {
						$this->mpdf->WriteHTML('<h3>Mark Attachment Type: ' . $class . '</h3>');
						$html = '';
						$html .= '<div class="glyphs">';
						foreach ($glyphs as $g) {
							$html .= '&#x25cc;&#x' . $g . '; ';
						}
						$html .= '</div>';
						$this->mpdf->WriteHTML($html);
					}
				}
			} else {
				$this->MarkAttachmentType = [];
			}

			// MarkGlyphSets in Version 0x00010002 of GDEF and later
			if ($ver_min >= 2 && $MarkGlyphSetsDef_offset) {
				if ($this->mode == 'summary') {
					$this->mpdf->WriteHTML('<h1>Mark Glyph Sets</h1>');
				}
				$this->reader->seek($gdef_offset + $MarkGlyphSetsDef_offset);
				$MarkSetTableFormat = $this->reader->readUInt16();
				$MarkSetCount = $this->reader->readUInt16();
				$MarkSetOffset = [];
				for ($i = 0; $i < $MarkSetCount; $i++) {
					$MarkSetOffset[] = $this->reader->readUInt32();
				}
				for ($i = 0; $i < $MarkSetCount; $i++) {
					// Coverage offsets are relative to the MarkGlyphSetsDef table, not the file
					$this->reader->seek($gdef_offset + $MarkGlyphSetsDef_offset + $MarkSetOffset[$i]);
					$glyphs = $this->_getCoverage();
					$this->MarkGlyphSets[$i] = $this->formatClassArr($glyphs);
					if ($this->mode == 'summary') {
						$this->mpdf->WriteHTML('<h3>Mark Glyph Set class: ' . $i . '</h3>');
						$html = '';
						$html .= '<div class="glyphs">';
						foreach ($glyphs as $g) {
							$html .= '&#x25cc;&#x' . $g . '; ';
						}
						$html .= '</div>';
						$this->mpdf->WriteHTML($html);
					}
				}
			} else {
				$this->MarkGlyphSets = [];
			}
		} else {
			$this->mpdf->WriteHTML('<div>GDEF table not defined</div>');
		}
	}

	function _getGSUBtables()
	{
		///////////////////////////////////
		// GSUB - Glyph Substitution
		///////////////////////////////////
		if (isset($this->tables["GSUB"])) {
			$this->mpdf->WriteHTML('<h1>GSUB Tables</h1>');
			$ffeats = [];
			$gsub_offset = $this->seek_table("GSUB");
			$this->reader->skip(4);
			$ScriptList_offset = $gsub_offset + $this->reader->readUInt16();
			$FeatureList_offset = $gsub_offset + $this->reader->readUInt16();
			$LookupList_offset = $gsub_offset + $this->reader->readUInt16();

			// ScriptList
			$this->reader->seek($ScriptList_offset);
			$ScriptCount = $this->reader->readUInt16();
			for ($i = 0; $i < $ScriptCount; $i++) {
				$ScriptTag = $this->reader->readTag(); // = "beng", "deva" etc.
				$ScriptTableOffset = $this->reader->readUInt16();
				$ffeats[$ScriptTag] = $ScriptList_offset + $ScriptTableOffset;
			}

			// Script Table
			foreach ($ffeats as $t => $o) {
				$ls = [];
				$this->reader->seek($o);
				$DefLangSys_offset = $this->reader->readUInt16();
				if ($DefLangSys_offset > 0) {
					$ls['DFLT'] = $DefLangSys_offset + $o;
				}
				$LangSysCount = $this->reader->readUInt16();
				for ($i = 0; $i < $LangSysCount; $i++) {
					$LangTag = $this->reader->readTag(); // =
					$LangTableOffset = $this->reader->readUInt16();
					$ls[$LangTag] = $o + $LangTableOffset;
				}
				$ffeats[$t] = $ls;
			}
			// Get FeatureIndexList
			// LangSys Table - from first listed langsys
			foreach ($ffeats as $st => $scripts) {
				foreach ($scripts as $t => $o) {
					$FeatureIndex = [];
					$langsystable_offset = $o;
					$this->reader->seek($langsystable_offset);
					$LookUpOrder = $this->reader->readUInt16(); //==NULL
					$ReqFeatureIndex = $this->reader->readUInt16();
					if ($ReqFeatureIndex != 0xFFFF) {
						$FeatureIndex[] = $ReqFeatureIndex;
					}
					$FeatureCount = $this->reader->readUInt16();
					for ($i = 0; $i < $FeatureCount; $i++) {
						$FeatureIndex[] = $this->reader->readUInt16(); // = index of feature
					}
					$ffeats[$st][$t] = $FeatureIndex;
				}
			}
			// Feauture List => LookupListIndex es
			$this->reader->seek($FeatureList_offset);
			$FeatureCount = $this->reader->readUInt16();
			$Feature = [];
			for ($i = 0; $i < $FeatureCount; $i++) {
				$Feature[$i] = ['tag' => $this->reader->readTag()];
				$Feature[$i]['offset'] = $FeatureList_offset + $this->reader->readUInt16();
			}
			for ($i = 0; $i < $FeatureCount; $i++) {
				$this->reader->seek($Feature[$i]['offset']);
				$this->reader->readUInt16(); // null
				$Feature[$i]['LookupCount'] = $Lookupcount = $this->reader->readUInt16();
				$Feature[$i]['LookupListIndex'] = [];
				for ($c = 0; $c < $Lookupcount; $c++) {
					$Feature[$i]['LookupListIndex'][] = $this->reader->readUInt16();
				}
			}

			foreach ($ffeats as $st => $scripts) {
				foreach ($scripts as $t => $o) {
					$FeatureIndex = $ffeats[$st][$t];
					foreach ($FeatureIndex as $k => $fi) {
						$ffeats[$st][$t][$k] = $Feature[$fi];
					}
				}
			}
			//=====================================================================================
			$gsub = [];
			$GSUBScriptLang = [];
			foreach ($ffeats as $st => $scripts) {
				foreach ($scripts as $t => $langsys) {
					$lg = [];
					foreach ($langsys as $ft) {
						$lg[$ft['LookupListIndex'][0]] = $ft;
					}
					// list of Lookups in order they need to be run i.e. order listed in Lookup table
					ksort($lg);
					foreach ($lg as $ft) {
						$gsub[$st][$t][$ft['tag']] = $ft['LookupListIndex'];
					}
					if (!isset($GSUBScriptLang[$st])) {
						$GSUBScriptLang[$st] = '';
					}
					$GSUBScriptLang[$st] .= $t . ' ';
				}
			}

			if ($this->mode == 'summary') {
				$this->mpdf->WriteHTML('<h3>GSUB Scripts &amp; Languages</h3>');
				$this->mpdf->WriteHTML('<div class="glyphs">');
				$html = '';
				if (count($gsub)) {
					foreach ($gsub as $st => $g) {
						$html .= '<h5>' . $st . '</h5>';
						foreach ($g as $l => $t) {
							$html .= '<div><a href="' . $this->detailLink($st, $l) . '">' . $l . '</a></b>: ';
							foreach ($t as $tag => $o) {
								$html .= $tag . ' ';
							}
							$html .= '</div>';
						}
					}
				} else {
					$html .= '<div>No entries in GSUB table.</div>';
				}
				$this->mpdf->WriteHTML($html);
				$this->mpdf->WriteHTML('</div>');

				// Summary mode has finished reporting and stops before the lookup list, so it has no
				// lookups and no RTL mapping to hand back - but it has just worked out which scripts and
				// languages the font offers, and extractInfo destructures all five either way
				return [$GSUBScriptLang, $gsub, [], '', []];
			}

			//=====================================================================================
			// Get metadata and offsets for whole Lookup List table
			$this->reader->seek($LookupList_offset);
			$LookupCount = $this->reader->readUInt16();
			$GSLookup = [];
			$Offsets = [];
			$SubtableCount = [];
			for ($i = 0; $i < $LookupCount; $i++) {
				$Offsets[$i] = $LookupList_offset + $this->reader->readUInt16();
			}
			for ($i = 0; $i < $LookupCount; $i++) {
				$this->reader->seek($Offsets[$i]);
				$GSLookup[$i]['Type'] = $this->reader->readUInt16();
				$GSLookup[$i]['Flag'] = $flag = $this->reader->readUInt16();
				$GSLookup[$i]['SubtableCount'] = $SubtableCount[$i] = $this->reader->readUInt16();
				for ($c = 0; $c < $SubtableCount[$i]; $c++) {
					$GSLookup[$i]['Subtables'][$c] = $Offsets[$i] + $this->reader->readUInt16();
				}
				// MarkFilteringSet = Index (base 0) into GDEF mark glyph sets structure
				if (($flag & 0x0010) == 0x0010) {
					$GSLookup[$i]['MarkFilteringSet'] = $this->reader->readUInt16();
				} else {
					$GSLookup[$i]['MarkFilteringSet'] = '';
				}
				// Lookup Type 7: Extension
				if ($GSLookup[$i]['Type'] == 7) {
					// Overwrites new offset (32-bit) for each subtable, and a new lookup Type
					for ($c = 0; $c < $SubtableCount[$i]; $c++) {
						$this->reader->seek($GSLookup[$i]['Subtables'][$c]);
						$ExtensionPosFormat = $this->reader->readUInt16();
						$type = $this->reader->readUInt16();
						$GSLookup[$i]['Subtables'][$c] = $GSLookup[$i]['Subtables'][$c] + $this->reader->readUInt32();
					}
					$GSLookup[$i]['Type'] = $type;
				}
			}

			//=====================================================================================
			// Process Whole LookupList - Get LuCoverage = Lookup coverage just for first glyph
			$this->GSLuCoverage = [];
			for ($i = 0; $i < $LookupCount; $i++) {
				for ($c = 0; $c < $GSLookup[$i]['SubtableCount']; $c++) {
					$this->reader->seek($GSLookup[$i]['Subtables'][$c]);
					$PosFormat = $this->reader->readUInt16();

					if ($GSLookup[$i]['Type'] == 5 && $PosFormat == 3) {
						$this->reader->skip(4);
					} else {
						if ($GSLookup[$i]['Type'] == 6 && $PosFormat == 3) {
							$BacktrackGlyphCount = $this->reader->readUInt16();
							$this->reader->skip(2 * $BacktrackGlyphCount + 2);
						}
					}
					// Reading position 0's Coverage is the whole of what the gate needs. The shaper offers a
					// subtable the glyph it is standing on and asks whether a match could start there, and every
					// format puts the Coverage of the first input position right here: straight after the format
					// for types 1 to 4 and 8 and for formats 1 and 2 of types 5 and 6, and after the counts and
					// backtrack offsets stepped over above for format 3. Otl::checkContextMatchMultiple starts
					// its input loop at 1 for the same reason - position 0 is what got it called.
					$Coverage = $GSLookup[$i]['Subtables'][$c] + $this->reader->readUInt16();
					$this->reader->seek($Coverage);
					$glyphs = $this->_getCoverage();
					$this->GSLuCoverage[$i][$c] = implode('|', $glyphs);
				}
			}

// $this->GSLuCoverage and $GSLookup
			//=====================================================================================
			$s = '<?php
$GSLuCoverage = ' . var_export($this->GSLuCoverage, true) . ';
?>';

			//=====================================================================================
			$s = '<?php
$GlyphClassBases = \'' . $this->GlyphClassBases . '\';
$GlyphClassMarks = \'' . $this->GlyphClassMarks . '\';
$GlyphClassLigatures = \'' . $this->GlyphClassLigatures . '\';
$GlyphClassComponents = \'' . $this->GlyphClassComponents . '\';
$MarkGlyphSets = ' . var_export($this->MarkGlyphSets, true) . ';
$MarkAttachmentType = ' . var_export($this->MarkAttachmentType, true) . ';
?>';

			//=====================================================================================
			//=====================================================================================
			//=====================================================================================
// Now repeats as original to get Substitution rules
			//=====================================================================================
			//=====================================================================================
			//=====================================================================================
			// Get metadata and offsets for whole Lookup List table
			$this->reader->seek($LookupList_offset);
			$LookupCount = $this->reader->readUInt16();
			$Lookup = [];
			for ($i = 0; $i < $LookupCount; $i++) {
				$Lookup[$i]['offset'] = $LookupList_offset + $this->reader->readUInt16();
			}
			for ($i = 0; $i < $LookupCount; $i++) {
				$this->reader->seek($Lookup[$i]['offset']);
				$Lookup[$i]['Type'] = $this->reader->readUInt16();
				$Lookup[$i]['Flag'] = $flag = $this->reader->readUInt16();
				$Lookup[$i]['SubtableCount'] = $this->reader->readUInt16();
				for ($c = 0; $c < $Lookup[$i]['SubtableCount']; $c++) {
					$Lookup[$i]['Subtable'][$c]['Offset'] = $Lookup[$i]['offset'] + $this->reader->readUInt16();
				}
				// MarkFilteringSet = Index (base 0) into GDEF mark glyph sets structure
				if (($flag & 0x0010) == 0x0010) {
					$Lookup[$i]['MarkFilteringSet'] = $this->reader->readUInt16();
				} else {
					$Lookup[$i]['MarkFilteringSet'] = '';
				}

				// Lookup Type 7: Extension
				if ($Lookup[$i]['Type'] == 7) {
					// Overwrites new offset (32-bit) for each subtable, and a new lookup Type
					for ($c = 0; $c < $Lookup[$i]['SubtableCount']; $c++) {
						$this->reader->seek($Lookup[$i]['Subtable'][$c]['Offset']);
						$ExtensionPosFormat = $this->reader->readUInt16();
						$type = $this->reader->readUInt16();
						$Lookup[$i]['Subtable'][$c]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt32();
					}
					$Lookup[$i]['Type'] = $type;
				}
			}

			//=====================================================================================
			// Process (1) Whole LookupList
			for ($i = 0; $i < $LookupCount; $i++) {
				for ($c = 0; $c < $Lookup[$i]['SubtableCount']; $c++) {
					$this->reader->seek($Lookup[$i]['Subtable'][$c]['Offset']);
					$SubstFormat = $this->reader->readUInt16();
					$Lookup[$i]['Subtable'][$c]['Format'] = $SubstFormat;

					/*
					  Lookup['Type'] Enumeration table for glyph substitution
					  Value	Type	Description
					  1	Single	Replace one glyph with one glyph
					  2	Multiple	Replace one glyph with more than one glyph
					  3	Alternate	Replace one glyph with one of many glyphs
					  4	Ligature	Replace multiple glyphs with one glyph
					  5	Context	Replace one or more glyphs in context
					  6	Chaining Context	Replace one or more glyphs in chained context
					  7	Extension Substitution	Extension mechanism for other substitutions (i.e. this excludes the Extension type substitution itself)
					  8	Reverse chaining context single 	Applied in reverse order, replace single glyph in chaining context
					 */

					// LookupType 1: Single Substitution Subtable
					if ($Lookup[$i]['Type'] == 1) {
						$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
						if ($SubstFormat == 1) { // Calculated output glyph indices
							$Lookup[$i]['Subtable'][$c]['DeltaGlyphID'] = $this->reader->readInt16();
						} else {
							if ($SubstFormat == 2) { // Specified output glyph indices
								$GlyphCount = $this->reader->readUInt16();
								for ($g = 0; $g < $GlyphCount; $g++) {
									$Lookup[$i]['Subtable'][$c]['Glyphs'][] = $this->reader->readUInt16();
								}
							}
						}
					} // LookupType 2: Multiple Substitution Subtable
					else {
						if ($Lookup[$i]['Type'] == 2) {
							$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
							$Lookup[$i]['Subtable'][$c]['SequenceCount'] = $SequenceCount = $this->reader->readInt16();
							for ($s = 0; $s < $SequenceCount; $s++) {
								$Lookup[$i]['Subtable'][$c]['Sequences'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readInt16();
							}
							for ($s = 0; $s < $SequenceCount; $s++) {
								// Sequence Tables
								$this->reader->seek($Lookup[$i]['Subtable'][$c]['Sequences'][$s]['Offset']);
								$Lookup[$i]['Subtable'][$c]['Sequences'][$s]['GlyphCount'] = $this->reader->readInt16();
								for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['Sequences'][$s]['GlyphCount']; $g++) {
									$Lookup[$i]['Subtable'][$c]['Sequences'][$s]['SubstituteGlyphID'][] = $this->reader->readUInt16();
								}
							}
						} // LookupType 3: Alternate Forms
						else {
							if ($Lookup[$i]['Type'] == 3) {
								$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
								$Lookup[$i]['Subtable'][$c]['AlternateSetCount'] = $AlternateSetCount = $this->reader->readInt16();
								for ($s = 0; $s < $AlternateSetCount; $s++) {
									$Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readInt16();
								}

								for ($s = 0; $s < $AlternateSetCount; $s++) {
									// AlternateSet Tables
									$this->reader->seek($Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['Offset']);
									$Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['GlyphCount'] = $this->reader->readInt16();
									for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['GlyphCount']; $g++) {
										$Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['SubstituteGlyphID'][] = $this->reader->readUInt16();
									}
								}
							} // LookupType 4: Ligature Substitution Subtable
							else {
								if ($Lookup[$i]['Type'] == 4) {
									$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
									$Lookup[$i]['Subtable'][$c]['LigSetCount'] = $LigSetCount = $this->reader->readInt16();
									for ($s = 0; $s < $LigSetCount; $s++) {
										$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readInt16();
									}
									for ($s = 0; $s < $LigSetCount; $s++) {
										// LigatureSet Tables
										$this->reader->seek($Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Offset']);
										$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['LigCount'] = $this->reader->readInt16();
										for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['LigCount']; $g++) {
											$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['LigatureOffset'][$g] = $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Offset'] + $this->reader->readUInt16();
										}
									}
									for ($s = 0; $s < $LigSetCount; $s++) {
										for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['LigCount']; $g++) {
											// Ligature tables
											$this->reader->seek($Lookup[$i]['Subtable'][$c]['LigSet'][$s]['LigatureOffset'][$g]);
											$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['LigGlyph'] = $this->reader->readUInt16();
											$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['CompCount'] = $this->reader->readUInt16();
											for ($l = 1; $l < $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['CompCount']; $l++) {
												$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['GlyphID'][$l] = $this->reader->readUInt16();
											}
										}
									}
								} // LookupType 5: Contextual Substitution Subtable
								else {
									if ($Lookup[$i]['Type'] == 5) {
										// Format 1: Context Substitution
										if ($SubstFormat == 1) {
											$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
											$Lookup[$i]['Subtable'][$c]['SubRuleSetCount'] = $SubRuleSetCount = $this->reader->readInt16();
											for ($s = 0; $s < $SubRuleSetCount; $s++) {
												$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readInt16();
											}
											for ($s = 0; $s < $SubRuleSetCount; $s++) {
												// SubRuleSet Tables
												$this->reader->seek($Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['Offset']);
												$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRuleCount'] = $this->reader->readInt16();
												for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRuleCount']; $g++) {
													$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRuleOffset'][$g] = $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['Offset'] + $this->reader->readUInt16();
												}
											}
											for ($s = 0; $s < $SubRuleSetCount; $s++) {
												// SubRule Tables
												for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRuleCount']; $g++) {
													// Ligature tables
													$this->reader->seek($Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRuleOffset'][$g]);

													$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$g]['GlyphCount'] = $this->reader->readUInt16();
													$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$g]['SubstCount'] = $this->reader->readUInt16();
													// "Input"::[GlyphCount - 1]::Array of input GlyphIDs-start with second glyph
													for ($l = 1; $l < $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$g]['GlyphCount']; $l++) {
														$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$g]['Input'][$l] = $this->reader->readUInt16();
													}
													// "SubstLookupRecord"::[SubstCount]::Array of SubstLookupRecords-in design order
													for ($l = 0; $l < $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$g]['SubstCount']; $l++) {
														$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$g]['SubstLookupRecord'][$l]['SequenceIndex'] = $this->reader->readUInt16();
														$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$g]['SubstLookupRecord'][$l]['LookupListIndex'] = $this->reader->readUInt16();
													}
												}
											}
										} // Format 2: Class-based Context Glyph Substitution
										else {
											if ($SubstFormat == 2) {
												$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												$Lookup[$i]['Subtable'][$c]['ClassDefOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												$Lookup[$i]['Subtable'][$c]['SubClassSetCnt'] = $this->reader->readUInt16();
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['SubClassSetCnt']; $b++) {
													$offset = $this->reader->readUInt16();
													if ($offset == 0x0000) {
														$Lookup[$i]['Subtable'][$c]['SubClassSetOffset'][] = 0;
													} else {
														$Lookup[$i]['Subtable'][$c]['SubClassSetOffset'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $offset;
													}
												}
											} elseif ($SubstFormat == 3) {
												// Format 3: Coverage-based Context Glyph Substitution
												// NB Unlike Lookup Type 6 Format 3, the count of substitutions precedes the Coverage table offsets
												$Lookup[$i]['Subtable'][$c]['InputGlyphCount'] = $this->reader->readUInt16();
												$Lookup[$i]['Subtable'][$c]['SubstCount'] = $this->reader->readUInt16();
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['InputGlyphCount']; $b++) {
													$Lookup[$i]['Subtable'][$c]['CoverageInput'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												}
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['SubstCount']; $b++) {
													$Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['SequenceIndex'] = $this->reader->readUInt16();
													$Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['LookupListIndex'] = $this->reader->readUInt16();
												}
											} else {
												throw new \Mpdf\Exception\FontException("GSUB Lookup Type " . $Lookup[$i]['Type'] . ", Format " . $SubstFormat . " not supported.");
											}
										}
									} // LookupType 6: Chaining Contextual Substitution Subtable
									else {
										if ($Lookup[$i]['Type'] == 6) {
											// Format 1: Simple Chaining Context Glyph Substitution  p255
											if ($SubstFormat == 1) {
												$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												$Lookup[$i]['Subtable'][$c]['ChainSubRuleSetCount'] = $this->reader->readUInt16();
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['ChainSubRuleSetCount']; $b++) {
													$Lookup[$i]['Subtable'][$c]['ChainSubRuleSetOffset'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												}
											} // Format 2: Class-based Chaining Context Glyph Substitution  p257
											else {
												if ($SubstFormat == 2) {
													$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
													$Lookup[$i]['Subtable'][$c]['BacktrackClassDefOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
													$Lookup[$i]['Subtable'][$c]['InputClassDefOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
													$Lookup[$i]['Subtable'][$c]['LookaheadClassDefOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
													$Lookup[$i]['Subtable'][$c]['ChainSubClassSetCnt'] = $this->reader->readUInt16();
													for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['ChainSubClassSetCnt']; $b++) {
														$offset = $this->reader->readUInt16();
														if ($offset == 0x0000) {
															$Lookup[$i]['Subtable'][$c]['ChainSubClassSetOffset'][] = $offset;
														} else {
															$Lookup[$i]['Subtable'][$c]['ChainSubClassSetOffset'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $offset;
														}
													}
												} // Format 3: Coverage-based Chaining Context Glyph Substitution  p259
												else {
													if ($SubstFormat == 3) {
														$Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount'] = $this->reader->readUInt16();
														for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount']; $b++) {
															$Lookup[$i]['Subtable'][$c]['CoverageBacktrack'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
														}
														$Lookup[$i]['Subtable'][$c]['InputGlyphCount'] = $this->reader->readUInt16();
														for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['InputGlyphCount']; $b++) {
															$Lookup[$i]['Subtable'][$c]['CoverageInput'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
														}
														$Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount'] = $this->reader->readUInt16();
														for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount']; $b++) {
															$Lookup[$i]['Subtable'][$c]['CoverageLookahead'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
														}
														$Lookup[$i]['Subtable'][$c]['SubstCount'] = $this->reader->readUInt16();
														for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['SubstCount']; $b++) {
															$Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['SequenceIndex'] = $this->reader->readUInt16();
															$Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['LookupListIndex'] = $this->reader->readUInt16();
															/*
															  Substitution Lookup Record
															  All contextual substitution subtables specify the substitution data in a Substitution Lookup Record (SubstLookupRecord). Each record contains a SequenceIndex, which indicates the position where the substitution will occur in the glyph sequence. In addition, a LookupListIndex identifies the lookup to be applied at the glyph position specified by the SequenceIndex.
															 */
														}
													}
												}
											}
										} else {
											// LookupType 8: Reverse Chaining Contextual Single Substitution Subtable
											if ($Lookup[$i]['Type'] == 8) {
												// Format 1 is the only one the specification defines
												if ($SubstFormat != 1) {
													throw new \Mpdf\Exception\FontException("GSUB Lookup Type " . $Lookup[$i]['Type'] . ", Format " . $SubstFormat . " not supported.");
												}
												$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												$Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount'] = $this->reader->readUInt16();
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount']; $b++) {
													$Lookup[$i]['Subtable'][$c]['CoverageBacktrack'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												}
												$Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount'] = $this->reader->readUInt16();
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount']; $b++) {
													$Lookup[$i]['Subtable'][$c]['CoverageLookahead'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
												}
												// One substitute glyph per glyph in the Coverage table - the substitution is written into the
												// subtable itself rather than delegated to a Lookup, as every other contextual type does
												$Lookup[$i]['Subtable'][$c]['GlyphCount'] = $this->reader->readUInt16();
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['GlyphCount']; $b++) {
													$Lookup[$i]['Subtable'][$c]['SubstituteGlyphID'][] = $this->reader->readUInt16();
												}
											} else {
												throw new \Mpdf\Exception\FontException("Lookup Type " . $Lookup[$i]['Type'] . " not supported.");
											}
										}
									}
								}
							}
						}
					}
				}
			}
			//=====================================================================================
			// Process (2) Whole LookupList
			// Get Coverage tables and prepare preg_replace
			for ($i = 0; $i < $LookupCount; $i++) {
				for ($c = 0; $c < $Lookup[$i]['SubtableCount']; $c++) {
					$SubstFormat = $Lookup[$i]['Subtable'][$c]['Format'];

					// LookupType 1: Single Substitution Subtable 1 => 1
					if ($Lookup[$i]['Type'] == 1) {
						$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
						$glyphs = $this->_getCoverage(false);
						for ($g = 0; $g < count($glyphs); $g++) {
							$replace = [];
							$substitute = [];
							$replace[] = unicode_hex($this->glyphToChar[$glyphs[$g]][0]);
							// Flag = Ignore
							if ($this->_checkGSUBignore($Lookup[$i]['Flag'], $replace[0], $Lookup[$i]['MarkFilteringSet'])) {
								continue;
							}
							if (isset($Lookup[$i]['Subtable'][$c]['DeltaGlyphID'])) { // Format 1
								$substitute[] = unicode_hex($this->glyphToChar[($glyphs[$g] + $Lookup[$i]['Subtable'][$c]['DeltaGlyphID'])][0]);
							} else { // Format 2
								$substitute[] = unicode_hex($this->glyphToChar[($Lookup[$i]['Subtable'][$c]['Glyphs'][$g])][0]);
							}
							$Lookup[$i]['Subtable'][$c]['subs'][] = ['Replace' => $replace, 'substitute' => $substitute];
						}
					} // LookupType 2: Multiple Substitution Subtable 1 => n
					else {
						if ($Lookup[$i]['Type'] == 2) {
							$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
							$glyphs = $this->_getCoverage();
							for ($g = 0; $g < count($glyphs); $g++) {
								$replace = [];
								$substitute = [];
								$replace[] = $glyphs[$g];
								// Flag = Ignore
								if ($this->_checkGSUBignore($Lookup[$i]['Flag'], $replace[0], $Lookup[$i]['MarkFilteringSet'])) {
									continue;
								}
								if (!isset($Lookup[$i]['Subtable'][$c]['Sequences'][$g]['SubstituteGlyphID']) || count($Lookup[$i]['Subtable'][$c]['Sequences'][$g]['SubstituteGlyphID']) == 0) {
									continue;
								} // Illegal for GlyphCount to be 0; either error in font, or something has gone wrong - lets carry on for now!
								foreach ($Lookup[$i]['Subtable'][$c]['Sequences'][$g]['SubstituteGlyphID'] as $sub) {
									$substitute[] = unicode_hex($this->glyphToChar[$sub][0]);
								}
								$Lookup[$i]['Subtable'][$c]['subs'][] = ['Replace' => $replace, 'substitute' => $substitute];
							}
						} // LookupType 3: Alternate Forms 1 => 1 (only first alternate form is used)
						else {
							if ($Lookup[$i]['Type'] == 3) {
								$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
								$glyphs = $this->_getCoverage();
								for ($g = 0; $g < count($glyphs); $g++) {
									$replace = [];
									$substitute = [];
									$replace[] = $glyphs[$g];
									// Flag = Ignore
									if ($this->_checkGSUBignore($Lookup[$i]['Flag'], $replace[0], $Lookup[$i]['MarkFilteringSet'])) {
										continue;
									}

									for ($gl = 0; $gl < $Lookup[$i]['Subtable'][$c]['AlternateSets'][$g]['GlyphCount']; $gl++) {
										$gid = $Lookup[$i]['Subtable'][$c]['AlternateSets'][$g]['SubstituteGlyphID'][$gl];
										// A glyph the cmap does not reach has no character to report it by
										if (!isset($this->glyphToChar[$gid][0])) {
											continue;
										}
										$substitute[] = unicode_hex($this->glyphToChar[$gid][0]);
									}

									//$gid = $Lookup[$i]['Subtable'][$c]['AlternateSets'][$g]['SubstituteGlyphID'][0];
									//$substitute[] = unicode_hex($this->glyphToChar[$gid][0]);

									$Lookup[$i]['Subtable'][$c]['subs'][] = ['Replace' => $replace, 'substitute' => $substitute];
								}
							} // LookupType 4: Ligature Substitution Subtable n => 1
							else {
								if ($Lookup[$i]['Type'] == 4) {
									$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
									$glyphs = $this->_getCoverage();
									$LigSetCount = $Lookup[$i]['Subtable'][$c]['LigSetCount'];
									for ($s = 0; $s < $LigSetCount; $s++) {
										for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['LigCount']; $g++) {
											$replace = [];
											$substitute = [];
											$replace[] = $glyphs[$s];
											// Flag = Ignore
											if ($this->_checkGSUBignore($Lookup[$i]['Flag'], $replace[0], $Lookup[$i]['MarkFilteringSet'])) {
												continue;
											}
											for ($l = 1; $l < $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['CompCount']; $l++) {
												$gid = $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['GlyphID'][$l];
												$rpl = unicode_hex($this->glyphToChar[$gid][0]);
												// Flag = Ignore
												if ($this->_checkGSUBignore($Lookup[$i]['Flag'], $rpl, $Lookup[$i]['MarkFilteringSet'])) {
													continue 2;
												}
												$replace[] = $rpl;
											}
											$gid = $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['LigGlyph'];
											if (!isset($this->glyphToChar[$gid][0])) {
												continue;
											}
											$substitute[] = unicode_hex($this->glyphToChar[$gid][0]);
											$Lookup[$i]['Subtable'][$c]['subs'][] = ['Replace' => $replace, 'substitute' => $substitute, 'CompCount' => $Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Ligature'][$g]['CompCount']];
										}
									}
								} // LookupType 5: Contextual Substitution Subtable
								else {
									if ($Lookup[$i]['Type'] == 5) {
										// Format 1: Context Substitution
										if ($SubstFormat == 1) {
											$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
											$Lookup[$i]['Subtable'][$c]['CoverageGlyphs'] = $CoverageGlyphs = $this->_getCoverage();

											for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['SubRuleSetCount']; $s++) {
												$SubRuleSet = $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s];
												$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['FirstGlyph'] = $CoverageGlyphs[$s];
												for ($r = 0; $r < $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRuleCount']; $r++) {
													$GlyphCount = $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$r]['GlyphCount'];
													for ($g = 1; $g < $GlyphCount; $g++) {
														$glyphID = $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$r]['Input'][$g];
														$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'][$r]['InputGlyphs'][$g] = unicode_hex($this->glyphToChar[$glyphID][0]);
													}
												}
											}
										} // Format 2: Class-based Context Glyph Substitution
										else {
											if ($SubstFormat == 2) {
												$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
												$Lookup[$i]['Subtable'][$c]['CoverageGlyphs'] = $CoverageGlyphs = $this->_getCoverage();

												$InputClasses = $this->_getClasses($Lookup[$i]['Subtable'][$c]['ClassDefOffset']);
												$Lookup[$i]['Subtable'][$c]['InputClasses'] = $InputClasses;

												for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['SubClassSetCnt']; $s++) {
													if ($Lookup[$i]['Subtable'][$c]['SubClassSetOffset'][$s] > 0) {
														$this->reader->seek($Lookup[$i]['Subtable'][$c]['SubClassSetOffset'][$s]);
														$Lookup[$i]['Subtable'][$c]['SubClassSet'][$s]['SubClassRuleCnt'] = $SubClassRuleCnt = $this->reader->readUInt16();
														$SubClassRule = [];
														for ($b = 0; $b < $SubClassRuleCnt; $b++) {
															$SubClassRule[$b] = $Lookup[$i]['Subtable'][$c]['SubClassSetOffset'][$s] + $this->reader->readUInt16();
															$Lookup[$i]['Subtable'][$c]['SubClassSet'][$s]['SubClassRule'][$b] = $SubClassRule[$b];
														}
													}
												}

												for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['SubClassSetCnt']; $s++) {
													// A SubClassSet is recorded above only where its offset was non-zero
													if (!isset($Lookup[$i]['Subtable'][$c]['SubClassSet'][$s])) {
														continue;
													}
													$SubClassRuleCnt = $Lookup[$i]['Subtable'][$c]['SubClassSet'][$s]['SubClassRuleCnt'];
													for ($b = 0; $b < $SubClassRuleCnt; $b++) {
														if ($Lookup[$i]['Subtable'][$c]['SubClassSetOffset'][$s] > 0) {
															$this->reader->seek($Lookup[$i]['Subtable'][$c]['SubClassSet'][$s]['SubClassRule'][$b]);
															$Rule = [];
															$Rule['InputGlyphCount'] = $this->reader->readUInt16();
															$Rule['SubstCount'] = $this->reader->readUInt16();
															for ($r = 1; $r < $Rule['InputGlyphCount']; $r++) {
																$Rule['Input'][$r] = $this->reader->readUInt16();
															}
															for ($r = 0; $r < $Rule['SubstCount']; $r++) {
																$Rule['SequenceIndex'][$r] = $this->reader->readUInt16();
																$Rule['LookupListIndex'][$r] = $this->reader->readUInt16();
															}

															$Lookup[$i]['Subtable'][$c]['SubClassSet'][$s]['SubClassRule'][$b] = $Rule;
														}
													}
												}
											} // Format 3: Coverage-based Context Glyph Substitution
											else {
												if ($SubstFormat == 3) {
													for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['InputGlyphCount']; $b++) {
														$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageInput'][$b]);
														$glyphs = $this->_getCoverage();
														$Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'][] = implode("|", $glyphs);
													}
												}
											}
										}
									} // LookupType 6: Chaining Contextual Substitution Subtable
									else {
										if ($Lookup[$i]['Type'] == 6) {
											// Format 1: Simple Chaining Context Glyph Substitution  p255
											if ($SubstFormat == 1) {
												$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
												$Lookup[$i]['Subtable'][$c]['CoverageGlyphs'] = $CoverageGlyphs = $this->_getCoverage();

												$ChainSubRuleSetCnt = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSetCount'];

												for ($s = 0; $s < $ChainSubRuleSetCnt; $s++) {
													$this->reader->seek($Lookup[$i]['Subtable'][$c]['ChainSubRuleSetOffset'][$s]);
													$ChainSubRuleCnt = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRuleCount'] = $this->reader->readUInt16();
													for ($r = 0; $r < $ChainSubRuleCnt; $r++) {
														$Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRuleOffset'][$r] = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSetOffset'][$s] + $this->reader->readUInt16();
													}
												}
												for ($s = 0; $s < $ChainSubRuleSetCnt; $s++) {
													$ChainSubRuleCnt = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRuleCount'];
													for ($r = 0; $r < $ChainSubRuleCnt; $r++) {
														// ChainSubRule
														$this->reader->seek($Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRuleOffset'][$r]);

														$BacktrackGlyphCount = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['BacktrackGlyphCount'] = $this->reader->readUInt16();
														for ($g = 0; $g < $BacktrackGlyphCount; $g++) {
															$glyphID = $this->reader->readUInt16();
															$Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['BacktrackGlyphs'][$g] = unicode_hex($this->glyphToChar[$glyphID][0]);
														}

														$InputGlyphCount = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['InputGlyphCount'] = $this->reader->readUInt16();
														for ($g = 1; $g < $InputGlyphCount; $g++) {
															$glyphID = $this->reader->readUInt16();
															$Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['InputGlyphs'][$g] = unicode_hex($this->glyphToChar[$glyphID][0]);
														}

														$LookaheadGlyphCount = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['LookaheadGlyphCount'] = $this->reader->readUInt16();
														for ($g = 0; $g < $LookaheadGlyphCount; $g++) {
															$glyphID = $this->reader->readUInt16();
															$Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['LookaheadGlyphs'][$g] = unicode_hex($this->glyphToChar[$glyphID][0]);
														}

														$SubstCount = $Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['SubstCount'] = $this->reader->readUInt16();
														for ($lu = 0; $lu < $SubstCount; $lu++) {
															$Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['SequenceIndex'][$lu] = $this->reader->readUInt16();
															$Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'][$r]['LookupListIndex'][$lu] = $this->reader->readUInt16();
														}
													}
												}
											} // Format 2: Class-based Chaining Context Glyph Substitution  p257
											else {
												if ($SubstFormat == 2) {
													$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
													$Lookup[$i]['Subtable'][$c]['CoverageGlyphs'] = $CoverageGlyphs = $this->_getCoverage();

													$BacktrackClasses = $this->_getClasses($Lookup[$i]['Subtable'][$c]['BacktrackClassDefOffset']);
													$Lookup[$i]['Subtable'][$c]['BacktrackClasses'] = $BacktrackClasses;

													$InputClasses = $this->_getClasses($Lookup[$i]['Subtable'][$c]['InputClassDefOffset']);
													$Lookup[$i]['Subtable'][$c]['InputClasses'] = $InputClasses;

													$LookaheadClasses = $this->_getClasses($Lookup[$i]['Subtable'][$c]['LookaheadClassDefOffset']);
													$Lookup[$i]['Subtable'][$c]['LookaheadClasses'] = $LookaheadClasses;

													for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['ChainSubClassSetCnt']; $s++) {
														if ($Lookup[$i]['Subtable'][$c]['ChainSubClassSetOffset'][$s] > 0) {
															$this->reader->seek($Lookup[$i]['Subtable'][$c]['ChainSubClassSetOffset'][$s]);
															$Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s]['ChainSubClassRuleCnt'] = $ChainSubClassRuleCnt = $this->reader->readUInt16();
															$ChainSubClassRule = [];
															for ($b = 0; $b < $ChainSubClassRuleCnt; $b++) {
																$ChainSubClassRule[$b] = $Lookup[$i]['Subtable'][$c]['ChainSubClassSetOffset'][$s] + $this->reader->readUInt16();
																$Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s]['ChainSubClassRule'][$b] = $ChainSubClassRule[$b];
															}
														}
													}

													for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['ChainSubClassSetCnt']; $s++) {
														// A ChainSubClassSet is recorded above only where its offset was non-zero
														if (!isset($Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s])) {
															continue;
														}
														$ChainSubClassRuleCnt = $Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s]['ChainSubClassRuleCnt'];
														for ($b = 0; $b < $ChainSubClassRuleCnt; $b++) {
															if ($Lookup[$i]['Subtable'][$c]['ChainSubClassSetOffset'][$s] > 0) {
																$this->reader->seek($Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s]['ChainSubClassRule'][$b]);
																$Rule = [];
																$Rule['BacktrackGlyphCount'] = $this->reader->readUInt16();
																for ($r = 0; $r < $Rule['BacktrackGlyphCount']; $r++) {
																	$Rule['Backtrack'][$r] = $this->reader->readUInt16();
																}
																$Rule['InputGlyphCount'] = $this->reader->readUInt16();
																for ($r = 1; $r < $Rule['InputGlyphCount']; $r++) {
																	$Rule['Input'][$r] = $this->reader->readUInt16();
																}
																$Rule['LookaheadGlyphCount'] = $this->reader->readUInt16();
																for ($r = 0; $r < $Rule['LookaheadGlyphCount']; $r++) {
																	$Rule['Lookahead'][$r] = $this->reader->readUInt16();
																}
																$Rule['SubstCount'] = $this->reader->readUInt16();
																for ($r = 0; $r < $Rule['SubstCount']; $r++) {
																	$Rule['SequenceIndex'][$r] = $this->reader->readUInt16();
																	$Rule['LookupListIndex'][$r] = $this->reader->readUInt16();
																}

																$Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s]['ChainSubClassRule'][$b] = $Rule;
															}
														}
													}
												} // Format 3: Coverage-based Chaining Context Glyph Substitution  p259
												else {
													if ($SubstFormat == 3) {
														for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount']; $b++) {
															$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageBacktrack'][$b]);
															$glyphs = $this->_getCoverage();
															$Lookup[$i]['Subtable'][$c]['CoverageBacktrackGlyphs'][] = implode("|", $glyphs);
														}
														for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['InputGlyphCount']; $b++) {
															$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageInput'][$b]);
															$glyphs = $this->_getCoverage();
															$Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'][] = implode("|", $glyphs);
															// Don't use above value as these are ordered numerically not as need to process
														}
														for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount']; $b++) {
															$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageLookahead'][$b]);
															$glyphs = $this->_getCoverage();
															$Lookup[$i]['Subtable'][$c]['CoverageLookaheadGlyphs'][] = implode("|", $glyphs);
														}
													}
												}
											}
										} else {
											// LookupType 8: Reverse Chaining Contextual Single Substitution 1 => 1
											if ($Lookup[$i]['Type'] == 8) {
												$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageTableOffset']);
												$glyphs = $this->_getCoverage();
												$Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'] = [implode("|", $glyphs)];
												for ($g = 0; $g < count($glyphs); $g++) {
													$replace = [];
													$substitute = [];
													$replace[] = $glyphs[$g];
													// Flag = Ignore
													if ($this->_checkGSUBignore($Lookup[$i]['Flag'], $replace[0], $Lookup[$i]['MarkFilteringSet'])) {
														continue;
													}
													if (!isset($Lookup[$i]['Subtable'][$c]['SubstituteGlyphID'][$g])) {
														continue;
													} // The substitutes must run parallel to the Coverage table; either an error in the font, or something has gone wrong
													$gid = $Lookup[$i]['Subtable'][$c]['SubstituteGlyphID'][$g];
													if (!isset($this->glyphToChar[$gid][0])) {
														continue;
													}
													$substitute[] = unicode_hex($this->glyphToChar[$gid][0]);
													$Lookup[$i]['Subtable'][$c]['subs'][] = ['Replace' => $replace, 'substitute' => $substitute];
												}
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount']; $b++) {
													$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageBacktrack'][$b]);
													$glyphs = $this->_getCoverage();
													$Lookup[$i]['Subtable'][$c]['CoverageBacktrackGlyphs'][] = implode("|", $glyphs);
												}
												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount']; $b++) {
													$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageLookahead'][$b]);
													$glyphs = $this->_getCoverage();
													$Lookup[$i]['Subtable'][$c]['CoverageLookaheadGlyphs'][] = implode("|", $glyphs);
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}

			//=====================================================================================
			//=====================================================================================
			//=====================================================================================

			$st = $this->script;
			$t = $this->language;
			$langsys = $this->langSys($gsub, 'GSUB');

			$lul = []; // array of LookupListIndexes
			$tags = []; // corresponding array of feature tags e.g. 'ccmp'
			foreach ($langsys as $tag => $ft) {
				foreach ($ft as $ll) {
					$lul[$ll] = $tag;
				}
			}
			ksort($lul); // Order the Lookups in the order they are in the GUSB table, regardless of Feature order
			$this->_getGSUBarray($Lookup, $lul, $st);
		}

		// The report says nothing about the RTL Private Use Area mapping the parser builds for Arabic
		// and Syriac joining, so there is nothing to hand back for it. These were undefined variables.
		return [$GSUBScriptLang, $gsub, $GSLookup, '', []];
	}

/////////////////////////////////////////////////////////////////////////////////////////
	// GSUB functions
	function _getGSUBarray(&$Lookup, &$lul, $scripttag, $level = 1, $coverage = '', $exB = '', $exL = '')
	{
		// Process (3) LookupList for specific Script-LangSys
		// Generate preg_replace
		$html = '';
		if ($level == 1) {
			$html .= '<bookmark level="0" content="GSUB features">';
		}
		foreach ($lul as $i => $tag) {
			$html .= '<div class="level' . $level . '">';
			$html .= '<h5 class="level' . $level . '">';
			if ($level == 1) {
				$html .= '<bookmark level="1" content="' . $tag . ' [#' . $i . ']">';
			}
			$html .= 'Lookup #' . $i . ' [tag: <span style="color:#000066;">' . $tag . '</span>]</h5>';
			$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
			if ($ignore) {
				$html .= '<div class="ignore">Ignoring: ' . $ignore . '</div> ';
			}

			$Type = $Lookup[$i]['Type'];
			$Flag = $Lookup[$i]['Flag'];
			if (($Flag & 0x0001) == 1) {
				$dir = 'RTL';
			} else {
				$dir = 'LTR';
			}

			for ($c = 0; $c < $Lookup[$i]['SubtableCount']; $c++) {
				$html .= '<div class="subtable">Subtable #' . $c;
				if ($level == 1) {
					$html .= '<bookmark level="2" content="Subtable #' . $c . '">';
				}
				$html .= '</div>';

				$SubstFormat = $Lookup[$i]['Subtable'][$c]['Format'];

				// LookupType 1: Single Substitution Subtable
				if ($Lookup[$i]['Type'] == 1) {
					$html .= '<div class="lookuptype">LookupType 1: Single Substitution Subtable</div>';
					for ($s = 0; $s < count($Lookup[$i]['Subtable'][$c]['subs']); $s++) {
						$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
						$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'][0];
						if ($level == 2 && strpos($coverage, $inputGlyphs[0]) === false) {
							continue;
						}
						$html .= '<div class="substitution">';
						$html .= '<span class="unicode">' . $this->formatUni($inputGlyphs[0]) . '&nbsp;</span> ';
						if ($level == 2 && $exB) {
							$html .= $exB;
						}
						$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($inputGlyphs[0]) . '</span>';
						if ($level == 2 && $exL) {
							$html .= $exL;
						}
						$html .= '&nbsp; &raquo; &raquo; &nbsp;';
						if ($level == 2 && $exB) {
							$html .= $exB;
						}
						$html .= '<span class="changed">&nbsp;' . $this->formatEntity($substitute) . '</span>';
						if ($level == 2 && $exL) {
							$html .= $exL;
						}
						$html .= '&nbsp; <span class="unicode">' . $this->formatUni($substitute) . '</span> ';
						$html .= '</div>';
					}
				} // LookupType 2: Multiple Substitution Subtable
				else {
					if ($Lookup[$i]['Type'] == 2) {
						$html .= '<div class="lookuptype">LookupType 2: Multiple Substitution Subtable</div>';
						for ($s = 0; $s < count($Lookup[$i]['Subtable'][$c]['subs']); $s++) {
							$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
							$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'];
							if ($level == 2 && strpos($coverage, $inputGlyphs[0]) === false) {
								continue;
							}
							$html .= '<div class="substitution">';
							$html .= '<span class="unicode">' . $this->formatUni($inputGlyphs[0]) . '&nbsp;</span> ';
							if ($level == 2 && $exB) {
								$html .= $exB;
							}
							$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($inputGlyphs[0]) . '</span>';
							if ($level == 2 && $exL) {
								$html .= $exL;
							}
							$html .= '&nbsp; &raquo; &raquo; &nbsp;';
							if ($level == 2 && $exB) {
								$html .= $exB;
							}
							$html .= '<span class="changed">&nbsp;' . $this->formatEntityArr($substitute) . '</span>';
							if ($level == 2 && $exL) {
								$html .= $exL;
							}
							$html .= '&nbsp; <span class="unicode">' . $this->formatUniArr($substitute) . '</span> ';
							$html .= '</div>';
						}
					} // LookupType 3: Alternate Forms
					else {
						if ($Lookup[$i]['Type'] == 3) {
							$html .= '<div class="lookuptype">LookupType 3: Alternate Forms</div>';
							for ($s = 0; $s < count($Lookup[$i]['Subtable'][$c]['subs']); $s++) {
								$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
								$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'][0];
								if ($level == 2 && strpos($coverage, $inputGlyphs[0]) === false) {
									continue;
								}
								$html .= '<div class="substitution">';
								$html .= '<span class="unicode">' . $this->formatUni($inputGlyphs[0]) . '&nbsp;</span> ';
								if ($level == 2 && $exB) {
									$html .= $exB;
								}
								$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($inputGlyphs[0]) . '</span>';
								if ($level == 2 && $exL) {
									$html .= $exL;
								}
								$html .= '&nbsp; &raquo; &raquo; &nbsp;';
								if ($level == 2 && $exB) {
									$html .= $exB;
								}
								$html .= '<span class="changed">&nbsp;' . $this->formatEntity($substitute) . '</span>';
								if ($level == 2 && $exL) {
									$html .= $exL;
								}
								$html .= '&nbsp; <span class="unicode">' . $this->formatUni($substitute) . '</span> ';
								if (count($Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute']) > 1) {
									for ($alt = 1; $alt < count($Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute']); $alt++) {
										$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'][$alt];
										$html .= '&nbsp; | &nbsp; ALT #' . $alt . ' &nbsp; ';
										$html .= '<span class="changed">&nbsp;' . $this->formatEntity($substitute) . '</span>';
										$html .= '&nbsp; <span class="unicode">' . $this->formatUni($substitute) . '</span> ';
									}
								}
								$html .= '</div>';
							}
						} // LookupType 4: Ligature Substitution Subtable
						else {
							if ($Lookup[$i]['Type'] == 4) {
								$html .= '<div class="lookuptype">LookupType 4: Ligature Substitution Subtable</div>';
								for ($s = 0; $s < count($Lookup[$i]['Subtable'][$c]['subs']); $s++) {
									$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
									$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'][0];
									if ($level == 2 && strpos($coverage, $inputGlyphs[0]) === false) {
										continue;
									}
									$html .= '<div class="substitution">';
									$html .= '<span class="unicode">' . $this->formatUniArr($inputGlyphs) . '&nbsp;</span> ';
									if ($level == 2 && $exB) {
										$html .= $exB;
									}
									$html .= '<span class="unchanged">&nbsp;' . $this->formatEntityArr($inputGlyphs) . '</span>';
									if ($level == 2 && $exL) {
										$html .= $exL;
									}
									$html .= '&nbsp; &raquo; &raquo; &nbsp;';
									if ($level == 2 && $exB) {
										$html .= $exB;
									}
									$html .= '<span class="changed">&nbsp;' . $this->formatEntity($substitute) . '</span>';
									if ($level == 2 && $exL) {
										$html .= $exL;
									}
									$html .= '&nbsp; <span class="unicode">' . $this->formatUni($substitute) . '</span> ';
									$html .= '</div>';
								}
							} // LookupType 5: Contextual Substitution Subtable
							else {
								if ($Lookup[$i]['Type'] == 5) {
									$html .= '<div class="lookuptype">LookupType 5: Contextual Substitution Subtable</div>';
									// Format 1: Context Substitution
									if ($SubstFormat == 1) {
										$html .= '<div class="lookuptypesub">Format 1: Context Substitution</div>';
										for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['SubRuleSetCount']; $s++) {
											// SubRuleSet											$html .= '<div class="rule">Subrule Set: ' . $s . '</div>';
											foreach ($Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'] as $rctr => $rule) {
												// SubRule
												$html .= '<div class="rule">SubRule: ' . $rctr . '</div>';
												$inputGlyphs = [];
												if ($rule['GlyphCount'] > 1) {
													$inputGlyphs = $rule['InputGlyphs'];
												}
												$inputGlyphs[0] = $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['FirstGlyph'];
												ksort($inputGlyphs);
												$nInput = count($inputGlyphs);

												$exampleI = [];
												$html .= '<div class="context">CONTEXT: ';
												for ($ff = 0; $ff < count($inputGlyphs); $ff++) {
													$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;' . $this->formatEntityStr($inputGlyphs[$ff]) . '&nbsp;</span></div>';
													$exampleI[] = $this->formatEntityFirst($inputGlyphs[$ff]);
												}
												$html .= '</div>';

												for ($b = 0; $b < $rule['SubstCount']; $b++) {
													$lup = $rule['SubstLookupRecord'][$b]['LookupListIndex'];
													$seqIndex = $rule['SubstLookupRecord'][$b]['SequenceIndex'];

													// GENERATE exampleI[<seqIndex] .... exampleI[>seqIndex]
													$exB = '';
													$exL = '';
													if ($seqIndex > 0) {
														$exB .= '<span class="inputother">';
														for ($ip = 0; $ip < $seqIndex; $ip++) {
															$exB .= $this->formatEntity($inputGlyphs[$ip]) . '&#x200d;';
														}
														$exB .= '</span>';
													}
													if (count($inputGlyphs) > ($seqIndex + 1)) {
														$exL .= '<span class="inputother">';
														for ($ip = $seqIndex + 1; $ip < count($inputGlyphs); $ip++) {
															$exL .= $this->formatEntity($inputGlyphs[$ip]) . '&#x200d;';
														}
														$exL .= '</span>';
													}
													$html .= '<div class="sequenceIndex">Substitution Position: ' . $seqIndex . '</div>';

													$lul2 = [$lup => $tag];

													// Only apply if the (first) 'Replace' glyph from the
													// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
													// Pass $inputGlyphs[$seqIndex] e.g. 00636|00645|00656
													// to level 2 and only apply if first Replace glyph is in this list
													$html .= $this->_getGSUBarray($Lookup, $lul2, $scripttag, 2, $inputGlyphs[$seqIndex], $exB, $exL);
												}
											}
										}
									} // Format 2: Class-based Context Glyph Substitution
									else {
										if ($SubstFormat == 2) {
											$html .= '<div class="lookuptypesub">Format 2: Class-based Context Glyph Substitution</div>';
											foreach ($Lookup[$i]['Subtable'][$c]['SubClassSet'] as $inputClass => $cscs) {
												$html .= '<div class="rule">Input Class: ' . $inputClass . '</div>';
												for ($cscrule = 0; $cscrule < $cscs['SubClassRuleCnt']; $cscrule++) {
													$html .= '<div class="rule">Rule: ' . $cscrule . '</div>';
													$rule = $cscs['SubClassRule'][$cscrule];

													$inputGlyphs = [];

													$inputGlyphs[0] = $this->classGlyphs($Lookup[$i]['Subtable'][$c]['InputClasses'], $inputClass);

													if ($rule['InputGlyphCount'] > 1) {
														//  NB starts at 1
														for ($gcl = 1; $gcl < $rule['InputGlyphCount']; $gcl++) {
															$classindex = $rule['Input'][$gcl];
															$inputGlyphs[$gcl] = $this->classGlyphs($Lookup[$i]['Subtable'][$c]['InputClasses'], $classindex);
														}
													}

													// Class 0 contains all the glyphs NOT in the other classes
													$class0excl = implode('|', $Lookup[$i]['Subtable'][$c]['InputClasses']);

													$exampleI = [];
													$html .= '<div class="context">CONTEXT: ';
													for ($ff = 0; $ff < count($inputGlyphs); $ff++) {
														if (!$inputGlyphs[$ff]) {
															$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;[NOT ' . $this->formatEntityStr($class0excl) . ']&nbsp;</span></div>';
															$exampleI[] = '[NOT ' . $this->formatEntityFirst($class0excl) . ']';
														} else {
															$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;' . $this->formatEntityStr($inputGlyphs[$ff]) . '&nbsp;</span></div>';
															$exampleI[] = $this->formatEntityFirst($inputGlyphs[$ff]);
														}
													}
													$html .= '</div>';

													for ($b = 0; $b < $rule['SubstCount']; $b++) {
														$lup = $rule['LookupListIndex'][$b];
														$seqIndex = $rule['SequenceIndex'][$b];

														// GENERATE exampleI[<seqIndex] .... exampleI[>seqIndex]
														$exB = '';
														$exL = '';

														if ($seqIndex > 0) {
															$exB .= '<span class="inputother">';
															for ($ip = 0; $ip < $seqIndex; $ip++) {
																if (!$inputGlyphs[$ip]) {
																	$exB .= '[*]';
																} else {
																	$exB .= $this->formatEntityFirst($inputGlyphs[$ip]) . '&#x200d;';
																}
															}
															$exB .= '</span>';
														}

														if (count($inputGlyphs) > ($seqIndex + 1)) {
															$exL .= '<span class="inputother">';
															for ($ip = $seqIndex + 1; $ip < count($inputGlyphs); $ip++) {
																if (!$inputGlyphs[$ip]) {
																	$exL .= '[*]';
																} else {
																	$exL .= $this->formatEntityFirst($inputGlyphs[$ip]) . '&#x200d;';
																}
															}
															$exL .= '</span>';
														}

														$html .= '<div class="sequenceIndex">Substitution Position: ' . $seqIndex . '</div>';

														$lul2 = [$lup => $tag];

														// Only apply if the (first) 'Replace' glyph from the
														// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
														// Pass $inputGlyphs[$seqIndex] e.g. 00636|00645|00656
														// to level 2 and only apply if first Replace glyph is in this list
														$html .= $this->_getGSUBarray($Lookup, $lul2, $scripttag, 2, $inputGlyphs[$seqIndex], $exB, $exL);
													}
												}
											}
										} // Format 3: Coverage-based Context Glyph Substitution  p259
										else {
											if ($SubstFormat == 3) {
												$html .= '<div class="lookuptypesub">Format 3: Coverage-based Context Glyph Substitution  </div>';
												// IgnoreMarks flag set on main Lookup table
												$inputGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'];
												$CoverageInputGlyphs = implode('|', $inputGlyphs);
												$nInput = $Lookup[$i]['Subtable'][$c]['InputGlyphCount'];

												$exampleI = [];
												$html .= '<div class="context">CONTEXT: ';
												for ($ff = 0; $ff < count($inputGlyphs); $ff++) {
													$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;' . $this->formatEntityStr($inputGlyphs[$ff]) . '&nbsp;</span></div>';
													$exampleI[] = $this->formatEntityFirst($inputGlyphs[$ff]);
												}
												$html .= '</div>';

												for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['SubstCount']; $b++) {
													$lup = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['LookupListIndex'];
													$seqIndex = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['SequenceIndex'];
													// GENERATE exampleI[<seqIndex] .... exampleI[>seqIndex]
													$exB = '';
													$exL = '';
													if ($seqIndex > 0) {
														$exB .= '<span class="inputother">';
														for ($ip = 0; $ip < $seqIndex; $ip++) {
															$exB .= $exampleI[$ip] . '&#x200d;';
														}
														$exB .= '</span>';
													}

													if (count($inputGlyphs) > ($seqIndex + 1)) {
														$exL .= '<span class="inputother">';
														for ($ip = $seqIndex + 1; $ip < count($inputGlyphs); $ip++) {
															$exL .= $exampleI[$ip] . '&#x200d;';
														}
														$exL .= '</span>';
													}

													$html .= '<div class="sequenceIndex">Substitution Position: ' . $seqIndex . '</div>';

													$lul2 = [$lup => $tag];

													// Only apply if the (first) 'Replace' glyph from the
													// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
													// Pass $inputGlyphs[$seqIndex] e.g. 00636|00645|00656
													// to level 2 and only apply if first Replace glyph is in this list
													$html .= $this->_getGSUBarray($Lookup, $lul2, $scripttag, 2, $inputGlyphs[$seqIndex], $exB, $exL);
												}
											}
										}
									}

								} // LookupType 6: Chaining Contextual Substitution Subtable
								else {
									if ($Lookup[$i]['Type'] == 6) {
										$html .= '<div class="lookuptype">LookupType 6: Chaining Contextual Substitution Subtable</div>';
										// Format 1: Simple Chaining Context Glyph Substitution  p255
										if ($SubstFormat == 1) {
											$html .= '<div class="lookuptypesub">Format 1: Simple Chaining Context Glyph Substitution  </div>';
											for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['ChainSubRuleSetCount']; $s++) {
												// ChainSubRuleSet												$html .= '<div class="rule">Subrule Set: ' . $s . '</div>';
												$firstInputGlyph = $Lookup[$i]['Subtable'][$c]['CoverageGlyphs'][$s]; // First input gyyph
												foreach ($Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'] as $rctr => $rule) {
													$html .= '<div class="rule">SubRule: ' . $rctr . '</div>';
													// ChainSubRule
													$inputGlyphs = [];
													if ($rule['InputGlyphCount'] > 1) {
														$inputGlyphs = $rule['InputGlyphs'];
													}
													$inputGlyphs[0] = $firstInputGlyph;
													ksort($inputGlyphs);
													$nInput = count($inputGlyphs);

													if ($rule['BacktrackGlyphCount']) {
														$backtrackGlyphs = $rule['BacktrackGlyphs'];
													} else {
														$backtrackGlyphs = [];
													}

													if ($rule['LookaheadGlyphCount']) {
														$lookaheadGlyphs = $rule['LookaheadGlyphs'];
													} else {
														$lookaheadGlyphs = [];
													}

													$exampleB = [];
													$exampleI = [];
													$exampleL = [];
													$html .= '<div class="context">CONTEXT: ';
													for ($ff = count($backtrackGlyphs) - 1; $ff >= 0; $ff--) {
														$html .= '<div>Backtrack #' . $ff . ': <span class="unicode">' . $this->formatUniStr($backtrackGlyphs[$ff]) . '</span></div>';
														$exampleB[] = $this->formatEntityFirst($backtrackGlyphs[$ff]);
													}
													for ($ff = 0; $ff < count($inputGlyphs); $ff++) {
														$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;' . $this->formatEntityStr($inputGlyphs[$ff]) . '&nbsp;</span></div>';
														$exampleI[] = $this->formatEntityFirst($inputGlyphs[$ff]);
													}
													for ($ff = 0; $ff < count($lookaheadGlyphs); $ff++) {
														$html .= '<div>Lookahead #' . $ff . ': <span class="unicode">' . $this->formatUniStr($lookaheadGlyphs[$ff]) . '</span></div>';
														$exampleL[] = $this->formatEntityFirst($lookaheadGlyphs[$ff]);
													}
													$html .= '</div>';

													for ($b = 0; $b < $rule['SubstCount']; $b++) {
														$lup = $rule['LookupListIndex'][$b];
														$seqIndex = $rule['SequenceIndex'][$b];

														// GENERATE exampleB[n] exampleI[<seqIndex] .... exampleI[>seqIndex] exampleL[n]
														$exB = '';
														$exL = '';
														if (count($exampleB)) {
															$exB .= '<span class="backtrack">' . implode('&#x200d;', $exampleB) . '</span>';
														}

														if ($seqIndex > 0) {
															$exB .= '<span class="inputother">';
															for ($ip = 0; $ip < $seqIndex; $ip++) {
																$exB .= $this->formatEntity($inputGlyphs[$ip]) . '&#x200d;';
															}
															$exB .= '</span>';
														}

														if (count($inputGlyphs) > ($seqIndex + 1)) {
															$exL .= '<span class="inputother">';
															for ($ip = $seqIndex + 1; $ip < count($inputGlyphs); $ip++) {
																$exL .= $this->formatEntity($inputGlyphs[$ip]) . '&#x200d;';
															}
															$exL .= '</span>';
														}

														if (count($exampleL)) {
															$exL .= '<span class="lookahead">' . implode('&#x200d;', $exampleL) . '</span>';
														}

														$html .= '<div class="sequenceIndex">Substitution Position: ' . $seqIndex . '</div>';

														$lul2 = [$lup => $tag];

														// Only apply if the (first) 'Replace' glyph from the
														// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
														// Pass $inputGlyphs[$seqIndex] e.g. 00636|00645|00656
														// to level 2 and only apply if first Replace glyph is in this list
														$html .= $this->_getGSUBarray($Lookup, $lul2, $scripttag, 2, $inputGlyphs[$seqIndex], $exB, $exL);
													}
												}
											}
										} // Format 2: Class-based Chaining Context Glyph Substitution  p257
										else {
											if ($SubstFormat == 2) {
												$html .= '<div class="lookuptypesub">Format 2: Class-based Chaining Context Glyph Substitution  </div>';
												foreach ($Lookup[$i]['Subtable'][$c]['ChainSubClassSet'] as $inputClass => $cscs) {
													$html .= '<div class="rule">Input Class: ' . $inputClass . '</div>';
													for ($cscrule = 0; $cscrule < $cscs['ChainSubClassRuleCnt']; $cscrule++) {
														$html .= '<div class="rule">Rule: ' . $cscrule . '</div>';
														$rule = $cscs['ChainSubClassRule'][$cscrule];

														// These contain classes of glyphs as strings
														// $Lookup[$i]['Subtable'][$c]['InputClasses'][(class)] e.g. 02E6|02E7|02E8
														// $Lookup[$i]['Subtable'][$c]['LookaheadClasses'][(class)]
														// $Lookup[$i]['Subtable'][$c]['BacktrackClasses'][(class)]
														// These contain arrays of classIndexes
														// [Backtrack] [Lookahead] and [Input] (Input is from the second position only)

														$inputGlyphs = [];

														$inputGlyphs[0] = $this->classGlyphs($Lookup[$i]['Subtable'][$c]['InputClasses'], $inputClass);
														if ($rule['InputGlyphCount'] > 1) {
															//  NB starts at 1
															for ($gcl = 1; $gcl < $rule['InputGlyphCount']; $gcl++) {
																$classindex = $rule['Input'][$gcl];
																$inputGlyphs[$gcl] = $this->classGlyphs($Lookup[$i]['Subtable'][$c]['InputClasses'], $classindex);
															}
														}
														// Class 0 contains all the glyphs NOT in the other classes - of its own ClassDef. A chained
														// context has three of them, so telling the reader a backtrack position is anything but the
														// input classes named the wrong set. The shaper keeps them apart as $bclass0excl and $lclass0excl.
														$class0excl = implode('|', $Lookup[$i]['Subtable'][$c]['InputClasses']);
														$bclass0excl = implode('|', $Lookup[$i]['Subtable'][$c]['BacktrackClasses']);
														$lclass0excl = implode('|', $Lookup[$i]['Subtable'][$c]['LookaheadClasses']);
														$nInput = $rule['InputGlyphCount'];

														if ($rule['BacktrackGlyphCount']) {
															for ($gcl = 0; $gcl < $rule['BacktrackGlyphCount']; $gcl++) {
																$classindex = $rule['Backtrack'][$gcl];
																$backtrackGlyphs[$gcl] = $this->classGlyphs($Lookup[$i]['Subtable'][$c]['BacktrackClasses'], $classindex);
															}
														} else {
															$backtrackGlyphs = [];
														}

														if ($rule['LookaheadGlyphCount']) {
															for ($gcl = 0; $gcl < $rule['LookaheadGlyphCount']; $gcl++) {
																$classindex = $rule['Lookahead'][$gcl];
																$lookaheadGlyphs[$gcl] = $this->classGlyphs($Lookup[$i]['Subtable'][$c]['LookaheadClasses'], $classindex);
															}
														} else {
															$lookaheadGlyphs = [];
														}

														$exampleB = [];
														$exampleI = [];
														$exampleL = [];
														$html .= '<div class="context">CONTEXT: ';
														for ($ff = count($backtrackGlyphs) - 1; $ff >= 0; $ff--) {
															if (!$backtrackGlyphs[$ff]) {
																$html .= '<div>Backtrack #' . $ff . ': <span class="unchanged">&nbsp;[NOT ' . $this->formatEntityStr($bclass0excl) . ']&nbsp;</span></div>';
																$exampleB[] = '[NOT ' . $this->formatEntityFirst($bclass0excl) . ']';
															} else {
																$html .= '<div>Backtrack #' . $ff . ': <span class="unicode">' . $this->formatUniStr($backtrackGlyphs[$ff]) . '</span></div>';
																$exampleB[] = $this->formatEntityFirst($backtrackGlyphs[$ff]);
															}
														}
														for ($ff = 0; $ff < count($inputGlyphs); $ff++) {
															if (!$inputGlyphs[$ff]) {
																$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;[NOT ' . $this->formatEntityStr($class0excl) . ']&nbsp;</span></div>';
																$exampleI[] = '[NOT ' . $this->formatEntityFirst($class0excl) . ']';
															} else {
																$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;' . $this->formatEntityStr($inputGlyphs[$ff]) . '&nbsp;</span></div>';
																$exampleI[] = $this->formatEntityFirst($inputGlyphs[$ff]);
															}
														}
														for ($ff = 0; $ff < count($lookaheadGlyphs); $ff++) {
															if (!$lookaheadGlyphs[$ff]) {
																$html .= '<div>Lookahead #' . $ff . ': <span class="unchanged">&nbsp;[NOT ' . $this->formatEntityStr($lclass0excl) . ']&nbsp;</span></div>';
																$exampleL[] = '[NOT ' . $this->formatEntityFirst($lclass0excl) . ']';
															} else {
																$html .= '<div>Lookahead #' . $ff . ': <span class="unicode">' . $this->formatUniStr($lookaheadGlyphs[$ff]) . '</span></div>';
																$exampleL[] = $this->formatEntityFirst($lookaheadGlyphs[$ff]);
															}
														}
														$html .= '</div>';

														for ($b = 0; $b < $rule['SubstCount']; $b++) {
															$lup = $rule['LookupListIndex'][$b];
															$seqIndex = $rule['SequenceIndex'][$b];

															// GENERATE exampleB[n] exampleI[<seqIndex] .... exampleI[>seqIndex] exampleL[n]
															$exB = '';
															$exL = '';
															if (count($exampleB)) {
																$exB .= '<span class="backtrack">' . implode('&#x200d;', $exampleB) . '</span>';
															}

															if ($seqIndex > 0) {
																$exB .= '<span class="inputother">';
																for ($ip = 0; $ip < $seqIndex; $ip++) {
																	if (!$inputGlyphs[$ip]) {
																		$exB .= '[*]';
																	} else {
																		$exB .= $this->formatEntityFirst($inputGlyphs[$ip]) . '&#x200d;';
																	}
																}
																$exB .= '</span>';
															}

															if (count($inputGlyphs) > ($seqIndex + 1)) {
																$exL .= '<span class="inputother">';
																for ($ip = $seqIndex + 1; $ip < count($inputGlyphs); $ip++) {
																	if (!$inputGlyphs[$ip]) {
																		$exL .= '[*]';
																	} else {
																		$exL .= $this->formatEntityFirst($inputGlyphs[$ip]) . '&#x200d;';
																	}
																}
																$exL .= '</span>';
															}

															if (count($exampleL)) {
																$exL .= '<span class="lookahead">' . implode('&#x200d;', $exampleL) . '</span>';
															}

															$html .= '<div class="sequenceIndex">Substitution Position: ' . $seqIndex . '</div>';

															$lul2 = [$lup => $tag];

															// Only apply if the (first) 'Replace' glyph from the
															// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
															// Pass $inputGlyphs[$seqIndex] e.g. 00636|00645|00656
															// to level 2 and only apply if first Replace glyph is in this list
															$html .= $this->_getGSUBarray($Lookup, $lul2, $scripttag, 2, $inputGlyphs[$seqIndex], $exB, $exL);
														}
													}
												}

											} // Format 3: Coverage-based Chaining Context Glyph Substitution  p259
											else {
												if ($SubstFormat == 3) {
													$html .= '<div class="lookuptypesub">Format 3: Coverage-based Chaining Context Glyph Substitution  </div>';
													// IgnoreMarks flag set on main Lookup table
													$inputGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'];
													$CoverageInputGlyphs = implode('|', $inputGlyphs);
													$nInput = $Lookup[$i]['Subtable'][$c]['InputGlyphCount'];

													if ($Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount']) {
														$backtrackGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageBacktrackGlyphs'];
													} else {
														$backtrackGlyphs = [];
													}

													if ($Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount']) {
														$lookaheadGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageLookaheadGlyphs'];
													} else {
														$lookaheadGlyphs = [];
													}

													$exampleB = [];
													$exampleI = [];
													$exampleL = [];
													$html .= '<div class="context">CONTEXT: ';
													for ($ff = count($backtrackGlyphs) - 1; $ff >= 0; $ff--) {
														$html .= '<div>Backtrack #' . $ff . ': <span class="unicode">' . $this->formatUniStr($backtrackGlyphs[$ff]) . '</span></div>';
														$exampleB[] = $this->formatEntityFirst($backtrackGlyphs[$ff]);
													}
													for ($ff = 0; $ff < count($inputGlyphs); $ff++) {
														$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;' . $this->formatEntityStr($inputGlyphs[$ff]) . '&nbsp;</span></div>';
														$exampleI[] = $this->formatEntityFirst($inputGlyphs[$ff]);
													}
													for ($ff = 0; $ff < count($lookaheadGlyphs); $ff++) {
														$html .= '<div>Lookahead #' . $ff . ': <span class="unicode">' . $this->formatUniStr($lookaheadGlyphs[$ff]) . '</span></div>';
														$exampleL[] = $this->formatEntityFirst($lookaheadGlyphs[$ff]);
													}
													$html .= '</div>';

													for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['SubstCount']; $b++) {
														$lup = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['LookupListIndex'];
														$seqIndex = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['SequenceIndex'];

														// GENERATE exampleB[n] exampleI[<seqIndex] .... exampleI[>seqIndex] exampleL[n]
														$exB = '';
														$exL = '';
														if (count($exampleB)) {
															$exB .= '<span class="backtrack">' . implode('&#x200d;', $exampleB) . '</span>';
														}

														if ($seqIndex > 0) {
															$exB .= '<span class="inputother">';
															for ($ip = 0; $ip < $seqIndex; $ip++) {
																$exB .= $exampleI[$ip] . '&#x200d;';
															}
															$exB .= '</span>';
														}

														if (count($inputGlyphs) > ($seqIndex + 1)) {
															$exL .= '<span class="inputother">';
															for ($ip = $seqIndex + 1; $ip < count($inputGlyphs); $ip++) {
																$exL .= $exampleI[$ip] . '&#x200d;';
															}
															$exL .= '</span>';
														}

														if (count($exampleL)) {
															$exL .= '<span class="lookahead">' . implode('&#x200d;', $exampleL) . '</span>';
														}

														$html .= '<div class="sequenceIndex">Substitution Position: ' . $seqIndex . '</div>';

														$lul2 = [$lup => $tag];

														// Only apply if the (first) 'Replace' glyph from the
														// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
														// Pass $inputGlyphs[$seqIndex] e.g. 00636|00645|00656
														// to level 2 and only apply if first Replace glyph is in this list
														$html .= $this->_getGSUBarray($Lookup, $lul2, $scripttag, 2, $inputGlyphs[$seqIndex], $exB, $exL);
													}
												}
											}
										}
									} else {
										// LookupType 8: Reverse Chaining Contextual Single Substitution Subtable
										if ($Lookup[$i]['Type'] == 8 && !empty($Lookup[$i]['Subtable'][$c]['subs'])) {
											$html .= '<div class="lookuptype">LookupType 8: Reverse Chaining Contextual Single Substitution Subtable</div>';
											foreach ($Lookup[$i]['Subtable'][$c]['subs'] as $luss) {
												$inputGlyphs = $luss['Replace'];
												$substitute = $luss['substitute'][0];
												if ($level == 2 && strpos($coverage, $inputGlyphs[0]) === false) {
													continue;
												}
												$html .= '<div class="substitution">';
												$html .= '<span class="unicode">' . $this->formatUni($inputGlyphs[0]) . '&nbsp;</span> ';
												$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($inputGlyphs[0]) . '</span>';
												$html .= '&nbsp; &raquo; &raquo; &nbsp;';
												$html .= '<span class="changed">&nbsp;' . $this->formatEntity($substitute) . '</span>';
												$html .= '&nbsp; <span class="unicode">' . $this->formatUni($substitute) . '</span> ';
												$html .= '</div>';
											}
										}
									}
								}
							}
						}
					}
				}
			}
			$html .= '</div>';
		}
		if ($level == 1) {
			$this->mpdf->WriteHTML($html);
		} else {
			return $html;
		}
	}

	//=====================================================================================
	//=====================================================================================
	// mPDF 5.7.1

	/**
	 * A lookup's MarkFilteringSet indexes GDEF's mark glyph sets. A font naming a set GDEF does not define is
	 * malformed, and guessing which marks it meant would dump silently wrong, so both callers fail loudly here.
	 */

	function _getGSUBignoreString($flag, $MarkFilteringSet)
	{
		// If ignoreFlag set, combine all ignore glyphs into -> "((?:(?: FBA1| FBA2| FBA3))*)"
		// else "()"
		// for Input - set on secondary Lookup table if in Context, and set Backtrack and Lookahead on Context Lookup
		$str = "";
		$ignoreflag = 0;

		// Flag & 0xFF?? = MarkAttachmentType
		if ($flag & 0xFF00) {
			$MarkAttachmentType = $flag >> 8;
			$ignoreflag = $flag;
			//$str = $this->MarkAttachmentType[$MarkAttachmentType];
			$str = "MarkAttachmentType[" . $MarkAttachmentType . "] ";
		}

		// Flag & 0x0010 = UseMarkFilteringSet
		if ($flag & 0x0010) {
			// Fail here rather than dump a lookup whose filtering set GDEF never defined
			$this->markGlyphSet($MarkFilteringSet);
			$ignoreflag = $flag;
			$str = "Marks outside Mark Glyph Set[" . $MarkFilteringSet . "] ";
		}

		// If Ignore Marks set, supercedes any above
		// Flag & 0x0008 = Ignore Marks
		if (($flag & 0x0008) == 0x0008) {
			$ignoreflag = 8;
			//$str = $this->GlyphClassMarks;
			$str = "Mark Glyphs ";
		}

		// Flag & 0x0004 = Ignore Ligatures
		if (($flag & 0x0004) == 0x0004) {
			$ignoreflag += 4;
			if ($str) {
				$str .= "|";
			}
			//$str .= $this->GlyphClassLigatures;
			$str .= "Ligature Glyphs ";
		}
		// Flag & 0x0002 = Ignore BaseGlyphs
		if (($flag & 0x0002) == 0x0002) {
			$ignoreflag += 2;
			if ($str) {
				$str .= "|";
			}
			//$str .= $this->GlyphClassBases;
			$str .= "Base Glyphs ";
		}
		if ($str) {
			return $str;
		} else {
			return "";
		}
	}

	// GSUB Patterns

	/*
	  BACKTRACK                        INPUT                   LOOKAHEAD
	  ==================================  ==================  ==================================
	  (FEEB|FEEC)(ign) ¦(FD12|FD13)(ign) ¦(0612)¦(ign) (0613)¦(ign) (FD12|FD13)¦(ign) (FEEB|FEEC)
	  ----------------  ----------------  -----  ------------  ---------------   ---------------
	  Backtrack 1       Backtrack 2     Input 1   Input 2       Lookahead 1      Lookahead 2
	  --------   ---    ---------  ---    ----   ---   ----   ---   ---------   ---    -------
	  \${1}  \${2}     \${3}   \${4}                      \${5+}  \${6+}    \${7+}  \${8+}

	  nBacktrack = 2               nInput = 2                 nLookahead = 2

	  nBsubs = 2xnBack          nIsubs = (nBsubs+)    nLsubs = (nBsubs+nIsubs+) 2xnLookahead
	  "\${1}\${2} "                 (nInput*2)-1               "\${5+} \${6+}"
	  "REPL"

	  ¦\${1}\${2} ¦\${3}\${4} ¦REPL¦\${5+} \${6+}¦\${7+} \${8+}¦

	  INPUT nInput = 5
	  ============================================================
	  ¦(0612)¦(ign) (0613)¦(ign) (0614)¦(ign) (0615)¦(ign) (0615)¦
	  \${1}  \${2}  \${3}  \${4} \${5} \${6}  \${7} \${8}  \${9} (All backreference numbers are + nBsubs)
	  -----  ------------ ------------ ------------ ------------
	  Input 1   Input 2      Input 3      Input 4      Input 5

	  A======  SequenceIndex=1 ; Lookup match nGlyphs=1
	  B===================  SequenceIndex=1 ; Lookup match nGlyphs=2
	  C===============================  SequenceIndex=1 ; Lookup match nGlyphs=3
	  D=======================  SequenceIndex=2 ; Lookup match nGlyphs=2
	  E=====================================  SequenceIndex=2 ; Lookup match nGlyphs=3
	  F======================  SequenceIndex=4 ; Lookup match nGlyphs=2

	  All backreference numbers are + nBsubs
	  A - "REPL\${2} \${3}\${4} \${5}\${6} \${7}\${8} \${9}"
	  B - "REPL\${2}\${4} \${5}\${6} \${7}\${8} \${9}"
	  C - "REPL\${2}\${4}\${6} \${7}\${8} \${9}"
	  D - "\${1} REPL\${2}\${4}\${6} \${7}\${8} \${9}"
	  E - "\${1} REPL\${2}\${4}\${6}\${8} \${9}"
	  F - "\${1}\${2} \${3}\${4} \${5} REPL\${6}\${8}"
	 */

	function _makeGSUBcontextInputMatch($inputGlyphs, $ignore, $lookupGlyphs, $seqIndex)
	{
		// $ignore = "((?:(?: FBA1| FBA2| FBA3))*)" or "()"
		// Returns e.g. ¦(0612)¦(ignore) (0613)¦(ignore) (0614)¦
		// $inputGlyphs = array of glyphs(glyphstrings) making up Input sequence in Context
		// $lookupGlyphs = array of glyphs (single Glyphs) making up Lookup Input sequence
		$mLen = count($lookupGlyphs);  // nGlyphs in the secondary Lookup match
		$nInput = count($inputGlyphs); // nGlyphs in the Primary Input sequence
		$str = "";
		for ($i = 0; $i < $nInput; $i++) {
			if ($i > 0) {
				$str .= $ignore . " ";
			}
			if ($i >= $seqIndex && $i < ($seqIndex + $mLen)) {
				$str .= "" . $lookupGlyphs[($i - $seqIndex)] . "";
			} else {
				$str .= "" . $inputGlyphs[($i)] . "";
			}
		}

		return $str;
	}

	function _makeGSUBinputMatch($inputGlyphs, $ignore)
	{
		// $ignore = "((?:(?: FBA1| FBA2| FBA3))*)" or "()"
		// Returns e.g. ¦(0612)¦(ignore) (0613)¦(ignore) (0614)¦
		// $inputGlyphs = array of glyphs(glyphstrings) making up Input sequence in Context
		// $lookupGlyphs = array of glyphs making up Lookup Input sequence - if applicable
		$str = "";
		for ($i = 1; $i <= count($inputGlyphs); $i++) {
			if ($i > 1) {
				$str .= $ignore . " ";
			}
			$str .= "" . $inputGlyphs[($i - 1)] . "";
		}

		return $str;
	}

	function _makeGSUBbacktrackMatch($backtrackGlyphs, $ignore)
	{
		// $ignore = "((?:(?: FBA1| FBA2| FBA3))*)" or "()"
		// Returns e.g. ¦(FEEB|FEEC)(ignore) ¦(FD12|FD13)(ignore) ¦
		// $backtrackGlyphs = array of glyphstrings making up Backtrack sequence
		// 3  2  1  0
		// each item being e.g. E0AD|E0AF|F1FD
		$str = "";
		for ($i = (count($backtrackGlyphs) - 1); $i >= 0; $i--) {
			$str .= "" . $backtrackGlyphs[$i] . " " . $ignore . " ";
		}

		return $str;
	}

	function _makeGSUBlookaheadMatch($lookaheadGlyphs, $ignore)
	{
		// $ignore = "((?:(?: FBA1| FBA2| FBA3))*)" or "()"
		// Returns e.g. ¦(ignore) (FD12|FD13)¦(ignore) (FEEB|FEEC)¦
		// $lookaheadGlyphs = array of glyphstrings making up Lookahead sequence
		// 0  1  2  3
		// each item being e.g. E0AD|E0AF|F1FD
		$str = "";
		for ($i = 0; $i < count($lookaheadGlyphs); $i++) {
			$str .= $ignore . " " . $lookaheadGlyphs[$i] . "";
		}

		return $str;
	}

	//////////////////////////////////////////////////////////////////////////////////

	//////////////////////////////////////////////////////////////////////////////////

	//////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////
	function _getGPOStables()
	{
		///////////////////////////////////
		// GPOS - Glyph Positioning
		///////////////////////////////////
		if (isset($this->tables["GPOS"])) {
			$this->mpdf->WriteHTML('<h1>GPOS Tables</h1>');
			$ffeats = [];
			$gpos_offset = $this->seek_table("GPOS");
			$this->reader->skip(4);
			$ScriptList_offset = $gpos_offset + $this->reader->readUInt16();
			$FeatureList_offset = $gpos_offset + $this->reader->readUInt16();
			$LookupList_offset = $gpos_offset + $this->reader->readUInt16();

			// ScriptList
			$this->reader->seek($ScriptList_offset);
			$ScriptCount = $this->reader->readUInt16();
			for ($i = 0; $i < $ScriptCount; $i++) {
				$ScriptTag = $this->reader->readTag(); // = "beng", "deva" etc.
				$ScriptTableOffset = $this->reader->readUInt16();
				$ffeats[$ScriptTag] = $ScriptList_offset + $ScriptTableOffset;
			}

			// Script Table
			foreach ($ffeats as $t => $o) {
				$ls = [];
				$this->reader->seek($o);
				$DefLangSys_offset = $this->reader->readUInt16();
				if ($DefLangSys_offset > 0) {
					$ls['DFLT'] = $DefLangSys_offset + $o;
				}
				$LangSysCount = $this->reader->readUInt16();
				for ($i = 0; $i < $LangSysCount; $i++) {
					$LangTag = $this->reader->readTag(); // =
					$LangTableOffset = $this->reader->readUInt16();
					$ls[$LangTag] = $o + $LangTableOffset;
				}
				$ffeats[$t] = $ls;
			}

			// Get FeatureIndexList
			// LangSys Table - from first listed langsys
			foreach ($ffeats as $st => $scripts) {
				foreach ($scripts as $t => $o) {
					$FeatureIndex = [];
					$langsystable_offset = $o;
					$this->reader->seek($langsystable_offset);
					$LookUpOrder = $this->reader->readUInt16(); //==NULL
					$ReqFeatureIndex = $this->reader->readUInt16();
					if ($ReqFeatureIndex != 0xFFFF) {
						$FeatureIndex[] = $ReqFeatureIndex;
					}
					$FeatureCount = $this->reader->readUInt16();
					for ($i = 0; $i < $FeatureCount; $i++) {
						$FeatureIndex[] = $this->reader->readUInt16(); // = index of feature
					}
					$ffeats[$st][$t] = $FeatureIndex;
				}
			}
			// Feauture List => LookupListIndex es
			$this->reader->seek($FeatureList_offset);
			$FeatureCount = $this->reader->readUInt16();
			$Feature = [];
			for ($i = 0; $i < $FeatureCount; $i++) {
				$Feature[$i] = ['tag' => $this->reader->readTag()];
				$Feature[$i]['offset'] = $FeatureList_offset + $this->reader->readUInt16();
			}
			for ($i = 0; $i < $FeatureCount; $i++) {
				$this->reader->seek($Feature[$i]['offset']);
				$this->reader->readUInt16(); // null
				$Feature[$i]['LookupCount'] = $Lookupcount = $this->reader->readUInt16();
				$Feature[$i]['LookupListIndex'] = [];
				for ($c = 0; $c < $Lookupcount; $c++) {
					$Feature[$i]['LookupListIndex'][] = $this->reader->readUInt16();
				}
			}

			foreach ($ffeats as $st => $scripts) {
				foreach ($scripts as $t => $o) {
					$FeatureIndex = $ffeats[$st][$t];
					foreach ($FeatureIndex as $k => $fi) {
						$ffeats[$st][$t][$k] = $Feature[$fi];
					}
				}
			}
			//=====================================================================================
			$gpos = [];
			$GPOSScriptLang = [];
			foreach ($ffeats as $st => $scripts) {
				foreach ($scripts as $t => $langsys) {
					$lg = [];
					foreach ($langsys as $ft) {
						$lg[$ft['LookupListIndex'][0]] = $ft;
					}
					// list of Lookups in order they need to be run i.e. order listed in Lookup table
					ksort($lg);
					foreach ($lg as $ft) {
						$gpos[$st][$t][$ft['tag']] = $ft['LookupListIndex'];
					}
					if (!isset($GPOSScriptLang[$st])) {
						$GPOSScriptLang[$st] = '';
					}
					$GPOSScriptLang[$st] .= $t . ' ';
				}
			}
			if ($this->mode == 'summary') {
				$this->mpdf->WriteHTML('<h3>GPOS Scripts &amp; Languages</h3>');
				$html = '';
				if (count($gpos)) {
					foreach ($gpos as $st => $g) {
						$html .= '<h5>' . $st . '</h5>';
						foreach ($g as $l => $t) {
							$html .= '<div><a href="' . $this->detailLink($st, $l) . '">' . $l . '</a></b>: ';
							foreach ($t as $tag => $o) {
								$html .= $tag . ' ';
							}
							$html .= '</div>';
						}
					}
				} else {
					$html .= '<div>No entries in GPOS table.</div>';
				}
				$this->mpdf->WriteHTML($html);
				$this->mpdf->WriteHTML('</div>');

				// As in _getGSUBtables: the scripts and languages are known, the lookups are not
				return [$GPOSScriptLang, $gpos, []];
			}

			//=====================================================================================
			// Get metadata and offsets for whole Lookup List table
			$this->reader->seek($LookupList_offset);
			$LookupCount = $this->reader->readUInt16();
			$Lookup = [];
			$Offsets = [];
			$SubtableCount = [];
			for ($i = 0; $i < $LookupCount; $i++) {
				$Offsets[$i] = $LookupList_offset + $this->reader->readUInt16();
			}
			for ($i = 0; $i < $LookupCount; $i++) {
				$this->reader->seek($Offsets[$i]);
				$Lookup[$i]['Type'] = $this->reader->readUInt16();
				$Lookup[$i]['Flag'] = $flag = $this->reader->readUInt16();
				$Lookup[$i]['SubtableCount'] = $SubtableCount[$i] = $this->reader->readUInt16();
				for ($c = 0; $c < $SubtableCount[$i]; $c++) {
					$Lookup[$i]['Subtables'][$c] = $Offsets[$i] + $this->reader->readUInt16();
				}
				// MarkFilteringSet = Index (base 0) into GDEF mark glyph sets structure
				if (($flag & 0x0010) == 0x0010) {
					$Lookup[$i]['MarkFilteringSet'] = $this->reader->readUInt16();
				} else {
					$Lookup[$i]['MarkFilteringSet'] = '';
				}
				// Lookup Type 9: Extension
				if ($Lookup[$i]['Type'] == 9) {
					// Overwrites new offset (32-bit) for each subtable, and a new lookup Type
					for ($c = 0; $c < $SubtableCount[$i]; $c++) {
						$this->reader->seek($Lookup[$i]['Subtables'][$c]);
						$ExtensionPosFormat = $this->reader->readUInt16();
						$type = $this->reader->readUInt16();
						$Lookup[$i]['Subtables'][$c] = $Lookup[$i]['Subtables'][$c] + $this->reader->readUInt32();
					}
					$Lookup[$i]['Type'] = $type;
				}
			}

			//=====================================================================================

			$st = $this->script;
			$t = $this->language;
			$langsys = $this->langSys($gpos, 'GPOS');

			$lul = []; // array of LookupListIndexes
			$tags = []; // corresponding array of feature tags e.g. 'ccmp'
			if (count($langsys)) {
				foreach ($langsys as $tag => $ft) {
					foreach ($ft as $ll) {
						$lul[$ll] = $tag;
					}
				}
			}
			ksort($lul); // Order the Lookups in the order they are in the GUSB table, regardless of Feature order
			$this->_getGPOSarray($Lookup, $lul, $st);

			return [$GPOSScriptLang, $gpos, $Lookup];
		} // end if GPOS
	}

	//////////////////////////////////////////////////////////////////////////////////
	//=====================================================================================
	//=====================================================================================
	//=====================================================================================
/////////////////////////////////////////////////////////////////////////////////////////
	// GPOS functions
	function _getGPOSarray(&$Lookup, $lul, $scripttag, $level = 1, $lcoverage = '', $exB = '', $exL = '')
	{
		// Process (3) LookupList for specific Script-LangSys
		$html = '';
		if ($level == 1) {
			$html .= '<bookmark level="0" content="GPOS features">';
		}
		foreach ($lul as $luli => $tag) {
			$html .= '<div class="level' . $level . '">';
			$html .= '<h5 class="level' . $level . '">';
			if ($level == 1) {
				$html .= '<bookmark level="1" content="' . $tag . ' [#' . $luli . ']">';
			}
			$html .= 'Lookup #' . $luli . ' [tag: <span style="color:#000066;">' . $tag . '</span>]</h5>';
			$ignore = $this->_getGSUBignoreString($Lookup[$luli]['Flag'], $Lookup[$luli]['MarkFilteringSet']);
			if ($ignore) {
				$html .= '<div class="ignore">Ignoring: ' . $ignore . '</div> ';
			}

			$Type = $Lookup[$luli]['Type'];
			$Flag = $Lookup[$luli]['Flag'];
			if (($Flag & 0x0001) == 1) {
				$dir = 'RTL';
			} else {
				$dir = 'LTR';
			}

			for ($c = 0; $c < $Lookup[$luli]['SubtableCount']; $c++) {
				$html .= '<div class="subtable">Subtable #' . $c;
				if ($level == 1) {
					$html .= '<bookmark level="2" content="Subtable #' . $c . '">';
				}
				$html .= '</div>';

				// Lets start
				$subtable_offset = $Lookup[$luli]['Subtables'][$c];
				$this->reader->seek($subtable_offset);
				$PosFormat = $this->reader->readUInt16();

				////////////////////////////////////////////////////////////////////////////////
				// LookupType 1: Single adjustment 	Adjust position of a single glyph (e.g. SmallCaps/Sups/Subs)
				////////////////////////////////////////////////////////////////////////////////
				if ($Lookup[$luli]['Type'] == 1) {
					$html .= '<div class="lookuptype">LookupType 1: Single adjustment [Format ' . $PosFormat . ']</div>';
					//===========
					// Format 1:
					//===========
					if ($PosFormat == 1) {
						$Coverage = $subtable_offset + $this->reader->readUInt16();
						$ValueFormat = $this->reader->readUInt16();
						$Value = $this->_getValueRecord($ValueFormat);

						$this->reader->seek($Coverage);
						$glyphs = $this->_getCoverage(); // Array of Hex Glyphs
						for ($g = 0; $g < count($glyphs); $g++) {
							if ($level == 2 && strpos($lcoverage, $glyphs[$g]) === false) {
								continue;
							}

							$html .= '<div class="substitution">';
							$html .= '<span class="unicode">' . $this->formatUni($glyphs[$g]) . '&nbsp;</span> ';
							if ($level == 2 && $exB) {
								$html .= $exB;
							}
							$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($glyphs[$g]) . '</span>';
							if ($level == 2 && $exL) {
								$html .= $exL;
							}
							$html .= '&nbsp; &raquo; &raquo; &nbsp;';
							if ($level == 2 && $exB) {
								$html .= $exB;
							}
							$html .= '<span class="changed" style="font-feature-settings:\'' . $tag . '\' 1;">&nbsp;' . $this->formatEntity($glyphs[$g]) . '</span>';
							if ($level == 2 && $exL) {
								$html .= $exL;
							}
							$html .= ' <span class="unicode">';
							if ($Value['XPlacement']) {
								$html .= ' Xpl: ' . $Value['XPlacement'] . ';';
							}
							if ($Value['YPlacement']) {
								$html .= ' YPl: ' . $Value['YPlacement'] . ';';
							}
							if ($Value['XAdvance']) {
								$html .= ' Xadv: ' . $Value['XAdvance'];
							}
							$html .= '</span>';
							$html .= '</div>';
						}
					} //===========
					// Format 2:
					//===========
					else {
						if ($PosFormat == 2) {
							$Coverage = $subtable_offset + $this->reader->readUInt16();
							$ValueFormat = $this->reader->readUInt16();
							$ValueCount = $this->reader->readUInt16();
							$Values = [];
							for ($v = 0; $v < $ValueCount; $v++) {
								$Values[] = $this->_getValueRecord($ValueFormat);
							}

							$this->reader->seek($Coverage);
							$glyphs = $this->_getCoverage(); // Array of Hex Glyphs

							for ($g = 0; $g < count($glyphs); $g++) {
								if ($level == 2 && strpos($lcoverage, $glyphs[$g]) === false) {
									continue;
								}
								$Value = $Values[$g];

								$html .= '<div class="substitution">';
								$html .= '<span class="unicode">' . $this->formatUni($glyphs[$g]) . '&nbsp;</span> ';
								if ($level == 2 && $exB) {
									$html .= $exB;
								}
								$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($glyphs[$g]) . '</span>';
								if ($level == 2 && $exL) {
									$html .= $exL;
								}
								$html .= '&nbsp; &raquo; &raquo; &nbsp;';
								if ($level == 2 && $exB) {
									$html .= $exB;
								}
								$html .= '<span class="changed" style="font-feature-settings:\'' . $tag . '\' 1;">&nbsp;' . $this->formatEntity($glyphs[$g]) . '</span>';
								if ($level == 2 && $exL) {
									$html .= $exL;
								}
								$html .= ' <span class="unicode">';
								if ($Value['XPlacement']) {
									$html .= ' Xpl: ' . $Value['XPlacement'] . ';';
								}
								if ($Value['YPlacement']) {
									$html .= ' YPl: ' . $Value['YPlacement'] . ';';
								}
								if ($Value['XAdvance']) {
									$html .= ' Xadv: ' . $Value['XAdvance'];
								}
								$html .= '</span>';
								$html .= '</div>';
							}
						}
					}
				} ////////////////////////////////////////////////////////////////////////////////
				// LookupType 2: Pair adjustment 	Adjust position of a pair of glyphs (Kerning)
				////////////////////////////////////////////////////////////////////////////////
				else {
					if ($Lookup[$luli]['Type'] == 2) {
						$html .= '<div class="lookuptype">LookupType 2: Pair adjustment e.g. Kerning [Format ' . $PosFormat . ']</div>';
						$Coverage = $subtable_offset + $this->reader->readUInt16();
						$ValueFormat1 = $this->reader->readUInt16();
						$ValueFormat2 = $this->reader->readUInt16();
						//===========
						// Format 1:
						//===========
						if ($PosFormat == 1) {
							$PairSetCount = $this->reader->readUInt16();
							$PairSetOffset = [];
							for ($p = 0; $p < $PairSetCount; $p++) {
								$PairSetOffset[] = $subtable_offset + $this->reader->readUInt16();
							}
							$this->reader->seek($Coverage);
							$glyphs = $this->_getCoverage(); // Array of Hex Glyphs
							for ($p = 0; $p < $PairSetCount; $p++) {
								if ($level == 2 && strpos($lcoverage, $glyphs[$p]) === false) {
									continue;
								}
								$this->reader->seek($PairSetOffset[$p]);
								// First Glyph = $glyphs[$p]
// Takes too long e.g. Calibri font - just list kerning pairs with this:
								$html .= '<div class="glyphs">';
								$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($glyphs[$p]) . ' </span>';

								//PairSet table
								$PairValueCount = $this->reader->readUInt16();
								for ($pv = 0; $pv < $PairValueCount; $pv++) {
									//PairValueRecord
									$gid = $this->reader->readUInt16();
									$SecondGlyph = unicode_hex($this->glyphToChar[$gid][0]);
									$Value1 = $this->_getValueRecord($ValueFormat1);
									$Value2 = $this->_getValueRecord($ValueFormat2);

									// If RTL pairs, GPOS declares a XPlacement e.g. -180 for an XAdvance of -180 to take
									// account of direction. mPDF does not need the XPlacement adjustment
									if ($dir == 'RTL' && $Value1['XPlacement']) {
										$Value1['XPlacement'] -= $Value1['XAdvance'];
									}

									if ($ValueFormat2) {
										// If RTL pairs, GPOS declares a XPlacement e.g. -180 for an XAdvance of -180 to take
										// account of direction. mPDF does not need the XPlacement adjustment
										if ($dir == 'RTL' && $Value2['XPlacement'] && $Value2['XAdvance']) {
											$Value2['XPlacement'] -= $Value2['XAdvance'];
										}
									}

									$html .= ' ' . $this->formatEntity($SecondGlyph) . ' ';

									/*
									  $html .= '<div class="substitution">';
									  $html .= '<span class="unicode">'.$this->formatUni($glyphs[$p]).'&nbsp;</span> ';
									  if ($level==2 && $exB) { $html .= $exB; }
									  $html .= '<span class="unchanged">&nbsp;'.$this->formatEntity($glyphs[$p]).$this->formatEntity($SecondGlyph).'</span>';
									  if ($level==2 && $exL) { $html .= $exL; }
									  $html .= '&nbsp; &raquo; &raquo; &nbsp;';
									  if ($level==2 && $exB) { $html .= $exB; }
									  $html .= '<span class="changed" style="font-feature-settings:\''.$tag.'\' 1;">&nbsp;'.$this->formatEntity($glyphs[$p]).$this->formatEntity($SecondGlyph).'</span>';
									  if ($level==2 && $exL) { $html .= $exL; }
									  $html .= ' <span class="unicode">';
									  if ($Value1['XPlacement']) { $html .= ' Xpl[1]: '.$Value1['XPlacement'].';'; }
									  if ($Value1['YPlacement']) { $html .= ' YPl[1]: '.$Value1['YPlacement'].';'; }
									  if ($Value1['XAdvance']) { $html .= ' Xadv[1]: '.$Value1['XAdvance']; }
									  if ($Value2['XPlacement']) { $html .= ' Xpl[2]: '.$Value2['XPlacement'].';'; }
									  if ($Value2['YPlacement']) { $html .= ' YPl[2]: '.$Value2['YPlacement'].';'; }
									  if ($Value2['XAdvance']) { $html .= ' Xadv[2]: '.$Value2['XAdvance']; }
									  $html .= '</span>';
									  $html .= '</div>';
									 */
								}
								$html .= '</div>';
							}
						} //===========
						// Format 2:
						//===========
						else {
							if ($PosFormat == 2) {
								$ClassDef1 = $subtable_offset + $this->reader->readUInt16();
								$ClassDef2 = $subtable_offset + $this->reader->readUInt16();
								$Class1Count = $this->reader->readUInt16();
								$Class2Count = $this->reader->readUInt16();

								$sizeOfPair = (2 * $this->count_bits($ValueFormat1)) + (2 * $this->count_bits($ValueFormat2));
								$sizeOfValueRecords = $Class1Count * $Class2Count * $sizeOfPair;

								// NB Class1Count includes Class 0 even though it is not defined by $ClassDef1
								// i.e. Class1Count = 5; Class1 will contain array(indices 1-4);
								$Class1 = $this->_getClassDefinitionTable($ClassDef1);
								$Class2 = $this->_getClassDefinitionTable($ClassDef2);

								$this->reader->seek($subtable_offset + 16);

								for ($i = 0; $i < $Class1Count; $i++) {
									for ($j = 0; $j < $Class2Count; $j++) {
										$Value1 = $this->_getValueRecord($ValueFormat1);
										$Value2 = $this->_getValueRecord($ValueFormat2);

										// If RTL pairs, GPOS declares a XPlacement e.g. -180 for an XAdvance of -180
										// of direction. mPDF does not need the XPlacement adjustment
										if ($dir == 'RTL' && $Value1['XPlacement'] && $Value1['XAdvance']) {
											$Value1['XPlacement'] -= $Value1['XAdvance'];
										}
										if ($ValueFormat2) {
											if ($dir == 'RTL' && $Value2['XPlacement'] && $Value2['XAdvance']) {
												$Value2['XPlacement'] -= $Value2['XAdvance'];
											}
										}

										// Class1Count counts class 0, which ClassDef1 does not define, and a font may
										// leave any other class empty too. Otl guards both the same way; this copy
										// indexed straight in and killed the dump on the first font with a gap.
										if (!isset($Class1[$i]) || !isset($Class2[$j])) {
											continue;
										}

										for ($c1 = 0; $c1 < count($Class1[$i]); $c1++) {
											$FirstGlyph = $Class1[$i][$c1];
											if ($level == 2 && strpos($lcoverage, $FirstGlyph) === false) {
												continue;
											}

											for ($c2 = 0; $c2 < count($Class2[$j]); $c2++) {
												$SecondGlyph = $Class2[$j][$c2];

												if (!$Value1['XPlacement'] && !$Value1['YPlacement'] && !$Value1['XAdvance'] && !$Value2['XPlacement'] && !$Value2['YPlacement'] && !$Value2['XAdvance']) {
													continue;
												}

												$html .= '<div class="substitution">';
												$html .= '<span class="unicode">' . $this->formatUni($FirstGlyph) . '&nbsp;</span> ';
												if ($level == 2 && $exB) {
													$html .= $exB;
												}
												$html .= '<span class="unchanged">&nbsp;' . $this->formatEntity($FirstGlyph) . $this->formatEntity($SecondGlyph) . '</span>';
												if ($level == 2 && $exL) {
													$html .= $exL;
												}
												$html .= '&nbsp; &raquo; &raquo; &nbsp;';
												if ($level == 2 && $exB) {
													$html .= $exB;
												}
												$html .= '<span class="changed" style="font-feature-settings:\'' . $tag . '\' 1;">&nbsp;' . $this->formatEntity($FirstGlyph) . $this->formatEntity($SecondGlyph) . '</span>';
												if ($level == 2 && $exL) {
													$html .= $exL;
												}
												$html .= ' <span class="unicode">';
												if ($Value1['XPlacement']) {
													$html .= ' Xpl[1]: ' . $Value1['XPlacement'] . ';';
												}
												if ($Value1['YPlacement']) {
													$html .= ' YPl[1]: ' . $Value1['YPlacement'] . ';';
												}
												if ($Value1['XAdvance']) {
													$html .= ' Xadv[1]: ' . $Value1['XAdvance'];
												}
												if ($Value2['XPlacement']) {
													$html .= ' Xpl[2]: ' . $Value2['XPlacement'] . ';';
												}
												if ($Value2['YPlacement']) {
													$html .= ' YPl[2]: ' . $Value2['YPlacement'] . ';';
												}
												if ($Value2['XAdvance']) {
													$html .= ' Xadv[2]: ' . $Value2['XAdvance'];
												}
												$html .= '</span>';
												$html .= '</div>';
											}
										}
									}
								}
							}
						}
					} ////////////////////////////////////////////////////////////////////////////////
					// LookupType 3: Cursive attachment 	Attach cursive glyphs
					////////////////////////////////////////////////////////////////////////////////
					else {
						if ($Lookup[$luli]['Type'] == 3) {
							$html .= '<div class="lookuptype">LookupType 3: Cursive attachment </div>';
							$Coverage = $subtable_offset + $this->reader->readUInt16();
							$EntryExitCount = $this->reader->readUInt16();
							$EntryAnchors = [];
							$ExitAnchors = [];
							for ($i = 0; $i < $EntryExitCount; $i++) {
								$EntryAnchors[$i] = $this->reader->readUInt16();
								$ExitAnchors[$i] = $this->reader->readUInt16();
							}

							$this->reader->seek($Coverage);
							$Glyphs = $this->_getCoverage();
							for ($i = 0; $i < $EntryExitCount; $i++) {
								// Need default XAdvance for glyph
								$pdfWidth = $this->mpdf->_getCharWidth($this->mpdf->fonts[$this->fontkey]['cw'], hexdec($Glyphs[$i]));
								$EntryAnchor = $EntryAnchors[$i];
								$ExitAnchor = $ExitAnchors[$i];
								$html .= '<div class="glyphs">';
								$html .= '<span class="unchanged">' . $this->formatEntity($Glyphs[$i]) . ' </span> ';
								$html .= '<span class="unicode"> ' . $this->formatUni($Glyphs[$i]) . ' => ';

								if ($EntryAnchor != 0) {
									$EntryAnchor += $subtable_offset;
									list($x, $y) = $this->_getAnchorTable($EntryAnchor);
									if ($dir == 'RTL') {
										if (round($pdfWidth) == round($x * 1000 / $this->unitsPerEm)) {
											$x = 0;
										} else {
											$x = $x - ($pdfWidth * $this->unitsPerEm / 1000);
										}
									}
									$html .= " Entry X: " . $x . " Y: " . $y . "; ";
								}
								if ($ExitAnchor != 0) {
									$ExitAnchor += $subtable_offset;
									list($x, $y) = $this->_getAnchorTable($ExitAnchor);
									if ($dir == 'LTR') {
										if (round($pdfWidth) == round($x * 1000 / $this->unitsPerEm)) {
											$x = 0;
										} else {
											$x = $x - ($pdfWidth * $this->unitsPerEm / 1000);
										}
									}
									$html .= " Exit X: " . $x . " Y: " . $y . "; ";
								}

								$html .= '</span></div>';
							}
						} ////////////////////////////////////////////////////////////////////////////////
						// LookupType 4: MarkToBase attachment 	Attach a combining mark to a base glyph
						////////////////////////////////////////////////////////////////////////////////
						else {
							if ($Lookup[$luli]['Type'] == 4) {
								$html .= '<div class="lookuptype">LookupType 4: MarkToBase attachment </div>';
								$MarkCoverage = $subtable_offset + $this->reader->readUInt16();
								$BaseCoverage = $subtable_offset + $this->reader->readUInt16();

								$this->reader->seek($MarkCoverage);
								$MarkGlyphs = $this->_getCoverage();

								$this->reader->seek($BaseCoverage);
								$BaseGlyphs = $this->_getCoverage();

								$firstMark = '';
								$html .= '<div class="glyphs">Marks: ';
								for ($i = 0; $i < count($MarkGlyphs); $i++) {
									if ($level == 2 && strpos($lcoverage, $MarkGlyphs[$i]) === false) {
										continue;
									} else {
										if (!$firstMark) {
											$firstMark = $MarkGlyphs[$i];
										}
									}
									$html .= ' ' . $this->formatEntity($MarkGlyphs[$i]) . ' ';
								}
								$html .= '</div>';
								if (!$firstMark) {
									return;
								}

								$html .= '<div class="glyphs">Bases: ';
								for ($j = 0; $j < count($BaseGlyphs); $j++) {
									$html .= ' ' . $this->formatEntity($BaseGlyphs[$j]) . ' ';
								}
								$html .= '</div>';

								// Example
								$html .= '<div class="glyphs" style="font-feature-settings:\'' . $tag . '\' 1;">Example(s): ';
								for ($j = 0; $j < min(count($BaseGlyphs), 20); $j++) {
									$html .= ' ' . $this->formatEntity($BaseGlyphs[$j]) . $this->formatEntity($firstMark, true) . ' &nbsp; ';
								}
								$html .= '</div>';
							} ////////////////////////////////////////////////////////////////////////////////
							// LookupType 5: MarkToLigature attachment 	Attach a combining mark to a ligature
							////////////////////////////////////////////////////////////////////////////////
							else {
								if ($Lookup[$luli]['Type'] == 5) {
									$html .= '<div class="lookuptype">LookupType 5: MarkToLigature attachment </div>';
									$MarkCoverage = $subtable_offset + $this->reader->readUInt16();
									//$MarkCoverage is already set in $lcoverage 00065|00073 etc
									$LigatureCoverage = $subtable_offset + $this->reader->readUInt16();
									$ClassCount = $this->reader->readUInt16(); // Number of classes defined for marks = Number of mark glyphs in the MarkCoverage table
									$MarkArray = $subtable_offset + $this->reader->readUInt16(); // Offset to MarkArray table
									$LigatureArray = $subtable_offset + $this->reader->readUInt16(); // Offset to LigatureArray table

									$this->reader->seek($MarkCoverage);
									$MarkGlyphs = $this->_getCoverage();
									$this->reader->seek($LigatureCoverage);
									$LigatureGlyphs = $this->_getCoverage();

									$firstMark = '';
									$html .= '<div class="glyphs">Marks: <span class="unchanged">';
									$MarkRecord = [];
									for ($i = 0; $i < count($MarkGlyphs); $i++) {
										if ($level == 2 && strpos($lcoverage, $MarkGlyphs[$i]) === false) {
											continue;
										} else {
											if (!$firstMark) {
												$firstMark = $MarkGlyphs[$i];
											}
										}
										// Get the relevant MarkRecord
										$MarkRecord[$i] = $this->_getMarkRecord($MarkArray, $i);
										//Mark Class is = $MarkRecord[$i]['Class']
										$html .= ' ' . $this->formatEntity($MarkGlyphs[$i]) . ' ';
									}
									$html .= '</span></div>';
									if (!$firstMark) {
										return;
									}

									$this->reader->seek($LigatureArray);
									$LigatureCount = $this->reader->readUInt16();
									$LigatureAttach = [];
									$html .= '<div class="glyphs">Ligatures: <span class="unchanged">';
									for ($j = 0; $j < count($LigatureGlyphs); $j++) {
										// Get the relevant LigatureRecord
										$LigatureAttach[$j] = $LigatureArray + $this->reader->readUInt16();
										$html .= ' ' . $this->formatEntity($LigatureGlyphs[$j]) . ' ';
									}
									$html .= '</span></div>';

									/*
									  for ($i=0;$i<count($MarkGlyphs);$i++) {
									  $html .= '<div class="glyphs">';
									  $html .= '<span class="unchanged">'.$this->formatEntity($MarkGlyphs[$i]).'</span>';

									  for ($j=0;$j<count($LigatureGlyphs);$j++) {
									  $this->reader->seek($LigatureAttach[$j]);
									  $ComponentCount = $this->reader->readUInt16();
									  $html .= '<span class="unchanged">'.$this->formatEntity($LigatureGlyphs[$j]).'</span>';
									  $offsets = array();
									  for ($comp=0;$comp<$ComponentCount;$comp++) {
									  // ComponentRecords
									  for ($class=0;$class<$ClassCount;$class++) {
									  $offset = $this->reader->readUInt16();
									  if ($offset!= 0 && $class == $MarkRecord[$i]['Class']) {

									  $html .= ' ['.$comp.'] ';

									  }
									  }
									  }
									  }
									  $html .= '</span></div>';
									  }
									 */
								} ////////////////////////////////////////////////////////////////////////////////
								// LookupType 6: MarkToMark attachment 	Attach a combining mark to another mark
								////////////////////////////////////////////////////////////////////////////////
								else {
									if ($Lookup[$luli]['Type'] == 6) {
										$html .= '<div class="lookuptype">LookupType 6: MarkToMark attachment </div>';
										$Mark1Coverage = $subtable_offset + $this->reader->readUInt16(); // Combining Mark
										//$Mark1Coverage is already set in $LuCoverage 0065|0073 etc
										$Mark2Coverage = $subtable_offset + $this->reader->readUInt16(); // Base Mark
										$ClassCount = $this->reader->readUInt16(); // Number of classes defined for marks = No. of Combining mark1 glyphs in the MarkCoverage table
										$this->reader->seek($Mark1Coverage);
										$Mark1Glyphs = $this->_getCoverage();
										$this->reader->seek($Mark2Coverage);
										$Mark2Glyphs = $this->_getCoverage();

										$firstMark = '';
										$html .= '<div class="glyphs">Marks: <span class="unchanged">';
										for ($i = 0; $i < count($Mark1Glyphs); $i++) {
											if ($level == 2 && strpos($lcoverage, $Mark1Glyphs[$i]) === false) {
												continue;
											} else {
												if (!$firstMark) {
													$firstMark = $Mark1Glyphs[$i];
												}
											}
											$html .= ' ' . $this->formatEntity($Mark1Glyphs[$i]) . ' ';
										}
										$html .= '</span></div>';

										if ($firstMark) {
											$html .= '<div class="glyphs">Bases: <span class="unchanged">';
											for ($j = 0; $j < count($Mark2Glyphs); $j++) {
												$html .= ' ' . $this->formatEntity($Mark2Glyphs[$j]) . ' ';
											}
											$html .= '</span></div>';

											// Example
											$html .= '<div class="glyphs" style="font-feature-settings:\'' . $tag . '\' 1;">Example(s): <span class="changed">';
											for ($j = 0; $j < min(count($Mark2Glyphs), 20); $j++) {
												$html .= ' ' . $this->formatEntity($Mark2Glyphs[$j]) . $this->formatEntity($firstMark, true) . ' &nbsp; ';
											}
											$html .= '</span></div>';
										}
									} ////////////////////////////////////////////////////////////////////////////////
									// LookupType 7: Context positioning 	Position one or more glyphs in context
									////////////////////////////////////////////////////////////////////////////////
									else {
										if ($Lookup[$luli]['Type'] == 7) {
											$html .= '<div class="lookuptype">LookupType 7: Context positioning [Format ' . $PosFormat . ']</div>';
											//===========
											// Format 1:
											//===========
											if ($PosFormat == 1) {
												throw new \Mpdf\Exception\FontException("GPOS Lookup Type " . $Type . " Format " . $PosFormat . " not YET TESTED.");
											} //===========
											// Format 2:
											//===========
											else {
												if ($PosFormat == 2) {
													throw new \Mpdf\Exception\FontException("GPOS Lookup Type " . $Type . " Format " . $PosFormat . " not YET TESTED.");
												} //===========
												// Format 3:
												//===========
												else {
													if ($PosFormat == 3) {
														throw new \Mpdf\Exception\FontException("GPOS Lookup Type " . $Type . " Format " . $PosFormat . " not YET TESTED.");
													} else {
														throw new \Mpdf\Exception\FontException("GPOS Lookup Type " . $Type . ", Format " . $PosFormat . " not supported.");
													}
												}
											}
										} ////////////////////////////////////////////////////////////////////////////////
										// LookupType 8: Chained Context positioning 	Position one or more glyphs in chained context
										////////////////////////////////////////////////////////////////////////////////
										else {
											if ($Lookup[$luli]['Type'] == 8) {
												$html .= '<div class="lookuptype">LookupType 8: Chained Context positioning [Format ' . $PosFormat . ']</div>';
												//===========
												// Format 1:
												//===========
												if ($PosFormat == 1) {
													throw new \Mpdf\Exception\FontException("GPOS Lookup Type " . $Type . " Format " . $PosFormat . " not TESTED YET.");
												} //===========
												// Format 2:
												//===========
												else {
													if ($PosFormat == 2) {
														$html .= '<div>GPOS Lookup Type 8: Format 2 not yet supported in OTL dump</div>';
														continue;
														/* NB When developing - cf. GSUB 6.2 */
														throw new \Mpdf\Exception\FontException("GPOS Lookup Type " . $Type . " Format " . $PosFormat . " not TESTED YET.");
													} //===========
													// Format 3:
													//===========
													else {
														if ($PosFormat == 3) {
															$BacktrackGlyphCount = $this->reader->readUInt16();
															$CoverageBacktrackOffset = [];
															for ($b = 0; $b < $BacktrackGlyphCount; $b++) {
																$CoverageBacktrackOffset[] = $subtable_offset + $this->reader->readUInt16(); // in glyph sequence order
															}
															$InputGlyphCount = $this->reader->readUInt16();
															$CoverageInputOffset = [];
															for ($b = 0; $b < $InputGlyphCount; $b++) {
																$CoverageInputOffset[] = $subtable_offset + $this->reader->readUInt16(); // in glyph sequence order
															}
															$LookaheadGlyphCount = $this->reader->readUInt16();
															$CoverageLookaheadOffset = [];
															for ($b = 0; $b < $LookaheadGlyphCount; $b++) {
																$CoverageLookaheadOffset[] = $subtable_offset + $this->reader->readUInt16(); // in glyph sequence order
															}
															$PosCount = $this->reader->readUInt16();

															$PosLookupRecord = [];
															for ($p = 0; $p < $PosCount; $p++) {
																// PosLookupRecord
																$PosLookupRecord[$p]['SequenceIndex'] = $this->reader->readUInt16();
																$PosLookupRecord[$p]['LookupListIndex'] = $this->reader->readUInt16();
															}

															$backtrackGlyphs = [];
															for ($b = 0; $b < $BacktrackGlyphCount; $b++) {
																$this->reader->seek($CoverageBacktrackOffset[$b]);
																$backtrackGlyphs[$b] = implode('|', $this->_getCoverage());
															}
															$inputGlyphs = [];
															for ($b = 0; $b < $InputGlyphCount; $b++) {
																$this->reader->seek($CoverageInputOffset[$b]);
																$inputGlyphs[$b] = implode('|', $this->_getCoverage());
															}
															$lookaheadGlyphs = [];
															for ($b = 0; $b < $LookaheadGlyphCount; $b++) {
																$this->reader->seek($CoverageLookaheadOffset[$b]);
																$lookaheadGlyphs[$b] = implode('|', $this->_getCoverage());
															}

															$exampleB = [];
															$exampleI = [];
															$exampleL = [];
															$html .= '<div class="context">CONTEXT: ';
															for ($ff = count($backtrackGlyphs) - 1; $ff >= 0; $ff--) {
																$html .= '<div>Backtrack #' . $ff . ': <span class="unicode">' . $this->formatUniStr($backtrackGlyphs[$ff]) . '</span></div>';
																$exampleB[] = $this->formatEntityFirst($backtrackGlyphs[$ff]);
															}
															for ($ff = 0; $ff < count($inputGlyphs); $ff++) {
																$html .= '<div>Input #' . $ff . ': <span class="unchanged">&nbsp;' . $this->formatEntityStr($inputGlyphs[$ff]) . '&nbsp;</span></div>';
																$exampleI[] = $this->formatEntityFirst($inputGlyphs[$ff]);
															}
															for ($ff = 0; $ff < count($lookaheadGlyphs); $ff++) {
																$html .= '<div>Lookahead #' . $ff . ': <span class="unicode">' . $this->formatUniStr($lookaheadGlyphs[$ff]) . '</span></div>';
																$exampleL[] = $this->formatEntityFirst($lookaheadGlyphs[$ff]);
															}
															$html .= '</div>';

															for ($p = 0; $p < $PosCount; $p++) {
																$lup = $PosLookupRecord[$p]['LookupListIndex'];
																$seqIndex = $PosLookupRecord[$p]['SequenceIndex'];

																// GENERATE exampleB[n] exampleI[<seqIndex] .... exampleI[>seqIndex] exampleL[n]
																$exB = '';
																$exL = '';
																if (count($exampleB)) {
																	$exB .= '<span class="backtrack">' . implode('&#x200d;', $exampleB) . '</span>';
																}

																if ($seqIndex > 0) {
																	$exB .= '<span class="inputother">';
																	for ($ip = 0; $ip < $seqIndex; $ip++) {
																		$exB .= $exampleI[$ip] . '&#x200d;';
																	}
																	$exB .= '</span>';
																}

																if (count($inputGlyphs) > ($seqIndex + 1)) {
																	$exL .= '<span class="inputother">';
																	for ($ip = $seqIndex + 1; $ip < count($inputGlyphs); $ip++) {
																		$exL .= '&#x200d;' . $exampleI[$ip];
																	}
																	$exL .= '</span>';
																}

																if (count($exampleL)) {
																	$exL .= '<span class="lookahead">' . implode('&#x200d;', $exampleL) . '</span>';
																}

																$html .= '<div class="sequenceIndex">Substitution Position: ' . $seqIndex . '</div>';

																$lul2 = [$lup => $tag];

																// Only apply if the (first) 'Replace' glyph from the
																// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
																// Pass $inputGlyphs[$seqIndex] e.g. 00636|00645|00656
																// to level 2 and only apply if first Replace glyph is in this list
																$html .= $this->_getGPOSarray($Lookup, $lul2, $scripttag, 2, $inputGlyphs[$seqIndex], $exB, $exL);
															}
														}
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
			$html .= '</div>';
		}
		if ($level == 1) {
			$this->mpdf->WriteHTML($html);
		} else {
			return $html;
		}
	}

	//=====================================================================================
	//=====================================================================================
	// GPOS FUNCTIONS
	//=====================================================================================

	function count_bits($n)
	{
		for ($c = 0; $n; $c++) {
			$n &= $n - 1; // clear the least significant bit set
		}

		return $c;
	}

	/**
	 * A link from the summary report to the detail report of one script and language system.
	 *
	 * These named font_dump_OTL.php, a spelling the file has never had, so following one 404s on any
	 * case-sensitive server; and they carried the script and language alone, losing the font the
	 * summary was of, so the detail report came back for whatever font the tool defaults to.
	 *
	 * @return string An href, with its ampersands escaped for HTML
	 */
	private function detailLink($script, $language)
	{
		$query = $this->detailReportQuery;
		$query['script'] = trim($script);
		$query['lang'] = trim($language);

		return 'font_dump_otl.php?' . htmlspecialchars(http_build_query($query), ENT_QUOTES);
	}

	/**
	 * The glyphs one class of a ClassDef holds, as the "|" separated string the report prints.
	 *
	 * Class 0 is every glyph the ClassDef does not mention, so a ClassDef never lists it and
	 * _getClasses never returns a key for it. A rule may still name it, and the report already
	 * renders an empty class as "[NOT <the other classes>]" - so that is what an unlisted class
	 * returns. Reading the key straight raised a warning per rule and then rendered the same thing
	 * from null.
	 *
	 * @see https://learn.microsoft.com/en-us/typography/opentype/spec/chapter2#class-definition-table
	 *
	 * @param array $classes class => glyphs, as _getClasses returns it
	 * @param int   $class   the class a rule names
	 *
	 * @return string
	 */
	private function classGlyphs($classes, $class)
	{
		return isset($classes[$class]) ? $classes[$class] : '';
	}

	/**
	 * The features one script and language system offers in GSUB or GPOS.
	 *
	 * A table with nothing for the script, or nothing for that language system within it, is
	 * reported and skipped rather than fatal. Fonts routinely substitute for a script without
	 * positioning it, or list a language system in one table only - 96 of the 245 script and
	 * language systems in the shipped fonts are in one table and not the other - and the half the
	 * reader asked for is in the other table. Only a script or language system that neither table
	 * carries is a mistake in the tag, and failIfNeitherTableOffers raises that once both have been
	 * asked. Before either, a missing script read straight through to a null a few lines later.
	 *
	 * @return array feature tag => list of lookup list indexes, empty if this table offers none
	 */
	private function langSys($features, $table)
	{
		if (!isset($features[$this->script])) {
			return $this->noteNotOffered(sprintf(
				'This font\'s %s table offers no script "%s". It has: %s',
				$table,
				trim($this->script),
				$features ? implode(', ', array_map('trim', array_keys($features))) : 'none'
			));
		}

		if (!isset($features[$this->script][$this->language])) {
			return $this->noteNotOffered(sprintf(
				'This font\'s %s script "%s" offers no language system "%s". It has: %s',
				$table,
				trim($this->script),
				trim($this->language),
				implode(', ', array_map('trim', array_keys($features[$this->script])))
			));
		}

		return $features[$this->script][$this->language];
	}

	/**
	 * Record, and show in the report, that one table has nothing for the script and language asked.
	 *
	 * @return array Always empty, so that the caller reports no lookups for this table
	 */
	private function noteNotOffered($message)
	{
		$this->notOffered[] = $message;
		$this->mpdf->WriteHTML('<div class="notoffered">' . $message . '</div>');

		return [];
	}

	/**
	 * Fail when neither GSUB nor GPOS carries the script and language system detail mode was asked
	 * for, naming what each table does carry. Called once both have been asked.
	 */
	private function failIfNeitherTableOffers()
	{
		if ($this->mode === 'detail' && count($this->notOffered) === 2) {
			throw new \Mpdf\MpdfException(implode("\n", $this->notOffered));
		}
	}

	/**
	 * ValueRecord, per the GPOS common table formats.
	 *
	 * A ValueFormat is a bitfield naming which of eight fields follow, in this order, so the record is
	 * only as long as the flags say. mPDF uses three of them and steps over the rest.
	 *
	 * The three it uses are always present in the returned array, zero where the font omitted them.
	 * Otl checks each with isset; the report reads all six of a pair unconditionally to decide what to
	 * print, and asking for absent keys raised tens of thousands of warnings on a single font.
	 *
	 * @see https://learn.microsoft.com/en-us/typography/opentype/spec/gpos#value-record
	 */
	function _getValueRecord($ValueFormat)
	{
		$vra = ['XPlacement' => 0, 'YPlacement' => 0, 'XAdvance' => 0];
		// Horizontal adjustment for placement-in design units
		if (($ValueFormat & 0x0001) == 0x0001) {
			$vra['XPlacement'] = $this->reader->readInt16();
		}
		// Vertical adjustment for placement-in design units
		if (($ValueFormat & 0x0002) == 0x0002) {
			$vra['YPlacement'] = $this->reader->readInt16();
		}
		// Horizontal adjustment for advance-in design units (only used for horizontal writing)
		if (($ValueFormat & 0x0004) == 0x0004) {
			$vra['XAdvance'] = $this->reader->readInt16();
		}
		// Vertical adjustment for advance-in design units (only used for vertical writing)
		if (($ValueFormat & 0x0008) == 0x0008) {
			$this->reader->readInt16();
		}
		// Offset to Device table for horizontal placement-measured from beginning of PosTable (may be NULL)
		if (($ValueFormat & 0x0010) == 0x0010) {
			$this->reader->readUInt16();
		}
		// Offset to Device table for vertical placement-measured from beginning of PosTable (may be NULL)
		if (($ValueFormat & 0x0020) == 0x0020) {
			$this->reader->readUInt16();
		}
		// Offset to Device table for horizontal advance-measured from beginning of PosTable (may be NULL)
		if (($ValueFormat & 0x0040) == 0x0040) {
			$this->reader->readUInt16();
		}
		// Offset to Device table for vertical advance-measured from beginning of PosTable (may be NULL)
		if (($ValueFormat & 0x0080) == 0x0080) {
			$this->reader->readUInt16();
		}

		return $vra;
	}

	function _getAnchorTable($offset = 0)
	{
		if ($offset) {
			$this->reader->seek($offset);
		}
		$AnchorFormat = $this->reader->readUInt16();
		$XCoordinate = $this->reader->readInt16();
		$YCoordinate = $this->reader->readInt16();

		// Format 2 specifies additional link to contour point; Format 3 additional Device table
		return [$XCoordinate, $YCoordinate];
	}

	function _getMarkRecord($offset, $MarkPos)
	{
		$this->reader->seek($offset);
		$MarkCount = $this->reader->readUInt16();
		$this->reader->skip($MarkPos * 4);
		$Class = $this->reader->readUInt16();
		$MarkAnchor = $offset + $this->reader->readUInt16();  // = Offset to anchor table
		list($x, $y) = $this->_getAnchorTable($MarkAnchor);
		$MarkRecord = ['Class' => $Class, 'AnchorX' => $x, 'AnchorY' => $y];

		return $MarkRecord;
	}

	//////////////////////////////////////////////////////////////////////////////////
	// Recursively get composite glyph data

	//////////////////////////////////////////////////////////////////////////////////
	// Recursively get composite glyphs

	//////////////////////////////////////////////////////////////////////////////////

	// CMAP Format 4

	function formatUni($char)
	{
		$x = preg_replace('/^[0]*/', '', $char);
		$x = str_pad($x, 4, '0', STR_PAD_LEFT);
		$d = hexdec($x);
		if (($d > 57343 && $d < 63744) || ($d > 122879 && $d < 126977)) {
			$id = 'M';
		} // E000 - F8FF, 1E000-1F000
		else {
			$id = 'U';
		}

		return $id . '+' . $x;
	}

	function formatEntity($char, $allowjoining = false)
	{
		$char = preg_replace('/^[0]/', '', $char);
		$x = '&#x' . $char . ';';
		if (strpos($this->GlyphClassMarks, $char) !== false) {
			if (!$allowjoining) {
				$x = '&#x25cc;' . $x;
			}
		}

		return $x;
	}

	function formatUniArr($arr)
	{
		$s = [];
		foreach ($arr as $c) {
			$x = preg_replace('/^[0]*/', '', $c);
			$d = hexdec($x);
			if (($d > 57343 && $d < 63744) || ($d > 122879 && $d < 126977)) {
				$id = 'M';
			} // E000 - F8FF, 1E000-1F000
			else {
				$id = 'U';
			}
			$s[] = $id . '+' . str_pad($x, 4, '0', STR_PAD_LEFT);
		}

		return implode(', ', $s);
	}

	function formatEntityArr($arr)
	{
		$s = [];
		foreach ($arr as $c) {
			$c = preg_replace('/^[0]/', '', $c);
			$x = '&#x' . $c . ';';
			if (strpos($this->GlyphClassMarks, $c) !== false) {
				$x = '&#x25cc;' . $x;
			}
			$s[] = $x;
		}

		return implode(' ', $s); // ZWNJ? &#x200d;
	}

	function formatClassArr($arr)
	{
		$s = [];
		foreach ($arr as $c) {
			$x = preg_replace('/^[0]*/', '', $c);
			$d = hexdec($x);
			if (($d > 57343 && $d < 63744) || ($d > 122879 && $d < 126977)) {
				$id = 'M';
			} // E000 - F8FF, 1E000-1F000
			else {
				$id = 'U';
			}
			$s[] = $id . '+' . str_pad($x, 4, '0', STR_PAD_LEFT);
		}

		return implode(', ', $s);
	}

	function formatUniStr($str)
	{
		$s = [];
		$arr = explode('|', $str);
		foreach ($arr as $c) {
			$x = preg_replace('/^[0]*/', '', $c);
			$d = hexdec($x);
			if (($d > 57343 && $d < 63744) || ($d > 122879 && $d < 126977)) {
				$id = 'M';
			} // E000 - F8FF, 1E000-1F000
			else {
				$id = 'U';
			}
			$s[] = $id . '+' . str_pad($x, 4, '0', STR_PAD_LEFT);
		}

		return implode(', ', $s);
	}

	function formatEntityStr($str)
	{
		$s = [];
		$arr = explode('|', $str);
		foreach ($arr as $c) {
			$c = preg_replace('/^[0]/', '', $c);
			$x = '&#x' . $c . ';';
			if (strpos($this->GlyphClassMarks, $c) !== false) {
				$x = '&#x25cc;' . $x;
			}
			$s[] = $x;
		}

		return implode(' ', $s); // ZWNJ? &#x200d;
	}

	function formatEntityFirst($str)
	{
		$arr = explode('|', $str);
		$char = preg_replace('/^[0]/', '', $arr[0]);
		$x = '&#x' . $char . ';';
		if (strpos($this->GlyphClassMarks, $char) !== false) {
			$x = '&#x25cc;' . $x;
		}

		return $x;
	}

}
