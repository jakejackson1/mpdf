---
name: mPDF HTML→PDF Pipeline Deep Knowledge
description: How mPDF converts HTML+CSS into a PDF — tokenizer, CSS cascade, layout engine, OTL/fonts, and PDF Writer layer. Reference when debugging rendering, adding features, or fixing layout bugs.
type: project
---

Full end-to-end walk of the HTML→PDF pipeline. All line numbers refer to the current `development` branch.

**Why:** Synthesized from deep reading of ~800KB of source across 10+ files. The architecture is non-obvious: a 27 500-line god-class, flat-array CSS cascade, and hand-wired DI factory. Understanding it prevents wrong-headed approaches.

**How to apply:** Use these facts when debugging rendering issues, adding HTML/CSS support, or working in any of the subsystems below.

---

## 1. Entry Point — `WriteHTML()` (`src/Mpdf.php:13188`)

```
WriteHTML($html, $mode = HTMLParserMode::DEFAULT_MODE, $init = true, $close = true)
```

**Tokenizer** (line 13442):
```php
$a = preg_split('/<(.*?)>/ms', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
```
Splits HTML into an alternating array: even indices = text nodes, odd indices = tag content. Unsupported tags are stripped first via `strip_tags($html, $this->enabledtags)` (line 13440).

**Main loop** (line 13453):
- `$i % 2 == 0` → text node branch: entity decode, apply OTL, route to `_saveTextBuffer()` (block) or `_saveCellTextBuffer()` (table cell)
- `$i % 2 == 1` → tag branch: normalize attributes, call `$this->tag->OpenTag()` or `$this->tag->CloseTag()`

**HTMLParserMode constants** (`src/HTMLParserMode.php`):
- `DEFAULT_MODE (0)` — full document (HTML + CSS + body)
- `HEADER_CSS (1)` — extract `<style>` blocks only
- `HTML_BODY (2)` — body content only
- `HTML_PARSE_NO_WRITE (3)` — parse without output (used for sizing/measurement)
- `HTML_HEADER_BUFFER (4)` — buffer output (headers, footers, nested elements)

**Attribute parsing** (line 13795–13854): single-quoted attrs normalized to double-quoted, then `preg_match_all` extracts `key="value"` pairs into `$attr` with uppercase keys. `CLASS`, `ID` uppercased; `STYLE` preserved as-is.

---

## 2. Tag Dispatch — `src/Tag.php` + `src/Tag/`

`Tag::OpenTag($tag, $attr, &$ahtml, &$ihtml)` (line 173) → `getTagInstance($tag)` → `$object->open($attr, $ahtml, $ihtml)`
`Tag::CloseTag($endtag, ...)` (line 244) → `$object->close($ahtml, $ihtml)`

Tag class mapping: default `Mpdf\Tag\{TagName}` (e.g. `DIV` → `Mpdf\Tag\Div`). Special cases: `BLOCKQUOTE` → `BlockQuote`, `PAGEFOOTER` → `PageFooter`.

**Key handlers:**
- `Tag/Img.php::open()` — extracts `SRC`, merges CSS via `MergeCSS()`, builds `$objattr` array (dimensions, margins, padding), serializes into text buffer as a special marker
- `Tag/A.php::open()` — handles `NAME` (PDF bookmarks) and `HREF` (hyperlinks); saves inline props. `close()` restores props.
- `Tag/Table.php::open()` — increments `$this->tableLevel`, saves parent table state, initializes cell/row/col arrays, resets inline properties

**Block vs inline:** `outerblocktags` and `innerblocktags` lists control auto-close of parent tags (e.g. `<LI>` auto-closes previous `<LI>`) and block nesting via `$this->blklvl`.

---

## 3. CSS Processing Pipeline

### 3a. `CssParser` — tokenization

Key data structures:
- **`$css[]`** — flat array keyed by selector string (e.g. `P`, `CLASS>>MYCLASS`, `ID>>HEADER`). Values: `['COLOR'=>'#f00', 'FONT-SIZE'=>'12pt']`
- **`$cascadeCSS[]`** — nested array for multi-level selectors (e.g. `DIV.myclass P`); each node has a `depth` field for specificity
- **`$usedClassNames[]`** — index of which class names appear in CSS to limit combination explosion

Parsing (line 219): single regex `/(.*?)\{(.*?)\}/` extracts selector/property blocks. Calls `NormalizeProperties::normalize()` immediately on each property block. `!important` is stripped (line 316) — mPDF does not implement `!important` priority.

### 3b. `CssMerger` — 8-step cascade in order (= specificity)

Merge sequence in `merge()` is implicit specificity (later = higher priority):
1. Table cascade CSS (if in table context)
2. Block cascade CSS + inherited props (direction, line-height, list-style-*, text-indent)
3. HTML attribute → CSS conversion (COLOR, WIDTH, HEIGHT, VALIGN, FONT face/size)
4. UA stylesheet (DefaultCss.php)
5. Table-specific CSS (CELLSPACING → CSS)
6. Stylesheet selectors: tag → class → `:nth-child()` → lang → ID
7. Tag+class/ID combinations
8. Descendant selectors
9. Inline `style=""` — always wins (merges last)

**Non-obvious:** Class combinations — all sorted permutations up to `maxClassDepth` are generated to match multi-class selectors like `.a.b.c`. Filtered by `usedClassNames` to avoid O(2^n) explosion.

**`sideEffects` flag** — `previewBlockCss()` disables side effects to allow lookahead without mutating `$mpdf->blk[]` / `$mpdf->table[]`.

### 3c. `NormalizeProperties` — shorthand expansion

- `font:` → FONT-STYLE, FONT-WEIGHT, FONT-SIZE, LINE-HEIGHT, FONT-FAMILY
- `margin:`/`padding:` → 4 individual sides (clockwise rule)
- `border-radius:` → 8 components (TL-H, TL-V, TR-H, TR-V, BL-H, BL-V, BR-H, BR-V)
- `background:` → color, image URL, repeat, position
- Font family validated against `$mpdf->fontdata`, falls back if unavailable

### 3d. `DefaultCss` — UA stylesheet

Notable defaults: BODY = `serif 11pt`, H1–H6 font sizes (2em down to 0.75em) with `PAGE-BREAK-AFTER: avoid`, TABLE = `BORDER-COLLAPSE: separate; BORDER-SPACING: 2px; EMPTY-CELLS: show; HYPHENS: manual`, TH = centered/bold.

### 3e. `CssManager` — facade

Public state:
- `$CSS[]` — parsed simple selectors (shared reference to CssParser output)
- `$cascadeCSS[]` — cascaded selector map
- `$tablecascadeCSS[]` — parallel cascade for current table context
- `$tbCSSlvl` — table nesting depth counter

Main API: `readCss($html)` parses and merges. `mergeCss($inherit, $tag, $attr)` delegates to CssMerger.

---

## 4. Layout Engine

### Block accumulation

`WriteFlowingBlock($s, $sOTLdata)` (line 7760) — accumulates text chunks per block:
- `$this->flowingBlockAttr['content'][]` — text chunks
- `$this->flowingBlockAttr['font'][]` — font stack
- `$this->flowingBlockAttr['cOTLdata'][]` — OpenType data per chunk

`finishFlowingBlock($endofblock, $next)` (line 6460) — finalizes a block: trims trailing spaces, adjusts OTL data, calculates line widths, applies alignment, calls `Cell()` for each output line.

### Page breaks

`Cell()` triggers a page break when: `$this->y + $this->divheight > $this->PageBreakTrigger && $this->AcceptPageBreak()`.
`AddPage()` (line 2909): saves font/spacing state → flushes float buffer via `printfloatbuffer()` → increments `$this->page` → resets margins/position.

### Specialized buffers

- `$this->pages[$page]` — per-page PDF content stream (string)
- `$this->buffer` (Buffer.php) — global PDF object buffer (array of chunks)
- `$this->columnbuffer[]` — column layout with position/height metadata
- `$this->tablebuffer` — rotated table output
- `$this->kwt_buffer[]` — keep-with-table content

**Document lifecycle** tracked in `$this->state`: 0=init, 1=active page, 2=page closing, 3=finished.

---

## 5. OpenType Layout (OTL) — `src/Otl.php`

Handles GSUB (glyph substitution) and GPOS (glyph positioning) for complex scripts (Arabic, Indic, Myanmar, etc.).

`applyOTL($str, $useOTL)` (line 122):
1. Loads `GDEFdata.json` from FontCache (GSUB/GPOS offsets, mark attachment types, glyph classes)
2. Parses string into Unicode codepoints
3. Segments by script block, assigns glyph properties (general_category, bidi_type, group = Mark/Space/Character)
4. Returns `$this->OTLdata[$subchunk][$charctr][property]`

OTL is only invoked when the font has `useOTL` flag set (checked in MultiCell, WriteFlowingBlock). `trimOTLdata()` (line 5812) removes OTL data for trimmed leading/trailing spaces.

---

## 6. Font Subsystem

### `TTFontFile.php` (repo root, not in `src/Fonts/`)

`extractInfo()` (line 672): parses `name`, `head`, `hmtx`, `hhea` tables for PostScript name, bbox, metrics.
`repackageTTF()` (line 4762): subsets the font to used glyphs, handles TTC files, builds cmap for PUA remapping. Returns binary TTF ready for PDF embedding.

### `FontCache.php` (`src/Fonts/`)

Two-layer cache: in-memory `$memoryCache[]` + disk JSON files.
Pattern: `fontkey.GDEFdata.json` (OTL metadata), `fontkey.ps.z` (compressed repackaged font).
`jsonLoad()` lazy-loads and caches parsed JSON to avoid re-parsing per document.

### Font subsetting decision (`FontWriter.php` line ~48)

Threshold: `percentSubset` config (default ~5% of glyphs used) and `maxTTFFilesize`. Below threshold → subset; above → embed full font.

---

## 7. PDF Writer Layer

### `BaseWriter` (`src/Writer/BaseWriter.php`)

- `write($s, $ln)` (line 30): routes to page buffer or object buffer based on `$this->mpdf->state`
- `object($obj_id)` (line 48): starts a PDF object, records byte offset in `$this->mpdf->offsets[$obj_id]`
- `stream($s)` (line 62): wraps binary data with `stream`/`endstream`, applies encryption if set
- `string($s)` (line 39): escapes and wraps PDF string literal `(text)`, applies RC4 encryption

### `ResourceWriter::writeResources()` (`src/Writer/ResourceWriter.php:99`)

Output sequence (order matters for xref):
1. Optional content groups (layers)
2. Extended graphics states (transparency, blending)
3. Spot colors
4. **Fonts** — FontWriter.writeFonts()
5. **Images** — ImageWriter.writeImages()
6. Form objects
7. Imported PDF pages
8. Gradients/shadings + patterns
9. Resource dictionary (`/Font`, `/ColorSpace`, `/ExtGState`, `/Shading`, `/Pattern`, `/XObject`, `/Properties`)
10. Encryption, bookmarks, metadata

### `PageWriter::writePages()` (line 42)

For each page:
- Removes references to unused fonts (regex over page content stream)
- Creates Page dict: `/Type /Page`, `/MediaBox`, `/Resources 2 0 R`, `/Contents n 0 R`
- Compresses content stream if zlib available

Then writes Pages root object with `/Kids` array and `/Count`.

### `FontWriter::writeFonts()` (line 43)

Per font type: CJK Adobe fonts → Type0; core (Times, Helvetica, Courier) → simple font dict; TTF → CIDFont + Type0 wrapper with embedded stream (`/Filter /FlateDecode`). Subset fonts via `TTFontFile::repackageTTF()`.

### Document finalization

`Close()` (line 1986) → `_enddoc()` (line 10014):
1. Headers/footers: `_puthtmlheaders()`
2. Page objects: `pageWriter->writePages()`
3. Resources: `resourceWriter->writeResources()`
4. Info dict + metadata (PDF/A, PDF/X XMP)
5. Encryption dict
6. xref table (byte offsets of all objects)
7. Trailer dict with `/Root` catalog reference

---

## Key Invariants to Remember

- **`Strict` trait**: Cannot add properties dynamically to `Mpdf` or collaborators — declare on the class or in `ConfigVariables`/`FontVariables` first, or you get `MpdfException`.
- **PHP 5.6 compat**: No typed properties, arrow functions, match expressions, named args, or nullsafe operator in `src/`.
- **CSS `!important`**: Stripped and ignored. Cascade order via merge sequence is the only specificity mechanism.
- **Table layout**: Completely separate from block layout. Tables are parsed into a data structure then laid out in a dedicated pass. Never intermix table and block layout assumptions.
- **OTL is opt-in**: Activated per-font by the `useOTL` flag. Most Latin fonts don't use it.
- **`tempDir`**: Font caches and temp files all land here. mPDF auto-cleans it — never share it with other processes.
