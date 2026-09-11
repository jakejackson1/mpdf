<?php

namespace Mpdf;

use Mpdf\Fonts\FileReader;
use Mpdf\Fonts\FontCache;
use Mpdf\Fonts\FontReader;
use Mpdf\Fonts\Table\ClassDef;
use Mpdf\Fonts\Table\Coverage;
use Mpdf\Fonts\GlyphOperator;

// NOTE*** If you change the defined constants below, be sure to delete all temporary font data files in /ttfontdata/
// to force mPDF to regenerate cached font files.
if (!defined('_OTL_OLD_SPEC_COMPAT_2')) {
	define('_OTL_OLD_SPEC_COMPAT_2', true);
}

// Define the value used in the "head" table of a created TTF file
// 0x74727565 "true" for Mac
// 0x00010000 for Windows
// Either seems to work for a font embedded in a PDF file
// when read by Adobe Reader on a Windows PC(!)
if (!defined('_TTF_MAC_HEADER')) {
	define('_TTF_MAC_HEADER', false);
}

// Recalculate correct metadata/profiles when making subset fonts (not SIP/SMP)
// e.g. xMin, xMax, maxNContours
if (!defined('_RECALC_PROFILE')) {
	define('_RECALC_PROFILE', false);
}

// mPDF 5.7.1
if (!function_exists('\Mpdf\unicode_hex')) {
	function unicode_hex($unicode_dec)
	{
		return sprintf("%05s", strtoupper(dechex($unicode_dec)));
	}
}

/**
 * TTFontFile class
 *
 * This class is based on The ReportLab Open Source PDF library
 * written in Python - http://www.reportlab.com/software/opensource/
 * together with ideas from the OpenOffice source code and others.
 * This header must be retained in any redistribution or
 * modification of the file.
 *
 * @author Ian Back <ianb@bpm1.com>
 * @license LGPL
 */
class TTFontFile
{

	use Strict;

	/**
	 * The font file, as something that can be read
	 *
	 * @var FileReader
	 */
	protected $reader;

	private $fontCache;

	private $fontDescriptor;

	var $GPOSFeatures;

	var $GPOSLookups;

	var $GPOSScriptLang;

	var $MarkAttachmentType;

	var $MarkGlyphSets;

	var $GlyphClassMarks;

	var $GlyphClassLigatures;

	var $GlyphClassBases;

	var $GlyphClassComponents;

	var $GSUBScriptLang;

	var $rtlPUAstr;

	var $fontkey;

	var $useOTL;

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

	var $lineGap;

	var $hheaascent;

	var $hheadescent;

	var $hhealineGap;

	var $advanceWidthMax;

	var $typoAscender;

	var $typoDescender;

	var $typoLineGap;

	var $usWinAscent;

	var $usWinDescent;

	var $strikeoutSize;

	var $strikeoutPosition;

	var $name;

	var $familyName;

	var $styleName;

	var $fullName;

	var $uniqueFontID;

	var $unitsPerEm;

	var $bbox;

	var $capHeight;

	var $xHeight;

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

	var $haskernGPOS;

	var $hassmallcapsGSUB;

	var $codeToGlyph;

	var $glyphdata;

	var $LuCoverage;

	public $panose;

	public $version;

	public $fontRevision;

	public $restrictedUse;

	public $glyphIDtoUni;

	public $glyphToChar;

	public $GSUBFeatures;

	public $GSUBLookups;

	public $GSLuCoverage;

	public function __construct(FontCache $fontCache, $fontDescriptor)
	{
		$this->fontCache = $fontCache;
		$this->fontDescriptor = $fontDescriptor;

		// Maximum size of glyf table to read in as string (otherwise reads each glyph from file)
		$this->maxStrLenRead = 200000;
	}

	public function getMetrics($file, $fontkey, $TTCfontID = 0, $debug = false, $BMPonly = false, $useOTL = 0)
	{
		$this->useOTL = $useOTL;
		$this->fontkey = $fontkey;
		$this->filename = $file;
		$this->reader = new FileReader($file);


		$this->charWidths = '';
		$this->glyphPos = [];
		$this->charToGlyph = [];
		$this->tables = [];
		$this->otables = [];
		$this->kerninfo = [];
		$this->haskernGPOS = [];
		$this->hassmallcapsGSUB = [];
		$this->ascent = 0;
		$this->descent = 0;
		$this->lineGap = 0;
		$this->hheaascent = 0;
		$this->hheadescent = 0;
		$this->hhealineGap = 0;
		$this->xHeight = 0;
		$this->capHeight = 0;
		$this->panose = [];
		$this->sFamilyClass = 0;
		$this->sFamilySubClass = 0;
		$this->typoAscender = 0;
		$this->typoDescender = 0;
		$this->typoLineGap = 0;
		$this->usWinAscent = 0;
		$this->usWinDescent = 0;
		$this->advanceWidthMax = 0;
		$this->strikeoutSize = 0;
		$this->strikeoutPosition = 0;
		$this->numTTCFonts = 0;
		$this->TTCFonts = [];
		$this->version = $version = $this->reader->readUInt32();
		$this->panose = [];

		if ($version === 0x4F54544F) {
			throw new \Mpdf\Exception\FontException(sprintf('Fonts with postscript outlines are not supported (%s)', $file));
		}

		if ($version === 0x74746366 && !$TTCfontID) {
			throw new \Mpdf\Exception\FontException(sprintf('TTCfontID for a TrueType Collection is not defined in mPDF "fontdata" configuration (%s)', $file));
		}

		if (!in_array($version, [0x00010000, 0x74727565], true) && !$TTCfontID) {
			throw new \Mpdf\Exception\FontException(sprintf('Not a TrueType font: version=%s)', $version));
		}

		if ($TTCfontID > 0) {
			$this->version = $version = $this->reader->readUInt32(); // TTC Header version now
			if (!in_array($version, [0x00010000, 0x00020000], true)) {
				throw new \Mpdf\Exception\FontException(sprintf('Error parsing TrueType Collection: version=%s - (%s)', $version, $file));
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

	function readTableDirectory($debug = false)
	{
		$this->numTables = $this->reader->readUInt16();
		$this->searchRange = $this->reader->readUInt16();
		$this->entrySelector = $this->reader->readUInt16();
		$this->rangeShift = $this->reader->readUInt16();
		$this->tables = [];

		for ($i = 0; $i < $this->numTables; $i++) {
			$record = [];
			$record['tag'] = $this->reader->readTag();
			$record['checksum'] = [$this->reader->readUInt16(), $this->reader->readUInt16()];
			$record['offset'] = $this->reader->readUInt32();
			$record['length'] = $this->reader->readUInt32();
			$this->tables[$record['tag']] = $record;
		}

		if ($debug) {
			$this->checksumTables();
		}
	}

	function checksumTables()
	{
		// Check the checksums for all tables
		foreach ($this->tables as $t) {
			if ($t['length'] > 0 && $t['length'] < $this->maxStrLenRead) { // 1.02
				$table = $this->reader->bytesAt($t['offset'], $t['length']);
				$checksum = $this->calcChecksum($table);
				if ($t['tag'] === 'head') {
					$up = unpack('n*', substr($table, 8, 4));
					$adjustment[0] = $up[1];
					$adjustment[1] = $up[2];
					$checksum = $this->sub32($checksum, $adjustment);
				}
				$xchecksum = $t['checksum'];
				if ($xchecksum != $checksum) {
					throw new \Mpdf\Exception\FontException(sprintf('TTF file "%s": invalid checksum %s table: %s (expected %s)', $this->filename, dechex($checksum[0]) . dechex($checksum[1]), $t['tag'], dechex($xchecksum[0]) . dechex($xchecksum[1])));
				}
			}
		}
	}

	function sub32($x, $y)
	{
		$xlo = $x[1];
		$xhi = $x[0];
		$ylo = $y[1];
		$yhi = $y[0];

		if ($ylo > $xlo) {
			$xlo += 1 << 16;
			++$yhi;
		}
		$reslo = $xlo - $ylo;
		if ($yhi > $xhi) {
			$xhi += 1 << 16;
		}
		$reshi = $xhi - $yhi;
		$reshi &= 0xFFFF;

		return [$reshi, $reslo];
	}

	function calcChecksum($data)
	{
		if (strlen($data) % 4) {
			$data .= str_repeat("\0", 4 - (strlen($data) % 4));
		}

		$len = strlen($data);
		$hi = 0x0000;
		$lo = 0x0000;

		for ($i = 0; $i < $len; $i += 4) {
			$hi += (ord($data[$i]) << 8) + ord($data[$i + 1]);
			$lo += (ord($data[$i + 2]) << 8) + ord($data[$i + 3]);
			$hi += ($lo >> 16) & 0xFFFF;
			$lo &= 0xFFFF;
		}

		$hi &= 0xFFFF;

		return [$hi, $lo];
	}

	function get_table_pos($tag)
	{
		if (!isset($this->tables[$tag])) {
			return [0, 0];
		}
		$offset = $this->tables[$tag]['offset'];
		$length = $this->tables[$tag]['length'];

		return [$offset, $length];
	}

	/**
	 * Move to a table named in the font's table directory.
	 *
	 * @return int The absolute position of the table, or of $offset_in_table bytes into it
	 */
	function seek_table($tag, $offset_in_table = 0)
	{
		list($pos, $dummy) = $this->get_table_pos($tag);
		$this->reader->seek($pos + $offset_in_table);

		return $pos + $offset_in_table;
	}

	function pack_short($val)
	{
		if ($val < 0) {
			$val = abs($val);
			$val = ~$val;
			++$val;
		}

		return pack('n', $val);
	}

	function splice($stream, $offset, $value)
	{
		return substr($stream, 0, $offset) . $value . substr($stream, $offset + strlen($value));
	}

	function _set_ushort($stream, $offset, $value)
	{
		$up = pack("n", $value);

		return $this->splice($stream, $offset, $up);
	}

	function _set_short($stream, $offset, $val)
	{
		if ($val < 0) {
			$val = abs($val);
			$val = ~$val;
			$val += 1;
		}
		$up = pack("n", $val);

		return $this->splice($stream, $offset, $up);
	}

	/**
	 * @return string One whole table from the font's table directory, or '' if it has none
	 */
	function get_table($tag)
	{
		list($pos, $length) = $this->get_table_pos($tag);

		if ($length == 0) {
			return '';
		}

		return $this->reader->bytesAt($pos, $length);
	}

	function add($tag, $data)
	{
		if ($tag === 'head') {
			$data = $this->splice($data, 8, "\0\0\0\0");
		}
		$this->otables[$tag] = $data;
	}

	function getCTG($file, $TTCfontID = 0, $debug = false, $useOTL = false)
	{
		// Only called if font is not to be used as embedded subset i.e. NOT called for SIP/SMP fonts
		$this->useOTL = $useOTL; // mPDF 5.7.1
		$this->filename = $file;
		$this->reader = new FileReader($file);


		$this->charWidths = '';
		$this->glyphPos = [];
		$this->charToGlyph = [];
		$this->tables = [];
		$this->numTTCFonts = 0;
		$this->TTCFonts = [];
		$this->reader->skip(4);

		if ($TTCfontID > 0) {
			$this->version = $version = $this->reader->readUInt32(); // TTC Header version now
			if (!in_array($version, [0x00010000, 0x00020000], true)) {
				throw new \Mpdf\Exception\FontException(sprintf("Error parsing TrueType Collection: version=%s (%s)", $version, $file));
			}
			$this->numTTCFonts = $this->reader->readUInt32();
			for ($i = 1; $i <= $this->numTTCFonts; $i++) {
				$this->TTCFonts[$i]['offset'] = $this->reader->readUInt32();
			}
			$this->reader->seek($this->TTCFonts[$TTCfontID]['offset']);
			$this->version = $version = $this->reader->readUInt32(); // TTFont version again now
		}
		$this->readTableDirectory($debug);

		// cmap - Character to glyph index mapping table
		$cmap_offset = $this->seek_table('cmap');
		$this->reader->skip(2);
		$cmapTableCount = $this->reader->readUInt16();
		$unicode_cmap_offset = 0;
		for ($i = 0; $i < $cmapTableCount; $i++) {
			$platformID = $this->reader->readUInt16();
			$encodingID = $this->reader->readUInt16();
			$offset = $this->reader->readUInt32();
			$save_pos = $this->reader->tell();
			if ($platformID == 3 && $encodingID == 1) { // Microsoft, Unicode
				$format = $this->reader->uint16At($cmap_offset + $offset);
				if ($format == 4) {
					$unicode_cmap_offset = $cmap_offset + $offset;
					break;
				}
			} elseif ($platformID == 0) { // Unicode -- assume all encodings are compatible
				$format = $this->reader->uint16At($cmap_offset + $offset);
				if ($format == 4) {
					$unicode_cmap_offset = $cmap_offset + $offset;
					break;
				}
			}
			$this->reader->seek($save_pos);
		}

		$glyphToChar = [];
		$charToGlyph = [];
		$this->getCMAP4($unicode_cmap_offset, $glyphToChar, $charToGlyph);

		// Map Unmapped glyphs - from $numGlyphs
		if ($useOTL) {
			$this->seek_table("maxp");
			$this->reader->skip(4);
			$numGlyphs = $this->reader->readUInt16();
			$bctr = 0xE000;
			for ($gid = 1; $gid < $numGlyphs; $gid++) {
				if (!isset($glyphToChar[$gid])) {
					while (isset($charToGlyph[$bctr])) {
						$bctr++;
					} // Avoid overwriting a glyph already mapped in PUA
					if ($bctr > 0xF8FF) {
						throw new \Mpdf\Exception\FontException(sprintf('Font "%s" cannot map all included glyphs into Private Use Area U+E000-U+F8FF; cannot use useOTL on this font', $file));
					}
					$glyphToChar[$gid][] = $bctr;
					$charToGlyph[$bctr] = $gid;
					$bctr++;
				}
			}
		}

		$this->reader->close();

		return $charToGlyph;
	}

	function getTTCFonts($file)
	{
		$this->filename = $file;

		$this->reader = new FileReader($file);

		$this->numTTCFonts = 0;
		$this->TTCFonts = [];
		$this->version = $version = $this->reader->readUInt32();
		if ($version === 0x74746366) {
			$this->version = $version = $this->reader->readUInt32(); // TTC Header version now
			if (!in_array($version, [0x00010000, 0x00020000], true)) {
				throw new \Mpdf\Exception\FontException(sprintf("Error parsing TrueType Collection: version=%s (%s)", $version, $file));
			}
		} else {
			throw new \Mpdf\Exception\FontException(sprintf("Not a TrueType Collection: version=%s (%s)", $version, $file));
		}

		$this->numTTCFonts = $this->reader->readUInt32();
		for ($i = 1; $i <= $this->numTTCFonts; $i++) {
			$this->TTCFonts[$i]['offset'] = $this->reader->readUInt32();
		}
	}

	function extractInfo($debug = false, $BMPonly = false, $useOTL = 0)
	{
		// Values are all set to 0 or blank at start of getMetrics
		// name - Naming table
		$name_offset = $this->seek_table("name");
		$format = $this->reader->readUInt16();
		if ($format != 0 && $format != 1) {
			throw new \Mpdf\Exception\FontException("Error loading font: Unknown name table format $format for font $this->filename");
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
					throw new \Mpdf\Exception\FontException("Error loading font: PostScript name is UTF-16BE string of odd length for font $this->filename");
				}
				$length /= 2;
				$N = '';
				while ($length > 0) {
					$char = $this->reader->readUInt16();
					$N .= (chr($char));
					$length -= 1;
				}
				$this->reader->seek($opos);
			} elseif ($platformId == 1 && $encodingId == 0 && $languageId == 0) { // Macintosh, Roman, English, PS Name
				$opos = $this->reader->tell();
				$N = $this->reader->bytesAt($string_data_offset + $offset, $length);
				$this->reader->seek($opos);
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
		} elseif ($names[4]) {
			$psName = preg_replace('/ /', '-', $names[4]);
		} elseif ($names[1]) {
			$psName = preg_replace('/ /', '-', $names[1]);
		} else {
			$psName = '';
		}

		if (!$psName) {
			throw new \Mpdf\Exception\FontException("Error loading font: Could not find PostScript font name '$this->filename'");
		}

		// CHECK IF psName valid (PadaukBook contains illegal characters in Name ID 6 i.e. Postscript Name)
		$psNameInvalid = false;
		$nameLength = strlen($psName);
		for ($i = 0; $i < $nameLength; $i++) {
			$c = $psName[$i];
			$oc = ord($c);
			if ($oc > 126 || strpos(' [](){}<>/%', $c) !== false) {
				//throw new \Mpdf\Exception\FontException("psName=".$psName." contains invalid character ".$c." ie U+".ord(c));
				$psNameInvalid = true;
				break;
			}
		}

		if ($psNameInvalid && $names[4]) {
			$psName = preg_replace('/ /', '-', $names[4]);
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

		if (!$psNameInvalid && $names[6]) {
			$this->fullName = $names[6];
		}

		// head - Font header table
		$this->seek_table('head');
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			$ver_min = $this->reader->readUInt16();
			if ($ver_maj != 1) {
				throw new \Mpdf\Exception\FontException('Error loading font: Unknown head table version ' . $ver_maj . '.' . $ver_min);
			}
			$this->fontRevision = $this->reader->readUInt16() . $this->reader->readUInt16();

			$this->reader->skip(4); // checksumAdjustment
			$magic = $this->reader->readUInt32();
			if ($magic !== 0x5F0F3CF5) {
				throw new \Mpdf\Exception\FontException('Error loading font: Invalid head table magic ' . $magic);
			}
			$this->reader->skip(2); // flags
		} else {
			$this->reader->skip(18);
		}
		$this->unitsPerEm = $unitsPerEm = $this->reader->readUInt16();
		$scale = 1000 / $unitsPerEm;
		$this->reader->skip(16); // created and modified, each 8 bytes
		$xMin = $this->reader->readInt16();
		$yMin = $this->reader->readInt16();
		$xMax = $this->reader->readInt16();
		$yMax = $this->reader->readInt16();
		$this->bbox = [($xMin * $scale), ($yMin * $scale), ($xMax * $scale), ($yMax * $scale)];

		$this->reader->skip(3 * 2); // macStyle, lowestRecPPEM, fontDirectionHint
		$indexToLocFormat = $this->reader->readInt16();
		$glyphDataFormat = $this->reader->readInt16();
		if ($glyphDataFormat != 0) {
			throw new \Mpdf\Exception\FontException(sprintf('Error loading font: Unknown glyph data format %s', $glyphDataFormat));
		}

		// hhea metrics table
		if (isset($this->tables["hhea"])) {
			$this->seek_table("hhea");
			$this->reader->skip(4); // majorVersion, minorVersion each ushort
			$hheaAscender = $this->reader->readInt16();
			$hheaDescender = $this->reader->readInt16();
			$hheaLineGap = $this->reader->readInt16();
			$hheaAdvanceWidthMax = $this->reader->readUInt16();
			$this->hheaascent = ($hheaAscender * $scale);
			$this->hheadescent = ($hheaDescender * $scale);
			$this->hhealineGap = ($hheaLineGap * $scale);
			$this->advanceWidthMax = ($hheaAdvanceWidthMax * $scale);
		}

		// OS/2 - OS/2 and Windows metrics table
		$use_typo_metrics = false;
		if (isset($this->tables["OS/2"])) {
			$this->seek_table("OS/2");
			$version = $this->reader->readUInt16();
			$this->reader->skip(2); // xAvgCharWidth
			$usWeightClass = $this->reader->readUInt16();
			$this->reader->skip(2); // usWidthClass
			$fsType = $this->reader->readUInt16();
			if ($fsType == 0x0002 || ($fsType & 0x0300) != 0) {
				$this->restrictedUse = true;
			}

			$this->reader->skip(16); // ySubscript and ySuperscript, 2 x 4 x short
			$yStrikeoutSize = $this->reader->readInt16();
			$yStrikeoutPosition = $this->reader->readInt16();
			$this->strikeoutSize = ($yStrikeoutSize * $scale);
			$this->strikeoutPosition = ($yStrikeoutPosition * $scale);

			$sF = $this->reader->readInt16();
			$this->sFamilyClass = ($sF >> 8);
			$this->sFamilySubClass = ($sF & 0xFF);
			// PANOSE, 10 bytes, per the OS/2 table
			$panose = $this->reader->read(10);
			$this->panose = [];
			$panoseLenght = strlen($panose);
			for ($p = 0; $p < $panoseLenght; $p++) {
				$this->panose[] = ord($panose[$p]);
			}

			$this->reader->skip(20); // ulUnicodeRange1-4, achVendID
			$fsSelection = $this->reader->readUInt16();
			$use_typo_metrics = (($fsSelection & 0x80) === 0x80); // bit#7 = USE_TYPO_METRICS
			$this->reader->skip(4); // first/lastCharIndex

			$sTypoAscender = $this->reader->readInt16();
			$sTypoDescender = $this->reader->readInt16();
			$sTypoLineGap = $this->reader->readInt16();

			if ($sTypoAscender) {
				$this->typoAscender = ($sTypoAscender * $scale);
			}
			if ($sTypoDescender) {
				$this->typoDescender = ($sTypoDescender * $scale);
			}
			if ($sTypoLineGap) {
				$this->typoLineGap = ($sTypoLineGap * $scale);
			}

			$usWinAscent = $this->reader->readUInt16();
			$usWinDescent = $this->reader->readUInt16();
			if ($usWinAscent) {
				$this->usWinAscent = ($usWinAscent * $scale);
			}
			if ($usWinDescent) {
				$this->usWinDescent = ($usWinDescent * $scale);
			}

			if ($version > 1) {
				$this->reader->skip(8); // ulCodePageRange1/2
				$sxHeight = $this->reader->readInt16();
				$this->xHeight = ($sxHeight * $scale);
				$sCapHeight = $this->reader->readInt16();
				$this->capHeight = ($sCapHeight * $scale);
			}
		} else {
			$usWeightClass = 400;
		}
		$this->stemV = 50 + (int) (($usWeightClass / 65.0) ** 2);

		// FONT DESCRIPTOR METRICS
		if ($this->fontDescriptor === 'winTypo') {
			$this->ascent = $this->typoAscender;
			$this->descent = $this->typoDescender;
			$this->lineGap = $this->typoLineGap;
		} elseif ($this->fontDescriptor === 'mac') {
			$this->ascent = $this->hheaascent;
			$this->descent = $this->hheadescent;
			$this->lineGap = $this->hhealineGap;
		} else { // $this->fontDescriptor === 'win'
			$this->ascent = $this->usWinAscent;
			$this->descent = -$this->usWinDescent;
			$this->lineGap = 0;

			// Special case - if either the winAscent or winDescent are greater than the
			// font bounding box yMin yMax, then reduce them accordingly.
			// This works with Myanmar Text (Windows 8 version) to give a
			// line-height normal that is equivalent to that produced in browsers.
			// Also Khmer OS = compatible with MSWord, Wordpad and browser.
			if ($this->ascent > $this->bbox[3]) {
				$this->ascent = $this->bbox[3];
			}

			if ($this->descent < $this->bbox[1]) {
				$this->descent = $this->bbox[1];
			}

			// Override case - if the USE_TYPO_METRICS bit is set on OS/2 fsSelection
			// this is telling the font to use the sTypo values and not the usWinAscent values.
			// This works as a fix with Cambria Math to give a normal line-height;
			// at present, this is the only font I have found with this bit set;
			// although note that MS WordPad and windows FF browser uses the big line-height from winAscent
			// but Word 2007 get it right
			if ($use_typo_metrics && $this->typoAscender) {
				$this->ascent = $this->typoAscender;
				$this->descent = $this->typoDescender;
				$this->lineGap = $this->typoLineGap;
			}
		}

		// post - PostScript table
		$this->seek_table('post');
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			if ($ver_maj < 1 || $ver_maj > 4) {
				throw new \Mpdf\Exception\FontException(sprintf('Error loading font: Unknown post table version %s', $ver_maj));
			}
			$this->reader->skip(2); // minor version
		} else {
			$this->reader->skip(4);
		}

		$this->italicAngle = $this->reader->readInt16() + $this->reader->readUInt16() / 65536.0;
		$this->underlinePosition = $this->reader->readInt16() * $scale;
		$this->underlineThickness = $this->reader->readInt16() * $scale;
		$isFixedPitch = $this->reader->readUInt32();

		$this->flags = 4;

		if ($this->italicAngle != 0) {
			$this->flags |= 64;
		}
		if ($usWeightClass >= 600) {
			$this->flags |= 262144;
		}
		if ($isFixedPitch) {
			$this->flags |= 1;
		}

		// hhea - Horizontal header table
		$this->seek_table('hhea');
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			if ($ver_maj != 1) {
				throw new \Mpdf\Exception\FontException(sprintf('Error loading font: Unknown hhea table version %s', $ver_maj));
			}
			$this->reader->skip(30); // minorVersion through the four reserved shorts - metricDataFormat sits 32 bytes in
		} else {
			$this->reader->skip(32);
		}

		$metricDataFormat = $this->reader->readInt16();

		if ($metricDataFormat != 0) {
			throw new \Mpdf\Exception\FontException(sprintf('Error loading font: Unknown horizontal metric data format "%s"', $metricDataFormat));
		}

		$numberOfHMetrics = $this->reader->readUInt16();

		if ($numberOfHMetrics == 0) {
			throw new \Mpdf\Exception\FontException('Error loading font: Number of horizontal metrics is 0');
		}

		// maxp - Maximum profile table
		$this->seek_table('maxp');
		if ($debug) {
			$ver_maj = $this->reader->readUInt16();
			if ($ver_maj != 1) {
				throw new \Mpdf\Exception\FontException(sprintf('Error loading font: Unknown maxp table version %s', $ver_maj));
			}
			$this->reader->skip(2); // minor version
		} else {
			$this->reader->skip(4);
		}
		$numGlyphs = $this->reader->readUInt16();

		// cmap - Character to glyph index mapping table
		$cmap_offset = $this->seek_table('cmap');
		$this->reader->skip(2); // version
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
			} elseif ((($platformID == 3 && $encodingID == 10) || $platformID == 0) && !$BMPonly) { // Microsoft, Unicode Format 12 table HKCS
				$format = $this->reader->uint16At($cmap_offset + $offset);
				if ($format == 12) {
					$unicode_cmap_offset = $cmap_offset + $offset;
					break;
				}
			}
			$this->reader->seek($save_pos);
		}

		if (!$unicode_cmap_offset) {
			throw new \Mpdf\Exception\FontException(sprintf('Font "%s" does not have cmap for Unicode (platform 3, encoding 1, format 4, or platform 0, any encoding, format 4)', $this->filename));
		}

		$sipset = false;
		$smpset = false;

		$this->rtlPUAstr = '';
		$this->GSUBScriptLang = [];
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
			$this->reader->skip(4); // language

			$nGroups = $this->reader->readUInt32();

			$glyphToChar = [];
			$charToGlyph = [];
			for ($i = 0; $i < $nGroups; $i++) {
				$startCharCode = $this->reader->readUInt32();
				$endCharCode = $this->reader->readUInt32();
				$startGlyphCode = $this->reader->readUInt32();
				// ZZZ98
				if ($endCharCode > 0x20000 && $endCharCode < 0x2FFFF) {
					$sipset = true;
				} elseif ($endCharCode > 0x10000 && $endCharCode < 0x1FFFF) {
					$smpset = true;
				}
				$offset = 0;
				for ($unichar = $startCharCode; $unichar <= $endCharCode; $unichar++) {
					$glyph = $startGlyphCode + $offset;
					$offset++;
					// ZZZ98
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

		// Map Unmapped glyphs (or glyphs mapped to upper PUA U+F00000 onwards i.e. > U+2FFFF) - from $numGlyphs
		if ($this->useOTL) {

			$bctr = 0xE000;

			for ($gid = 1; $gid < $numGlyphs; $gid++) {

				if (!isset($glyphToChar[$gid])) {

					while (isset($charToGlyph[$bctr])) {
						$bctr++;
					}

					// Avoid overwriting a glyph already mapped in PUA
					// ZZZ98
					if (($bctr > 0xF8FF) && ($bctr < 0x2CEB0)) {
						if (!$BMPonly) {
							$bctr = 0x2CEB0; // Use unassigned area 0x2CEB0 to 0x2F7FF (space for 10,000 characters)
							$this->sipset = $sipset = true; // forces subsetting; also ensure charwidths are saved
							while (isset($charToGlyph[$bctr])) {
								$bctr++;
							}
						} else {
							throw new \Mpdf\Exception\FontException(sprintf('The font "%s" does not have enough space to map all (unmapped) included glyphs into Private Use Area U+E000-U+F8FF', $names[1]));
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

		$this->GSUBScriptLang = [];
		$this->rtlPUAstr = '';
		if ($useOTL) {
			$this->_getGDEFtables();
			list($this->GSUBScriptLang, $this->GSUBFeatures, $this->GSUBLookups, $this->rtlPUAstr) = $this->_getGSUBtables();
			list($this->GPOSScriptLang, $this->GPOSFeatures, $this->GPOSLookups) = $this->_getGPOStables();
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

		// if xHeight and/or CapHeight are not available from OS/2 (e.g. eraly versions)
		// Calculate from yMax of 'x' or 'H' Glyphs...
		if ($this->xHeight == 0) {
			if (isset($charToGlyph[0x78])) {
				$gidx = $charToGlyph[0x78]; // U+0078 (LATIN SMALL LETTER X)
				$start = $this->seek_table('loca');
				if ($indexToLocFormat == 0) {
					$this->reader->skip($gidx * 2);
					$locax = $this->reader->readUInt16() * 2;
				} elseif ($indexToLocFormat == 1) {
					$this->reader->skip($gidx * 4);
					$locax = $this->reader->readUInt32();
				}
				$start = $this->seek_table('glyf');
				$this->reader->skip($locax);
				$this->reader->skip(8);
				$yMaxx = $this->reader->readInt16();
				$this->xHeight = $yMaxx * $scale;
			}
		}

		if ($this->capHeight == 0) {
			if (isset($charToGlyph[0x48])) {
				$gidH = $charToGlyph[0x48]; // U+0048 (LATIN CAPITAL LETTER H)
				$start = $this->seek_table('loca');
				if ($indexToLocFormat == 0) {
					$this->reader->skip($gidH * 2);
					$locaH = $this->reader->readUInt16() * 2;
				} elseif ($indexToLocFormat == 1) {
					$this->reader->skip($gidH * 4);
					$locaH = $this->reader->readUInt32();
				}
				$start = $this->seek_table('glyf');
				$this->reader->skip($locaH);
				$this->reader->skip(8);
				$yMaxH = $this->reader->readInt16();
				$this->capHeight = $yMaxH * $scale;
			} else {
				$this->capHeight = $this->ascent;
			}
			// final default is to set it = to Ascent
		}

		// hmtx - Horizontal metrics table
		$this->getHMTX($numberOfHMetrics, $numGlyphs, $glyphToChar, $scale);

		// kern - Kerning pair table
		// Recognises old form of Kerning table - as required by Windows - Format 0 only
		$kern_offset = $this->seek_table("kern");
		$version = $this->reader->readUInt16();
		$nTables = $this->reader->readUInt16();

		// subtable header
		$sversion = $this->reader->readUInt16();
		$slength = $this->reader->readUInt16();
		$scoverage = $this->reader->readUInt16();
		$format = $scoverage >> 8;
		if ($kern_offset && $version == 0 && $format == 0) {
			// Format 0
			$nPairs = $this->reader->readUInt16();
			$this->reader->skip(6);
			for ($i = 0; $i < $nPairs; $i++) {
				$left = $this->reader->readUInt16();
				$right = $this->reader->readUInt16();
				$val = $this->reader->readInt16();
				if (isset($glyphToChar[$left]) && count($glyphToChar[$left]) == 1 && isset($glyphToChar[$right]) && count($glyphToChar[$right]) == 1) {
					if ($left != 32 && $right != 32) {
						$this->kerninfo[$glyphToChar[$left][0]][$glyphToChar[$right][0]] = intval($val * $scale);
					}
				}
			}
		}
	}

	function _getGDEFtables()
	{
		// https://learn.microsoft.com/en-us/typography/opentype/spec/gdef
		if (isset($this->tables["GDEF"])) {
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
			if ($GlyphClassDef_offset) {

				$this->reader->seek($gdef_offset + $GlyphClassDef_offset);
				// 1 Base glyph (single character, spacing glyph)
				// 2 Ligature glyph (multiple character, spacing glyph)
				// 3 Mark glyph (non-spacing combining glyph)
				// 4 Component glyph (part of single character, spacing glyph)
				$GlyphByClass = $this->_getClassDefinitionTable();
			} else {
				$GlyphByClass = [];
			}

			if (isset($GlyphByClass[1]) && count($GlyphByClass[1]) > 0) {
				$this->GlyphClassBases = ' ' . implode('| ', $GlyphByClass[1]);
			} else {
				$this->GlyphClassBases = '';
			}
			if (isset($GlyphByClass[2]) && count($GlyphByClass[2]) > 0) {
				$this->GlyphClassLigatures = ' ' . implode('| ', $GlyphByClass[2]);
			} else {
				$this->GlyphClassLigatures = '';
			}
			if (isset($GlyphByClass[3]) && count($GlyphByClass[3]) > 0) {
				$this->GlyphClassMarks = ' ' . implode('| ', $GlyphByClass[3]);
			} else {
				$this->GlyphClassMarks = '';
			}
			if (isset($GlyphByClass[4]) && count($GlyphByClass[4]) > 0) {
				$this->GlyphClassComponents = ' ' . implode('| ', $GlyphByClass[4]);
			} else {
				$this->GlyphClassComponents = '';
			}

			if (isset($GlyphByClass[3]) && count($GlyphByClass[3]) > 0) {
				$Marks = $GlyphByClass[3];
			} else { // to use for MarkAttachmentType
				$Marks = [];
			}

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
				$this->reader->seek($gdef_offset + $MarkAttachClassDef_offset);
				$MarkAttachmentTypes = $this->_getClassDefinitionTable();
				foreach ($MarkAttachmentTypes as $class => $glyphs) {
					if (is_array($Marks) && count($Marks)) {
						$mat = array_diff($Marks, $MarkAttachmentTypes[$class]);
						sort($mat, SORT_STRING);
					} else {
						$mat = [];
					}

					$this->MarkAttachmentType[$class] = ' ' . implode('| ', $mat);
				}
			} else {
				$this->MarkAttachmentType = [];
			}

			// MarkGlyphSets in Version 0x00010002 of GDEF and later
			if ($ver_min >= 2 && $MarkGlyphSetsDef_offset) {
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
					$this->MarkGlyphSets[$i] = ' ' . implode('| ', $glyphs);
				}
			} else {
				$this->MarkGlyphSets = [];
			}
		} else {
			throw new \Mpdf\Exception\FontException(sprintf('Unable to set font "%s" to use OTL as it does not include OTL tables (or at least not a GDEF table).', $this->filename));
		}

		// The shaper reads GSUB and GPOS from here rather than from the font, so each is cached whole,
		// one file per table. They used to share one file, GSUB first, which is the only reason a GPOS
		// offset ever had to have the length of GSUB added to it.
		foreach (['GSUB', 'GPOS'] as $tag) {
			if (!isset($this->tables[$tag])) {
				continue;
			}

			$this->seek_table($tag);
			$this->fontCache->write($this->fontkey . '.' . $tag . '.dat', $this->reader->read($this->tables[$tag]['length']));
		}

		$font = [
			'GlyphClassBases' => $this->GlyphClassBases,
			'GlyphClassMarks' => $this->GlyphClassMarks,
			'GlyphClassLigatures' => $this->GlyphClassLigatures,
			'GlyphClassComponents' => $this->GlyphClassComponents,
			'MarkGlyphSets' => $this->MarkGlyphSets,
			'MarkAttachmentType' => $this->MarkAttachmentType,
		];

		$this->fontCache->jsonWrite($this->fontkey . '.GDEFdata.json', $font);
	}

	/**
	 * A Class Definition table as a list of hex strings per class, for GDEF's glyph and mark
	 * attachment classes and for GPOS pair positioning.
	 *
	 * @see https://learn.microsoft.com/en-us/typography/opentype/spec/chapter2#class-definition-table
	 *
	 * @param int $offset Seek here first. GDEF's two class definitions are read where the caller
	 *                    already is; a GPOS PairPos subtable names its two by offset.
	 */
	function _getClassDefinitionTable($offset = 0)
	{
		if ($offset > 0) {
			$this->reader->seek($offset);
		}

		$GlyphByClass = [];

		foreach (ClassDef::pairs($this->reader) as $pair) {
			list($glyphID, $class) = $pair;

			// Several fonts (dejavu..., FreeSerif) carry a MarkAttachClassDef Format 1 with startGlyphID
			// 0 and glyphCount 1, which does not seem to mean anything useful, and FreeSerif has no
			// glyphToChar[0] to go with it
			if (isset($this->glyphToChar[$glyphID][0])) {
				$GlyphByClass[$class][] = unicode_hex($this->glyphToChar[$glyphID][0]);
			}
		}

		foreach ($GlyphByClass as $class => $glyphs) {
			sort($GlyphByClass[$class], SORT_STRING); // easier to read in development; order is not significant
		}

		ksort($GlyphByClass);

		return $GlyphByClass;
	}

	/**
	 * GSUB - Glyph Substitution
	 */
	function _getGSUBtables()
	{
		if (!isset($this->tables['GSUB'])) {
			return [[], [], [], ''];
		}

		$ffeats = [];
		$gsub_offset = $this->seek_table('GSUB');
		$this->reader->skip(4); // major minor version
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
			$tag = $this->reader->readTag();
			if ($tag == 'smcp') {
				$this->hassmallcapsGSUB = true;
			}
			$Feature[$i] = ['tag' => $tag];
			$Feature[$i]['offset'] = $FeatureList_offset + $this->reader->readUInt16();
		}

		for ($i = 0; $i < $FeatureCount; $i++) {
			$this->reader->seek($Feature[$i]['offset']);
			$this->reader->readUInt16(); // null [FeatureParams]
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
				// Offset16 from the start of this Lookup table. Stored relative to the start of GSUB,
				// because the shaper reads a copy of GSUB alone and has no idea where in the file it
				// came from - it used to subtract the table's base back off at all twelve call sites.
				$GSLookup[$i]['Subtables'][$c] = $Offsets[$i] + $this->reader->readUInt16() - $gsub_offset;
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
					$this->reader->seek($gsub_offset + $GSLookup[$i]['Subtables'][$c]);
					$ExtensionPosFormat = $this->reader->readUInt16();
					$type = $this->reader->readUInt16();
					// Offset32 from the start of the extension subtable, so adding it to a GSUB-relative
					// offset leaves it GSUB-relative
					$ext_offset = $this->reader->readUInt32();
					$GSLookup[$i]['Subtables'][$c] = $GSLookup[$i]['Subtables'][$c] + $ext_offset;
				}
				$GSLookup[$i]['Type'] = $type;
			}
		}

		// Process Whole LookupList - Get LuCoverage = Lookup coverage just for first glyph
		$this->GSLuCoverage = [];
		for ($i = 0; $i < $LookupCount; $i++) {
			for ($c = 0; $c < $GSLookup[$i]['SubtableCount']; $c++) {
				$subtable_offset = $gsub_offset + $GSLookup[$i]['Subtables'][$c];
				$this->reader->seek($subtable_offset);
				$PosFormat = $this->reader->readUInt16();

				if ($GSLookup[$i]['Type'] == 5 && $PosFormat == 3) {
					$this->reader->skip(4); // glyphCount + seqLookupCount
				} elseif ($GSLookup[$i]['Type'] == 6 && $PosFormat == 3) {
					$BacktrackGlyphCount = $this->reader->readUInt16();
					$this->reader->skip(2 * $BacktrackGlyphCount + 2); // backtrackCoverageOffsets + inputGlyphCount
				}

				// Reading position 0's Coverage is the whole of what the gate needs. The shaper offers a
				// subtable the glyph it is standing on and asks whether a match could start there, and every
				// format puts the Coverage of the first input position right here: straight after the format
				// for types 1 to 4 and 8 and for formats 1 and 2 of types 5 and 6, and after the counts and
				// backtrack offsets stepped over above for format 3. Otl::checkContextMatchMultiple starts
				// its input loop at 1 for the same reason - position 0 is what got it called.
				$Coverage = $subtable_offset + $this->reader->readUInt16();
				$this->reader->seek($Coverage);
				$glyphs = $this->_getCoverage(false, 2);
				$this->GSLuCoverage[$i][$c] = $glyphs;
			}
		}

		// $this->GSLuCoverage and $GSLookup
		$this->fontCache->jsonWrite($this->fontkey . '.GSUBdata.json', $this->GSLuCoverage);

		// Now repeats as original to get Substitution rules
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
					} elseif ($SubstFormat == 2) { // Specified output glyph indices
						$GlyphCount = $this->reader->readUInt16();
						for ($g = 0; $g < $GlyphCount; $g++) {
							$Lookup[$i]['Subtable'][$c]['Glyphs'][] = $this->reader->readUInt16();
						}
					}
				} // LookupType 2: Multiple Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 2) {
					$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
					$Lookup[$i]['Subtable'][$c]['SequenceCount'] = $SequenceCount = $this->reader->readUInt16();
					for ($s = 0; $s < $SequenceCount; $s++) {
						$Lookup[$i]['Subtable'][$c]['Sequences'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
					}
					for ($s = 0; $s < $SequenceCount; $s++) {
						// Sequence Tables
						$this->reader->seek($Lookup[$i]['Subtable'][$c]['Sequences'][$s]['Offset']);
						$Lookup[$i]['Subtable'][$c]['Sequences'][$s]['GlyphCount'] = $this->reader->readUInt16();
						for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['Sequences'][$s]['GlyphCount']; $g++) {
							$Lookup[$i]['Subtable'][$c]['Sequences'][$s]['SubstituteGlyphID'][] = $this->reader->readUInt16();
						}
					}
				} // LookupType 3: Alternate Forms
				elseif ($Lookup[$i]['Type'] == 3) {
					$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
					$Lookup[$i]['Subtable'][$c]['AlternateSetCount'] = $AlternateSetCount = $this->reader->readUInt16();
					for ($s = 0; $s < $AlternateSetCount; $s++) {
						$Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
					}

					for ($s = 0; $s < $AlternateSetCount; $s++) {
						// AlternateSet Tables
						$this->reader->seek($Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['Offset']);
						$Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['GlyphCount'] = $this->reader->readUInt16();
						for ($g = 0; $g < $Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['GlyphCount']; $g++) {
							$Lookup[$i]['Subtable'][$c]['AlternateSets'][$s]['SubstituteGlyphID'][] = $this->reader->readUInt16();
						}
					}
				} // LookupType 4: Ligature Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 4) {
					$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
					$Lookup[$i]['Subtable'][$c]['LigSetCount'] = $LigSetCount = $this->reader->readUInt16();
					for ($s = 0; $s < $LigSetCount; $s++) {
						$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
					}
					for ($s = 0; $s < $LigSetCount; $s++) {
						// LigatureSet Tables
						$this->reader->seek($Lookup[$i]['Subtable'][$c]['LigSet'][$s]['Offset']);
						$Lookup[$i]['Subtable'][$c]['LigSet'][$s]['LigCount'] = $this->reader->readUInt16();
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
				elseif ($Lookup[$i]['Type'] == 5) {
					// Format 1: Context Substitution
					if ($SubstFormat == 1) {
						$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
						$Lookup[$i]['Subtable'][$c]['SubRuleSetCount'] = $SubRuleSetCount = $this->reader->readUInt16();
						for ($s = 0; $s < $SubRuleSetCount; $s++) {
							$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['Offset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
						}
						for ($s = 0; $s < $SubRuleSetCount; $s++) {
							// SubRuleSet Tables
							$this->reader->seek($Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['Offset']);
							$Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRuleCount'] = $this->reader->readUInt16();
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
					elseif ($SubstFormat == 2) {
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
					} // Format 3: Coverage-based Context Glyph Substitution
					elseif ($SubstFormat == 3) {
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
				} // LookupType 6: Chaining Contextual Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 6) {
					// Format 1: Simple Chaining Context Glyph Substitution  p255
					if ($SubstFormat == 1) {
						$Lookup[$i]['Subtable'][$c]['CoverageTableOffset'] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
						$Lookup[$i]['Subtable'][$c]['ChainSubRuleSetCount'] = $this->reader->readUInt16();
						for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['ChainSubRuleSetCount']; $b++) {
							$Lookup[$i]['Subtable'][$c]['ChainSubRuleSetOffset'][] = $Lookup[$i]['Subtable'][$c]['Offset'] + $this->reader->readUInt16();
						}
					} // Format 2: Class-based Chaining Context Glyph Substitution  p257
					elseif ($SubstFormat == 2) {
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
					elseif ($SubstFormat == 3) {
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
							// Substitution Lookup Record
							// All contextual substitution subtables specify the substitution data in a Substitution Lookup Record
							// (SubstLookupRecord). Each record contains a SequenceIndex, which indicates the position where the substitution
							// will occur in the glyph sequence. In addition, a LookupListIndex identifies the lookup to be applied at the
							// glyph position specified by the SequenceIndex.
						}
					}
				} // LookupType 8: Reverse Chaining Contextual Single Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 8) {
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
					throw new \Mpdf\Exception\FontException(sprintf('Lookup Type "%s" not supported.', $Lookup[$i]['Type']));
				}
			}
		}

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
				elseif ($Lookup[$i]['Type'] == 2) {
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
				elseif ($Lookup[$i]['Type'] == 3) {
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
						$gid = $Lookup[$i]['Subtable'][$c]['AlternateSets'][$g]['SubstituteGlyphID'][0];
						if (!isset($this->glyphToChar[$gid][0])) {
							continue;
						}
						$substitute[] = unicode_hex($this->glyphToChar[$gid][0]);
						$Lookup[$i]['Subtable'][$c]['subs'][] = ['Replace' => $replace, 'substitute' => $substitute];
					}
				} // LookupType 4: Ligature Substitution Subtable n => 1
				elseif ($Lookup[$i]['Type'] == 4) {
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
				elseif ($Lookup[$i]['Type'] == 5) {
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
					elseif ($SubstFormat == 2) {
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
							if ($Lookup[$i]['Subtable'][$c]['SubClassSetOffset'][$s] > 0) {
								$SubClassRuleCnt = $Lookup[$i]['Subtable'][$c]['SubClassSet'][$s]['SubClassRuleCnt'];
								for ($b = 0; $b < $SubClassRuleCnt; $b++) {
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
					elseif ($SubstFormat == 3) {
						for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['InputGlyphCount']; $b++) {
							$this->reader->seek($Lookup[$i]['Subtable'][$c]['CoverageInput'][$b]);
							$glyphs = $this->_getCoverage();
							$Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'][] = implode("|", $glyphs);
						}
					}
				} // LookupType 6: Chaining Contextual Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 6) {
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
					elseif ($SubstFormat == 2) {
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
							if (isset($Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s]['ChainSubClassRuleCnt'])) {
								$ChainSubClassRuleCnt = $Lookup[$i]['Subtable'][$c]['ChainSubClassSet'][$s]['ChainSubClassRuleCnt'];
							} else {
								$ChainSubClassRuleCnt = 0;
							}
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
					elseif ($SubstFormat == 3) {
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
				} // LookupType 8: Reverse Chaining Contextual Single Substitution 1 => 1
				elseif ($Lookup[$i]['Type'] == 8) {
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

		$GSUBScriptLang = [];
		$rtlpua = []; // All glyphs added to PUA [for magic_reverse]
		foreach ($gsub as $st => $scripts) {
			foreach ($scripts as $t => $langsys) {
				$lul = []; // array of LookupListIndexes
				$tags = []; // corresponding array of feature tags e.g. 'ccmp'

				foreach ($langsys as $tag => $ft) {
					foreach ($ft as $ll) {
						$lul[$ll] = $tag;
					}
				}
				ksort($lul); // Order the Lookups in the order they are in the GUSB table, regardless of Feature order
				$volt = $this->_getGSUBarray($Lookup, $lul, $st);

				// Interrogate $volt
				// isol, fin, medi, init(arab syrc) into $rtlSUB for use in ArabJoin
				// but also identify all RTL chars in PUA for magic_reverse (arab syrc hebr thaa nko  samr)
				// identify reph, matras, vatu, half forms etc for Indic for final re-ordering
				$rtl = [];
				$rtlSUB = [];
				$finals = '';

				if (strpos('arab syrc hebr thaa nko  samr', $st) !== false) { // all RTL scripts [any/all languages] ? Mandaic

					foreach ($volt as $v) {
						// isol fina fin2 fin3 medi med2 for Syriac
						// ISOLATED FORM :: FINAL :: INITIAL :: MEDIAL :: MED2 :: FIN2 :: FIN3
						if (strpos('isol fina init medi fin2 fin3 med2', $v['tag']) !== false) {

							$key = $v['match'];
							$key = preg_replace('/[\(\)]*/', '', $key);
							$sub = $v['replace'];
							if ($v['tag'] === 'isol') {
								$kk = 0;
							} elseif ($v['tag'] === 'fina') {
								$kk = 1;
							} elseif ($v['tag'] === 'init') {
								$kk = 2;
							} elseif ($v['tag'] === 'medi') {
								$kk = 3;
							} elseif ($v['tag'] === 'med2') {
								$kk = 4;
							} elseif ($v['tag'] === 'fin2') {
								$kk = 5;
							} elseif ($v['tag'] === 'fin3') {
								$kk = 6;
							}

							$rtl[$key][$kk] = $sub;
							if (isset($v['prel']) && count($v['prel'])) {
								$rtl[$key]['prel'][$kk] = $v['prel'];
							}
							if (isset($v['postl']) && count($v['postl'])) {
								$rtl[$key]['postl'][$kk] = $v['postl'];
							}
							if (isset($v['ignore']) && $v['ignore']) {
								$rtl[$key]['ignore'][$kk] = $v['ignore'];
							}
							$rtlpua[] = $sub;

						} else { // Add any other glyphs which are in PUA
							if (isset($v['context']) && $v['context']) {
								foreach ($v['rules'] as $vs) {
									$matchCount = count($vs['match']);
									for ($i = 0; $i < $matchCount; $i++) {
										if (isset($vs['replace'][$i]) && preg_match('/^0[A-F0-9]{4}$/', $vs['match'][$i])) {
											if (preg_match('/^0[EF][A-F0-9]{3}$/', $vs['replace'][$i])) {
												$rtlpua[] = $vs['replace'][$i];
											}
										}
									}
								}
							} else {
								preg_match_all('/\((0[A-F0-9]{4})\)/', $v['match'], $m);
								$matchCount = count($m[0]);
								for ($i = 0; $i < $matchCount; $i++) {
									$sb = explode(' ', $v['replace']);
									foreach ($sb as $sbg) {
										if (preg_match('/(0[EF][A-F0-9]{3})/', $sbg, $mr)) {
											$rtlpua[] = $mr[1];
										}
									}
								}
							}
						}
					}

					// For kashida, need to determine all final forms except ones already identified by kashida priority rules (see \Mpdf\Otl)
					foreach ($rtl as $base => $variants) {
						if (isset($variants[1])) { // i.e. final form
							if (strpos('0FE8E 0FE94 0FEA2 0FEAA 0FEAE 0FEC2 0FEDA 0FEDE 0FB93 0FECA 0FED2 0FED6 0FEEE 0FEF0 0FEF2', $variants[1]) === false) { // not already included
								// This version does not exclude RA (0631) FEAE; Ya (064A)  FEF2; Alef Maqsurah (0649) FEF0 which
								// are selected in priority if connected to a medial Bah
								//if (strpos('0FE8E 0FE94 0FEA2 0FEAA 0FEC2 0FEDA 0FEDE 0FB93 0FECA 0FED2 0FED6 0FEEE', $variants[1])===false) {	// not already included
								$finals .= $variants[1] . ' ';
							}
						}
					}

					ksort($rtl);
					$rtlSUB = $rtl;
				}

				// INDIC - Dynamic properties
				$rphf = [];
				$half = [];
				$pref = [];
				$blwf = [];
				$pstf = [];

				if (strpos('dev2 bng2 gur2 gjr2 ory2 tml2 tel2 knd2 mlm2 deva beng guru gujr orya taml telu knda mlym', $st) !== false) { // all INDIC scripts [any/all languages]
					if (strpos('deva beng guru gujr orya taml telu knda mlym', $st) !== false) {
						$is_old_spec = true;
					} else {
						$is_old_spec = false;
					}

					// First get 'locl' substitutions (reversed!)
					$loclsubs = [];
					foreach ($volt as $v) {
						if (strpos('locl', $v['tag']) !== false) {
							$key = $v['match'];
							$key = preg_replace('/[\(\)]*/', '', $key);
							$sub = $v['replace'];
							if ($key && strlen(trim($key)) == 5 && $sub) {
								$loclsubs[$sub] = $key;
							}
						}
					}

					foreach ($volt as $v) {
						// <rphf> <half> <pref> <blwf> <pstf>
						// defines consonant types:
						//     Reph <rphf>
						//     Half forms <half>
						//     Pre-base-reordering forms of Ra/Rra <pref>
						//     Below-base forms <blwf>
						//     Post-base forms <pstf>
						// applied together with <locl> feature to input sequences consisting of two characters
						// This is done for each consonant
						// for <rphf> and <half>, features are applied to Consonant + Halant combinations
						// for <pref>, <blwf> and <pstf>, features are applied to Halant + Consonant combinations
						// Old version eg 'deva' <pref>, <blwf> and <pstf>, features are applied to Consonant + Halant
						// Some malformed fonts still do Consonant + Halant for these - so match both??
						// If these two glyphs form a ligature, with no additional glyphs in context
						// this means the consonant has the corresponding form
						// Currently set to cope with both
						// See also classes/otl.php

						if (strpos('rphf half pref blwf pstf', $v['tag']) !== false) {
							if (isset($v['context']) && $v['context'] && $v['nBacktrack'] == 0 && $v['nLookahead'] == 0) {
								foreach ($v['rules'] as $vs) {
									if (count($vs['match']) == 2 && count($vs['replace']) == 1) {
										$sub = $vs['replace'][0];
										// If Halant Cons   <pref>, <blwf> and <pstf> in New version only
										if (strpos('0094D 009CD 00A4D 00ACD 00B4D 00BCD 00C4D 00CCD 00D4D', $vs['match'][0]) !== false && strpos('pref blwf pstf', $v['tag']) !== false && !$is_old_spec) {
											$key = $vs['match'][1];
											$tag = $v['tag'];
											if (isset($loclsubs[$key])) {
												${$tag}[$loclsubs[$key]] = $sub;
											}
											$tmp = &$$tag;
											$tmp[hexdec($key)] = hexdec($sub);
										} // If Cons Halant    <rphf> and <half> always
										// and <pref>, <blwf> and <pstf> in Old version
										elseif (strpos('0094D 009CD 00A4D 00ACD 00B4D 00BCD 00C4D 00CCD 00D4D', $vs['match'][1]) !== false && (strpos('rphf half', $v['tag']) !== false || (strpos('pref blwf pstf', $v['tag']) !== false && ($is_old_spec || _OTL_OLD_SPEC_COMPAT_2)))) {
											$key = $vs['match'][0];
											$tag = $v['tag'];
											if (isset($loclsubs[$key])) {
												${$tag}[$loclsubs[$key]] = $sub;
											}
											$tmp = &$$tag;
											$tmp[hexdec($key)] = hexdec($sub);
										}
									}
								}
							} elseif (!isset($v['context'])) {
								$key = $v['match'];
								$key = preg_replace('/[\(\)]*/', '', $key);
								$sub = $v['replace'];
								if ($key && strlen(trim($key)) == 11 && $sub) {
									// If Cons Halant    <rphf> and <half> always
									// and <pref>, <blwf> and <pstf> in Old version
									// If Halant Cons   <pref>, <blwf> and <pstf> in New version only
									if (strpos('0094D 009CD 00A4D 00ACD 00B4D 00BCD 00C4D 00CCD 00D4D', substr($key, 0, 5)) !== false && strpos('pref blwf pstf', $v['tag']) !== false && !$is_old_spec) {
										$key = substr($key, 6, 5);
										$tag = $v['tag'];
										if (isset($loclsubs[$key])) {
											${$tag}[$loclsubs[$key]] = $sub;
										}
										$tmp = &$$tag;
										$tmp[hexdec($key)] = hexdec($sub);
									} elseif (strpos('0094D 009CD 00A4D 00ACD 00B4D 00BCD 00C4D 00CCD 00D4D', substr($key, 6, 5)) !== false && (strpos('rphf half', $v['tag']) !== false || (strpos('pref blwf pstf', $v['tag']) !== false && ($is_old_spec || _OTL_OLD_SPEC_COMPAT_2)))) {
										$key = substr($key, 0, 5);
										$tag = $v['tag'];
										if (isset($loclsubs[$key])) {
											${$tag}[$loclsubs[$key]] = $sub;
										}
										$tmp = &$$tag;
										$tmp[hexdec($key)] = hexdec($sub);
									}
								}
							}
						}
					}
				}

				if (count($rtl) || count($rphf) || count($half) || count($pref) || count($blwf) || count($pstf) || $finals) {
					$font = [
						'rtlSUB' => $rtlSUB,
						'finals' => $finals,
						'rphf' => $rphf,
						'half' => $half,
						'pref' => $pref,
						'blwf' => $blwf,
						'pstf' => $pstf,
					];

					$this->fontCache->jsonWrite($this->fontkey . '.GSUB.' . $st . '.' . $t . '.json', $font);
				}

				if (!isset($GSUBScriptLang[$st])) {
					$GSUBScriptLang[$st] = '';
				}
				$GSUBScriptLang[$st] .= $t . ' ';
			}
		}

		// All RTL glyphs from font added to (or already in) PUA [reqd for magic_reverse]
		$rtlPUAstr = '';
		if (count($rtlpua)) {
			$rtlpua = array_unique($rtlpua);
			sort($rtlpua);
			$n = count($rtlpua);
			for ($i = 0; $i < $n; $i++) {
				if (hexdec($rtlpua[$i]) < hexdec('E000') || hexdec($rtlpua[$i]) > hexdec('F8FF')) {
					unset($rtlpua[$i]);
				}
			}
			sort($rtlpua, SORT_STRING);

			$rangeid = -1;
			$range = [];
			$prevgid = -2;

			// for each character
			foreach ($rtlpua as $gidhex) {
				$gid = hexdec($gidhex);
				if ($gid == ($prevgid + 1)) {
					$range[$rangeid]['end'] = $gidhex;
					$range[$rangeid]['count']++;
				} else {
					// new range
					$rangeid++;
					$range[$rangeid] = [];
					$range[$rangeid]['start'] = $gidhex;
					$range[$rangeid]['end'] = $gidhex;
					$range[$rangeid]['count'] = 1;
				}
				$prevgid = $gid;
			}

			foreach ($range as $rg) {
				if ($rg['count'] == 1) {
					$rtlPUAstr .= "\x{" . $rg['start'] . "}";
				} elseif ($rg['count'] == 2) {
					$rtlPUAstr .= "\x{" . $rg['start'] . "}\x{" . $rg['end'] . "}";
				} else {
					$rtlPUAstr .= "\x{" . $rg['start'] . "}-\x{" . $rg['end'] . "}";
				}
			}
		}

		return [$GSUBScriptLang, $gsub, $GSLookup, $rtlPUAstr];
	}

	// GSUB functions
	function _getGSUBarray(&$Lookup, &$lul, $scripttag)
	{
		// Process (3) LookupList for specific Script-LangSys
		// Generate preg_replace
		$volt = [];
		$reph = '';
		$matraE = '';
		$vatu = '';

		foreach ($lul as $i => $tag) {
			for ($c = 0; $c < $Lookup[$i]['SubtableCount']; $c++) {
				$SubstFormat = $Lookup[$i]['Subtable'][$c]['Format'];

				// LookupType 1: Single Substitution Subtable
				if ($Lookup[$i]['Type'] == 1) {
					$subCount = count($Lookup[$i]['Subtable'][$c]['subs']);
					for ($s = 0; $s < $subCount; $s++) {
						$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
						$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'][0];
						// Ignore has already been applied earlier on
						$repl = $this->_makeGSUBinputMatch($inputGlyphs, "()");
						$subs = $this->_makeGSUBinputReplacement(1, $substitute, "()", 0, 1, 0);
						$volt[] = ['match' => $repl, 'replace' => $subs, 'tag' => $tag, 'key' => $inputGlyphs[0], 'type' => 1];
					}
				} // LookupType 2: Multiple Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 2) {
					if (!isset($Lookup[$i]['Subtable'][$c]['subs'])) {
						continue; // every entry was filtered out by the Ignore flags
					}

					for ($s = 0; $s < count($Lookup[$i]['Subtable'][$c]['subs']); $s++) {
						$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
						$substitute = implode(" ", $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute']);
						// Ignore has already been applied earlier on
						$repl = $this->_makeGSUBinputMatch($inputGlyphs, "()");
						$subs = $this->_makeGSUBinputReplacement(1, $substitute, "()", 0, 1, 0);
						$volt[] = ['match' => $repl, 'replace' => $subs, 'tag' => $tag, 'key' => $inputGlyphs[0], 'type' => 2];
					}
				} // LookupType 3: Alternate Forms
				elseif ($Lookup[$i]['Type'] == 3) {
					if (!isset($Lookup[$i]['Subtable'][$c]['subs'])) {
						continue; // every entry was filtered out by the Ignore flags
					}

					for ($s = 0; $s < count($Lookup[$i]['Subtable'][$c]['subs']); $s++) {
						$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
						$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'][0];
						// Ignore has already been applied earlier on
						$repl = $this->_makeGSUBinputMatch($inputGlyphs, "()");
						$subs = $this->_makeGSUBinputReplacement(1, $substitute, "()", 0, 1, 0);
						$volt[] = ['match' => $repl, 'replace' => $subs, 'tag' => $tag, 'key' => $inputGlyphs[0], 'type' => 3];
					}
				} // LookupType 4: Ligature Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 4) {
					if (!isset($Lookup[$i]['Subtable'][$c]['subs'])) {
						continue; // every entry was filtered out by the Ignore flags
					}

					for ($s = 0; $s < count($Lookup[$i]['Subtable'][$c]['subs']); $s++) {
						$inputGlyphs = $Lookup[$i]['Subtable'][$c]['subs'][$s]['Replace'];
						$substitute = $Lookup[$i]['Subtable'][$c]['subs'][$s]['substitute'][0];
						// Ignore has already been applied earlier on
						$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
						$repl = $this->_makeGSUBinputMatch($inputGlyphs, $ignore);
						$subs = $this->_makeGSUBinputReplacement(count($inputGlyphs), $substitute, $ignore, 0, count($inputGlyphs), 0);
						$volt[] = ['match' => $repl, 'replace' => $subs, 'tag' => $tag, 'key' => $inputGlyphs[0], 'type' => 4, 'CompCount' => $Lookup[$i]['Subtable'][$c]['subs'][$s]['CompCount'], 'Lig' => $substitute];
					}
				} // LookupType 5: Chaining Contextual Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 5) {
					// Format 1: Context Substitution
					if ($SubstFormat == 1) {
						$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
						for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['SubRuleSetCount']; $s++) {
							// SubRuleSet
							$subRule = [];
							foreach ($Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['SubRule'] as $rule) {
								// SubRule
								$inputGlyphs = [];
								if ($rule['GlyphCount'] > 1) {
									$inputGlyphs = $rule['InputGlyphs'];
								}
								$inputGlyphs[0] = $Lookup[$i]['Subtable'][$c]['SubRuleSet'][$s]['FirstGlyph'];
								ksort($inputGlyphs);
								$nInput = count($inputGlyphs);

								$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, [], 0);
								$subRule = ['context' => 1, 'tag' => $tag, 'matchback' => '', 'match' => $contextInputMatch, 'nBacktrack' => 0, 'nInput' => $nInput, 'nLookahead' => 0, 'rules' => [],];

								for ($b = 0; $b < $rule['SubstCount']; $b++) {
									$lup = $rule['SubstLookupRecord'][$b]['LookupListIndex'];
									$seqIndex = $rule['SubstLookupRecord'][$b]['SequenceIndex'];

									// $Lookup[$lup] = secondary Lookup
									for ($lus = 0; $lus < $Lookup[$lup]['SubtableCount']; $lus++) {
										if (!empty($Lookup[$lup]['Subtable'][$lus]['subs'])) {
											foreach ($Lookup[$lup]['Subtable'][$lus]['subs'] as $luss) {
												$lookupGlyphs = $luss['Replace'];
												$mLen = count($lookupGlyphs);

												// Only apply if the (first) 'Replace' glyph from the
												// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
												// then apply the substitution
												if (strpos($inputGlyphs[$seqIndex], $lookupGlyphs[0]) === false) {
													continue;
												}
												$REPL = implode(" ", $luss['substitute']);
												if (strpos("isol fina fin2 fin3 medi med2 init ", $tag) !== false && $scripttag == 'arab') {
													$volt[] = ['match' => $lookupGlyphs[0], 'replace' => $REPL, 'tag' => $tag, 'prel' => $backtrackGlyphs, 'postl' => $lookaheadGlyphs, 'ignore' => $ignore];
												} else {
													$subRule['rules'][] = ['type' => $Lookup[$lup]['Type'], 'match' => $lookupGlyphs, 'replace' => $luss['substitute'], 'seqIndex' => $seqIndex, 'key' => $lookupGlyphs[0],];
												}
											}
										}
									}
								}

								if (count($subRule['rules'])) {
									$volt[] = $subRule;
								}
							}
						}
					} // Format 2: Class-based Context Glyph Substitution
					elseif ($SubstFormat == 2) {
						$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
						foreach ($Lookup[$i]['Subtable'][$c]['SubClassSet'] as $inputClass => $cscs) {
							for ($cscrule = 0; $cscrule < $cscs['SubClassRuleCnt']; $cscrule++) {
								$rule = $cscs['SubClassRule'][$cscrule];

								$inputGlyphs = [];

								$inputGlyphs[0] = $Lookup[$i]['Subtable'][$c]['InputClasses'][$inputClass];
								if ($rule['InputGlyphCount'] > 1) {
									//  NB starts at 1
									for ($gcl = 1; $gcl < $rule['InputGlyphCount']; $gcl++) {
										$classindex = $rule['Input'][$gcl];
										if (isset($Lookup[$i]['Subtable'][$c]['InputClasses'][$classindex])) {
											$inputGlyphs[$gcl] = $Lookup[$i]['Subtable'][$c]['InputClasses'][$classindex];
										} // if class[0] = all glyphs excluding those specified in all other classes
										// set to blank '' for now
										else {
											$inputGlyphs[$gcl] = '';
										}
									}
								}

								$nInput = $rule['InputGlyphCount'];
								$nIsubs = (2 * $nInput) - 1;

								$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, [], 0);
								$subRule = ['context' => 1, 'tag' => $tag, 'matchback' => '', 'match' => $contextInputMatch, 'nBacktrack' => 0, 'nInput' => $nInput, 'nLookahead' => 0, 'rules' => [],];

								for ($b = 0; $b < $rule['SubstCount']; $b++) {
									$lup = $rule['LookupListIndex'][$b];
									$seqIndex = $rule['SequenceIndex'][$b];

									// $Lookup[$lup] = secondary Lookup
									for ($lus = 0; $lus < $Lookup[$lup]['SubtableCount']; $lus++) {
										if (isset($Lookup[$lup]['Subtable'][$lus]['subs']) && count($Lookup[$lup]['Subtable'][$lus]['subs'])) {
											foreach ($Lookup[$lup]['Subtable'][$lus]['subs'] as $luss) {
												$lookupGlyphs = $luss['Replace'];
												$mLen = count($lookupGlyphs);

												// Only apply if the (first) 'Replace' glyph from the
												// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
												// then apply the substitution
												if (strpos($inputGlyphs[$seqIndex], $lookupGlyphs[0]) === false) {
													continue;
												}

												// Returns e.g. ¦(0612)¦(ignore) (0613)¦(ignore) (0614)¦
												$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, $lookupGlyphs, $seqIndex);
												$REPL = implode(" ", $luss['substitute']);
												// Returns e.g. "REPL\${6}\${8}" or "\${1}\${2} \${3} REPL\${4}\${6}\${8} \${9}"

												if (strpos("isol fina fin2 fin3 medi med2 init ", $tag) !== false && $scripttag == 'arab') {
													$volt[] = ['match' => $lookupGlyphs[0], 'replace' => $REPL, 'tag' => $tag, 'prel' => $backtrackGlyphs, 'postl' => $lookaheadGlyphs, 'ignore' => $ignore];
												} else {
													$subRule['rules'][] = ['type' => $Lookup[$lup]['Type'], 'match' => $lookupGlyphs, 'replace' => $luss['substitute'], 'seqIndex' => $seqIndex, 'key' => $lookupGlyphs[0],];
												}
											}
										}
									}
								}
								if (count($subRule['rules'])) {
									$volt[] = $subRule;
								}
							}
						}

					} // Format 3: Coverage-based Context Glyph Substitution  p259
					elseif ($SubstFormat == 3) {

						// IgnoreMarks flag set on main Lookup table
						$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
						$inputGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'];
						$nInput = $Lookup[$i]['Subtable'][$c]['InputGlyphCount'];

						$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, [], 0);

						// Type 5 is a plain context: it has no backtrack or lookahead sequence, as Format 1 above
						$subRule = ['context' => 1, 'tag' => $tag, 'matchback' => '', 'match' => $contextInputMatch, 'nBacktrack' => 0, 'nInput' => $nInput, 'nLookahead' => 0, 'rules' => [],];

						for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['SubstCount']; $b++) {
							$lup = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['LookupListIndex'];
							$seqIndex = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['SequenceIndex'];
							for ($lus = 0; $lus < $Lookup[$lup]['SubtableCount']; $lus++) {
								if (empty($Lookup[$lup]['Subtable'][$lus]['subs']) || !is_array($Lookup[$lup]['Subtable'][$lus]['subs'])) {
									continue;
								}

								foreach ($Lookup[$lup]['Subtable'][$lus]['subs'] as $luss) {
									$lookupGlyphs = $luss['Replace'];

									// Only apply if the (first) 'Replace' glyph from the
									// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
									// then apply the substitution
									if (strpos($inputGlyphs[$seqIndex], $lookupGlyphs[0]) === false) {
										continue;
									}

									// Returns e.g. ¦(0612)¦(ignore) (0613)¦(ignore) (0614)¦
									$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, $lookupGlyphs, $seqIndex);
									$REPL = implode(" ", $luss['substitute']);

									if (strpos("isol fina fin2 fin3 medi med2 init ", $tag) !== false && $scripttag == 'arab') {
										$volt[] = ['match' => $lookupGlyphs[0], 'replace' => $REPL, 'tag' => $tag, 'prel' => [], 'postl' => [], 'ignore' => $ignore];
									} else {
										$subRule['rules'][] = ['type' => $Lookup[$lup]['Type'], 'match' => $lookupGlyphs, 'replace' => $luss['substitute'], 'seqIndex' => $seqIndex, 'key' => $lookupGlyphs[0],];
									}
								}
							}
						}
						if (count($subRule['rules'])) {
							$volt[] = $subRule;
						}
					}

				} // LookupType 6: ing Contextual Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 6) {

					// Format 1: Simple Chaining Context Glyph Substitution  p255
					if ($SubstFormat == 1) {
						$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
						for ($s = 0; $s < $Lookup[$i]['Subtable'][$c]['ChainSubRuleSetCount']; $s++) {

							// ChainSubRuleSet
							$subRule = [];
							$firstInputGlyph = $Lookup[$i]['Subtable'][$c]['CoverageGlyphs'][$s]; // First input gyyph

							foreach ($Lookup[$i]['Subtable'][$c]['ChainSubRuleSet'][$s]['ChainSubRule'] as $rule) {
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
								$backtrackMatch = $this->_makeGSUBbacktrackMatch($backtrackGlyphs, $ignore);

								if ($rule['LookaheadGlyphCount']) {
									$lookaheadGlyphs = $rule['LookaheadGlyphs'];
								} else {
									$lookaheadGlyphs = [];
								}

								$lookaheadMatch = $this->_makeGSUBlookaheadMatch($lookaheadGlyphs, $ignore);

								$nBsubs = 2 * count($backtrackGlyphs);
								$nIsubs = (2 * $nInput) - 1;

								$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, [], 0);
								$subRule = ['context' => 1, 'tag' => $tag, 'matchback' => $backtrackMatch, 'match' => ($contextInputMatch . $lookaheadMatch), 'nBacktrack' => count($backtrackGlyphs), 'nInput' => $nInput, 'nLookahead' => count($lookaheadGlyphs), 'rules' => [],];

								for ($b = 0; $b < $rule['SubstCount']; $b++) {
									$lup = $rule['LookupListIndex'][$b];
									$seqIndex = $rule['SequenceIndex'][$b];

									// $Lookup[$lup] = secondary Lookup
									for ($lus = 0; $lus < $Lookup[$lup]['SubtableCount']; $lus++) {
										if (!empty($Lookup[$lup]['Subtable'][$lus]['subs'])) {
											foreach ($Lookup[$lup]['Subtable'][$lus]['subs'] as $luss) {
												$lookupGlyphs = $luss['Replace'];
												$mLen = count($lookupGlyphs);

												// Only apply if the (first) 'Replace' glyph from the
												// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
												// then apply the substitution
												if (strpos($inputGlyphs[$seqIndex], $lookupGlyphs[0]) === false) {
													continue;
												}

												// Returns e.g. ¦(0612)¦(ignore) (0613)¦(ignore) (0614)¦
												$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, $lookupGlyphs, $seqIndex);

												$REPL = implode(" ", $luss['substitute']);

												if (strpos("isol fina fin2 fin3 medi med2 init ", $tag) !== false && $scripttag == 'arab') {
													$volt[] = ['match' => $lookupGlyphs[0], 'replace' => $REPL, 'tag' => $tag, 'prel' => $backtrackGlyphs, 'postl' => $lookaheadGlyphs, 'ignore' => $ignore];
												} else {
													$subRule['rules'][] = ['type' => $Lookup[$lup]['Type'], 'match' => $lookupGlyphs, 'replace' => $luss['substitute'], 'seqIndex' => $seqIndex, 'key' => $lookupGlyphs[0],];
												}
											}
										}
									}
								}

								if (count($subRule['rules'])) {
									$volt[] = $subRule;
								}
							}
						}

					} // Format 2: Class-based Chaining Context Glyph Substitution  p257
					elseif ($SubstFormat == 2) {
						$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
						foreach ($Lookup[$i]['Subtable'][$c]['ChainSubClassSet'] as $inputClass => $cscs) {
							for ($cscrule = 0; $cscrule < $cscs['ChainSubClassRuleCnt']; $cscrule++) {
								$rule = $cscs['ChainSubClassRule'][$cscrule];

								// These contain classes of glyphs as strings
								// $Lookup[$i]['Subtable'][$c]['InputClasses'][(class)] e.g. 02E6|02E7|02E8
								// $Lookup[$i]['Subtable'][$c]['LookaheadClasses'][(class)]
								// $Lookup[$i]['Subtable'][$c]['BacktrackClasses'][(class)]
								// These contain arrays of classIndexes
								// [Backtrack] [Lookahead] and [Input] (Input is from the second position only)

								$inputGlyphs = [];

								if (isset($Lookup[$i]['Subtable'][$c]['InputClasses'][$inputClass])) {
									$inputGlyphs[0] = $Lookup[$i]['Subtable'][$c]['InputClasses'][$inputClass];
								} else {
									$inputGlyphs[0] = '';
								}
								if ($rule['InputGlyphCount'] > 1) {
									//  NB starts at 1
									for ($gcl = 1; $gcl < $rule['InputGlyphCount']; $gcl++) {
										$classindex = $rule['Input'][$gcl];
										if (isset($Lookup[$i]['Subtable'][$c]['InputClasses'][$classindex])) {
											$inputGlyphs[$gcl] = $Lookup[$i]['Subtable'][$c]['InputClasses'][$classindex];
										} // if class[0] = all glyphs excluding those specified in all other classes
										// set to blank '' for now
										else {
											$inputGlyphs[$gcl] = '';
										}
									}
								}

								$nInput = $rule['InputGlyphCount'];

								if ($rule['BacktrackGlyphCount']) {
									for ($gcl = 0; $gcl < $rule['BacktrackGlyphCount']; $gcl++) {
										$classindex = $rule['Backtrack'][$gcl];
										if (isset($Lookup[$i]['Subtable'][$c]['BacktrackClasses'][$classindex])) {
											$backtrackGlyphs[$gcl] = $Lookup[$i]['Subtable'][$c]['BacktrackClasses'][$classindex];
										} // if class[0] = all glyphs excluding those specified in all other classes
										// set to blank '' for now
										else {
											$backtrackGlyphs[$gcl] = '';
										}
									}
								} else {
									$backtrackGlyphs = [];
								}
								// Returns e.g. ¦(FEEB|FEEC)(ignore) ¦(FD12|FD13)(ignore) ¦
								$backtrackMatch = $this->_makeGSUBbacktrackMatch($backtrackGlyphs, $ignore);

								if ($rule['LookaheadGlyphCount']) {
									for ($gcl = 0; $gcl < $rule['LookaheadGlyphCount']; $gcl++) {
										$classindex = $rule['Lookahead'][$gcl];
										if (isset($Lookup[$i]['Subtable'][$c]['LookaheadClasses'][$classindex])) {
											$lookaheadGlyphs[$gcl] = $Lookup[$i]['Subtable'][$c]['LookaheadClasses'][$classindex];
										} // if class[0] = all glyphs excluding those specified in all other classes
										// set to blank '' for now
										else {
											$lookaheadGlyphs[$gcl] = '';
										}
									}
								} else {
									$lookaheadGlyphs = [];
								}
								// Returns e.g. ¦(ignore) (FD12|FD13)¦(ignore) (FEEB|FEEC)¦
								$lookaheadMatch = $this->_makeGSUBlookaheadMatch($lookaheadGlyphs, $ignore);

								$nBsubs = 2 * count($backtrackGlyphs);
								$nIsubs = (2 * $nInput) - 1;

								$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, [], 0);
								$subRule = ['context' => 1, 'tag' => $tag, 'matchback' => $backtrackMatch, 'match' => ($contextInputMatch . $lookaheadMatch), 'nBacktrack' => count($backtrackGlyphs), 'nInput' => $nInput, 'nLookahead' => count($lookaheadGlyphs), 'rules' => [],];

								for ($b = 0; $b < $rule['SubstCount']; $b++) {
									$lup = $rule['LookupListIndex'][$b];
									$seqIndex = $rule['SequenceIndex'][$b];

									// $Lookup[$lup] = secondary Lookup
									for ($lus = 0; $lus < $Lookup[$lup]['SubtableCount']; $lus++) {
										if (!empty($Lookup[$lup]['Subtable'][$lus]['subs'])) {
											foreach ($Lookup[$lup]['Subtable'][$lus]['subs'] as $luss) {
												$lookupGlyphs = $luss['Replace'];
												$mLen = count($lookupGlyphs);

												// Only apply if the (first) 'Replace' glyph from the
												// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
												// then apply the substitution
												if (strpos($inputGlyphs[$seqIndex], $lookupGlyphs[0]) === false) {
													continue;
												}

												// Returns e.g. ¦(0612)¦(ignore) (0613)¦(ignore) (0614)¦
												$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, $lookupGlyphs, $seqIndex);
												$REPL = implode(" ", $luss['substitute']);
												// Returns e.g. "REPL\${6}\${8}" or "\${1}\${2} \${3} REPL\${4}\${6}\${8} \${9}"

												if (strpos("isol fina fin2 fin3 medi med2 init ", $tag) !== false && $scripttag == 'arab') {
													$volt[] = ['match' => $lookupGlyphs[0], 'replace' => $REPL, 'tag' => $tag, 'prel' => $backtrackGlyphs, 'postl' => $lookaheadGlyphs, 'ignore' => $ignore];
												} else {
													$subRule['rules'][] = ['type' => $Lookup[$lup]['Type'], 'match' => $lookupGlyphs, 'replace' => $luss['substitute'], 'seqIndex' => $seqIndex, 'key' => $lookupGlyphs[0],];
												}
											}
										}
									}
								}
								if (count($subRule['rules'])) {
									$volt[] = $subRule;
								}
							}
						}

					} // Format 3: Coverage-based Chaining Context Glyph Substitution  p259
					elseif ($SubstFormat == 3) {
						// IgnoreMarks flag set on main Lookup table
						$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
						$inputGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'];
						$CoverageInputGlyphs = implode('|', $inputGlyphs);
						$nInput = $Lookup[$i]['Subtable'][$c]['InputGlyphCount'];

						if ($Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount']) {
							$backtrackGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageBacktrackGlyphs'];
						} else {
							$backtrackGlyphs = [];
						}
						// Returns e.g. ¦(FEEB|FEEC)(ignore) ¦(FD12|FD13)(ignore) ¦
						$backtrackMatch = $this->_makeGSUBbacktrackMatch($backtrackGlyphs, $ignore);

						if ($Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount']) {
							$lookaheadGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageLookaheadGlyphs'];
						} else {
							$lookaheadGlyphs = [];
						}
						// Returns e.g. ¦(ignore) (FD12|FD13)¦(ignore) (FEEB|FEEC)¦
						$lookaheadMatch = $this->_makeGSUBlookaheadMatch($lookaheadGlyphs, $ignore);

						$nBsubs = 2 * count($backtrackGlyphs);
						$nIsubs = (2 * $nInput) - 1;
						$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, [], 0);
						$subRule = ['context' => 1, 'tag' => $tag, 'matchback' => $backtrackMatch, 'match' => ($contextInputMatch . $lookaheadMatch), 'nBacktrack' => count($backtrackGlyphs), 'nInput' => $nInput, 'nLookahead' => count($lookaheadGlyphs), 'rules' => [],];

						for ($b = 0; $b < $Lookup[$i]['Subtable'][$c]['SubstCount']; $b++) {
							$lup = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['LookupListIndex'];
							$seqIndex = $Lookup[$i]['Subtable'][$c]['SubstLookupRecord'][$b]['SequenceIndex'];
							for ($lus = 0; $lus < $Lookup[$lup]['SubtableCount']; $lus++) {
								if (empty($Lookup[$lup]['Subtable'][$lus]['subs']) || ! is_array($Lookup[$lup]['Subtable'][$lus]['subs'])) {
									continue;
								}

								foreach ($Lookup[$lup]['Subtable'][$lus]['subs'] as $luss) {
									$lookupGlyphs = $luss['Replace'];

									// Only apply if the (first) 'Replace' glyph from the
									// Lookup list is in the [inputGlyphs] at ['SequenceIndex']
									// then apply the substitution
									if (strpos($inputGlyphs[$seqIndex], $lookupGlyphs[0]) === false) {
										continue;
									}

									// Returns e.g. ¦(0612)¦(ignore) (0613)¦(ignore) (0614)¦
									$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, $lookupGlyphs, $seqIndex);
									$REPL = implode(" ", $luss['substitute']);

									if (strpos("isol fina fin2 fin3 medi med2 init ", $tag) !== false && $scripttag == 'arab') {
										$volt[] = ['match' => $lookupGlyphs[0], 'replace' => $REPL, 'tag' => $tag, 'prel' => $backtrackGlyphs, 'postl' => $lookaheadGlyphs, 'ignore' => $ignore];
									} else {
										$subRule['rules'][] = ['type' => $Lookup[$lup]['Type'], 'match' => $lookupGlyphs, 'replace' => $luss['substitute'], 'seqIndex' => $seqIndex, 'key' => $lookupGlyphs[0],];
									}
								}
							}
						}
						if (count($subRule['rules'])) {
							$volt[] = $subRule;
						}
					}
				} // LookupType 8: Reverse Chaining Contextual Single Substitution Subtable
				elseif ($Lookup[$i]['Type'] == 8) {
					if (empty($Lookup[$i]['Subtable'][$c]['subs'])) {
						continue; // every entry was filtered out by the Ignore flags
					}

					// IgnoreMarks flag set on main Lookup table
					$ignore = $this->_getGSUBignoreString($Lookup[$i]['Flag'], $Lookup[$i]['MarkFilteringSet']);
					$inputGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageInputGlyphs'];

					if ($Lookup[$i]['Subtable'][$c]['BacktrackGlyphCount']) {
						$backtrackGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageBacktrackGlyphs'];
					} else {
						$backtrackGlyphs = [];
					}
					// Returns e.g. ¦(FEEB|FEEC)(ignore) ¦(FD12|FD13)(ignore) ¦
					$backtrackMatch = $this->_makeGSUBbacktrackMatch($backtrackGlyphs, $ignore);

					if ($Lookup[$i]['Subtable'][$c]['LookaheadGlyphCount']) {
						$lookaheadGlyphs = $Lookup[$i]['Subtable'][$c]['CoverageLookaheadGlyphs'];
					} else {
						$lookaheadGlyphs = [];
					}
					// Returns e.g. ¦(ignore) (FD12|FD13)¦(ignore) (FEEB|FEEC)¦
					$lookaheadMatch = $this->_makeGSUBlookaheadMatch($lookaheadGlyphs, $ignore);

					// Type 8 replaces exactly one glyph, so the input sequence is always a single Coverage table
					$contextInputMatch = $this->_makeGSUBcontextInputMatch($inputGlyphs, $ignore, [], 0);
					$subRule = ['context' => 1, 'tag' => $tag, 'matchback' => $backtrackMatch, 'match' => ($contextInputMatch . $lookaheadMatch), 'nBacktrack' => count($backtrackGlyphs), 'nInput' => 1, 'nLookahead' => count($lookaheadGlyphs), 'rules' => [],];

					foreach ($Lookup[$i]['Subtable'][$c]['subs'] as $luss) {
						if (strpos("isol fina fin2 fin3 medi med2 init ", $tag) !== false && $scripttag == 'arab') {
							$volt[] = ['match' => $luss['Replace'][0], 'replace' => implode(" ", $luss['substitute']), 'tag' => $tag, 'prel' => $backtrackGlyphs, 'postl' => $lookaheadGlyphs, 'ignore' => $ignore];
						} else {
							$subRule['rules'][] = ['type' => 1, 'match' => $luss['Replace'], 'replace' => $luss['substitute'], 'seqIndex' => 0, 'key' => $luss['Replace'][0],];
						}
					}

					if (count($subRule['rules'])) {
						$volt[] = $subRule;
					}
				}
			}
		}

		return $volt;
	}

	function _checkGSUBignore($flag, $glyph, $MarkFilteringSet)
	{
		$ignore = false;
		// Flag & 0x0008 = Ignore Marks - (unless already done with MarkAttachmentType)
		if ((($flag & 0x0008) == 0x0008 && ($flag & 0xFF00) == 0) && strpos($this->GlyphClassMarks, $glyph)) {
			$ignore = true;
		}
		if ((($flag & 0x0004) == 0x0004) && strpos($this->GlyphClassLigatures, $glyph)) {
			$ignore = true;
		}
		if ((($flag & 0x0002) == 0x0002) && strpos($this->GlyphClassBases, $glyph)) {
			$ignore = true;
		}
		// Flag & 0xFF?? = MarkAttachmentType
		if ($flag & 0xFF00) {
			// "a lookup must ignore any mark glyphs that are not in the specified mark attachment class"
			// $this->MarkAttachmentType is already adjusted for this i.e. contains all Marks except those in the MarkAttachmentClassDef table
			if (strpos($this->MarkAttachmentType[($flag >> 8)], $glyph)) {
				$ignore = true;
			}
		}
		// Flag & 0x0010 = UseMarkFilteringSet: skip every mark *except* those in the set
		if (($flag & 0x0010) && strpos($this->GlyphClassMarks, $glyph)
				&& !strpos($this->markGlyphSet($MarkFilteringSet), $glyph)) {
			$ignore = true;
		}

		return $ignore;
	}


	/**
	 * UseMarkFilteringSet means "skip every mark except those in the given mark glyph set", so the glyphs to
	 * ignore are GlyphClassMarks minus that set - not the set itself.
	 *
	 * @param string $marks Space-prefixed, "|"-separated glyph list, e.g. " 00DCA| 00DD2"
	 * @param string $set   The mark glyph set, in the same format
	 *
	 * @return string
	 */
	/**
	 * A lookup's MarkFilteringSet indexes GDEF's mark glyph sets. A font naming a set GDEF does not define is
	 * malformed, and guessing which marks it meant would shape silently wrong, so both callers fail loudly here.
	 */
	/**
	 * The glyphs of one of GDEF's mark glyph sets.
	 *
	 * protected rather than private because OtlDump reports on the same sets, from the same parse.
	 */
	protected function markGlyphSet($MarkFilteringSet)
	{
		if (!isset($this->MarkGlyphSets[$MarkFilteringSet])) {
			throw new \Mpdf\Exception\FontException(sprintf('Font "%s" uses mark filtering set %s, which GDEF does not define', $this->fontkey, $MarkFilteringSet));
		}

		return $this->MarkGlyphSets[$MarkFilteringSet];
	}

	private function marksOutsideFilteringSet($marks, $set)
	{
		$keep = [];
		$inSet = [];
		foreach (explode('|', $set) as $glyph) {
			$inSet[trim($glyph)] = true;
		}

		foreach (explode('|', $marks) as $glyph) {
			$glyph = trim($glyph);
			if ($glyph !== '' && !isset($inSet[$glyph])) {
				$keep[] = $glyph;
			}
		}

		return $keep ? ' ' . implode('| ', $keep) : '';
	}

	function _getGSUBignoreString($flag, $MarkFilteringSet)
	{
		// If ignoreFlag set, combine all ignore glyphs into -> "((?:(?: FBA1| FBA2| FBA3))*)"
		// else "()"
		// for Input - set on secondary Lookup table if in Context, and set Backtrack and Lookahead on Context Lookup
		$str = "";
		$ignoreflag = 0;

		// Flag & 0xFF?? = MarkAttachmentType
		if ($flag & 0xFF00) {
			// "a lookup must ignore any mark glyphs that are not in the specified mark attachment class"
			// $this->MarkAttachmentType is already adjusted for this i.e. contains all Marks except those in the MarkAttachmentClassDef table
			$MarkAttachmentType = $flag >> 8;
			$ignoreflag = $flag;
			$str = $this->MarkAttachmentType[$MarkAttachmentType];
		}

		// Flag & 0x0010 = UseMarkFilteringSet
		if ($flag & 0x0010) {
			$ignoreflag = $flag;
			$str = $this->marksOutsideFilteringSet($this->GlyphClassMarks, $this->markGlyphSet($MarkFilteringSet));
		}

		// If Ignore Marks set, supercedes any above
		// Flag & 0x0008 = Ignore Marks - (unless already done with MarkAttachmentType)
		if (($flag & 0x0008) == 0x0008 && ($flag & 0xFF00) == 0) {
			$ignoreflag = 8;
			$str = $this->GlyphClassMarks;
		}

		// Flag & 0x0004 = Ignore Ligatures
		if (($flag & 0x0004) == 0x0004) {
			$ignoreflag += 4;
			if ($str) {
				$str .= "|";
			}
			$str .= $this->GlyphClassLigatures;
		}
		// Flag & 0x0002 = Ignore BaseGlyphs
		if (($flag & 0x0002) == 0x0002) {
			$ignoreflag += 2;
			if ($str) {
				$str .= "|";
			}
			$str .= $this->GlyphClassBases;
		}
		if ($str) {
			// This originally returned e.g. ((?:(?:[IGNORE8]))*) when NOT specific to a Lookup e.g. rtlSub in
			// arabictypesetting.GSUB.arab.DFLT.php
			// This would save repeatedly saving long text strings if used multiple times
			// When writing e.g. arabictypesetting.GSUB.arab.DFLT.php to file, included as $ignore[8]
			// Would need to also write the $ignore array to that file
			//		// If UseMarkFilteringSet (specific to the Lookup) return the string
			//		if (($flag & 0x0010) && ($flag & 0x0008) != 0x0008) {
			//			return "((?:(?:" . $str . "))*)";
			//		}
			//		else { return "((?:(?:" . "[IGNORE".$ignoreflag."]" . "))*)"; }
			//		// e.g. ((?:(?: 0031| 0032| 0033| 0034| 0045))*)
			// But never finished coding it to add the $ignore array to the file, and it doesn't seem to occur often enough to be worth
			// writing. So just output it as a string:
			return "((?:(?:" . $str . "))*)";
		} else {
			return "()";
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
		$mLen = count($lookupGlyphs); // nGlyphs in the secondary Lookup match
		$nInput = count($inputGlyphs); // nGlyphs in the Primary Input sequence
		$str = "";
		for ($i = 0; $i < $nInput; $i++) {
			if ($i > 0) {
				$str .= $ignore . " ";
			}
			if ($i >= $seqIndex && $i < ($seqIndex + $mLen)) {
				$str .= "(" . $lookupGlyphs[($i - $seqIndex)] . ")";
			} else {
				$str .= "(" . $inputGlyphs[($i)] . ")";
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
			$str .= "(" . $inputGlyphs[($i - 1)] . ")";
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
			$str .= "(" . $backtrackGlyphs[$i] . ")" . $ignore . " ";
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
			$str .= $ignore . " (" . $lookaheadGlyphs[$i] . ")";
		}

		return $str;
	}

	function _makeGSUBinputReplacement($nInput, $REPL, $ignore, $nBsubs, $mLen, $seqIndex)
	{
		// Returns e.g. "REPL\${6}\${8}" or "\${1}\${2} \${3} REPL\${4}\${6}\${8} \${9}"
		// $nInput	nGlyphs in the Primary Input sequence
		// $REPL 	replacement glyphs from secondary lookup
		// $ignore = "((?:(?: FBA1| FBA2| FBA3))*)" or "()"
		// $nBsubs	Number of Backtrack substitutions (= 2x Number of Backtrack glyphs)
		// $mLen 	nGlyphs in the secondary Lookup match - if no secondary lookup, should=$nInput
		// $seqIndex	Sequence Index to apply the secondary match
		if ($ignore == "()") {
			$ign = false;
		} else {
			$ign = true;
		}
		$str = "";
		if ($nInput == 1) {
			$str = $REPL;
		} elseif ($nInput > 1) {
			if ($mLen == $nInput) { // whole string replaced
				$str = $REPL;
				if ($ign) {
					// for every nInput over 1, add another replacement backreference, to move IGNORES after replacement
					for ($x = 2; $x <= $nInput; $x++) {
						$str .= '\\' . ($nBsubs + (2 * ($x - 1)));
					}
				}
			} else { // if only part of string replaced:
				for ($x = 1; $x < ($seqIndex + 1); $x++) {
					if ($x == 1) {
						$str .= '\\' . ($nBsubs + 1);
					} else {
						if ($ign) {
							$str .= '\\' . ($nBsubs + (2 * ($x - 1)));
						}
						$str .= ' \\' . ($nBsubs + 1 + (2 * ($x - 1)));
					}
				}
				if ($seqIndex > 0) {
					$str .= " ";
				}
				$str .= $REPL;
				if ($ign) {
					for ($x = (max(($seqIndex + 1), 2)); $x < ($seqIndex + 1 + $mLen); $x++) { //  move IGNORES after replacement
						$str .= '\\' . ($nBsubs + (2 * ($x - 1)));
					}
				}
				for ($x = ($seqIndex + 1 + $mLen); $x <= $nInput; $x++) {
					if ($ign) {
						$str .= '\\' . ($nBsubs + (2 * ($x - 1)));
					}
					$str .= ' \\' . ($nBsubs + 1 + (2 * ($x - 1)));
				}
			}
		}

		return $str;
	}

	/**
	 * A Coverage table, in whichever of three shapes the caller needs.
	 *
	 * @param bool $convert2hex Return the covered characters as hex strings
	 * @param int  $mode        2 returns unicode => Coverage Index, which is what indexes a
	 *                          subtable's parallel array of substitutions. Anything else, with
	 *                          $convert2hex false, returns the glyph IDs themselves.
	 */
	function _getCoverage($convert2hex = true, $mode = 1)
	{
		$glyphs = Coverage::glyphs($this->reader);

		if (!$convert2hex && $mode != 2) {
			return $glyphs;
		}

		$g = [];
		foreach ($glyphs as $index => $glyphID) {
			// A Coverage table may name a glyph no character reaches, and the position of every glyph
			// after it in the table still has to line up with the rules that index it
			$uni = isset($this->glyphToChar[$glyphID][0]) ? $this->glyphToChar[$glyphID][0] : 0;

			if ($convert2hex) {
				$g[] = unicode_hex($uni);
			} else {
				$g[$uni] = $index;
			}
		}

		return $g;
	}

	/**
	 * A Class Definition table as one "|"-separated hex string per class, which is the form the
	 * cached GSUB data carries and the shaper's ignore strings are matched against.
	 *
	 * Unlike Otl::_getClasses this keeps class 0, and unlike Otl it drops glyphs no character
	 * reaches rather than testing for them at match time.
	 *
	 * @return array class => "00041|00042|..."
	 */
	function _getClasses($offset)
	{
		$this->reader->seek($offset);
		$GlyphByClass = [];

		foreach (ClassDef::pairs($this->reader) as $pair) {
			list($glyphID, $class) = $pair;

			if (isset($this->glyphToChar[$glyphID][0])) {
				$GlyphByClass[$class][] = unicode_hex($this->glyphToChar[$glyphID][0]);
			}
		}

		$gbc = [];
		foreach ($GlyphByClass as $class => $garr) {
			$gbc[$class] = implode('|', $garr);
		}

		return $gbc;
	}

	function _getGPOStables()
	{
		///////////////////////////////////
		// GPOS - Glyph Positioning
		///////////////////////////////////
		if (!isset($this->tables["GPOS"])) {
			return [[], [], []];
		}

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
			$tag = $this->reader->readTag();
			if ($tag === 'kern') {
				$this->haskernGPOS = true;
			}
			$Feature[$i] = ['tag' => $tag];
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

		$gpos = [];
		$GPOSScriptLang = [];
		foreach ($ffeats as $st => $scripts) {

			foreach ($scripts as $t => $langsys) {

				$lg = [];
				foreach ($langsys as $ft) {
					if (isset($ft['LookupListIndex'][0])) {
						$lg[ $ft['LookupListIndex'][0] ] = $ft;
					}
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
				// Offset16 from the start of this Lookup table, stored relative to the start of GPOS.
				// See _getGSUBtables().
				$Lookup[$i]['Subtables'][$c] = $Offsets[$i] + $this->reader->readUInt16() - $gpos_offset;
			}
			// MarkFilteringSet = Index (base 0) into GDEF mark glyph sets structure
			if (($flag & 0x0010) === 0x0010) {
				$Lookup[$i]['MarkFilteringSet'] = $this->reader->readUInt16();
			} else {
				$Lookup[$i]['MarkFilteringSet'] = '';
			}

			// Lookup Type 9: Extension
			if ($Lookup[$i]['Type'] == 9) {
				// Overwrites new offset (32-bit) for each subtable, and a new lookup Type
				for ($c = 0; $c < $SubtableCount[$i]; $c++) {
					$this->reader->seek($gpos_offset + $Lookup[$i]['Subtables'][$c]);
					$ExtensionPosFormat = $this->reader->readUInt16();
					$type = $this->reader->readUInt16();
					// Offset32 from the start of the extension subtable, so this stays GPOS-relative
					$Lookup[$i]['Subtables'][$c] = $Lookup[$i]['Subtables'][$c] + $this->reader->readUInt32();
				}
				$Lookup[$i]['Type'] = $type;
			}
		}

		// Process Whole LookupList - Get LuCoverage = Lookup coverage just for first glyph
		$this->LuCoverage = [];
		for ($i = 0; $i < $LookupCount; $i++) {
			for ($c = 0; $c < $Lookup[$i]['SubtableCount']; $c++) {
				$subtable_offset = $gpos_offset + $Lookup[$i]['Subtables'][$c];
				$this->reader->seek($subtable_offset);
				$PosFormat = $this->reader->readUInt16();

				if ($Lookup[$i]['Type'] == 7 && $PosFormat == 3) {
					$this->reader->skip(4);
				} elseif ($Lookup[$i]['Type'] == 8 && $PosFormat == 3) {
					$BacktrackGlyphCount = $this->reader->readUInt16();
					$this->reader->skip(2 * $BacktrackGlyphCount + 2);
				}
				// Reading position 0's Coverage is the whole of what the gate needs. The shaper offers a
				// subtable the glyph it is standing on and asks whether a match could start there, and every
				// format puts the Coverage of the first position right here: straight after the format for
				// types 1 to 6 and for formats 1 and 2 of types 7 and 8, and after the counts and backtrack
				// offsets stepped over above for format 3. For types 4, 5 and 6 that Coverage is the mark's,
				// which is the right gate - those lookups are applied standing on the mark.
				$Coverage = $subtable_offset + $this->reader->readUInt16();
				$this->reader->seek($Coverage);
				$glyphs = $this->_getCoverage(false, 2);
				$this->LuCoverage[$i][$c] = $glyphs;
			}
		}

		$this->fontCache->jsonWrite($this->fontkey . '.GPOSdata.json', $this->LuCoverage);

		return [$GPOSScriptLang, $gpos, $Lookup];
	}

	function makeSubset($file, &$subset, $TTCfontID = 0, $debug = false, $useOTL = false)
	{
		$this->useOTL = $useOTL;
		$this->filename = $file;
		$this->reader = new FileReader($file);


		$this->charWidths = '';
		$this->glyphPos = [];
		$this->charToGlyph = [];
		$this->tables = [];
		$this->otables = [];
		$this->ascent = 0;
		$this->descent = 0;
		$this->strikeoutSize = 0;
		$this->strikeoutPosition = 0;
		$this->numTTCFonts = 0;
		$this->TTCFonts = [];
		$this->reader->skip(4);
		$this->maxUni = 0;

		if ($TTCfontID > 0) {
			$this->version = $version = $this->reader->readUInt32(); // TTC Header version now
			if (!in_array($version, [0x00010000, 0x00020000], true)) {
				throw new \Mpdf\Exception\FontException(sprintf('Error parsing TrueType Collection: version=%s - %s', $version, $file));
			}
			$this->numTTCFonts = $this->reader->readUInt32();
			for ($i = 1; $i <= $this->numTTCFonts; $i++) {
				$this->TTCFonts[$i]['offset'] = $this->reader->readUInt32();
			}
			$this->reader->seek($this->TTCFonts[$TTCfontID]['offset']);
			$this->version = $version = $this->reader->readUInt32(); // TTFont version again now
		}
		$this->readTableDirectory($debug);

		// head - Font header table
		$this->seek_table('head');
		$this->reader->skip(50);
		$indexToLocFormat = $this->reader->readUInt16();
		$glyphDataFormat = $this->reader->readUInt16();

		// hhea - Horizontal header table
		$this->seek_table('hhea');
		$this->reader->skip(32);
		$metricDataFormat = $this->reader->readUInt16();
		$orignHmetrics = $numberOfHMetrics = $this->reader->readUInt16();

		// maxp - Maximum profile table
		$this->seek_table('maxp');
		$this->reader->skip(4);
		$numGlyphs = $this->reader->readUInt16();

		// cmap - Character to glyph index mapping table
		$cmap_offset = $this->seek_table('cmap');
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
					$unicode_cmap_offset = $cmap_offset + $offset;
					break;
				}
			}
			$this->reader->seek($save_pos);
		}

		if (!$unicode_cmap_offset) {
			throw new \Mpdf\Exception\FontException(sprintf('Font "%s" does not have Unicode cmap (platform 3, encoding 1, format 4, or platform 0 [any encoding] format 4)', $this->filename));
		}

		$glyphToChar = [];
		$charToGlyph = [];
		$this->getCMAP4($unicode_cmap_offset, $glyphToChar, $charToGlyph);

		// Map Unmapped glyphs - from $numGlyphs
		if ($useOTL) {
			$bctr = 0xE000;
			for ($gid = 1; $gid < $numGlyphs; $gid++) {
				if (!isset($glyphToChar[$gid])) {
					while (isset($charToGlyph[$bctr])) {
						$bctr++;
					} // Avoid overwriting a glyph already mapped in PUA
					if ($bctr > 0xF8FF) {
						throw new \Mpdf\Exception\FontException($file . " : WARNING - Font cannot map all included glyphs into Private Use Area U+E000 - U+F8FF; cannot use useOTL on this font");
					}
					$glyphToChar[$gid][] = $bctr;
					$charToGlyph[$bctr] = $gid;
					$bctr++;
				}
			}
		}

		$this->charToGlyph = $charToGlyph;
		$this->glyphToChar = $glyphToChar;

		// hmtx - Horizontal metrics table
		$scale = 1; // not used
		$this->getHMTX($numberOfHMetrics, $numGlyphs, $glyphToChar, $scale);

		// loca - Index to location
		$this->getLOCA($indexToLocFormat, $numGlyphs);

		$subsetglyphs = [0 => 0, 1 => 1, 2 => 2];
		$subsetCharToGlyph = [];
		foreach ($subset as $code) {
			if (isset($this->charToGlyph[$code])) {
				$subsetglyphs[$this->charToGlyph[$code]] = $code; // Old Glyph ID => Unicode
				$subsetCharToGlyph[$code] = $this->charToGlyph[$code]; // Unicode to old GlyphID
			}
			$this->maxUni = max($this->maxUni, $code);
		}

		list($start, $dummy) = $this->get_table_pos('glyf');

		$glyphSet = [];
		ksort($subsetglyphs);
		$n = 0;
		$fsLastCharIndex = 0; // maximum Unicode index (character code) in this font, according to the cmap subtable for platform ID 3 and platform- specific encoding ID 0 or 1.
		foreach ($subsetglyphs as $originalGlyphIdx => $uni) {
			$fsLastCharIndex = max($fsLastCharIndex, $uni);
			$glyphSet[$originalGlyphIdx] = $n; // old glyphID to new glyphID
			$n++;
		}

		$codeToGlyph = [];
		ksort($subsetCharToGlyph);
		foreach ($subsetCharToGlyph as $uni => $originalGlyphIdx) {
			$codeToGlyph[$uni] = $glyphSet[$originalGlyphIdx];
		}
		$this->codeToGlyph = $codeToGlyph;

		ksort($subsetglyphs);
		foreach ($subsetglyphs as $originalGlyphIdx => $uni) {
			$this->getGlyphs($originalGlyphIdx, $start, $glyphSet, $subsetglyphs);
		}

		$numGlyphs = $numberOfHMetrics = count($subsetglyphs);

		// name - table copied from the original
		// MS spec says that "Platform and encoding ID's in the name table should be consistent with those in the cmap table.
		// If they are not, the font will not load in Windows"
		// Doesn't seem to be a problem?
		$this->add('name', $this->get_table('name'));

		// tables copied from the original
		$tags = ['cvt ', 'fpgm', 'prep', 'gasp'];
		foreach ($tags as $tag) {
			if (isset($this->tables[$tag])) {
				$this->add($tag, $this->get_table($tag));
			}
		}

		// post - PostScript
		if (isset($this->tables['post'])) {
			$opost = $this->get_table('post');
			$post = "\x00\x03\x00\x00" . substr($opost, 4, 12) . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
			$this->add('post', $post);
		}

		// Sort CID2GID map into segments of contiguous codes
		ksort($codeToGlyph);
		unset($codeToGlyph[0]);

		$rangeid = 0;
		$range = [];
		$prevcid = -2;
		$prevglidx = -1;

		// for each character
		foreach ($codeToGlyph as $cid => $glidx) {
			if ($cid == ($prevcid + 1) && $glidx == ($prevglidx + 1)) {
				$range[$rangeid][] = $glidx;
			} else {
				// new range
				$rangeid = $cid;
				$range[$rangeid] = [];
				$range[$rangeid][] = $glidx;
			}
			$prevcid = $cid;
			$prevglidx = $glidx;
		}

		// cmap - Character to glyph mapping
		$segCount = count($range) + 1; // + 1 Last segment has missing character 0xFFFF
		$searchRange = 1;
		$entrySelector = 0;

		while ($searchRange * 2 <= $segCount) {
			$searchRange *= 2;
			++$entrySelector;
		}

		$searchRange *= 2;
		$rangeShift = $segCount * 2 - $searchRange;
		$length = 16 + (8 * $segCount) + ($numGlyphs + 1);
		$cmap = [
			0, 3, // Index : version, number of encoding subtables
			0, 0, // Encoding Subtable : platform (UNI=0), encoding 0
			0, 28, // Encoding Subtable : offset (hi,lo)
			0, 3, // Encoding Subtable : platform (UNI=0), encoding 3
			0, 28, // Encoding Subtable : offset (hi,lo)
			3, 1, // Encoding Subtable : platform (MS=3), encoding 1
			0, 28, // Encoding Subtable : offset (hi,lo)
			4, $length, 0, // Format 4 Mapping subtable: format, length, language
			$segCount * 2,
			$searchRange,
			$entrySelector,
			$rangeShift,
		];

		// endCode(s)
		foreach ($range as $start => $subrange) {
			$endCode = $start + (count($subrange) - 1);
			$cmap[] = $endCode; // endCode(s)
		}

		$cmap[] = 0xFFFF; // endCode of last Segment
		$cmap[] = 0; // reservedPad

		// startCode(s)
		foreach ($range as $start => $subrange) {
			$cmap[] = $start; // startCode(s)
		}

		$cmap[] = 0xFFFF; // startCode of last Segment

		// idDelta(s)
		foreach ($range as $start => $subrange) {
			$idDelta = -($start - $subrange[0]);
			$n += count($subrange);
			$cmap[] = $idDelta; // idDelta(s)
		}

		$cmap[] = 1; // idDelta of last Segment
		// idRangeOffset(s)

		foreach ($range as $subrange) {
			$cmap[] = 0; // idRangeOffset[segCount]  	Offset in bytes to glyph indexArray, or 0
		}

		$cmap[] = 0; // idRangeOffset of last Segment
		foreach ($range as $subrange) {
			foreach ($subrange as $glidx) {
				$cmap[] = $glidx;
			}
		}

		$cmap[] = 0; // Mapping for last character
		$cmapstr = '';

		foreach ($cmap as $cm) {
			$cmapstr .= pack('n', $cm);
		}
		$this->add('cmap', $cmapstr);

		// glyf - Glyph data
		list($glyfOffset, $glyfLength) = $this->get_table_pos('glyf');
		if ($glyfLength < $this->maxStrLenRead) {
			$glyphData = $this->get_table('glyf');
		}

		$offsets = [];
		$glyf = '';
		$pos = 0;
		$hmtxstr = '';
		$xMinT = 0;
		$yMinT = 0;
		$xMaxT = 0;
		$yMaxT = 0;
		$advanceWidthMax = 0;
		$minLeftSideBearing = 0;
		$minRightSideBearing = 0;
		$xMaxExtent = 0;
		$maxPoints = 0; // points in non-compound glyph
		$maxContours = 0; // contours in non-compound glyph
		$maxComponentPoints = 0; // points in compound glyph
		$maxComponentContours = 0; // contours in compound glyph
		$maxComponentElements = 0; // number of glyphs referenced at top level
		$maxComponentDepth = 0; // levels of recursion, set to 0 if font has only simple glyphs
		$this->glyphdata = [];

		foreach ($subsetglyphs as $originalGlyphIdx => $uni) {
			// hmtx - Horizontal Metrics
			$hm = $this->getHMetric($orignHmetrics, $originalGlyphIdx);
			$hmtxstr .= $hm;

			$offsets[] = $pos;
			$glyphPos = $this->glyphPos[$originalGlyphIdx];
			$glyphLen = $this->glyphPos[$originalGlyphIdx + 1] - $glyphPos;
			if ($glyfLength < $this->maxStrLenRead) {
				$data = substr($glyphData, $glyphPos, $glyphLen);
			} else {
				if ($glyphLen > 0) {
					$data = $this->reader->bytesAt($glyfOffset + $glyphPos, $glyphLen);
				} else {
					$data = '';
				}
			}

			if ($glyphLen > 0) {
				if (_RECALC_PROFILE) {
					$xMin = FontReader::int16(substr($data, 2, 2));
					$yMin = FontReader::int16(substr($data, 4, 2));
					$xMax = FontReader::int16(substr($data, 6, 2));
					$yMax = FontReader::int16(substr($data, 8, 2));
					$xMinT = min($xMinT, $xMin);
					$yMinT = min($yMinT, $yMin);
					$xMaxT = max($xMaxT, $xMax);
					$yMaxT = max($yMaxT, $yMax);
					$aw = FontReader::int16(substr($hm, 0, 2));
					$lsb = FontReader::int16(substr($hm, 2, 2));
					$advanceWidthMax = max($advanceWidthMax, $aw);
					$minLeftSideBearing = min($minLeftSideBearing, $lsb);
					$minRightSideBearing = min($minRightSideBearing, ($aw - $lsb - ($xMax - $xMin)));
					$xMaxExtent = max($xMaxExtent, ($lsb + ($xMax - $xMin)));
				}
				$up = unpack("n", substr($data, 0, 2));
			}
			if ($glyphLen > 2 && ($up[1] & (1 << 15))) { // If number of contours <= -1 i.e. composiste glyph
				$pos_in_glyph = 10;
				$flags = GlyphOperator::MORE;
				$nComponentElements = 0;
				while ($flags & GlyphOperator::MORE) {
					$nComponentElements += 1; // number of glyphs referenced at top level
					$up = unpack("n", substr($data, $pos_in_glyph, 2));
					$flags = $up[1];
					$up = unpack("n", substr($data, $pos_in_glyph + 2, 2));
					$glyphIdx = $up[1];
					$this->glyphdata[$originalGlyphIdx]['compGlyphs'][] = $glyphIdx;
					$data = $this->_set_ushort($data, $pos_in_glyph + 2, $glyphSet[$glyphIdx]);
					$pos_in_glyph += 4;
					if ($flags & GlyphOperator::WORDS) {
						$pos_in_glyph += 4;
					} else {
						$pos_in_glyph += 2;
					}
					if ($flags & GlyphOperator::SCALE) {
						$pos_in_glyph += 2;
					} elseif ($flags & GlyphOperator::XYSCALE) {
						$pos_in_glyph += 4;
					} elseif ($flags & GlyphOperator::TWOBYTWO) {
						$pos_in_glyph += 8;
					}
				}
				$maxComponentElements = max($maxComponentElements, $nComponentElements);

			} // Simple Glyph
			elseif (_RECALC_PROFILE && $glyphLen > 2 && $up[1] < (1 << 15) && $up[1] > 0) {  // Number of contours > 0 simple glyph
				$nContours = $up[1];
				$this->glyphdata[$originalGlyphIdx]['nContours'] = $nContours;
				$maxContours = max($maxContours, $nContours);

				// Count number of points in simple glyph
				$pos_in_glyph = 10 + ($nContours * 2) - 2; // Last endContourPoint
				$up = unpack("n", substr($data, $pos_in_glyph, 2));
				$points = $up[1] + 1;
				$this->glyphdata[$originalGlyphIdx]['nPoints'] = $points;
				$maxPoints = max($maxPoints, $points);
			}

			$glyf .= $data;
			$pos += $glyphLen;
			if ($pos % 4 != 0) {
				$padding = 4 - ($pos % 4);
				$glyf .= str_repeat("\0", $padding);
				$pos += $padding;
			}
		}

		if (_RECALC_PROFILE) {
			foreach ($this->glyphdata as $originalGlyphIdx => $val) {
				$maxdepth = $depth = -1;
				$points = 0;
				$contours = 0;
				$this->getGlyphData($originalGlyphIdx, $maxdepth, $depth, $points, $contours);
				$maxComponentDepth = max($maxComponentDepth, $maxdepth);
				$maxComponentPoints = max($maxComponentPoints, $points);
				$maxComponentContours = max($maxComponentContours, $contours);
			}
		}

		$offsets[] = $pos;
		$this->add('glyf', $glyf);

		// hmtx - Horizontal Metrics
		$this->add('hmtx', $hmtxstr);

		// loca - Index to location
		$locastr = '';
		if ((($pos + 1) >> 1) > 0xFFFF) {
			$indexToLocFormat = 1; // long format
			foreach ($offsets as $offset) {
				$locastr .= pack("N", $offset);
			}
		} else {
			$indexToLocFormat = 0; // short format
			foreach ($offsets as $offset) {
				$locastr .= pack("n", ($offset / 2));
			}
		}
		$this->add('loca', $locastr);

		// head - Font header
		$head = $this->get_table('head');
		$head = $this->_set_ushort($head, 50, $indexToLocFormat);

		if (_RECALC_PROFILE) {
			$head = $this->_set_short($head, 36, $xMinT); // for all glyph bounding boxes
			$head = $this->_set_short($head, 38, $yMinT); // for all glyph bounding boxes
			$head = $this->_set_short($head, 40, $xMaxT); // for all glyph bounding boxes
			$head = $this->_set_short($head, 42, $yMaxT); // for all glyph bounding boxes
			$head[17] = chr($head[17] & ~(1 << 4)); // Unset Bit 4 (as hdmx/LTSH tables not included)
		}

		$this->add('head', $head);

		// hhea - Horizontal Header
		$hhea = $this->get_table('hhea');
		$hhea = $this->_set_ushort($hhea, 34, $numberOfHMetrics);
		if (_RECALC_PROFILE) {
			$hhea = $this->_set_ushort($hhea, 10, $advanceWidthMax);
			$hhea = $this->_set_short($hhea, 12, $minLeftSideBearing);
			$hhea = $this->_set_short($hhea, 14, $minRightSideBearing);
			$hhea = $this->_set_short($hhea, 16, $xMaxExtent);
		}
		$this->add('hhea', $hhea);

		// maxp - Maximum Profile
		$maxp = $this->get_table('maxp');
		$maxp = $this->_set_ushort($maxp, 4, $numGlyphs);
		if (_RECALC_PROFILE) {
			$maxp = $this->_set_ushort($maxp, 6, $maxPoints); // points in non-compound glyph
			$maxp = $this->_set_ushort($maxp, 8, $maxContours); // contours in non-compound glyph
			$maxp = $this->_set_ushort($maxp, 10, $maxComponentPoints); // points in compound glyph
			$maxp = $this->_set_ushort($maxp, 12, $maxComponentContours); // contours in compound glyph
			$maxp = $this->_set_ushort($maxp, 28, $maxComponentElements); // number of glyphs referenced at top level
			$maxp = $this->_set_ushort($maxp, 30, $maxComponentDepth); // levels of recursion, set to 0 if font has only simple glyphs
		}
		$this->add('maxp', $maxp);

		// OS/2 - OS/2
		if (isset($this->tables['OS/2'])) {
			$os2_offset = $this->seek_table("OS/2");
			if (_RECALC_PROFILE) {
				$fsSelection = $this->reader->uint16At($os2_offset + 62);
				$fsSelection = ($fsSelection & ~(1 << 6)); // 2-byte bit field containing information concerning the nature of the font patterns
				// bit#0 = Italic; bit#5=Bold
				// Match name table's font subfamily string
				// Clear bit#6 used for 'Regular' and optional
			}

			// NB Currently this method never subsets characters above BMP
			// Could set nonBMP bit according to $this->maxUni
			$nonBMP = $this->reader->uint16At($os2_offset + 46);
			$nonBMP = ($nonBMP & ~(1 << 9)); // Unset Bit 57 (indicates non-BMP) - for interactive forms

			$os2 = $this->get_table('OS/2');
			if (_RECALC_PROFILE) {
				$os2 = $this->_set_ushort($os2, 62, $fsSelection);
				$os2 = $this->_set_ushort($os2, 66, $fsLastCharIndex);
				$os2 = $this->_set_ushort($os2, 42, 0x0000); // ulCharRange (ulUnicodeRange) bits 24-31 | 16-23
				$os2 = $this->_set_ushort($os2, 44, 0x0000); // ulCharRange (Unicode ranges) bits  8-15 |  0-7
				$os2 = $this->_set_ushort($os2, 46, $nonBMP); // ulCharRange (Unicode ranges) bits 56-63 | 48-55
				$os2 = $this->_set_ushort($os2, 48, 0x0000); // ulCharRange (Unicode ranges) bits 40-47 | 32-39
				$os2 = $this->_set_ushort($os2, 50, 0x0000); // ulCharRange (Unicode ranges) bits  88-95 | 80-87
				$os2 = $this->_set_ushort($os2, 52, 0x0000); // ulCharRange (Unicode ranges) bits  72-79 | 64-71
				$os2 = $this->_set_ushort($os2, 54, 0x0000); // ulCharRange (Unicode ranges) bits  120-127 | 112-119
				$os2 = $this->_set_ushort($os2, 56, 0x0000); // ulCharRange (Unicode ranges) bits  104-111 | 96-103
			}
			$os2 = $this->_set_ushort($os2, 46, $nonBMP); // Unset Bit 57 (indicates non-BMP) - for interactive forms

			$this->add('OS/2', $os2);
		}

		$this->reader->close();

		// Put the TTF file together
		$stm = '';
		$this->endTTFile($stm);

		return $stm;
	}

	function makeSubsetSIP($file, &$subset, $TTCfontID = 0, $debug = false, $useOTL = 0)
	{
		$this->reader = new FileReader($file);


		$this->filename = $file;
		$this->useOTL = $useOTL; // mPDF 5.7.1
		$this->charWidths = '';
		$this->glyphPos = [];
		$this->charToGlyph = [];
		$this->tables = [];
		$this->otables = [];
		$this->ascent = 0;
		$this->descent = 0;
		$this->strikeoutSize = 0;
		$this->strikeoutPosition = 0;
		$this->numTTCFonts = 0;
		$this->TTCFonts = [];
		$this->reader->skip(4);

		if ($TTCfontID > 0) {
			$this->version = $version = $this->reader->readUInt32(); // TTC Header version now
			if (!in_array($version, [0x00010000, 0x00020000])) {
				throw new \Mpdf\Exception\FontException("ERROR - Error parsing TrueType Collection: version=" . $version . " - " . $file);
			}
			$this->numTTCFonts = $this->reader->readUInt32();
			for ($i = 1; $i <= $this->numTTCFonts; $i++) {
				$this->TTCFonts[$i]['offset'] = $this->reader->readUInt32();
			}
			$this->reader->seek($this->TTCFonts[$TTCfontID]['offset']);
			$this->version = $version = $this->reader->readUInt32(); // TTFont version again now
		}
		$this->readTableDirectory($debug);

		// head - Font header table
		$this->seek_table('head');
		$this->reader->skip(50);
		$indexToLocFormat = $this->reader->readUInt16();
		$glyphDataFormat = $this->reader->readUInt16();

		// hhea - Horizontal header table
		$this->seek_table('hhea');
		$this->reader->skip(32);
		$metricDataFormat = $this->reader->readUInt16();
		$orignHmetrics = $numberOfHMetrics = $this->reader->readUInt16();

		// maxp - Maximum profile table
		$this->seek_table('maxp');
		$this->reader->skip(4);
		$numGlyphs = $this->reader->readUInt16();

		// cmap - Character to glyph index mapping table
		$cmap_offset = $this->seek_table('cmap');
		$this->reader->skip(2);
		$cmapTableCount = $this->reader->readUInt16();
		$unicode_cmap_offset = 0;
		for ($i = 0; $i < $cmapTableCount; $i++) {

			$platformID = $this->reader->readUInt16();
			$encodingID = $this->reader->readUInt16();
			$offset = $this->reader->readUInt32();
			$save_pos = $this->reader->tell();

			if (($platformID == 3 && $encodingID == 10) || $platformID == 0) { // Microsoft, Unicode Format 12 table HKCS
				$format = $this->reader->uint16At($cmap_offset + $offset);
				if ($format == 12) {
					$unicode_cmap_offset = $cmap_offset + $offset;
					break;
				}
			}

			if (($platformID == 3 && $encodingID == 1) || $platformID == 0) { // Microsoft, Unicode
				$format = $this->reader->uint16At($cmap_offset + $offset);
				if ($format == 4) {
					$unicode_cmap_offset = $cmap_offset + $offset;
				}
			}

			$this->reader->seek($save_pos);
		}

		if (!$unicode_cmap_offset) {
			throw new \Mpdf\Exception\FontException(sprintf('Font "%s" does not have cmap for Unicode (platform 3, encoding 1, format 4, or platform 0, any encoding, format 4)', $file));
		}

		// Format 12 CMAP does characters above Unicode BMP i.e. some HKCS characters U+20000 and above
		if ($format == 12) {
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
				$offset = 0;
				for ($unichar = $startCharCode; $unichar <= $endCharCode; $unichar++) {
					$glyph = $startGlyphCode + $offset;
					$offset++;
					// ZZZ98
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

		// Map Unmapped glyphs - from $numGlyphs
		if ($useOTL) {
			$bctr = 0xE000;
			for ($gid = 1; $gid < $numGlyphs; $gid++) {
				if (!isset($glyphToChar[$gid])) {
					while (isset($charToGlyph[$bctr])) {
						$bctr++;
					} // Avoid overwriting a glyph already mapped in PUA
					// ZZZ98
					if ($bctr > 0xF8FF && $bctr < 0x2CEB0) {
						$bctr = 0x2CEB0;
						while (isset($charToGlyph[$bctr])) {
							$bctr++;
						}
					}
					$glyphToChar[$gid][] = $bctr;
					$charToGlyph[$bctr] = $gid;
					$this->maxUniChar = max($bctr, $this->maxUniChar);
					$bctr++;
				}
			}
		}

		// hmtx - Horizontal metrics table
		$scale = 1; // not used here
		$this->getHMTX($numberOfHMetrics, $numGlyphs, $glyphToChar, $scale);

		// loca - Index to location
		$this->getLOCA($indexToLocFormat, $numGlyphs);

		$glyphMap = [0 => 0];
		$glyphSet = [0 => 0];
		$codeToGlyph = [];

		// Set a substitute if ASCII characters do not have glyphs
		if (isset($charToGlyph[0x3F])) {
			$subs = $charToGlyph[0x3F];
		} else { // Question mark
			$subs = $charToGlyph[32];
		}

		foreach ($subset as $code) {
			if (isset($charToGlyph[$code])) {
				$originalGlyphIdx = $charToGlyph[$code];
			} elseif ($code < 128) {
				$originalGlyphIdx = $subs;
			} else {
				$originalGlyphIdx = 0;
			}
			if (!isset($glyphSet[$originalGlyphIdx])) {
				$glyphSet[$originalGlyphIdx] = count($glyphMap);
				$glyphMap[] = $originalGlyphIdx;
			}
			$codeToGlyph[$code] = $glyphSet[$originalGlyphIdx];
		}

		list($start, $dummy) = $this->get_table_pos('glyf');

		$n = 0;
		while ($n < count($glyphMap)) {
			$originalGlyphIdx = $glyphMap[$n];
			$glyphPos = $this->glyphPos[$originalGlyphIdx];
			$glyphLen = $this->glyphPos[$originalGlyphIdx + 1] - $glyphPos;
			++$n;
			if (!$glyphLen) {
				continue;
			}
			$this->reader->seek($start + $glyphPos);
			$numberOfContours = $this->reader->readInt16();
			if ($numberOfContours < 0) {
				$this->reader->skip(8);
				$flags = GlyphOperator::MORE;
				while ($flags & GlyphOperator::MORE) {
					$flags = $this->reader->readUInt16();
					$glyphIdx = $this->reader->readUInt16();
					if (!isset($glyphSet[$glyphIdx])) {
						$glyphSet[$glyphIdx] = count($glyphMap);
						$glyphMap[] = $glyphIdx;
					}
					if ($flags & GlyphOperator::WORDS) {
						$this->reader->skip(4);
					} else {
						$this->reader->skip(2);
					}
					if ($flags & GlyphOperator::SCALE) {
						$this->reader->skip(2);
					} elseif ($flags & GlyphOperator::XYSCALE) {
						$this->reader->skip(4);
					} elseif ($flags & GlyphOperator::TWOBYTWO) {
						$this->reader->skip(8);
					}
				}
			}
		}

		$numGlyphs = $n = count($glyphMap);
		$numberOfHMetrics = $n;

		// MS spec says that "Platform and encoding ID's in the name table should be consistent with those in the cmap table.
		// If they are not, the font will not load in Windows"
		// Doesn't seem to be a problem?
		// Needs to have a name entry in 3,0 (e.g. symbol) - original font will be 3,1 (i.e. Unicode)
		$name = $this->get_table('name');
		$name_offset = $this->seek_table("name");
		$format = $this->reader->readUInt16();
		$numRecords = $this->reader->readUInt16();
		$string_data_offset = $name_offset + $this->reader->readUInt16();
		for ($i = 0; $i < $numRecords; $i++) {
			$platformId = $this->reader->readUInt16();
			$encodingId = $this->reader->readUInt16();
			if ($platformId == 3 && $encodingId == 1) {
				$pos = 6 + ($i * 12) + 2;
				$name = $this->_set_ushort($name, $pos, 0x00); // Change encoding to 3,0 rather than 3,1
			}
			$this->reader->skip(8);
		}
		$this->add('name', $name);

		// OS/2
		if (isset($this->tables['OS/2'])) {
			$os2 = $this->get_table('OS/2');
			$os2 = $this->_set_ushort($os2, 42, 0x00); // ulCharRange (Unicode ranges)
			$os2 = $this->_set_ushort($os2, 44, 0x00); // ulCharRange (Unicode ranges)
			$os2 = $this->_set_ushort($os2, 46, 0x00); // ulCharRange (Unicode ranges)
			$os2 = $this->_set_ushort($os2, 48, 0x00); // ulCharRange (Unicode ranges)

			$os2 = $this->_set_ushort($os2, 50, 0x00); // ulCharRange (Unicode ranges)
			$os2 = $this->_set_ushort($os2, 52, 0x00); // ulCharRange (Unicode ranges)
			$os2 = $this->_set_ushort($os2, 54, 0x00); // ulCharRange (Unicode ranges)
			$os2 = $this->_set_ushort($os2, 56, 0x00); // ulCharRange (Unicode ranges)
			// Set Symbol character only in ulCodePageRange
			$os2 = $this->_set_ushort($os2, 78, 0x8000); // ulCodePageRange = Bit #31 Symbol ****  78 = Bit 16-31
			$os2 = $this->_set_ushort($os2, 80, 0x0000); // ulCodePageRange = Bit #31 Symbol ****  80 = Bit 0-15
			$os2 = $this->_set_ushort($os2, 82, 0x0000); // ulCodePageRange = Bit #32- Symbol **** 82 = Bits 48-63
			$os2 = $this->_set_ushort($os2, 84, 0x0000); // ulCodePageRange = Bit #32- Symbol **** 84 = Bits 32-47

			$os2 = $this->_set_ushort($os2, 64, 0x01); // FirstCharIndex
			$os2 = $this->_set_ushort($os2, 66, count($subset)); // LastCharIndex
			// Set PANOSE first bit to 5 for Symbol
			$os2 = $this->splice($os2, 32, chr(5) . chr(0) . chr(1) . chr(0) . chr(1) . chr(0) . chr(0) . chr(0) . chr(0) . chr(0));
			$this->add('OS/2', $os2);
		}

		//tables copied from the original
		$tags = ['cvt ', 'fpgm', 'prep', 'gasp'];
		foreach ($tags as $tag) {  // 1.02
			if (isset($this->tables[$tag])) {
				$this->add($tag, $this->get_table($tag));
			}
		}

		// post - PostScript
		if (isset($this->tables['post'])) {
			$opost = $this->get_table('post');
			$post = "\x00\x03\x00\x00" . substr($opost, 4, 12) . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
		}
		$this->add('post', $post);

		// hhea - Horizontal Header
		$hhea = $this->get_table('hhea');
		$hhea = $this->_set_ushort($hhea, 34, $numberOfHMetrics);
		$this->add('hhea', $hhea);

		// maxp - Maximum Profile
		$maxp = $this->get_table('maxp');
		$maxp = $this->_set_ushort($maxp, 4, $numGlyphs);
		$this->add('maxp', $maxp);

		// CMap table Formats [1,0,]6 and [3,0,]4
		// Sort CID2GID map into segments of contiguous codes
		$rangeid = 0;
		$range = [];
		$prevcid = -2;
		$prevglidx = -1;

		// for each character
		foreach ($subset as $cid => $code) {
			$glidx = $codeToGlyph[$code];
			if ($cid == ($prevcid + 1) && $glidx == ($prevglidx + 1)) {
				$range[$rangeid][] = $glidx;
			} else {
				// new range
				$rangeid = $cid;
				$range[$rangeid] = [];
				$range[$rangeid][] = $glidx;
			}
			$prevcid = $cid;
			$prevglidx = $glidx;
		}

		// cmap - Character to glyph mapping
		$segCount = count($range) + 1; // + 1 Last segment has missing character 0xFFFF
		$searchRange = 1;
		$entrySelector = 0;

		while ($searchRange * 2 <= $segCount) {
			$searchRange = $searchRange * 2;
			$entrySelector = $entrySelector + 1;
		}

		$searchRange = $searchRange * 2;
		$rangeShift = $segCount * 2 - $searchRange;
		$length = 16 + (8 * $segCount) + ($numGlyphs + 1);
		$cmap = [
			4, $length, 0, // Format 4 Mapping subtable: format, length, language
			$segCount * 2,
			$searchRange,
			$entrySelector,
			$rangeShift,
		];

		// endCode(s)
		foreach ($range as $start => $subrange) {
			$endCode = $start + (count($subrange) - 1);
			$cmap[] = $endCode; // endCode(s)
		}
		$cmap[] = 0xFFFF; // endCode of last Segment
		$cmap[] = 0; // reservedPad

		// startCode(s)
		foreach ($range as $start => $subrange) {
			$cmap[] = $start; // startCode(s)
		}
		$cmap[] = 0xFFFF; // startCode of last Segment

		// idDelta(s)
		foreach ($range as $start => $subrange) {
			$idDelta = -($start - $subrange[0]);
			$n += count($subrange);
			$cmap[] = $idDelta; // idDelta(s)
		}
		$cmap[] = 1; // idDelta of last Segment

		// idRangeOffset(s)
		foreach ($range as $subrange) {
			$cmap[] = 0; // idRangeOffset[segCount]  	Offset in bytes to glyph indexArray, or 0
		}

		$cmap[] = 0; // idRangeOffset of last Segment
		foreach ($range as $subrange) {
			foreach ($subrange as $glidx) {
				$cmap[] = $glidx;
			}
		}

		$cmap[] = 0; // Mapping for last character
		$cmapstr4 = '';
		foreach ($cmap as $cm) {
			$cmapstr4 .= pack("n", $cm);
		}

		// cmap - Character to glyph mapping
		$entryCount = count($subset);
		$length = 10 + $entryCount * 2;

		$off = 20 + $length;
		$hoff = $off >> 16;
		$loff = $off & 0xFFFF;

		$cmap = [
			0, 2, // Index : version, number of subtables
			1, 0, // Subtable : platform, encoding
			0, 20, // offset (hi,lo)
			3, 0, // Subtable : platform, encoding	// See note above for 'name'
			$hoff, $loff, // offset (hi,lo)
			6, $length, // Format 6 Mapping table: format, length
			0, 1, // language, First char code
			$entryCount,
		];

		$cmapstr = '';
		foreach ($subset as $code) {
			$cmap[] = $codeToGlyph[$code];
		}

		foreach ($cmap as $cm) {
			$cmapstr .= pack("n", $cm);
		}

		$cmapstr .= $cmapstr4;
		$this->add('cmap', $cmapstr);

		// hmtx - Horizontal Metrics
		$hmtxstr = '';
		for ($n = 0; $n < $numGlyphs; $n++) {
			$originalGlyphIdx = $glyphMap[$n];
			$hm = $this->getHMetric($orignHmetrics, $originalGlyphIdx);
			$hmtxstr .= $hm;
		}
		$this->add('hmtx', $hmtxstr);

		// glyf - Glyph data
		list($glyfOffset, $glyfLength) = $this->get_table_pos('glyf');
		if ($glyfLength < $this->maxStrLenRead) {
			$glyphData = $this->get_table('glyf');
		}

		$offsets = [];
		$glyf = '';
		$pos = 0;
		for ($n = 0; $n < $numGlyphs; $n++) {

			$offsets[] = $pos;
			$originalGlyphIdx = $glyphMap[$n];
			$glyphPos = $this->glyphPos[$originalGlyphIdx];
			$glyphLen = $this->glyphPos[$originalGlyphIdx + 1] - $glyphPos;

			if ($glyfLength < $this->maxStrLenRead) {
				$data = substr($glyphData, $glyphPos, $glyphLen);
			} else {
				if ($glyphLen > 0) {
					$data = $this->reader->bytesAt($glyfOffset + $glyphPos, $glyphLen);
				} else {
					$data = '';
				}
			}

			if ($glyphLen > 0) {
				$up = unpack('n', substr($data, 0, 2));
			}

			if ($glyphLen > 2 && ($up[1] & (1 << 15))) {

				$pos_in_glyph = 10;
				$flags = GlyphOperator::MORE;

				while ($flags & GlyphOperator::MORE) {
					$up = unpack('n', substr($data, $pos_in_glyph, 2));
					$flags = $up[1];
					$up = unpack('n', substr($data, $pos_in_glyph + 2, 2));
					$glyphIdx = $up[1];
					$data = $this->_set_ushort($data, $pos_in_glyph + 2, $glyphSet[$glyphIdx]);
					$pos_in_glyph += 4;

					if ($flags & GlyphOperator::WORDS) {
						$pos_in_glyph += 4;
					} else {
						$pos_in_glyph += 2;
					}

					if ($flags & GlyphOperator::SCALE) {
						$pos_in_glyph += 2;
					} elseif ($flags & GlyphOperator::XYSCALE) {
						$pos_in_glyph += 4;
					} elseif ($flags & GlyphOperator::TWOBYTWO) {
						$pos_in_glyph += 8;
					}
				}
			}

			$glyf .= $data;
			$pos += $glyphLen;

			if ($pos % 4 != 0) {
				$padding = 4 - ($pos % 4);
				$glyf .= str_repeat("\0", $padding);
				$pos += $padding;
			}
		}

		$offsets[] = $pos;
		$this->add('glyf', $glyf);

		// loca - Index to location
		$locastr = '';
		if ((($pos + 1) >> 1) > 0xFFFF) {
			$indexToLocFormat = 1;        // long format
			foreach ($offsets as $offset) {
				$locastr .= pack("N", $offset);
			}
		} else {
			$indexToLocFormat = 0;        // short format
			foreach ($offsets as $offset) {
				$locastr .= pack("n", ($offset / 2));
			}
		}

		$this->add('loca', $locastr);

		// head - Font header
		$head = $this->get_table('head');
		$head = $this->_set_ushort($head, 50, $indexToLocFormat);
		$this->add('head', $head);

		$this->reader->close();

		$stm = '';
		$this->endTTFile($stm);

		return $stm;
	}

	function getGlyphData($originalGlyphIdx, &$maxdepth, &$depth, &$points, &$contours)
	{
		$depth++;
		$maxdepth = max($maxdepth, $depth);

		if (count($this->glyphdata[$originalGlyphIdx]['compGlyphs'])) {
			foreach ($this->glyphdata[$originalGlyphIdx]['compGlyphs'] as $glyphIdx) {
				$this->getGlyphData($glyphIdx, $maxdepth, $depth, $points, $contours);
			}
		} elseif (($this->glyphdata[$originalGlyphIdx]['nContours'] > 0) && $depth > 0) { // simple
			$contours += $this->glyphdata[$originalGlyphIdx]['nContours'];
			$points += $this->glyphdata[$originalGlyphIdx]['nPoints'];
		}

		$depth--;
	}

	function getGlyphs($originalGlyphIdx, &$start, &$glyphSet, &$subsetglyphs)
	{
		$glyphPos = $this->glyphPos[$originalGlyphIdx];
		$glyphLen = $this->glyphPos[$originalGlyphIdx + 1] - $glyphPos;

		if (!$glyphLen) {
			return;
		}

		$this->reader->seek($start + $glyphPos);
		$numberOfContours = $this->reader->readInt16();

		if ($numberOfContours < 0) {
			$this->reader->skip(8);
			$flags = GlyphOperator::MORE;
			while ($flags & GlyphOperator::MORE) {
				$flags = $this->reader->readUInt16();
				$glyphIdx = $this->reader->readUInt16();
				if (!isset($glyphSet[$glyphIdx])) {
					$glyphSet[$glyphIdx] = count($subsetglyphs); // old glyphID to new glyphID
					$subsetglyphs[$glyphIdx] = true;
				}
				$savepos = $this->reader->tell();
				$this->getGlyphs($glyphIdx, $start, $glyphSet, $subsetglyphs);
				$this->reader->seek($savepos);
				if ($flags & GlyphOperator::WORDS) {
					$this->reader->skip(4);
				} else {
					$this->reader->skip(2);
				}
				if ($flags & GlyphOperator::SCALE) {
					$this->reader->skip(2);
				} elseif ($flags & GlyphOperator::XYSCALE) {
					$this->reader->skip(4);
				} elseif ($flags & GlyphOperator::TWOBYTWO) {
					$this->reader->skip(8);
				}
			}
		}
	}

	function getHMTX($numberOfHMetrics, $numGlyphs, &$glyphToChar, $scale)
	{
		$start = $this->seek_table('hmtx');
		$aw = 0;
		$this->charWidths = str_pad('', 256 * 256 * 2, "\x00");

		if ($this->maxUniChar > 65536) {
			$this->charWidths .= str_pad('', 256 * 256 * 2, "\x00");
		} // Plane 1 SMP

		if ($this->maxUniChar > 131072) {
			$this->charWidths .= str_pad('', 256 * 256 * 2, "\x00");
		} // Plane 2 SMP

		$nCharWidths = 0;
		if (($numberOfHMetrics * 4) < $this->maxStrLenRead) {
			$data = $this->reader->bytesAt($start, $numberOfHMetrics * 4);
			$arr = unpack('n*', $data);
		} else {
			$this->reader->seek($start);
		}

		for ($glyph = 0; $glyph < $numberOfHMetrics; $glyph++) {

			if (($numberOfHMetrics * 4) < $this->maxStrLenRead) {
				$aw = $arr[($glyph * 2) + 1];
			} else {
				$aw = $this->reader->readUInt16();
				$lsb = $this->reader->readUInt16();
			}
			if (isset($glyphToChar[$glyph]) || $glyph == 0) {
				if ($aw >= (1 << 15)) {
					$aw = 0;
				}

				// 1.03 Some (arabic) fonts have -ve values for width
				// although should be unsigned value - comes out as e.g. 65108 (intended -50)
				if ($glyph === 0) {
					$this->defaultWidth = $scale * $aw;
					continue;
				}

				foreach ($glyphToChar[$glyph] as $char) {
					if ($char != 0 && $char != 65535) {
						$w = (int) round($scale * $aw);
						if ($w === 0) {
							$w = 65535;
						}
						if ($char < 196608) {
							$this->charWidths[$char * 2] = chr($w >> 8);
							$this->charWidths[$char * 2 + 1] = chr($w & 0xFF);
							$nCharWidths++;
						}
					}
				}
			}
		}

		$data = $this->reader->bytesAt(($start + $numberOfHMetrics * 4), ($numGlyphs * 2));
		$arr = unpack("n*", $data);
		$diff = $numGlyphs - $numberOfHMetrics;
		$w = (int) round($scale * $aw);
		if ($w === 0) {
			$w = 65535;
		}
		for ($pos = 0; $pos < $diff; $pos++) {
			$glyph = $pos + $numberOfHMetrics;
			if (isset($glyphToChar[$glyph])) {
				foreach ($glyphToChar[$glyph] as $char) {
					if ($char != 0 && $char != 65535) {
						if ($char < 196608) {
							$this->charWidths[$char * 2] = chr($w >> 8);
							$this->charWidths[$char * 2 + 1] = chr($w & 0xFF);
							$nCharWidths++;
						}
					}
				}
			}
		}

		// NB 65535 is a set width of 0
		// First bytes define number of chars in font
		$this->charWidths[0] = chr($nCharWidths >> 8);
		$this->charWidths[1] = chr($nCharWidths & 0xFF);
	}

	function getHMetric($numberOfHMetrics, $gid)
	{
		$start = $this->seek_table("hmtx");
		if ($gid < $numberOfHMetrics) {
			$this->reader->seek($start + ($gid * 4));
			$hm = $this->reader->read(4);
		} else {
			$this->reader->seek($start + (($numberOfHMetrics - 1) * 4));
			$hm = $this->reader->read(2);
			$this->reader->seek($start + ($numberOfHMetrics * 2) + ($gid * 2));
			$hm .= $this->reader->read(2);
		}

		return $hm;
	}

	function getLOCA($indexToLocFormat, $numGlyphs)
	{
		$start = $this->seek_table('loca');
		$this->glyphPos = [];
		if ($indexToLocFormat == 0) {
			$data = $this->reader->bytesAt($start, ($numGlyphs * 2) + 2);
			$arr = unpack("n*", $data);
			for ($n = 0; $n <= $numGlyphs; $n++) {
				$this->glyphPos[] = ($arr[$n + 1] * 2);
			}
		} elseif ($indexToLocFormat == 1) {
			$data = $this->reader->bytesAt($start, ($numGlyphs * 4) + 4);
			$arr = unpack("N*", $data);
			for ($n = 0; $n <= $numGlyphs; $n++) {
				$this->glyphPos[] = ($arr[$n + 1]);
			}
		} else {
			throw new \Mpdf\Exception\FontException('Unknown location table format ' . $indexToLocFormat);
		}
	}

	/**
	 * CMAP Format 4
	 */
	function getCMAP4($unicode_cmap_offset, &$glyphToChar, &$charToGlyph)
	{
		$this->maxUniChar = 0;
		$this->reader->seek($unicode_cmap_offset + 2);
		$length = $this->reader->readUInt16();
		$limit = $unicode_cmap_offset + $length;
		$this->reader->skip(2); // language

		$segCount = $this->reader->readUInt16() / 2;
		$this->reader->skip(6); // searchRange, entrySelector, rangeShift
		$endCount = [];

		for ($i = 0; $i < $segCount; $i++) {
			$endCount[] = $this->reader->readUInt16();
		}

		$this->reader->skip(2); // reservedPad
		$startCount = [];

		for ($i = 0; $i < $segCount; $i++) {
			$startCount[] = $this->reader->readUInt16();
		}

		$idDelta = [];

		for ($i = 0; $i < $segCount; $i++) {
			$idDelta[] = $this->reader->readInt16();
		}  // ???? was unsigned short

		$idRangeOffset_start = $this->reader->tell();
		$idRangeOffset = [];

		for ($i = 0; $i < $segCount; $i++) {
			$idRangeOffset[] = $this->reader->readUInt16();
		}

		for ($n = 0; $n < $segCount; $n++) {
			$endpoint = ($endCount[$n] + 1);
			for ($unichar = $startCount[$n]; $unichar < $endpoint; $unichar++) {
				if ($idRangeOffset[$n] == 0) {
					$glyph = ($unichar + $idDelta[$n]) & 0xFFFF;
				} else {
					$offset = ($unichar - $startCount[$n]) * 2 + $idRangeOffset[$n];
					$offset = $idRangeOffset_start + 2 * $n + $offset;
					if ($offset >= $limit) {
						$glyph = 0;
					} else {
						$glyph = $this->reader->uint16At($offset);
						if ($glyph != 0) {
							$glyph = ($glyph + $idDelta[$n]) & 0xFFFF;
						}
					}
				}
				$charToGlyph[$unichar] = $glyph;
				if ($unichar < 196608) {
					$this->maxUniChar = max($unichar, $this->maxUniChar);
				}
				$glyphToChar[$glyph][] = $unichar;
			}
		}
	}

	function endTTFile(&$stm)
	{
		$stm = '';
		$numTables = count($this->otables);
		$searchRange = 1;
		$entrySelector = 0;
		while ($searchRange * 2 <= $numTables) {
			$searchRange *= 2;
			$entrySelector += 1;
		}
		$searchRange *= 16;
		$rangeShift = $numTables * 16 - $searchRange;

		// Header
		if (_TTF_MAC_HEADER) {
			$stm .= pack('Nnnnn', 0x74727565, $numTables, $searchRange, $entrySelector, $rangeShift); // Mac
		} else {
			$stm .= pack('Nnnnn', 0x00010000, $numTables, $searchRange, $entrySelector, $rangeShift); // Windows
		}

		// Table directory
		$tables = $this->otables;
		ksort($tables);
		$offset = 12 + $numTables * 16;
		foreach ($tables as $tag => $data) {
			if ($tag === 'head') {
				$head_start = $offset;
			}
			$stm .= $tag;
			$checksum = $this->calcChecksum($data);
			$stm .= pack('nn', $checksum[0], $checksum[1]);
			$stm .= pack('NN', $offset, strlen($data));
			$paddedLength = (strlen($data) + 3) & ~3;
			$offset += $paddedLength;
		}

		// Table data
		foreach ($tables as $tag => $data) {
			$data .= "\0\0\0";
			$stm .= substr($data, 0, (strlen($data) & ~3));
		}

		$checksum = $this->calcChecksum($stm);
		$checksum = $this->sub32([0xB1B0, 0xAFBA], $checksum);
		$chk = pack("nn", $checksum[0], $checksum[1]);
		$stm = $this->splice($stm, ($head_start + 8), $chk);

		return $stm;
	}

	function repackageTTF($file, $TTCfontID = 0, $debug = false, $useOTL = false)
	{
		$this->useOTL = $useOTL;
		$this->filename = $file;
		$this->reader = new FileReader($file);


		$this->charWidths = '';
		$this->glyphPos = [];
		$this->charToGlyph = [];
		$this->tables = [];
		$this->otables = [];
		$this->ascent = 0;
		$this->descent = 0;
		$this->strikeoutSize = 0;
		$this->strikeoutPosition = 0;
		$this->numTTCFonts = 0;
		$this->TTCFonts = [];
		$this->reader->skip(4);
		$this->maxUni = 0;

		if ($TTCfontID > 0) {
			$this->version = $version = $this->reader->readUInt32(); // TTC Header version now
			if (!in_array($version, [0x00010000, 0x00020000], true)) {
				throw new \Mpdf\Exception\FontException(sprintf('Error parsing TrueType Collection: version=%s - %s', $version, $file));
			}
			$this->numTTCFonts = $this->reader->readUInt32();
			for ($i = 1; $i <= $this->numTTCFonts; $i++) {
				$this->TTCFonts[$i]['offset'] = $this->reader->readUInt32();
			}
			$this->reader->seek($this->TTCFonts[$TTCfontID]['offset']);
			$this->version = $version = $this->reader->readUInt32(); // TTFont version again now
		}

		$this->readTableDirectory($debug);
		$tags = ['OS/2', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'post', 'cvt ', 'fpgm', 'gasp', 'prep'];

		foreach ($tags as $tag) {
			if (isset($this->tables[$tag])) {
				$this->add($tag, $this->get_table($tag));
			}
		}

		if ($useOTL) {

			// maxp - Maximum profile table
			$this->seek_table('maxp');
			$this->reader->skip(4);
			$numGlyphs = $this->reader->readUInt16();

			// cmap - Character to glyph index mapping table
			$cmap_offset = $this->seek_table('cmap');
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
						$unicode_cmap_offset = $cmap_offset + $offset;
						break;
					}
				}
				$this->reader->seek($save_pos);
			}

			if (!$unicode_cmap_offset) {
				throw new \Mpdf\Exception\FontException(sprintf('Font "%s" does not have cmap for Unicode (platform 3, encoding 1, format 4, or platform 0, any encoding, format 4)', $this->filename));
			}

			$glyphToChar = [];
			$charToGlyph = [];
			$this->getCMAP4($unicode_cmap_offset, $glyphToChar, $charToGlyph);

			// Map Unmapped glyphs - from $numGlyphs
			$bctr = 0xE000;
			for ($gid = 1; $gid < $numGlyphs; $gid++) {
				if (!isset($glyphToChar[$gid])) {
					while (isset($charToGlyph[$bctr])) {
						$bctr++;
					} // Avoid overwriting a glyph already mapped in PUA (6,400)
					if ($bctr > 0xF8FF) {
						throw new \Mpdf\Exception\FontException("Problem. Trying to repackage TF file; not enough space for unmapped glyphs");
					}
					$glyphToChar[$gid][] = $bctr;
					$charToGlyph[$bctr] = $gid;
					$bctr++;
				}
			}

			// Sort CID2GID map into segments of contiguous codes
			unset($charToGlyph[65535]);
			unset($charToGlyph[0]);

			ksort($charToGlyph);
			$rangeid = 0;
			$range = [];
			$prevcid = -2;
			$prevglidx = -1;

			// for each character
			foreach ($charToGlyph as $cid => $glidx) {
				if ($cid == ($prevcid + 1) && $glidx == ($prevglidx + 1)) {
					$range[$rangeid][] = $glidx;
				} else {
					// new range
					$rangeid = $cid;
					$range[$rangeid] = [];
					$range[$rangeid][] = $glidx;
				}
				$prevcid = $cid;
				$prevglidx = $glidx;
			}

			// CMap table
			// cmap - Character to glyph mapping
			$segCount = count($range) + 1; // + 1 Last segment has missing character 0xFFFF
			$searchRange = 1;
			$entrySelector = 0;

			while ($searchRange * 2 <= $segCount) {
				$searchRange *= 2;
				++$entrySelector;
			}

			$searchRange *= 2;
			$rangeShift = $segCount * 2 - $searchRange;
			$length = 16 + (8 * $segCount) + ($numGlyphs + 1);
			$cmap = [0, 3, // Index : version, number of encoding subtables
				0, 0, // Encoding Subtable : platform (UNI=0), encoding 0
				0, 28, // Encoding Subtable : offset (hi,lo)
				0, 3, // Encoding Subtable : platform (UNI=0), encoding 3
				0, 28, // Encoding Subtable : offset (hi,lo)
				3, 1, // Encoding Subtable : platform (MS=3), encoding 1
				0, 28, // Encoding Subtable : offset (hi,lo)
				4, $length, 0, // Format 4 Mapping subtable: format, length, language
				$segCount * 2,
				$searchRange,
				$entrySelector,
				$rangeShift];

			// endCode(s)
			foreach ($range as $start => $subrange) {
				$endCode = $start + (count($subrange) - 1);
				$cmap[] = $endCode; // endCode(s)
			}
			$cmap[] = 0xFFFF; // endCode of last Segment
			$cmap[] = 0; // reservedPad

			// startCode(s)
			foreach ($range as $start => $subrange) {
				$cmap[] = $start; // startCode(s)
			}
			$cmap[] = 0xFFFF; // startCode of last Segment

			// idDelta(s)
			foreach ($range as $start => $subrange) {
				$idDelta = -($start - $subrange[0]);
				$cmap[] = $idDelta; // idDelta(s)
			}

			$cmap[] = 1; // idDelta of last Segment
			// idRangeOffset(s)
			foreach ($range as $subrange) {
				$cmap[] = 0; // idRangeOffset[segCount] Offset in bytes to glyph indexArray, or 0
			}

			$cmap[] = 0; // idRangeOffset of last Segment
			foreach ($range as $subrange) {
				foreach ($subrange as $glidx) {
					$cmap[] = $glidx;
				}
			}
			$cmap[] = 0; // Mapping for last character
			$cmapstr = '';
			foreach ($cmap as $cm) {
				$cmapstr .= pack('n', $cm);
			}

			$this->add('cmap', $cmapstr);
		} else {
			$this->add('cmap', $this->get_table('cmap'));
		}

		$this->reader->close();
		$stm = '';
		$this->endTTFile($stm);

		return $stm;
	}
}
