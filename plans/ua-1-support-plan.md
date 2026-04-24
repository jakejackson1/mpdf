# Plan: PDF/UA-1 Support for mPDF

## Context

PDF/UA-1 (ISO 14289-1:2014) is the accessibility standard for PDF files. It requires a logical structure tree — every content element tagged with semantically appropriate structure elements (`/H1`, `/P`, `/Figure`, `/Table`, `/TD`, `/L`, etc.) and layout artifacts (headers, footers, page numbers, decorative rules) explicitly excluded. Assistive technology (screen readers) navigates the structure tree to present content to users with disabilities.

mPDF currently has **zero** tagged PDF support. There is no `StructTreeRoot`, no `MarkInfo`, no marked content operators (`BDC`/`EMC`), no MCID assignments, and alt text on `<img>` is silently ignored. All HTML semantics (headings, tables, lists) are tracked internally during parsing but never materialised into the PDF structure.

The goal is to add a `PDFUA` config flag (mirroring the existing `PDFA` flag) that, when enabled, causes mPDF to automatically produce a fully structure-tagged PDF/UA-1 conforming document from the HTML passed to `WriteHTML()`.

**This plan reflects a deep codebase exploration.** Line numbers and method signatures are verified against the current `development` branch.

---

## Pre-Condition

Before Phase 1 begins: **`composer test` must be green on the development branch.** Any pre-existing failures must be fixed or accounted for before starting this work.

---

## Test Inputs — mpdf-examples Repository

Tests use HTML drawn from real-world examples in [github.com/mpdf/mpdf-examples](https://github.com/mpdf/mpdf-examples). The most useful examples for PDF/UA coverage:

| Example file | What it exercises |
|---|---|
| `example01_basic.php` | H1–H6, P, A (hyperlink), IMG, DIV, BLOCKQUOTE, ADDRESS, PRE, HR |
| `example04_images.php` | GIF/JPG/CMYK/WEBP/PNG/BMP/WMF/SVG images; various sizes |
| `example05_tables.php` | Simple tables; THEAD/TFOOT/TH; cell backgrounds |
| `example06_tables_nested.php` | Nested tables (Table-in-TD) |
| `example07_tables_borders.php` | Complex collapsed/separate table borders |
| `example08_lists.php` | OL/UL with roman, decimal, alpha, disc markers; nested lists |
| `example12_paging_html.php` | HTML headers and footers (pagination artifacts) |
| `example14_page_numbers_ToC_Index_Bookmarks.php` | Multi-page documents; ToC; page numbers (artifact) |
| `example16_headers_method_2.php` | Method-2 header/footer API — when copied into a PHPUnit test, **strip `mode='c'`** (core fonts are incompatible with PDF/UA-1 and trigger a separate `MpdfException` covered by `testCoreFontsNotAllowed` in Phase 1's `MetadataTest.php`). Use `PdfUaTestCase::makeMpdf()` (embedded TrueType). The point of this test input is to verify that Method-2 headers/footers produce the correct `/Artifact <</Type /Pagination /Subtype /Header\|Footer>> BDC … EMC` wrapping — NOT to re-test the core-font exception |
| `example22_columns.php` | Multi-column layout; headings across page breaks |
| `example26_RTL.php` | Right-to-left text (Hebrew/Arabic); bidirectional |
| `example34_invoice_example.php` | Real-world complex table (invoice rows, totals, colspan) |
| `example10_floating_and_fixed_position_elements.php` | Float and fixed-position rendering — both should default to Artifact in PDFUA mode |
| `example36_annotations_and_attached_files.php` | Sticky note annotations and file attachments — `/Contents`, `/StructParent`, Note struct elements |
| `example39_PDFA_compliance.php` | PDF/A model — verifies PDFUA/PDFA coexistence |
| `example64_protected_document.php` | `setProtection()` + PDF/UA interaction — when copied into a PHPUnit test, **strip `mode='c'`** (core fonts are forbidden by PDF/UA and would trigger the unrelated core-font exception). Use `PdfUaTestCase::makeMpdf()` (embedded TrueType). The purpose is to verify that `setProtection()` combined with `PDFUA=true` keeps the "extract for accessibility" permission bit (bit 10) set and leaves the XMP stream unencrypted — NOT to re-test the core-font exception |

**Test implementation pattern** — embed the HTML inline rather than fetching the remote repo:

```php
private function getInvoiceHtml()
{
    // HTML extracted from example34_invoice_example.php (the $html variable)
    return '<html><head><style>...</style></head><body>...</body></html>';
}
```

**Font mode**: PDF/UA tests must NOT use `mode='c'` (core fonts). Do not extend `BaseMpdfTest` directly (it defaults to `['mode' => 'c']`). Instead, create a `PdfUaTestCase` base class for the `Mpdf\Ua` namespace that initialises mPDF with embedded TrueType fonts:

```php
// tests/Mpdf/Ua/PdfUaTestCase.php
namespace Mpdf\Ua;

abstract class PdfUaTestCase extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
    protected function makeMpdf(array $config = [])
    {
        // 'title' is now a ConfigVariables key (§1a) — it flows through the config-merge loop
        // and populates $mpdf->title before WriteHTML() / Output() run.
        $defaults = ['PDFUA' => true, 'title' => 'Test Document'];
        return new \Mpdf\Mpdf(array_merge($defaults, $config));
    }

    protected function getOutput(\Mpdf\Mpdf $mpdf, $html)
    {
        $mpdf->WriteHTML($html);
        return $mpdf->Output(null, 'S');
    }
}
```

**Content stream compression**: mPDF FlateDecode-compresses page content streams by default. `Output('', 'S')` returns binary bytes — asserting BDC/EMC operators directly against this string will silently fail. `PdfUaTestCase::getOutput()` must disable compression, or provide a helper that extracts and decompresses content streams before assertion:

```php
protected function makeMpdf(array $config = [])
{
    $defaults = ['PDFUA' => true, 'title' => 'Test Document'];
    $mpdf = new \Mpdf\Mpdf(array_merge($defaults, $config));
    $mpdf->compress = false;  // disable FlateDecode so content stream assertions work
    return $mpdf;
}
```

XMP metadata streams are NOT compressed by default and remain string-matchable without decompression — XMP assertions work against raw `Output()` bytes.

---

## Testing Strategy

**Tests are written alongside each phase, not at the end.** Each phase concludes with:

1. **Unit tests** written in per-concern test classes (see below) for that phase's new behaviour.
2. **`composer test` must pass in full** (all existing tests) before moving to the next phase.
3. New unit tests must also pass.
4. **`vendor/bin/phpunit --group=snapshot`** must pass — snapshot tests are excluded from `composer test` by default but must be run explicitly at each phase gate. Requires `imagick`, `ghostscript`, and PNG ImageMagick policy enabled. Failing diffs are written to `tmp/artifacts/`.

**Test class organisation** — one class per concern rather than one growing `PDFUATest.php`:

| Class | Covers |
|---|---|
| `tests/Mpdf/Ua/PdfUaTestCase.php` | Base class for all PDF/UA test classes (see above) |
| `tests/Mpdf/Ua/MetadataTest.php` | XMP, catalog, viewer prefs, font restrictions (Phases 1–2) |
| `tests/Mpdf/Ua/StructureTreeTest.php` | Pure unit tests for `StructureTree` and `StructureElement` (Phase 2) |
| `tests/Mpdf/Ua/ContentStreamTest.php` | BDC/EMC operators, balancing, artifact marking (Phases 3–4) |
| `tests/Mpdf/Ua/StructureElementsTest.php` | HTML → PDF struct element mapping (Phase 4) |
| `tests/Mpdf/Ua/IntegrationTest.php` | Full example-based integration tests (Phase 4) |
| `tests/Mpdf/Ua/ValidationTest.php` | Warnings and error throwing for violations (Phase 5) |

All new test classes extend `PdfUaTestCase` in the `Mpdf\Ua` namespace (matches the composer autoload-dev mapping `"Mpdf\\": "tests/Mpdf"` — files at `tests/Mpdf/Ua/` resolve to `Mpdf\Ua\`).

---

## Architecture Overview

PDF/UA-1 tagging requires two parallel systems operating during document generation:

1. **Structure accumulator** — a new `Mpdf\Ua\StructureTree` class that builds the logical structure in memory as HTML is parsed. Tag handlers push/pop structure elements onto a stack. Each content item gets an MCID (marked content ID integer) that cross-references to a structure element.

2. **Content stream tagger** — modified page content emission that wraps each content item in `BDC`/`EMC` operators with its MCID, and wraps artifacts in `BMC`/`BDC`/`EMC` as appropriate.

At `_enddoc()` the accumulated structure tree and parent tree are written as PDF objects, and `MarkInfo` + `StructTreeRoot` refs are added to the catalog.

**Marked content operator rules (ISO 32000-1 §14.6):**
- `BMC` — takes a tag name only, no property dict. Valid for simple artifact marking with no properties: `/Artifact BMC ... EMC`
- `BDC` — takes a tag name AND a property dict (inline or named). Required whenever a property dict is present: `/Artifact <</Type /Pagination /Subtype /Header>> BDC ... EMC` or `/P <</MCID 5>> BDC ... EMC`
- Using `BMC` with a property dict is **invalid PDF** and will fail veraPDF.

**Activation model** (mirrors PDF/A):
```php
$mpdf = new \Mpdf\Mpdf([
    'PDFUA' => true,       // enables PDF/UA-1 mode
    'PDFUAauto' => false,  // if true, warns instead of throwing on violations
]);
```

---

## Coexistence with PDF/A-1b, PDF/X-1a, and PDF/A-3

These existing compliance modes share code paths with the planned PDFUA implementation. This section documents exactly what each shared condition looks like in the source so the implementer knows which to extend and which to leave alone.

### XMP Metadata block (`writeMetadata()`, lines 117–135)

Current structure:
```php
if ($this->mpdf->PDFX) {
    // pdfx: RDF block
} elseif ($this->mpdf->PDFA) {
    // pdfaid: RDF block
}
```
PDFX and PDFA are **mutually exclusive** (`if/elseif`). PDFUA must be a **separate `if` block** placed after the closing `}` of the `elseif ($this->mpdf->PDFA)` block — not `elseif`. This allows PDF/A + PDF/UA coexistence (e.g., PDF/A-3 + PDF/UA-1 for accessible archival documents). PDF/X + PDF/UA is also valid.

### Catalog metadata reference (`writeCatalog()`, line 381)

```php
// Current:
if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
    $this->writer->write('/Metadata ' . $this->mpdf->MetadataRoot . ' 0 R');
}
// Required change (Phase 1):
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->PDFUA) {
    $this->writer->write('/Metadata ' . $this->mpdf->MetadataRoot . ' 0 R');
}
```

### OutputIntents (`writeCatalog()`, line 386)

```php
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->ICCProfile) {
    $this->writer->write('/OutputIntents [' . $this->mpdf->OutputIntentRoot . ' 0 R]');
}
```
**Do not add PDFUA here.** PDF/UA-1 does NOT require an `/OutputIntents` entry — that is a PDF/A and PDF/X requirement. Leave this condition unchanged.

### ViewerPreferences block (`writeCatalog()`, line 410)

```php
// Current:
if ($this->mpdf->DisplayPreferences || $this->mpdf->directionality === 'rtl' || $this->mpdf->mirrorMargins) {
// Required change (Phase 1):
if ($this->mpdf->DisplayPreferences || $this->mpdf->directionality === 'rtl' || $this->mpdf->mirrorMargins || $this->mpdf->PDFUA) {
```

Inside the block, `DisplayDocTitle` (line 426) is currently only set from `DisplayPreferences` string:
```php
// Current:
if (is_int(strpos($this->mpdf->DisplayPreferences, 'DisplayDocTitle'))) {
// Required change (Phase 1):
if ($this->mpdf->PDFUA || is_int(strpos($this->mpdf->DisplayPreferences, 'DisplayDocTitle'))) {
    $this->writer->write('/DisplayDocTitle true');
}
```

`PrintScaling` exclusion (line 439) and `Duplex` exclusion (line 448) guard with `!$this->mpdf->PDFA && !$this->mpdf->PDFX` — **do not add PDFUA** to these guards; PDF/UA allows both.

### Annotation `/F 28` flag (`writeAnnotations()`, lines 543–545 for links, 648–650 for sticky notes)

```php
// Current (both locations):
if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
    $annot .= ' /F 28';
}
// Required change (Phase 1h or Phase 4):
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->PDFUA) {
    $annot .= ' /F 28';
}
```

### Annotation `/CA 1` opacity (`writeAnnotations()`, line 649)

```php
// Current:
if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
    $annot .= ' /F 28';
    $annot .= ' /CA 1';   // force full opacity
}
// Required change (extend same condition for PDFUA):
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->PDFUA) {
    $annot .= ' /F 28';
    $annot .= ' /CA 1';   // force full opacity for accessibility
}
```

### Annotation `/Contents` for sticky notes (line 641)

**Already written** — line 641: `$annot .= ' /Contents ' . $this->writer->utf16BigEndianTextString($pl['txt']);` applies to ALL PageAnnots unconditionally. No change needed here for `/Contents` on sticky notes.

For **link annotations** (line 528–529), `/Contents` is currently commented out:
```php
// Removed as causing undesired effects in Chrome PDF viewer
// $annot .= ' /Contents ' . $this->writer->utf16BigEndianTextString($pl[4]);
```
Re-enable conditionally for PDFUA only (Phase 4), using captured link text (not the URL at `$pl[4]`):
```php
if ($this->mpdf->PDFUA && isset($pl['txt'])) {
    $annot .= ' /Contents ' . $this->writer->utf16BigEndianTextString($pl['txt']);
}
```

### Annotation `/Subj` exclusion (line 695)

```php
if (!$this->mpdf->PDFA && !$this->mpdf->PDFX && isset($pl['opt']['subj'])) {
    $annot .= ' /Subj ' . ...;
}
```
`/Subj` is PDF 1.5+ — **do not add PDFUA to this exclusion**. PDF/UA is based on PDF 1.7 which allows `/Subj`. Leave unchanged.

### Core font check (`FontWriter.php`, line 137)

```php
// Current:
if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
    throw new \Mpdf\MpdfException('Core fonts are not allowed in PDF/A1-b or PDFX/1-a files...');
}
// Required change (Phase 1g):
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->PDFUA) {
    throw new \Mpdf\MpdfException(
        'Core fonts cannot be used in PDF/UA-1 mode as they cannot be embedded ' .
        '(Times, Helvetica, Courier etc.) — use a TrueType/OpenType font instead.'
    );
}
```

### PDF/A-3 `additionalXmpRdf` hook (line 113–115)

```php
if (!empty($this->mpdf->additionalXmpRdf)) {
    $m .= $this->mpdf->additionalXmpRdf;
}
```
This hook (used by PDF/A-3 for associated file RDF) is written BEFORE the PDFX/PDFA conditional blocks. The PDFUA `pdfuaid:part` block must be added AFTER the `elseif ($this->mpdf->PDFA)` closing brace — it will appear after `additionalXmpRdf` content in the XMP stream, which is valid XMP.

### Metadata write trigger (`Mpdf.php`, ~line 10098)

```php
// Current:
if ($this->PDFA || $this->PDFX) {
    $this->metadataWriter->writeMetadata();
}
// Required change (Phase 1d):
if ($this->PDFA || $this->PDFX || $this->PDFUA) {
    $this->metadataWriter->writeMetadata();
}
```

### Compatibility summary

| Combination | Valid? | Notes |
|---|---|---|
| PDFUA only | ✓ | Standard use case |
| PDFA-1b + PDFUA | ✓ | Both XMP blocks written; struct tree adds PDFUA on top of PDFA |
| PDFA-3b + PDFUA | ✓ | Common for accessible archival PDFs |
| PDFX-1a + PDFUA | ✓ | Both XMP blocks written; CMYK color restrictions remain |
| PDFA + PDFX | ✗ | Mutually exclusive (existing `if/elseif`); no change needed |

---

## Critical Files

| File | Role |
|---|---|
| `src/Config/ConfigVariables.php` | Add `PDFUA`, `PDFUAauto` defaults (~line 155) |
| `src/Mpdf.php` | Add `var` declarations only (~line 82); init in constructor (~lines 1139 + 1188); extend metadata call (~line 10098); hook `newFlowingBlock()` / `finishFlowingBlock()`; no new methods |
| `src/Writer/MetadataWriter.php` | Add `pdfuaid:part` XMP (~line 135); `/MarkInfo` + `/StructTreeRoot` in `writeCatalog()` (~line 383); force `DisplayDocTitle` (~line 426); extend metadata condition (~line 381) |
| `src/Writer/PageWriter.php` | Add `/StructParents N` to every page dict; add `/Tabs /S` on annotated pages |
| `src/Writer/FontWriter.php` | Extend core font check (~line 137) to include `PDFUA` |
| `src/Writer/ResourceWriter.php` | Call `StructureWriter` after resource dictionary (~line 228) |
| `src/Tag/BlockTag.php` | Push/pop structure elements for block-level tags; `StructType::fromCssClass()` for ToC class detection |
| `src/Tag/Img.php` | Read `ALT` attribute (currently absent); emit Figure or Artifact |
| `src/Tag/Td.php`, `Th.php`, `Tr.php`, `Table.php` | Push TH/TD with Scope/ColSpan/RowSpan; push Table/TR/THead/TBody/TFoot |
| `src/Tag/A.php` | Emit Link struct element (href only, not name anchors); set `Contents` key on link annotations; call `structureTree->addObjref()` |
| `src/Tag/Ul.php`, `Ol.php`, `Li.php` | Push L/LI/Lbl/LBody struct elements |
| `src/Tag/Abbr.php` (verify exists) | Read `TITLE` attr → `/E` expansion on Span struct element |
| `src/ServiceFactory.php` | Instantiate `UaState` and assign its collaborators (`markedContentHelper`, `structureTree`, `structureWriter`, `ariaIdResolver`, `ligatureActualTextWriter`, `fpdiStructMerger`) |
| `src/Ua/UaState.php` | **New** — facade holding all PDF/UA-1 state + collaborator references; only UA1 symbol on `Mpdf.php` |
| `src/Ua/MarkedContentHelper.php` | **New** — encapsulates BDC/EMC operator emission |
| `src/Ua/StructType.php` | **New** — single source of truth for HTML tag → PDF struct type mapping |
| `src/Ua/StructureTree.php` | **New** — core structure accumulator |
| `src/Ua/StructureElement.php` | **New** — node in the structure tree |
| `src/Ua/StructureWriter.php` | **New** — writes struct tree objects at close time |
| `src/Ua/AriaIdResolver.php` | **New** — two-pass resolver for ID-referencing ARIA attrs (added by D6) |
| `src/Ua/LigatureActualTextWriter.php` | **New** — wraps OTL-shaped runs with /Span /ActualText (added by D1) |
| `src/Ua/Import/FpdiStructMerger.php` | **New** — merges tagged-source struct subtrees from FPDI imports (added by D2) |

---

## Phase 1: Foundation — Config, Metadata, Catalog

These changes deliver a document that *claims* PDF/UA-1 at the document-metadata level. No structure tree yet, but all document-level requirements are met.

### 1a. Config (`src/Config/ConfigVariables.php`, ~line 155)

After the `'PDFAversion' => '1-B'` line, add:
```php
// PDF/UA-1 Accessible files (ISO 14289-1:2014)
'PDFUA' => false,
// Overrides warnings making changes when possible to force PDF/UA-1 compliance
'PDFUAauto' => false,
```

Also add **`'title'`** as a recognised config key so it is no longer silently dropped by the constructor's `array_intersect_key()` merge. Place it with the other document-metadata keys (near `'author'`, `'subject'`, `'keywords'`, `'creator'`):
```php
// Document metadata (PDF /Title, written via SetTitle() internally)
'title' => '',
```

With this entry, users can pass `['title' => 'My Document', 'PDFUA' => true]` to the `Mpdf` constructor and the title is populated before `WriteHTML()` / `Output()` is called. PDF/UA-1 requires a non-empty `/Title`; the config key is the most discoverable way for users to supply one.

`SetTitle($title)` at `src/Mpdf.php:1803–1807` remains the canonical setter for code that sets the title after construction; the config key is an additive convenience.

### 1b. Property declarations (`src/Mpdf.php`, ~line 82)

The **mode flags** `PDFUA` and `PDFUAauto` live directly on `Mpdf.php` as `var` properties — matching the existing `$PDFA` / `$PDFAauto` pattern. Writer code checks `$this->mpdf->PDFUA` the same way it checks `$this->mpdf->PDFA`.

`UaState` (the accumulator for warnings / struct-parents counter / `StructTreeRoot` object number / implicit-LI flag and the six UA collaborators) is held on `Mpdf.php` as a **private** property. Consumers never reach it through `$this->mpdf->ua` — they receive `UaState` via **constructor dependency injection** from `ServiceFactory` (§2d). This avoids the implicit God-object coupling that the older `var $cssManager` / `var $writer` pattern creates.

Add after the existing `var $ICCProfile;` declaration:

```php
var $PDFUA;          // bool — config flag (same pattern as $PDFA)
var $PDFUAauto;      // bool — auto-fix mode (same pattern as $PDFAauto)
private $ua;         // \Mpdf\Ua\UaState — injected via ServiceFactory; never reached through $mpdf
```

`PDFUA` and `PDFUAauto` are fed by the existing `ConfigVariables` config-merge loop (they are declared in `ConfigVariables` per §1a, so passing `['PDFUA' => true, 'PDFUAauto' => true]` to the constructor sets them directly — no special intake step needed).

Initialize `$this->ua` in the constructor at the two existing `$this->PDFAXwarnings = [];` reset points (~lines 1134 and 1183) from the injected service map:

```php
$this->ua = $services['uaState'];
// UaState's warnings / structParentsCounter / structTreeRootObjNum / openedImplicitLI
// are initialised by its own property defaults
```

**Access pattern**:
- Config-time: users pass `['PDFUA' => true, 'PDFUAauto' => true]` to the `Mpdf` constructor — unchanged, backwards-compatible.
- Runtime mode checks: writer classes and tag handlers read `$this->mpdf->PDFUA` / `$this->mpdf->PDFUAauto` directly (same pattern as `$this->mpdf->PDFA`).
- Code inside `Mpdf.php` reads `$this->PDFUA` / `$this->PDFUAauto` and — because `$ua` is private to `Mpdf.php` — `$this->ua` for the facade.
- Every other consumer (tag handlers, writers, `\Mpdf\Ua\*` collaborators, `FpdiTrait`) receives `UaState` via its constructor and stores it as its own `$this->ua` field. Those consumers never traverse `$this->mpdf->…` to find UaState.
- Tag handlers call `$this->ua->getMarkedContentHelper()->begin(...)` / `->end()`.
- The depth balance check inside `Mpdf::_enddoc()` reads `$this->ua->getMarkedContentHelper()->getDepth()`.
- Warnings are recorded via `$this->ua->addWarning($msg)`.
- `/StructParents` allocation uses `$this->ua->nextStructParents()`.

### 1c. XMP metadata (`src/Writer/MetadataWriter.php`, after line 135)

After the closing `}` of the `elseif ($this->mpdf->PDFA)` block, add a separate `if` (not `elseif` — UA and A can coexist):
```php
// PDF/UA-1 XMP namespace
if ($this->mpdf->PDFUA) {
    if (empty($this->mpdf->title)) {
        if ($this->mpdf->PDFUAauto) {
            $this->ua->addWarning('PDF/UA-1 requires a document title. Set the \'title\' config option.');
        } else {
            throw new \Mpdf\MpdfException('PDF/UA-1 requires a document title. Set the \'title\' config option.');
        }
    }
    $m .= '   <rdf:Description rdf:about="uuid:' . $uuid
        . '" xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/">' . "\n";
    $m .= '    <pdfuaid:part>1</pdfuaid:part>' . "\n";
    $m .= '   </rdf:Description>' . "\n";
}
```

Note: `dc:title` is already written unconditionally from `$this->mpdf->title` on lines ~88-97. The check here only validates it exists.

### 1d. Extend metadata condition (`src/Mpdf.php`, ~line 10098)

```php
// Before: if ($this->PDFA || $this->PDFX) {
if ($this->PDFA || $this->PDFX || $this->PDFUA) {
    $this->metadataWriter->writeMetadata();
}
```

### 1e. Catalog additions (`src/Writer/MetadataWriter.php`, `writeCatalog()`)

**Extend metadata reference (~line 381):**
```php
// Before: if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->PDFUA) {
    $this->writer->write('/Metadata ' . $this->mpdf->MetadataRoot . ' 0 R');
}
```

**Add MarkInfo and StructTreeRoot ref after that block:**
```php
if ($this->mpdf->PDFUA) {
    $this->writer->write('/MarkInfo <</Marked true /Suspects false>>');
    if ($this->ua->getStructTreeRootObjNum()) {
        $this->writer->write('/StructTreeRoot ' . $this->ua->getStructTreeRootObjNum() . ' 0 R');
    }
}
```

### 1f. Force `DisplayDocTitle` (`src/Writer/MetadataWriter.php`, ~line 410 + 426)

Extend the ViewerPreferences block to always open for PDF/UA:
```php
// Before: if ($this->mpdf->DisplayPreferences || ... || $this->mpdf->mirrorMargins) {
if ($this->mpdf->DisplayPreferences || $this->mpdf->directionality === 'rtl' || $this->mpdf->mirrorMargins || $this->mpdf->PDFUA) {
```

Inside the block, extend the `DisplayDocTitle` check:
```php
// Before: if (is_int(strpos($this->mpdf->DisplayPreferences, 'DisplayDocTitle'))) {
if ($this->mpdf->PDFUA || is_int(strpos($this->mpdf->DisplayPreferences, 'DisplayDocTitle'))) {
    $this->writer->write('/DisplayDocTitle true');
}
```

### 1g. Font embedding enforcement (`src/Writer/FontWriter.php`, ~line 137)

Core fonts (Times, Helvetica, Courier, etc.) are referenced by name in mPDF with no embedded font program — they violate PDF/UA-1 §7.21 which requires all rendering fonts to be embedded. mPDF has no font file for the 14 standard Type 1 core fonts. Extend the existing check:

```php
// Before: if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->PDFUA) {
    throw new \Mpdf\MpdfException(
        'Core fonts cannot be used in PDF/UA-1 mode as they cannot be embedded ' .
        '(Times, Helvetica, Courier etc.) — use a TrueType/OpenType font instead.'
    );
}
```

### 1h. `/StructParents` and `/Tabs /S` on page dicts (`src/Writer/PageWriter.php`)

**Every page dict** (not just annotated pages) must carry a `/StructParents N` integer key. This is the index into the ParentTree NumTree — veraPDF fails immediately if it is absent (ISO 32000-1 §14.7.4.4).

Inside the `for ($n = 1; $n <= $nb; $n++)` loop, just before the `$this->writer->write('/Contents ...')` line:
```php
if ($this->mpdf->PDFUA) {
    $structParents = $this->ua->nextStructParents();  // sequential 0-based integer
    $this->mpdf->pageDim[$n]['structParents'] = $structParents;
    $this->writer->write('/StructParents ' . $structParents);
}
```

The `pageDim[$n]['structParents']` value is stored so `StructureWriter` can build the ParentTree NumTree keyed by these integers.

`/Tabs /S` (tab order = structure order) — **required on every page dict** (Matterhorn 28-001), not just annotated pages. Write it unconditionally alongside `/StructParents`:
```php
if ($this->mpdf->PDFUA) {
    $structParents = $this->ua->nextStructParents();
    $this->mpdf->pageDim[$n]['structParents'] = $structParents;
    $this->writer->write('/StructParents ' . $structParents);
    $this->writer->write('/Tabs /S');
}
```

**Common mistake to avoid**: the existing draft placed `/Tabs /S` inside the `if ($annotsnum || $formsnum)` block. This is wrong — veraPDF will fail on every non-annotated page.

### Phase 1 Tests — `tests/Mpdf/Ua/MetadataTest.php` (create)

Mirror the structure of `tests/Mpdf/PDFATest.php`. Use `['mode' => 'c']` only when core font testing is needed (see below).

| Test method | Assertion |
|---|---|
| `testXmpContainsPdfuaidPart` | Output string contains `<pdfuaid:part>1</pdfuaid:part>` |
| `testCatalogContainsMarkInfo` | Output contains `/MarkInfo <</Marked true /Suspects false>>` |
| `testCatalogContainsMetadataRef` | Output contains `/Metadata … 0 R` |
| `testViewerPreferencesDisplayDocTitle` | Output contains `/DisplayDocTitle true` |
| `testThrowsWhenTitleMissing` | `MpdfException` thrown when `title` is empty and `PDFUAauto=false`. **Construction note**: do NOT use `makeMpdf()` (which pre-sets the title via the default config). Construct `Mpdf` directly with `['PDFUA' => true, 'PDFUAauto' => false]` — `'title'` is omitted so the config-merge leaves `$mpdf->title` at its `ConfigVariables` default of empty string. Do NOT call `$mpdf->SetTitle()` before `Output()`. |
| `testWarnsWhenTitleMissingWithAuto` | No exception; `$mpdf->ua->getWarnings()` non-empty when `PDFUAauto=true`. Same direct-construction approach as `testThrowsWhenTitleMissing` — do not use `makeMpdf()`, and do not set `'title'` in the constructor config. |
| `testCoreFontsNotAllowed` | Construction with `['PDFUA'=>true, 'mode'=>'c']` succeeds; calling `$mpdf->WriteHTML('<p>x</p>'); $mpdf->Output(null, 'S')` throws `MpdfException` — the check fires in `FontWriter::writeFonts()` at output time, not at `__construct`. |
| `testTabsSOnEveryPage` | Output contains `/Tabs /S` in every page dict — including pages with no annotations |
| `testStructParentsOnEveryPage` | Each page dict in the output contains `/StructParents` |
| `testPdfVersionForcedTo17` | Output PDF header is `%PDF-1.7` when `PDFUA=true` regardless of other settings |
| `testCatalogContainsLang` | Document catalog contains `/Lang` entry when `PDFUA=true` and `currentLang` is set |
| `testThrowsWhenLangMissingStrict` | `MpdfException` thrown when `PDFUA=true`, `PDFUAauto=false`, and neither `currentLang` nor `default_lang` is set. **Construction note**: construct with `['PDFUA' => true, 'PDFUAauto' => false]` and NO `mode` argument — `currentLang` and `default_lang` are only set inside the mode-processing block (`Mpdf.php` lines 1412–1413) and remain empty string when no mode is supplied. Do NOT pass `'currentLang' => ''` in the config array — it is not a `ConfigVariables` key and is silently dropped. |
| `testPdfuaFalseByDefaultNoMarkInfo` | With `PDFUA=false` (default), output does NOT contain `/MarkInfo` |
| `testPdfuaPdfaCoexistenceXmp` | With both `PDFUA=true` and `PDFA=true`, output contains BOTH `<pdfuaid:part>1</pdfuaid:part>` AND `<pdfaid:part>` XMP elements — verify the `if PDFUA` block is a standalone `if`, not an `elseif` chained to the PDFA block |

### Phase 1 Completion Gate

```bash
composer test                              # all existing + new Phase 1 tests must pass
vendor/bin/phpunit --group=snapshot        # snapshot suite must stay green
```

---

## Phase 2: Structure Tree Infrastructure

New classes that accumulate the logical document structure in memory during HTML parsing.

### 2a0. `src/Ua/UaState.php` (new) — facade holding all PDF/UA-1 state

`Mpdf.php` carries the two mode flags `$PDFUA` / `$PDFUAauto` directly (matching the existing `$PDFA` / `$PDFAauto` pattern), plus the `$ua` facade. Everything else — the warning accumulator, the struct-parents counter, the `StructTreeRoot` object number, the implicit-LI flag, and references to all UA collaborators (`MarkedContentHelper`, `StructureTree`, `StructureWriter`, `AriaIdResolver`, `LigatureActualTextWriter`, `FpdiStructMerger`) — lives on `UaState`. **All fields are `protected`** and reached through explicit getters / setters; no `Strict` trait (this class is self-contained).

```php
namespace Mpdf\Ua;

class UaState
{
    // --- accumulated state ---
    protected $warnings              = [];
    protected $structParentsCounter  = 0;
    protected $structTreeRootObjNum  = 0;       // PDF object number of StructTreeRoot
    protected $openedImplicitLI      = false;   // DT/DD opened an implicit LI

    // --- collaborators (wired by ServiceFactory via setters) ---
    /** @var MarkedContentHelper */            protected $markedContentHelper;
    /** @var StructureTree */                  protected $structureTree;
    /** @var StructureWriter */                protected $structureWriter;
    /** @var AriaIdResolver */                 protected $ariaIdResolver;
    /** @var LigatureActualTextWriter */       protected $ligatureActualTextWriter;
    /** @var Import\FpdiStructMerger */        protected $fpdiStructMerger;

    // The mode flags `PDFUA` and `PDFUAauto` live on `Mpdf.php` as `var` properties,
    // matching the `$PDFA` / `$PDFAauto` pattern. Writer code reads
    // `$this->mpdf->PDFUA` / `$this->mpdf->PDFUAauto` directly.

    // ================== Getters ==================

    /** @return string[] */
    public function getWarnings()             { return $this->warnings; }

    /** @return int */
    public function getStructParentsCounter() { return $this->structParentsCounter; }

    /** @return int */
    public function getStructTreeRootObjNum() { return $this->structTreeRootObjNum; }

    /** @return bool */
    public function isOpenedImplicitLI()      { return $this->openedImplicitLI; }

    /** @return MarkedContentHelper */
    public function getMarkedContentHelper()  { return $this->markedContentHelper; }

    /** @return StructureTree */
    public function getStructureTree()        { return $this->structureTree; }

    /** @return StructureWriter */
    public function getStructureWriter()      { return $this->structureWriter; }

    /** @return AriaIdResolver */
    public function getAriaIdResolver()       { return $this->ariaIdResolver; }

    /** @return LigatureActualTextWriter */
    public function getLigatureActualTextWriter() { return $this->ligatureActualTextWriter; }

    /** @return Import\FpdiStructMerger */
    public function getFpdiStructMerger()     { return $this->fpdiStructMerger; }

    // ================== Setters (only where callers legitimately mutate state) ==================

    /** @param int $n */
    public function setStructTreeRootObjNum($n) { $this->structTreeRootObjNum = (int) $n; }

    /** @param bool $v */
    public function setOpenedImplicitLI($v)   { $this->openedImplicitLI = (bool) $v; }

    public function setMarkedContentHelper(MarkedContentHelper $h) { $this->markedContentHelper = $h; }
    public function setStructureTree(StructureTree $t)             { $this->structureTree = $t; }
    public function setStructureWriter(StructureWriter $w)         { $this->structureWriter = $w; }
    public function setAriaIdResolver(AriaIdResolver $r)           { $this->ariaIdResolver = $r; }
    public function setLigatureActualTextWriter(LigatureActualTextWriter $w) { $this->ligatureActualTextWriter = $w; }
    public function setFpdiStructMerger(Import\FpdiStructMerger $m){ $this->fpdiStructMerger = $m; }

    // ================== Behaviour ==================

    /** @param string $msg */
    public function addWarning($msg)
    {
        $this->warnings[] = (string) $msg;
    }

    /** @return int previous value */
    public function nextStructParents()
    {
        return $this->structParentsCounter++;
    }
}
```

**Encapsulation rationale**: `UaState` holds every piece of state that isn't a mode flag. Marking fields `protected` and routing access through named getters/setters gives three wins:

1. Any invalid external write (e.g., stashing a random value on `->structureTree`) becomes a visible `setStructureTree()` call with a typed parameter — the type-hint catches misuse at development time on PHP 7+ even though `src/` must remain PHP 5.6 compatible (the hints are constructor-param style, not property types).
2. Booleans are reached through `isOpenedImplicitLI()` — the `isX()` prefix makes call sites self-documenting vs the ambiguous-read `->openedImplicitLI`.
3. Future evolution (e.g. lazy-initialising a collaborator, asserting required fields, adding observability) is a single-file change inside `UaState` instead of touching every caller.

**Why the mode flags stay on `Mpdf.php` and not on `UaState`**: `PDFUA` and `PDFUAauto` are read from a huge number of call sites (73+ mode checks) and must feel natural next to the existing `PDFA` / `PDFAauto` flags. Pushing them into the `UaState` facade adds an unnecessary `->ua->` hop at every check and breaks the visual parallel with the rest of mPDF's mode flags. Everything that accumulates or is mutated at runtime — warnings, counters, the root object number, collaborator references — still lives on `UaState`; removing PDF/UA-1 from mPDF becomes deleting `$ua` + the `$PDFUA`/`$PDFUAauto` declarations + the `src/Ua/` directory.

### 2a. `src/Ua/StructureElement.php` (new)

All properties `protected`. No `Strict` trait (this class does not extend Mpdf or any Strict-using base).

```php
namespace Mpdf\Ua;

class StructureElement
{
    protected $type;       // string — validated PDF struct type
    protected $parent;     // StructureElement|null
    protected $children;   // StructureElement[]
    protected $mcids;      // array of ['page'=>int, 'mcid'=>int, 'pageRef'=>int]
    protected $objrefs;    // array of ['structParent'=>int, 'obj'=>int]
    protected $attributes; // array — Alt, Lang, Scope, ColSpan, RowSpan, etc.
    protected $id;         // string|null — globally unique (for Note elements)
    protected $objNum;     // int — PDF object number assigned at write time

    public function __construct($type, $attributes = [])
    {
        if (!StructType::isValid($type)) {
            throw new \InvalidArgumentException(
                'Invalid PDF struct type: "' . $type . '"'
            );
        }
        $this->type       = $type;
        $this->attributes = $attributes;
        $this->parent     = null;
        $this->children   = [];
        $this->mcids      = [];
        $this->objrefs    = [];
        $this->id         = null;
        $this->objNum     = 0;
    }

    public function getType()       { return $this->type; }
    public function getParent()     { return $this->parent; }
    public function getChildren()   { return $this->children; }
    public function getMcids()      { return $this->mcids; }
    public function getObjrefs()    { return $this->objrefs; }
    public function getAttributes() { return $this->attributes; }
    public function getId()         { return $this->id; }
    public function getObjNum()     { return $this->objNum; }

    // Package-internal setters (used only by StructureTree and StructureWriter)
    public function setId($id)           { $this->id = $id; }
    public function setObjNum($n)        { $this->objNum = $n; }
    public function addMcid($page, $mcid, $pageRef = 0)
    {
        $this->mcids[] = ['page' => $page, 'mcid' => $mcid, 'pageRef' => $pageRef];
    }
    public function addObjref($structParent, $obj)
    {
        $this->objrefs[] = ['structParent' => $structParent, 'obj' => $obj];
    }
    public function addChild(StructureElement $child)
    {
        $child->parent  = $this;   // direct write: same class
        $this->children[] = $child;
    }
}
```

**Note on `\InvalidArgumentException`:** Uses SPL's built-in `\InvalidArgumentException` to avoid a dependency on a potentially absent `Mpdf\Exception\InvalidArgumentException` class. Verify whether `src/Exception/InvalidArgumentException.php` exists before choosing; if it exists, prefer the namespaced version for consistency with the rest of the codebase.

`StructureElement::$mcids` stores `['page' => $structParentsIndex, 'mcid' => $mcid, 'pageRef' => $pageObjNum]` so `StructureWriter` can emit MCR dicts when a struct element spans pages. The `pageRef` (PDF object number for the page object) must be filled in at write time by looking up `$this->mpdf->offsets`.

### 2b. `src/Ua/StructureTree.php` (new)

All properties `protected`.

```php
namespace Mpdf\Ua;

class StructureTree
{
    protected $root;                   // StructureElement — Document root
    protected $stack;                  // StructureElement[] — open element stack
    protected $mcidByPage;             // int[] — per-page MCID counter
    protected $parentTree;             // [page][mcid] => StructureElement
    protected $artifactDepth;          // int
    protected $annotParentCounter;     // int
    protected $annotParentTree;        // [structParentInt] => StructureElement
    protected $roleMappings;           // [customRole] => standardType

    public function __construct()
    {
        $this->root               = new StructureElement('Document');
        $this->stack              = [$this->root];
        $this->mcidByPage         = [];
        $this->parentTree         = [];
        $this->artifactDepth      = 0;
        $this->annotParentCounter = 0;
        $this->annotParentTree    = [];
        $this->roleMappings       = [];
    }

    // --- Getters ---
    public function getRoot()            { return $this->root; }
    public function getCurrent()         { return end($this->stack); }
    public function getParentTree()      { return $this->parentTree; }
    public function getAnnotParentTree() { return $this->annotParentTree; }
    public function getRoleMappings()    { return $this->roleMappings; }
    public function isArtifactContext()  { return $this->artifactDepth > 0; }

    // --- open / close ---
    public function open($type, $attributes = [])
    {
        if (!StructType::isValid($type)) {
            throw new \InvalidArgumentException('Invalid struct type: "' . $type . '"');
        }
        if ($this->isArtifactContext()) {
            return;  // suppress struct element creation in artifact scope
        }
        $elem = new StructureElement($type, $attributes);
        $this->getCurrent()->addChild($elem);
        $this->stack[] = $elem;
    }

    public function close()
    {
        if (count($this->stack) <= 1) {
            return;  // never pop the Document root
        }
        if ($this->isArtifactContext()) {
            return;  // open() was a no-op; close() must match
        }
        array_pop($this->stack);
    }

    // --- content / artifact ---
    public function addContent($structParentsIndex)
    {
        if (!is_int($structParentsIndex) || $structParentsIndex < 0) {
            throw new \InvalidArgumentException(
                'structParentsIndex must be a non-negative integer'
            );
        }
        if ($this->isArtifactContext()) {
            return $this->addArtifact();
        }
        $mcid = $this->nextMcidForPage($structParentsIndex);
        $this->getCurrent()->addMcid($structParentsIndex, $mcid);
        $this->parentTree[$structParentsIndex][$mcid] = $this->getCurrent();
        return $mcid;
    }

    public function addContentForElement(StructureElement $elem, $structParentsIndex)
    {
        if ($this->isArtifactContext()) {
            return -1;
        }
        $mcid = $this->nextMcidForPage($structParentsIndex);
        $elem->addMcid($structParentsIndex, $mcid);
        $this->parentTree[$structParentsIndex][$mcid] = $elem;
        return $mcid;
    }

    public function addArtifact()
    {
        return -1;
    }

    public function openArtifact()  { $this->artifactDepth++; }
    public function closeArtifact()
    {
        if ($this->artifactDepth > 0) {
            $this->artifactDepth--;
        }
    }

    // --- role map ---
    public function addRoleMapping($role, $standardType)
    {
        if (!isset($this->roleMappings[$role])) {
            $this->roleMappings[$role] = $standardType;
        }
        // first registration wins — duplicates with different target are silently ignored
    }

    // --- annotation struct parent ---
    public function nextAnnotStructParent(StructureElement $elem)
    {
        $idx = $this->annotParentCounter++;
        $this->annotParentTree[$idx] = $elem;
        return $idx;
    }

    // --- internals ---
    private function nextMcidForPage($page)
    {
        // MCIDs must reset to 0 per page — veraPDF requires dense arrays per /StructParents key
        if (!isset($this->mcidByPage[$page])) {
            $this->mcidByPage[$page] = 0;
        }
        return $this->mcidByPage[$page]++;
    }
}
```

**MCID counter invariant:** MCID is per-page (`$mcidByPage[$page]`), not global. This is required by ISO 32000-1 §14.7.4.4 — the ParentTree value for each `/StructParents N` key must be a dense array starting at index 0.

**ParentTree key clarification**: The NumTree keys are the `/StructParents` integers assigned sequentially to page dicts (not 1-based page numbers). The value for each key is a **dense array** of references to struct element objects, ordered by MCID (index 0 = MCID 0, index 1 = MCID 1, etc. — never sparse).

**Multi-page struct elements**: When a struct element has MCIDs on multiple pages, its `/K` array in the PDF object contains MCR (Marked Content Reference) dicts rather than bare integers:
```
/K [<</Type /MCR /Pg 5 0 R /MCID 0>> <</Type /MCR /Pg 7 0 R /MCID 3>>]
```

**`addRoleMapping($role, $standardType)` conflict behavior**: The **first registration wins** — duplicates with a different target type are silently ignored. This prevents conflicting RoleMap entries that veraPDF would reject.

### 2c. `src/Ua/StructType.php` (new) — HTML tag → PDF struct type mapping

Single source of truth for all mapping decisions. Replaces ad-hoc `strtoupper($tag)` calls and any static arrays scattered across tag handlers.

```php
namespace Mpdf\Ua;

class StructType
{
    // HTML tag (uppercase) → PDF struct type
    private static $tagMap = [
        'P'          => 'P',
        'H1'         => 'H1', 'H2' => 'H2', 'H3' => 'H3',
        'H4'         => 'H4', 'H5' => 'H5', 'H6' => 'H6',
        'BLOCKQUOTE' => 'BlockQuote',
        'DIV'        => 'Div',
        'SPAN'       => 'Span',
        'A'          => 'Link',
        'UL'         => 'L',  'OL' => 'L',
        'LI'         => 'LI',
        // Definition lists: DL → L, DT → Lbl (term label), DD → LBody (definition body)
        // Per Tagged PDF Best Practice Guide §4.2.3 — treat <dl> as a list structure
        'DL'         => 'L', 'DT' => 'Lbl', 'DD' => 'LBody',
        'TABLE'      => 'Table',
        'TR'         => 'TR', 'TD' => 'TD', 'TH' => 'TH',
        'THEAD'      => 'THead', 'TBODY' => 'TBody', 'TFOOT' => 'TFoot',
        'FIGURE'     => 'Figure', 'IMG' => 'Figure',
        'CAPTION'    => 'Caption',
        'FIGCAPTION' => 'Caption',   // HTML5 figure caption — maps to PDF Caption
        'SECTION'    => 'Sect',
        'ARTICLE'    => 'Art',
        'NAV'        => 'Sect',
        'ASIDE'      => 'Sect',
        'MAIN'       => 'Div',
        'HEADER'     => 'Div',
        'FOOTER'     => 'Div',
        'ADDRESS'    => 'P',
        'PRE'        => 'Code',
        'CODE'       => 'Code',
        'Q'          => 'Quote',
        'STRONG'     => 'Span', 'B' => 'Span',
        'EM'         => 'Span', 'I' => 'Span',
        'ABBR'       => 'Span', 'ACRONYM' => 'Span',
        'SUB'        => 'Span', 'SUP' => 'Span',
        'MARK'       => 'Span', 'DEL' => 'Span',
        'INS'        => 'Span', 'S'   => 'Span',
        'SMALL'      => 'Span',
    ];

    // mPDF-generated ToC CSS class prefixes → PDF struct type
    private static $tocClassMap = [
        'mpdf_toc'          => 'TOC',
        'mpdf_toc_level_'   => 'TOCI',   // prefix — handles level_0, level_1, …
        // <a class="mpdf_toc_a"> creates a real PDF Link annotation → must be Link,
        // NOT Reference (Reference is for textual cross-refs without annotations).
        // ISO 32000-1 §14.8.4.4.2 Table 338: Link requires an OBJR kid pointing at
        // the annotation object.
        'mpdf_toc_a'        => 'Link',
        'mpdf_toc_p_level_' => 'Lbl',    // prefix — page number label
    ];

    // Standard PDF struct types (ISO 32000-1 §14.8)
    private static $validTypes = [
        'Document','Part','Art','Sect','Div','BlockQuote','Caption',
        'TOC','TOCI','Index','NonStruct','Private',
        'P','H','H1','H2','H3','H4','H5','H6',
        'L','LI','Lbl','LBody',
        'Table','TR','TH','TD','THead','TBody','TFoot',
        'Span','Quote','Note','Reference','BibEntry','Code',
        'Link','Annot',
        'Figure','Formula','Form',
    ];

    // Grouping elements that cannot hold direct content (MCIDs)
    private static $groupingTypes = [
        'Document','Part','Art','Sect','Div','BlockQuote','Caption',
        'TOC','TOCI','Index','NonStruct','Private',
        'Table','THead','TBody','TFoot','L',
    ];

    /**
     * Resolve an HTML tag + attributes to a PDF struct type.
     *
     * @param  string $htmlTag  Uppercase HTML tag (e.g. 'P', 'H1', 'DIV')
     * @param  array  $attr     Tag attributes (uppercase keys)
     * @return string|null      PDF struct type, or null if unrecognised
     */
    public static function fromHtmlTag($htmlTag, $attr = [])
    {
        // ROLE attribute override (if value is itself a valid PDF type)
        if (!empty($attr['ROLE'])) {
            $role = trim($attr['ROLE']);
            if (in_array($role, self::$validTypes, true)) {
                return $role;
            }
        }
        $upper = strtoupper($htmlTag);
        return isset(self::$tagMap[$upper]) ? self::$tagMap[$upper] : null;
    }

    /**
     * Resolve a CSS class (single, not space-separated) to a PDF struct type.
     * Used by tag handlers for mPDF-generated ToC HTML.
     *
     * @param  string $cssClass  e.g. 'mpdf_toc', 'mpdf_toc_level_2'
     * @return string|null
     */
    public static function fromCssClass($cssClass)
    {
        if (isset(self::$tocClassMap[$cssClass])) {
            return self::$tocClassMap[$cssClass];
        }
        foreach (self::$tocClassMap as $prefix => $type) {
            if (strncmp($cssClass, $prefix, strlen($prefix)) === 0) {
                return $type;
            }
        }
        return null;
    }

    /** @return bool */
    public static function isValid($type)
    {
        return in_array($type, self::$validTypes, true);
    }

    /**
     * Grouping elements cannot receive MCIDs directly — they hold child elements.
     * @return bool
     */
    public static function isGrouping($type)
    {
        return in_array($type, self::$groupingTypes, true);
    }
}
```

**Integration in tag handlers:** Every call site that uses `strtoupper($tag)` to produce a struct type is replaced with `\Mpdf\Ua\StructType::fromHtmlTag($tag, $attr)`. Tag handlers import `Mpdf\Ua\StructType`.

**`<a>` tag — destination anchors vs. link anchors:** mPDF's `<a>` tag serves two distinct roles:

| Case | HTML | Struct action |
|------|------|---------------|
| External hyperlink | `<a href="http://...">` | Open `Link` struct element + register OBJR for URI annotation |
| Internal link (GoTo) | `<a href="#anchor">` | Open `Link` struct element + register OBJR for GoTo Link annotation |
| Destination anchor | `<a name="anchor">` | **No struct element opened** — named destination only; content flows through to parent struct element |
| Both (rare) | `<a name="a" href="#b">` | Open `Link` struct element (href wins); also register named destination |

**Implementation rule in `src/Tag/A.php` (open handler):**
```php
// When PDFUA active:
$hasHref = !empty($attr['HREF']);
$hasName = !empty($attr['NAME']);

if ($hasHref) {
    // Both external and internal hrefs create Link annotations → Link struct elem
    $this->ua->getStructureTree()->open('Link');
    // ... rest of existing href handling
} elseif ($hasName) {
    // Pure destination anchor: register named destination, no struct element
    // (content inside <a name="..."> inherits parent struct type)
}
```

**OBJR requirement for `Link` struct elements:** Every `Link` struct element must have an Object Reference (OBJR) kid in the PDF struct dict pointing to the associated Link annotation. This is validated by veraPDF (Matterhorn 02-003). The `A` close handler must call `structureTree->addObjref()` after the annotation object is written. The `StructureWriter` emits OBJR as:
```
<< /Type /OBJR /Obj N 0 R >>
```
as a kid of the Link struct element, alongside any MCIDs.

**`$tagMap['A'] = 'Link'` remains correct** as the default — `fromHtmlTag()` is only consulted for the `href` case. The `A` tag handler is responsible for checking `$attr['HREF']` before calling `structureTree->open()`.

### 2d. Register in `ServiceFactory.php`

`UaState` is registered as a service and injected into every consumer that needs to read or mutate UA state. The individual collaborators (`MarkedContentHelper`, `StructureTree`, `StructureWriter`, `AriaIdResolver`, `LigatureActualTextWriter`, `FpdiStructMerger`) live **inside** `UaState` behind `protected` fields reached via getters (`getStructureTree()`, `getMarkedContentHelper()`, …).

Construct `$uaState` first, then every collaborator, then wire the collaborators into `$uaState` via setters. This happens after `$writer` (BaseWriter) is constructed:

```php
$uaState = new \Mpdf\Ua\UaState();

$structureTree = new \Mpdf\Ua\StructureTree();
$uaState->setStructureTree($structureTree);
$uaState->setMarkedContentHelper(new \Mpdf\Ua\MarkedContentHelper($writer));
$uaState->setStructureWriter(new \Mpdf\Ua\StructureWriter($writer, $uaState));
$uaState->setAriaIdResolver(new \Mpdf\Ua\AriaIdResolver($uaState));
$uaState->setLigatureActualTextWriter(new \Mpdf\Ua\LigatureActualTextWriter($writer, $uaState));
$uaState->setFpdiStructMerger(new \Mpdf\Ua\Import\FpdiStructMerger($mpdf, $uaState));

// In getServices() return array:
'uaState' => $uaState,

// In getServiceIds():
'uaState',
```

**Dependency injection surface** — every consumer accepts `UaState` in its constructor and never reaches for it through `$this->mpdf->…`:

| Consumer | Constructor addition |
|---|---|
| `Mpdf::__construct()` | already receives the service map; stores `$services['uaState']` to its **private** `$this->ua` |
| `\Mpdf\Tag\Tag` (base class, `src/Tag/Tag.php:86`) | add `UaState $ua` as an 11th parameter; store as `protected $ua`. All tag subclasses (`BlockTag`, `A`, `Img`, `Table*`, `Li`, `Annotation`, `Abbr`, …) inherit the field and reference it as `$this->ua` |
| `src/Writer/MetadataWriter.php` | add `UaState $ua` parameter; used for `getStructTreeRootObjNum()` / title-validation warnings |
| `src/Writer/PageWriter.php` | add `UaState $ua` parameter; used for `nextStructParents()` on page dicts |
| `src/Writer/FormWriter.php` | add `UaState $ua` parameter; used for `nextStructParents()` on SVG / FPDI Form XObjects |
| `src/Writer/ResourceWriter.php` | add `UaState $ua` parameter; used to reach `getStructureWriter()->writeStructTree()` |
| `src/FpdiTrait.php` | the trait is applied to `Mpdf`; it reads `$this->ua` (the private property on the class it's mixed into) — no new parameter needed |
| `src/Image/Svg.php` | add `UaState $ua` parameter (or receive via the existing SVG-construction path); used to assign MCIDs on Form XObject runs |

Instantiation is unconditional (cheap when `$mpdf->PDFUA` is false — the collaborators are simple PHP objects, no resource allocation).

### 2e. `src/Ua/StructureWriter.php` (new)

Called from `ResourceWriter::writeResources()` when `$this->mpdf->PDFUA` is true.

```php
namespace Mpdf\Ua;

class StructureWriter
{
    public function writeStructTree() {
        // 1. Walk the StructureTree root recursively.
        //    Write one PDF object per StructureElement:
        //      /Type /StructElem /S /<type> /P <parentRef 0 R>
        //      /K [<children or MCR dicts>] /Pg <pageRef 0 R> (if single page)
        //    For single-page elements: /K is [<mcid int>] or [<child refs>]
        //    For multi-page elements: /K is [<</Type /MCR /Pg N 0 R /MCID n>> ...]
        //    Store the assigned object number on each element ($elem->objNum).
        //    Include /A <</O /Layout /Scope /Column>> or other attribute objects when
        //    $elem->attributes is non-empty.
        //    Note struct elements are a separate attribute namespace (/O /Layout, /O /Table, etc.)

        // 2. Write the /ParentTree NumTree object.
        //    Keys: the /StructParents integers from pageDim[n]['structParents']
        //    Values: arrays of struct elem obj refs ordered by MCID
        //    Format: << /Nums [0 [ref ref …] 1 [ref ref …] …] >>

        // 3. Write the /RoleMap dict if any custom role= types were mapped.
        //    /RoleMap << /CustomRole /P … >>
        //    Required when role= HTML attributes introduce types not in the standard set.

        // 4. Write the /StructTreeRoot object:
        //    /Type /StructTreeRoot /K [<document root obj ref>]
        //    /ParentTree <numtree ref 0 R> /RoleMap <rolemap ref 0 R> (if present)
        //    Store the resulting object number via $this->ua->setStructTreeRootObjNum($n).
    }
}
```

Hook into `ResourceWriter::writeResources()` after the resource dictionary:
```php
if ($this->mpdf->PDFUA) {
    $this->ua->getStructureWriter()->writeStructTree();
}
```

Because `writeStructTree()` sets `structTreeRoot` via the setter, the catalog's `writeCatalog()` (called later in `_enddoc()`) picks up the correct object number. **Ordering is correct**: `writeResources()` fires before `writeCatalog()` in `_enddoc()`.

### Phase 2 Tests

**`tests/Mpdf/Ua/StructTypeTest.php`** (new — pure static unit tests, no mPDF dependency):

| Test method | Assertion |
|---|---|
| `testFromHtmlTagReturnsCorrectType` | `StructType::fromHtmlTag('P')` returns `'P'`; `fromHtmlTag('H1')` returns `'H1'`; `fromHtmlTag('UL')` returns `'L'` |
| `testFromHtmlTagUnknownReturnsNull` | `fromHtmlTag('UNKNOWN')` returns `null` |
| `testFromHtmlTagRoleOverride` | `fromHtmlTag('DIV', ['ROLE' => 'Sect'])` returns `'Sect'` |
| `testFromHtmlTagRoleInvalidIgnored` | `fromHtmlTag('DIV', ['ROLE' => 'BadType'])` returns `'Div'` (fallback to tagMap) |
| `testFromHtmlTagDl` | `fromHtmlTag('DL')` returns `'L'`; `fromHtmlTag('DT')` returns `'Lbl'`; `fromHtmlTag('DD')` returns `'LBody'` |
| `testFromHtmlTagFigcaption` | `fromHtmlTag('FIGCAPTION')` returns `'Caption'` |
| `testFromCssClassToc` | `fromCssClass('mpdf_toc')` returns `'TOC'` |
| `testFromCssClassTociLevel` | `fromCssClass('mpdf_toc_level_2')` returns `'TOCI'` |
| `testFromCssClassTocA` | `fromCssClass('mpdf_toc_a')` returns `'Link'` (not `'Reference'`) |
| `testFromCssClassTocPLevel` | `fromCssClass('mpdf_toc_p_level_0')` returns `'Lbl'` |
| `testFromCssClassUnknownReturnsNull` | `fromCssClass('mpdf_other')` returns `null` |
| `testIsValidKnownType` | `isValid('P')`, `isValid('Table')`, `isValid('Link')` all return `true` |
| `testIsValidUnknownType` | `isValid('BadType')` returns `false` |
| `testIsGroupingForGroupingTypes` | `isGrouping('TOC')`, `isGrouping('L')`, `isGrouping('Table')` return `true` |
| `testIsGroupingForLeafTypes` | `isGrouping('P')`, `isGrouping('TD')`, `isGrouping('Span')` return `false` |

**`tests/Mpdf/Ua/StructureTreeTest.php`** (new — no mPDF dependency, pure unit tests):

| Test method | Assertion |
|---|---|
| `testOpenCreatesRootDocument` | After construction, root element type is `'Document'` |
| `testOpenPushesChildElement` | `open('P')` adds a `P` child to the current element |
| `testClosePoppsStack` | After `open('P')` and `close()`, current element is back to Document root |
| `testAddContentReturnsMcid` | `addContent(0)` returns a non-negative integer |
| `testMcidIsUnique` | Two `addContent()` calls return different MCID integers |
| `testAddArtifactReturnsMinusOne` | `addArtifact()` returns `-1` |
| `testParentTreeIsPopulated` | After `addContent($idx)`, `getParentTree()[$idx][$mcid]` returns the current element |
| `testMcidsRecordedOnElement` | The element's `getMcids()` array contains the assigned entry |
| `testOpenArtifactSuppressesStructElements` | `openArtifact()` then `open('P')` does not add children to the Document root |
| `testAddContentInArtifactContextReturnsMinusOne` | `openArtifact()` then `addContent(0)` returns `-1` |
| `testCloseArtifactRestoresNormalBehaviour` | After `closeArtifact()`, `open('P')` works normally again |
| `testCloseWhenOnlyRootOnStackIsNoop` | After construction (only Document root on stack), calling `close()` leaves root as current element — no underflow crash |
| `testCloseInArtifactContextIsNoop` | `openArtifact()`, then `open('P')` (no-op), then `close()` — stack remains unchanged (no element was pushed so nothing should be popped) |
| `testCloseArtifactWhenDepthIsZeroIsNoop` | Calling `closeArtifact()` when `artifactDepth` is already 0 does NOT make `isArtifactContext()` return true (counter clamped at 0, not negative) |
| `testMcidResetsToZeroPerPage` | `addContent(0)` returns 0; `addContent(0)` again returns 1; `addContent(1)` (new page) returns 0 again — MCID counter resets per `structParentsIndex` |
| `testAddContentForElementInArtifactContextReturnsMinusOne` | `openArtifact()` then `addContentForElement($elem, 0)` returns `-1` and assigns no MCID entry to `$elem->getMcids()` |
| `testAddRoleMappingDuplicateFirstWins` | `addRoleMapping('CustomBox', 'Div')` then `addRoleMapping('CustomBox', 'Sect')` — `getRoleMappings()['CustomBox']` returns `'Div'` (first registration wins) |

**Extend `tests/Mpdf/Ua/MetadataTest.php`**:

| Test method | Assertion |
|---|---|
| `testStructTreeRootInCatalog` | After `Output()`, catalog contains `/StructTreeRoot` reference |
| `testStructTreeRootObjectExists` | The PDF output contains a `/Type /StructTreeRoot` object |

### Phase 2 Completion Gate

```bash
composer test                              # all existing + Phase 1 + Phase 2 tests must pass
vendor/bin/phpunit --group=snapshot        # snapshot suite must stay green
```

---

## Phase 3: Content Stream BDC/EMC Tagging

This phase wires up the MCID-bearing BDC/EMC operators into the page content stream.

**⚠ Phase 3 is NOT a standalone deliverable.** After Phase 3 alone, the document has `/MarkInfo /Marked true` but body content (paragraphs, headings, tables) produces zero struct elements — 95% of content is untagged. A document with PDFUA enabled after Phase 3 but before Phase 4 is non-conformant. Phases 3 and 4 must ship together in the same release.

Architectural note: §3a (BDC/EMC helpers) and §3b (block-layout hook) are pure infrastructure with no active callers until Phase 4 wires the tag handlers. They could logically be folded into Phase 2. §3c (image tagging) and §3d (header/footer artifact marking) are live rendering integrations that affect every PDF generated with PDFUA=true. The phasing is retained for review granularity but the Phase 3 completion gate should NOT be treated as a release checkpoint.

Struct element type names (P, H1, Figure, etc.) are assigned in Phase 4; Phase 3 tests validate only the infrastructure.

### 3a. `src/Ua/MarkedContentHelper.php` (new) — BDC/EMC encapsulation

BDC/EMC operator emission is handled by a dedicated collaborator — **no new methods are added to `Mpdf.php`**. All call sites use `$this->ua->getMarkedContentHelper()->begin(...)` / `->end()`.

```php
namespace Mpdf\Ua;

use Mpdf\Mpdf;
use Mpdf\Writer\BaseWriter;

class MarkedContentHelper
{
    private $writer;
    private $depth = 0;   // tracks BDC/EMC nesting depth

    public function __construct(BaseWriter $writer)
    {
        $this->writer = $writer;
    }

    /**
     * Emit a BDC operator for a tagged content sequence.
     * Pass $mcid = -1 to emit /Artifact BMC (no property dict).
     */
    public function begin($structType, $mcid, $altText = null)
    {
        if ($mcid === -1) {
            $this->writer->write('/Artifact BMC');
        } else {
            $props = '/MCID ' . $mcid;
            $this->writer->write('/' . $structType . ' <<' . $props . '>> BDC');
        }
        $this->depth++;
    }

    /**
     * Emit EMC. No-op if depth is already 0 (guards against underflow).
     */
    public function end()
    {
        if ($this->depth <= 0) {
            return;
        }
        $this->writer->write('EMC');
        $this->depth--;
    }

    /** @return int */
    public function getDepth()
    {
        return $this->depth;
    }
}
```

**CRITICAL: Routes through `$this->writer->write()`, never writes direct to `$this->pages[$this->page]`.**
`BaseWriter::write()` routes to the correct buffer depending on current rendering context:
- `bufferoutput=true` → `headerbuffer` (header/footer rendering)
- `ColActive=true` → `columnbuffer[]` (multi-column layout)
- `table_rotate=true` → `tablebuffer` (rotated table)
- `kwt=true` → `kwt_buffer[]` (keep-with-table headings)
- otherwise → `pages[$page]`

A direct `$this->pages[$this->page] .=` write bypasses all of these and places BDC/EMC in the wrong buffer in 4 out of 5 routing contexts — producing unbalanced operators that cause hard veraPDF failures.

**Depth balance check in `_enddoc()`:** Read `$this->ua->getMarkedContentHelper()->getDepth()` — no separate `pdfuaMarkedContentDepth` property:
```php
if ($this->PDFUA && $this->ua->getMarkedContentHelper()->getDepth() !== 0) {
    if ($this->PDFUAauto) {
        $this->ua->addWarning('Unbalanced BDC/EMC depth at end of document: ' . $this->ua->getMarkedContentHelper()->getDepth());
    } else {
        throw new \Mpdf\MpdfException('PDF/UA-1: Unbalanced marked content operators (depth=' . $this->ua->getMarkedContentHelper()->getDepth() . ')');
    }
}
```

**Column mode — sentinel strategy (see §6 below):** During column collection (`ColActive=1`), tag handlers do NOT call `markedContentHelper->begin()` / `->end()`. Instead they write sentinel entries to `columnbuffer[]` which are expanded to real BDC/EMC operators in the final output loop of `printcolumnbuffer()`. This means `markedContentHelper->getDepth()` does not track column-mode emissions; the balance assertion remains valid because all column buffers are flushed (via `SetColumns(0)` or page-end handling) before `_enddoc()` runs.

### 3b. Block content tagging — `newFlowingBlock()` and `finishFlowingBlock()`

`newFlowingBlock()` (line 6428) initialises `$this->flowingBlockAttr`. Add two new keys:

```php
$this->flowingBlockAttr['pdfua_struct_open'] = false;  // true when a struct element is on the stack
$this->flowingBlockAttr['pdfua_type'] = 'P';
```

**MCID assignment belongs in `finishFlowingBlock()`, not at tag-open time.** A block can span pages; each page the block appears on needs its own MCID pointing to the same struct element. At tag-open, the struct element is pushed onto the StructureTree stack. At `finishFlowingBlock()` time, the page is known and the MCID is assigned:

In `finishFlowingBlock()` (line 6460), just before the first `Cell()` call emits content:
```php
if ($this->PDFUA && $this->flowingBlockAttr['pdfua_struct_open']) {
    $structParents = isset($this->pageDim[$this->page]['structParents'])
        ? $this->pageDim[$this->page]['structParents']
        : 0;
    $mcid = $this->ua->getStructureTree()->addContent($structParents);
    $this->ua->getMarkedContentHelper()->begin($this->flowingBlockAttr['pdfua_type'], $mcid);
}
```

After the last `Cell()` for the block (at `$endofblock === true`):
```php
if ($this->PDFUA && $endofblock && $this->flowingBlockAttr['pdfua_struct_open']) {
    $this->ua->getMarkedContentHelper()->end();
}
```

**Multi-page blocks:** At the page-break point inside `finishFlowingBlock()`, close the current BDC with EMC, then on the new page call `addContent($newStructParents)` again for a new MCID — the same struct element records both MCIDs.

### 3c. Image tagging (`src/Tag/Img.php`)

Currently the `ALT` attribute is never read. Add at the top of `open()`:
```php
$alt = isset($attr['ALT']) ? $attr['ALT'] : null;
```

Store on `$objattr` before the `OBJECT_IDENTIFIER` string is assembled:
```php
$objattr['pdfua_alt'] = $alt;
```

During image rendering in `src/Mpdf.php` (where the `Do` operator is emitted), wrap. Use `isset()` pattern — **no `??` operator** (PHP 7.0+ only):
```php
if ($this->PDFUA) {
    $alt = isset($objattr['pdfua_alt']) ? $objattr['pdfua_alt'] : null;
    if ($alt === '') {
        // Explicitly empty alt = decorative, no property dict needed
        $mcid = $this->ua->getStructureTree()->addArtifact();
    } else {
        $figAlt = ($alt !== null) ? $alt : '';
        $this->ua->getStructureTree()->open('Figure', ['Alt' => $figAlt]);
        $structParents = isset($this->pageDim[$this->page]['structParents'])
            ? $this->pageDim[$this->page]['structParents']
            : 0;
        $mcid = $this->ua->getStructureTree()->addContent($structParents);
    }
    $this->ua->getMarkedContentHelper()->begin('Figure', $mcid);
}
// ... existing image Do operator ...
if ($this->PDFUA) {
    $this->ua->getMarkedContentHelper()->end();
    if ($mcid !== -1) {
        $this->ua->getStructureTree()->close();
    }
}
```

**Convention (W3C):** `alt=""` (explicitly empty) = decorative Artifact. `alt` absent = unknown; treat as decorative and add to `$this->ua->getWarnings()` (via `->addWarning()`). `alt="text"` = Figure with `Alt` attribute.

### 3d. Header/footer artifact marking

#### Four header/footer types — all handled in one place

mPDF exposes four header/footer APIs, all of which converge on a single internal rendering method:

| API | How used |
|---|---|
| `SetHTMLHeader()` / `SetHTMLFooter()` | PHP method call — sets `HTMLHeader[]` / `HTMLFooter[]` array entries |
| `SetHeader()` / `SetFooter()` | PHP method call — simple pipe-delimited string, stored as HTML in same arrays |
| `<htmlpageheader>` / `<htmlpagefooter>` | HTML tag — parsed into the same arrays via `Tag/HtmlPageHeader.php` / `HtmlPageFooter.php` |
| `<pageheader>` / `<pagefooter>` | HTML tag — simplified non-HTML format, also stored in the same arrays |

All four types are rendered exclusively through `_puthtmlheaders()` in `Mpdf.php`. **A single injection point in `_puthtmlheaders()` covers all four types.** No per-type special handling is required.

#### The bufferoutput routing issue

During `_puthtmlheaders()`, mPDF sets `$this->bufferoutput = true` before rendering and restores it after. While `bufferoutput=true`, `BaseWriter::endPage()` routes ALL content to `$this->headerbuffer` (not to `$this->pages[$this->page]`). The `headerbuffer` is later inserted at the `___HEADER___MARKER___` position in the page stream by `PageWriter::writePages()`.

**Critical**: Do NOT write BDC/EMC directly to `$this->pages[$this->page]` in Phase 3d — that places the markers in the wrong page buffer.

**Routing timing issue**: `$this->bufferoutput` is set to `true` INSIDE `WriteHTML()` (at `Mpdf.php:13362`), not before the `WriteHTML()` call in `_puthtmlheaders()`. A `$this->writer->write(BDC)` call placed BEFORE `WriteHTML()` executes with `bufferoutput=false` and routes to the regular page stream — not to `$this->headerbuffer`. The same applies to any `EMC` written AFTER `WriteHTML()` returns.

**Correct approach**: After `WriteHTML()` completes, `$this->headerbuffer` holds all the rendered header content as a string. Prepend BDC and append EMC directly to that string before it is spliced into the page:

```php
// _puthtmlheaders() — HEADER rendering block (after existing WriteHTML call):
$this->ua->getStructureTree()->openArtifact();
$this->WriteHTML($html, HTMLParserMode::HTML_HEADER_BUFFER);
$this->ua->getStructureTree()->closeArtifact();
if ($this->PDFUA) {
    // Wrap the accumulated headerbuffer content with Pagination artifact operators
    $this->headerbuffer = '/Artifact <</Type /Pagination /Subtype /Header>> BDC' . "\n"
        . $this->headerbuffer
        . "\nEMC\n";
}

// _puthtmlheaders() — FOOTER rendering block (same pattern):
$this->ua->getStructureTree()->openArtifact();
$this->WriteHTML($html, HTMLParserMode::HTML_HEADER_BUFFER);
$this->ua->getStructureTree()->closeArtifact();
if ($this->PDFUA) {
    $this->headerbuffer = '/Artifact <</Type /Pagination /Subtype /Footer>> BDC' . "\n"
        . $this->headerbuffer
        . "\nEMC\n";
}
```

`openArtifact()` is called BEFORE `WriteHTML()` so that all struct element creation inside the header rendering is suppressed from the start. The BDC/EMC wrap happens AFTER, ensuring the operators are in the same string that gets spliced into the page.

#### Struct tree suppression for header/footer HTML content

HTML headers/footers can contain arbitrary markup: headings, images, tables, spans. Without suppression, a `<h1>` inside a header would create an `/H1` struct element in the document structure tree — semantically wrong (it is a pagination artifact, not document content).

`openArtifact()` / `closeArtifact()` on `StructureTree` set an internal flag that causes all tag handlers and `finishFlowingBlock()` to skip struct element creation and MCID assignment for the duration. This is already needed for columns (Phase 4 multi-column treatment) and follows the same pattern.

**Implementation of `openArtifact()` / `closeArtifact()` in `StructureTree`:**

`$artifactDepth` is already declared in §2b as `var $artifactDepth; // int — depth counter for aria-hidden/role=none subtrees`. Use the counter — not a boolean — so nested artifact scopes work correctly (e.g., header rendering calls `openArtifact()`, then a watermark inside the header also calls `openArtifact()`; the inner `closeArtifact()` must not prematurely exit the outer scope):

```php
// $artifactDepth already declared as var above
// NO 'private $inArtifactScope' — use the counter from §2b

public function openArtifact()
{
    $this->artifactDepth++;
}

public function closeArtifact()
{
    $this->artifactDepth--;
}

public function isInArtifact()
{
    return $this->artifactDepth > 0;
}
```

All tag handlers and `finishFlowingBlock()` guard struct element creation:

```php
if ($this->mpdf->PDFUA && !$this->ua->getStructureTree()->isInArtifact()) {
    // ... struct element push/pop, MCID assignment
}
```

The `processingHeader` and `processingFooter` flags that are already set by `_puthtmlheaders()` could also serve as guards, but `openArtifact()` is cleaner because it works for any artifact scope (columns, watermarks, fixed-position blocks) without coupling to header-specific flags.

#### Page numbers in headers/footers

Page numbers rendered inside headers/footers (`{PAGENO}`, `{nb}`) are replaced during `_puthtmlheaders()` rendering, which occurs while `bufferoutput=true` and inside the Pagination BDC/EMC pair. They are already correctly wrapped as Artifact content — no additional marking needed.

#### Images in HTML headers/footers

Images (`<img>`) inside HTML headers/footers would normally produce Figure struct elements. Because `openArtifact()` suppresses struct element creation during header/footer rendering, header images are subsumed into the enclosing Pagination artifact. No `/Figure` struct element is created; no MCID is assigned. This is correct PDF/UA behaviour.

Page numbers (inline in body, not in headers): `/Artifact <</Type /Pagination>> BDC ... EMC`.

### Phase 3 Tests

**`tests/Mpdf/Ua/ContentStreamTest.php`** (new):

Phase 3 tests check the BDC/EMC *infrastructure* only — they do not assert structure element type names since those are wired in Phase 4.

| Test method | Assertion |
|---|---|
| `testHelperWritesBdcToPageStream` | Calling `$mpdf->ua->getMarkedContentHelper()->begin('P', 5)` appends `/P <</MCID 5>> BDC` to the page stream |
| `testHelperWritesEmcToPageStream` | `$mpdf->ua->getMarkedContentHelper()->end()` appends `EMC` to the page stream |
| `testArtifactHelperWritesBmcNoDictToPageStream` | `$mpdf->ua->getMarkedContentHelper()->begin('Artifact', -1)` appends `/Artifact BMC` (no dict) |
| `testHelperTracksDepth` | After `begin()` depth is 1; after `end()` depth is 0; `end()` when depth is 0 is no-op |
| `testHeaderArtifactUsesBdcNotBmc` | Page stream for doc with HTML header contains `/Artifact <</Type /Pagination /Subtype /Header>> BDC` |
| `testFooterArtifactUsesBdcNotBmc` | Page stream contains `/Artifact <</Type /Pagination /Subtype /Footer>> BDC` |
| `testImageWithEmptyAltProducesArtifactBmc` | Image with `alt=""` produces `/Artifact BMC` (no dict — BMC is correct here) |
| `testBdcEmcBalancedSimple` | In the page content stream for a simple `<p>Hello</p>` document, count of `BDC` + `BMC` occurrences equals count of `EMC` occurrences. Scope the regex to tokens emitted by mPDF's PDFUA code only (match lines ending in `BDC` or `BMC` only when preceded by a known tag name or `/Artifact`) to avoid picking up existing Optional Content Group operators |
| `testBdcEmcBalancedWithHeaders` | Same balance assertion applied to `example12_paging_html.php` HTML which includes HTML headers and footers — validates that header/footer BDC/EMC pairs close correctly |
| `testEndMarkedContentWhenDepthIsZeroIsNoop` | Calling `$mpdf->ua->getMarkedContentHelper()->end()` when depth is already 0 does NOT write `EMC` to the stream and does NOT make the counter negative — the depth remains 0 |
| `testUnbalancedMarkedContentDepthPositiveThrows` | If `$mpdf->ua->getMarkedContentHelper()->getDepth() > 0` at `_enddoc()` time and `PDFUAauto=false`, an `MpdfException` is thrown |
| `testUnbalancedMarkedContentDepthPositiveWarns` | If `$mpdf->ua->getMarkedContentHelper()->getDepth() > 0` at `_enddoc()` time and `PDFUAauto=true`, no exception is thrown but `$mpdf->ua->getWarnings()` is non-empty |
| `testHeaderContentIsArtifactWrapped` | Page content stream for a doc with an HTML header contains the header text inside an `/Artifact <</Type /Pagination /Subtype /Header>> BDC … EMC` block |
| `testFooterContentIsArtifactWrapped` | Page content stream for a doc with an HTML footer contains footer text inside an `/Artifact <</Type /Pagination /Subtype /Footer>> BDC … EMC` block |
| `testEncryptedOutputHasXmpNotEncrypted` | With `PDFUA=true` and `SetProtection()` active, the raw PDF output contains plaintext XMP namespace declarations (`pdfuaid:part`) — the XMP stream is not encrypted |

### Phase 3 Completion Gate

```bash
composer test                              # all existing + Phase 1 + 2 + 3 tests must pass
vendor/bin/phpunit --group=snapshot        # snapshot suite must stay green
```

---

## Phase 4: Element-by-Element Structure Tagging

All tag handlers guard their structure tree calls with `if ($this->mpdf->PDFUA)`. MCID assignment happens in `finishFlowingBlock()` (wired in Phase 3) — tag handlers only push/pop struct elements onto the StructureTree stack and set `flowingBlockAttr['pdfua_struct_open'] = true`.

### Headings and paragraphs (`src/Tag/BlockTag.php`)

**`open()` method** — after CSS is resolved, before `newFlowingBlock()` is called:

```php
if ($this->mpdf->PDFUA) {
    // Check CSS class first (for ToC divs: mpdf_toc, mpdf_toc_level_N, etc.)
    $structType = null;
    if (!empty($attr['CLASS'])) {
        foreach (explode(' ', strtolower($attr['CLASS'])) as $cls) {
            $tocType = \Mpdf\Ua\StructType::fromCssClass($cls);
            if ($tocType !== null) {
                $structType = $tocType;
                break;
            }
        }
    }
    if ($structType === null) {
        $structType = \Mpdf\Ua\StructType::fromHtmlTag($tag, $attr);
    }
    if ($structType !== null) {
        $this->ua->getStructureTree()->open($structType);
    }
}
```

After `newFlowingBlock()`:
```php
if ($this->mpdf->PDFUA) {
    $this->mpdf->flowingBlockAttr['pdfua_struct_open'] = true;
    $this->mpdf->flowingBlockAttr['pdfua_type'] = $structType;
}
```

**`close()` method** — after `finishFlowingBlock()`:
```php
if ($this->mpdf->PDFUA) {
    $this->ua->getStructureTree()->close();
}
```

### Lists (`src/Tag/Ul.php`, `Ol.php`, `Li.php`)

- `<ul>` open → `structureTree->open('L')`
- `<ol>` open → `structureTree->open('L', ['ListNumbering' => 'Decimal'])`
- `<li>` open → `structureTree->open('LI')`, then `structureTree->open('LBody')`
- The bullet/number character injected by `BlockTag` (~line 354) → `structureTree->open('Lbl')` … `close()`
- `</li>` close → `close()` (LBody), then `close()` (LI)
- `</ul>`/`</ol>` close → `close()` (L)

### Nested Lists (`src/Tag/Ul.php`, `Ol.php`, `Li.php`) — nesting handled automatically

The stack-based `open()` / `close()` mechanism handles nested lists naturally **without any special-case code**. When a nested `<ul>` appears inside `<li>`, the struct tree stack is: `Document → L → LI → LBody`. `structureTree->open('L')` makes the nested `L` a child of `LBody`. Closing the inner list (`</ul>`) pops to `LBody`, then `</li>` closes `LBody` and `LI`, and `</ul>` (outer) closes the outer `L`. The correct PDF/UA structure (`L > LI > LBody > L > LI > LBody`) is produced automatically.

Add an explicit test for nested lists in `StructureElementsTest`:
```
testNestedListProducesNestedLStruct — <ul><li>A<ul><li>B</li></ul></li></ul> produces outer L > LI > LBody > L (inner) > LI > LBody
```

### Tables (`src/Tag/Table.php`, `Tr.php`, `Td.php`, `Th.php`)

**Struct tree nesting**: Same stack mechanism. When a nested `<table>` opens inside a `<td>`, the inner Table struct element is added as a child of TD. Correct and automatic.

**Critical parse-vs-render timing issue for table cells:**

For normal blocks, `finishFlowingBlock()` fires during RENDERING (the struct element is on the stack, `addContent()` assigns an MCID for the current page). For table cells, `finishFlowingBlock()` fires during HTML PARSING (the table-collect phase) — at that point the page is unknown. By the time `_tableWrite()` renders the cell, the block-level struct elements (P, H, etc.) inside the cell have already been pushed AND popped during parsing. The stack no longer has them.

**Solution: cell-level BDC at TD granularity (Phase 4 approach)**

At parse time, `Td::open()` / `Th::open()` store a reference to the newly created TD/TH struct element on the cell dict:
```php
if ($this->mpdf->PDFUA) {
    $attrs = [/* colspan, rowspan, scope */];
    $this->ua->getStructureTree()->open('TD', $attrs);
    // Store reference to the struct element BEFORE it's popped
    $this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['pdfua_struct_elem']
        = $this->ua->getStructureTree()->getCurrent();
}
```

`Td::close()` / `Th::close()` call `structureTree->close()` to pop the TD off the stack.

P, H, and other block struct elements inside the cell are pushed and popped during parsing (as children of TD in the struct tree) but do NOT get individual MCIDs in Phase 4. They exist in the struct tree for navigation, and the TD's single MCID covers the cell's content region. This is valid PDF/UA-1: struct elements can be grouping parents without direct MCID assignment.

In `_tableWrite()` (~line 21912), before and after processing `cell['textbuffer']`:
```php
// Before cell content rendering:
$pdfuaElem = isset($cell['pdfua_struct_elem']) ? $cell['pdfua_struct_elem'] : null;
if ($this->PDFUA && $pdfuaElem) {
    $structParents = isset($this->pageDim[$this->page]['structParents'])
        ? $this->pageDim[$this->page]['structParents']
        : 0;
    $mcid = $this->ua->getStructureTree()->addContentForElement($pdfuaElem, $structParents);
    $this->ua->getMarkedContentHelper()->begin($pdfuaElem->getType(), $mcid);
}
// ... existing cell rendering ...
// After cell content rendering:
if ($this->PDFUA && $pdfuaElem) {
    $this->ua->getMarkedContentHelper()->end();
}
```

Add `addContentForElement(StructureElement $elem, $structParentsIndex)` to `StructureTree` — same logic as `addContent()` but operates on an explicit element reference rather than the stack top.

**Nested tables** produce nested BDC/EMC blocks (outer TD BDC wraps inner `_tableWrite()` call which emits inner TD BDCs). Nested marked content sequences are valid in PDF (ISO 32000-1 §14.6). No special handling needed.

**Repeated header rows** (multi-page tables): when `$this->mpdf->tablethead` is true and a thead row is being re-output on a continuation page, call `structureTree->openArtifact()` / `closeArtifact()` (the repeat is presentational, not new logical content). Do not create new TR/TD struct elements for repeated headers.

**Table struct element list:**
- `<table>` open → `structureTree->open('Table')`
- `<thead>` / `<tbody>` / `<tfoot>` open → `structureTree->open('THead'|'TBody'|'TFoot')`
  - If no explicit `<thead>`/`<tbody>` markup, auto-wrap with `TBody`
- `<tr>` open → `structureTree->open('TR')`
- `<td>` open: `structureTree->open('TD', $attrs)` + store elem ref on `$cell`
- `<th>` open: `structureTree->open('TH', ['Scope'=>'Column', ...])` + store elem ref
- All corresponding close tags → `structureTree->close()`

### Links (`src/Tag/A.php`)

- `<a href="...">` open → `structureTree->open('Link')`
- `</a>` close → `structureTree->close()`
- Capture the link text between open and close from `$this->mpdf->textbuffer` at close time and store it on the `PageLinks` record as `$pl['txt']`.
- In `MetadataWriter::writeAnnotations()` at ~line 529, un-comment and activate the `/Contents` line that is currently commented out:
  ```php
  // BEFORE (commented out — causes issues in Chrome PDF viewer):
  // $annot .= ' /Contents ' . $this->writer->utf16BigEndianTextString($pl[4]);
  // AFTER (enable conditionally for PDFUA):
  if ($this->mpdf->PDFUA && isset($pl['txt'])) {
      $annot .= ' /Contents ' . $this->writer->utf16BigEndianTextString($pl['txt']);
  }
  ```
- For PDF/UA: also add `/StructParent N` to each link annotation object (see Annotations section below).

### PDF Annotations (`src/Tag/Annotation.php`, `src/Writer/MetadataWriter.php`)

PDF/UA-1 §7.18 requires ALL annotation types (except Link which uses the struct tree, and Widget which has form-specific handling) to have a `/Contents` key with a meaningful text description.

**`<annotation content="...">` sticky notes:**

The `content` attribute already provides the text for the popup. In `MetadataWriter::writeAnnotations()` (~line 603+), when writing `PageAnnots` entries, add `/Contents` for PDFUA:
```php
if ($this->mpdf->PDFUA) {
    $annot .= ' /Contents ' . $this->writer->utf16BigEndianTextString($pl['opt']['content']);
}
```

**Annotation `/F` flags**: The existing code adds `/F 28` for PDFA/PDFX. Extend to include PDFUA:
```php
// Before: if ($this->mpdf->PDFA || $this->mpdf->PDFX) {
if ($this->mpdf->PDFA || $this->mpdf->PDFX || $this->mpdf->PDFUA) {
    $annot .= ' /F 28';
}
```

**Struct tree association for annotations (Note struct elements):**

Sticky note annotations are visible rendered content (the icon appears on the page) and must be in the struct tree. Each annotation maps to a `Note` struct element. The association uses OBJR (Object Reference) dicts, which is different from MCID-based tagging:

- The struct element's `/K` contains an OBJR dict: `<</Type /OBJR /Obj N 0 R>>` (reference to the annotation PDF object)
- The annotation object gets `/StructParent M` (an integer index into the ParentTree, distinct from page-level `/StructParents`)
- The ParentTree NumTree has an entry keyed by `M` whose value is the struct element's object reference (not an array — individual annotation entries are single references)

**ParentTree distinction** (critical):
- `/StructParents N` on **page dicts** → ParentTree entry is an **array** of struct elem refs ordered by MCID: `N [ref ref ref …]`
- `/StructParent M` on **annotation/XObject/FormXObject** → ParentTree entry is a **single** struct elem ref: `M ref`

`StructureTree` must track annotation OBJR entries separately from MCID entries. Add a new method:
```php
// Register an annotation's association: assign a StructParent integer, return it.
// The struct element gets an OBJR entry in its /K array.
public function addAnnotationForElement($elem, $annotObjNum) {
    $structParentIndex = $this->nextAnnotStructParent($elem);
    $elem->addObjref($structParentIndex, $annotObjNum);
    return $structParentIndex;
}
```

**Ordering problem**: `writeAnnotations()` writes the full annotation object dict in one pass. To include `/StructParent N`, the value must be known before the dict is written — but struct parent indices are allocated by `StructureTree`, which normally operates at parse time. Mixing parse-time and write-time `StructureTree` mutations is dangerous.

**Implementation approach (commit to this — do not use the conflicting open/close-after-write pattern)**: Pre-assign `structParent` integers during `PageWriter::writePages()` where `$annotid` and `$totaladdnum` are already computed. Store them on `$this->mpdf->PageAnnots[$n][$k]['structParent']`. Then in `writeAnnotations()`, emit `/StructParent N` using the pre-stored value.

`StructureWriter::writeStructTree()` must merge both the page-level MCID arrays and the per-annotation single refs into the unified ParentTree NumTree.

**Scope**: This is a complete implementation of annotation struct association. Add to Phase 4 (not a later phase) since annotation objects are written alongside links in `writeAnnotations()`.

The `annotParentCounter` and `annotParentTree` properties are already defined on `StructureTree` (see §2b). The `objrefs` array and `addObjref()` method are already defined on `StructureElement` (see §2a). Update `StructureWriter` to emit OBJR dicts in struct element `/K` arrays.

### Active Form Fields (AcroForm Widget Annotations)

mPDF supports HTML form fields (text, password, checkbox, radio, select/listbox, textarea, button) that become PDF Widget annotations in an AcroForm. Key implementation facts:

- **Widget annotations already have `/TU` (tooltip)**: `Form.php` always writes `/TU` from the HTML `title`/`alt` attribute (lines ~805, ~1805, ~1438). This satisfies the PDF/UA-1 §7.18 requirement that every widget has an alternate description. **No change needed for /TU.**
- **Missing `/StructParent`**: Widget annotation dicts currently lack `/StructParent N`, which is required for PDF/UA-1 struct tree association.
- **Missing struct tree association**: No `Form` struct element with OBJR dict exists for any widget.

**PDF/UA treatment**: Identical mechanism to sticky-note annotations (OBJR dicts, not MCIDs):
- Each Widget annotation → `Form` struct element with OBJR in `/K` array
- Each Widget annotation gets `/StructParent M` integer key
- ParentTree NumTree entry: `M → single struct elem ref` (not array — same as sticky notes)

**Implementation** (parallel to annotation `/StructParent` pre-assignment in Phase 4):

**Step 1**: In `PageWriter::writePages()`, after `$this->form->countPageForms($n, $totaladdnum)` (lines ~77-79), pre-assign struct parent indices for form widgets on page `$n`:
```php
if ($this->mpdf->PDFUA && count($this->form->forms) > 0) {
    foreach ($this->form->forms as $ref => $frm) {
        if ($frm['page'] == $n) {
            // Pre-assign by creating a placeholder element to register with nextAnnotStructParent
            // (actual Form struct element creation happens in StructureWriter)
            $this->form->forms[$ref]['structParent']
                = $this->ua->getStructureTree()->annotParentCounter++;
        }
    }
}
```

**Step 2**: In `Form.php::_putform_tx()`, `_putform_ch()`, `_putform_bt()` (lines ~1768, ~1686, ~1409), add after writing `/TU`:
```php
if ($this->mpdf->PDFUA && isset($form['structParent'])) {
    $this->writer->write('/StructParent ' . $form['structParent']);
}
```

**Step 3**: In `StructureWriter::writeStructTree()`, after building sticky-note Note struct elements, create `Form` struct elements for each widget:
```php
foreach ($this->mpdf->form->forms as $frm) {
    if (isset($frm['structParent'])) {
        $formElem = new StructureElement('Form', ['TU' => $frm['TU']]);
        $formElem->objrefs[] = ['structParent' => $frm['structParent'], 'obj' => $frm['obj']];
        // $frm['obj'] is assigned during _putform_* and stored on the forms array
        $this->ua->getStructureTree()->getRoot()->children[] = $formElem;
        $this->ua->getStructureTree()->annotParentTree[$frm['structParent']] = $formElem;
    }
}
```

**Radio buttons**: Each individual radio button Widget is a separate annotation. Pre-assign `structParent` per kid widget, not per group parent. The parent field object (`form_radio_groups`) is not a Widget and does not get `/StructParent`. Each kid Widget gets its own `Form` struct element.

**Storing `obj` on forms array**: `_putform_*()` methods call `$this->writer->object()` which increments `$this->mpdf->n`. Store the assigned object number: `$this->forms[$formCount]['obj'] = $this->mpdf->n;` immediately after the `$this->writer->object()` call in each write method.

**Note**: Form fields are referenced from page `/Annots` via `$this->form->addFormIds()` (Form.php:738). The page-level `/StructParents N` key (already added in Phase 1h) covers MCIDs for content; the per-widget `/StructParent M` (singular) keys for widgets use the `annotParentCounter` on `StructureTree` — same counter used for sticky notes. This ensures unique integers across all OBJR-style ParentTree entries.

### Barcodes (`<barcode>`)

**How they work**: `<barcode code="978-0-..." type="EAN13">` is stored as an `OBJECT_IDENTIFIER` object in `$this->textbuffer` and processed at Mpdf.php lines 7414–7510. Rendering: 1D bars via `WriteBarcode()` (line 26155) or `WriteBarcode2()` (line 26535); QR codes via the `mpdf/qrcode` package (`vendor/mpdf/qrcode/src/Output/Mpdf.php`). All rendering uses `Rect()` calls → **PDF path operators** (`re f`) — vector graphics, NOT text operators. Human-readable text (EAN/ISBN when `text="1"`) uses `Cell()` inside `WriteBarcode()` and is part of the same rendering block.

**PDF/UA treatment — Figure with Alt**: Barcodes encode meaningful data (URLs, product numbers, ISBNs), so tag as `Figure` with `Alt = "Barcode: <code value>"`. Use the `code` attribute value from `$objattr['code']` at lines 7414–7510.

**Implementation** — at lines 7414–7510 in Mpdf.php, before the WriteBarcode/WriteBarcode2/QR render call:
```php
if ($this->PDFUA) {
    $altText = 'Barcode: ' . $objattr['code'];
    $this->ua->getStructureTree()->open('Figure', ['Alt' => $altText]);
    $structParents = isset($this->pageDim[$this->page]['structParents'])
        ? $this->pageDim[$this->page]['structParents'] : 0;
    $mcid = $this->ua->getStructureTree()->addContent($structParents);
    $this->ua->getMarkedContentHelper()->begin('Figure', $mcid);
}
// ... existing WriteBarcode() / WriteBarcode2() / QR render call ...
if ($this->PDFUA) {
    $this->ua->getMarkedContentHelper()->end();
    $this->ua->getStructureTree()->close();
}
```

**Human-readable text** (EAN `text="1"`): The `Cell()` call for the digits is inside `WriteBarcode()` — it is wrapped by the Figure BDC/EMC along with the bars. Do NOT emit a separate P struct element for it; it's part of the Figure's content.

**QR codes encoding URLs**: The `code` attribute contains the URL — Alt = "QR code: https://example.com" is fine.

**`aria-label` on `<barcode>`**: If an `aria-label` attribute is present on the barcode tag, use it as the Alt value instead of the auto-generated "Barcode: <code>".

**Test**: `testBarcodeProducesFigureStructElement` — `<barcode code="9780954224608" type="EAN13">` produces `/S /Figure` struct element with Alt containing the code value; BDC/EMC wraps the entire bar + text rendering.

### Watermarks (`<watermarktext>` / `<watermarkimage>`)

**How they work**: `SetWatermarkText()` / `SetWatermarkImage()` store settings; `watermark()` and `watermarkImg()` render them. Both are called from `Footer()` (~line 13167), so `$this->processingFooter=true` during rendering — `BaseWriter::endPage()` routes content directly to `$this->pages[$this->page]` (not `columnbuffer`). Watermarks are repeating background/overlay elements on every page.

**Text watermarks**: `watermark()` (~line 10556) calls `Rotate()` + `Text()` → `BT … Tj … ET` operators.

**Image watermarks**: `watermarkImg()` (~line 10643) calls `Image()` → `Do` operator. When `watermarkImgBehind=true`, content is injected before the `___BACKGROUND___PATTERNS` marker via `preg_replace()` (~line 9001).

**PDF/UA treatment — Artifact**: Watermarks are decorative, so tag with `/Artifact <</Type /Background>> BDC … EMC`.

**Injection for text watermarks** — in `watermark()`:
```php
if ($this->PDFUA) {
    $this->pages[$this->page] .= '/Artifact <</Type /Background>> BDC' . "\n";
}
// ... existing rotation + Text() call ...
if ($this->PDFUA) {
    $this->pages[$this->page] .= 'EMC' . "\n";
}
```

**Injection for image watermarks (`watermarkImgBehind=false`)** — same pattern around the `Image()` call in `watermarkImg()`.

**Injection for image watermarks (`watermarkImgBehind=true`)** — the content is injected via `preg_replace()` before the `___BACKGROUND___PATTERNS` marker. Include BDC/EMC in the replacement string:
```php
// Wrap the image content with Artifact BDC/EMC in the preg_replace insertion
$pdfuaPrefix = $this->PDFUA ? '/Artifact <</Type /Background>> BDC' . "\n" : '';
$pdfuaSuffix = $this->PDFUA ? "\nEMC" : '';
$this->pages[$this->page] = preg_replace(
    '/(\s*___BACKGROUND___PATTERNS' . $this->uniqstr . '\s*)/',
    "\n" . $pdfuaPrefix . $imageContent . $pdfuaSuffix . "\n$1",
    $this->pages[$this->page]
);
```

**Why `/Type /Background`** (not `/Subtype /Header` or `/Subtype /Footer`): Background is the correct Artifact type per ISO 32000-1 §14.8.2.2 for decorative watermarks, page backgrounds, and repeating graphic overlays.

**Test**: `testWatermarkTextIsArtifact` — page with `<watermarktext content="DRAFT">` produces `/Artifact <</Type /Background>> BDC … BT … Tj … ET … EMC` in the page stream; BDC/EMC balanced.

### `<textcircle>` Tag

**How it works**: `<textcircle top-text="..." bottom-text="..." r="20mm">` renders text along a circular arc. It is stored as an `OBJECT_IDENTIFIER` inline object in `$this->textbuffer` (like images) and processed when the textbuffer is flushed. Rendering happens in `DirectWrite::CircularText()` (`src/DirectWrite.php:278`), which positions each character individually with transform matrices (`transformRotate()`) and then emits it via `Cell()`. The output is **actual PDF text operators** (`BT … Tj … ET`), not graphics paths — text is readable by PDF text extraction tools and screen readers.

**PDF/UA treatment — `Span` struct element**: Since the content is real PDF text, tag as `Span` (not `Figure` and not Artifact). Set `ActualText` on the struct element to the concatenation of `top-text + divider + bottom-text` — this provides the canonical accessible text regardless of character stream order.

**Implementation**: At `Mpdf.php` lines 7524–7551 (where textcircle objects are processed from the textbuffer), when `PDFUA=true`:
```php
// Push a Span struct element for the textcircle
$this->ua->getStructureTree()->open('Span', ['ActualText' => $topText . $divider . $bottomText]);
$structParents = isset($this->pageDim[$this->page]['structParents'])
    ? $this->pageDim[$this->page]['structParents'] : 0;
$mcid = $this->ua->getStructureTree()->addContent($structParents);
$this->ua->getMarkedContentHelper()->begin('Span', $mcid);
// ... call DirectWrite::CircularText() ...
$this->ua->getMarkedContentHelper()->end();
$this->ua->getStructureTree()->close();
```

The `ActualText` attribute value in `StructureWriter` should be output as a UTF-16BE PDF string.

**Test**: `testTextCircleProducesSpanStructElement` — `<textcircle top-text="foo" bottom-text="bar" r="20mm">` produces a `/S /Span` struct element with `ActualText` attribute containing "foobar" (or "foobar" with divider if present).

### `Image()` Direct PHP Method

mPDF exposes a low-level `Image($file, $x, $y, $w, $h, ...)` PHP method that writes an image directly to the current page position, bypassing the HTML parser and textbuffer entirely. It is used by library consumers who build documents programmatically rather than via `WriteHTML()`. Its current signature (line 8869 of `Mpdf.php`):

```php
function Image($file, $x, $y, $w = 0, $h = 0, $type = '', $link = '',
               $paint = true, $constrain = true, $watermark = false,
               $shownoimg = true, $allowvector = true)
```

**Add an `$alt` parameter** for PDF/UA:

```php
function Image($file, $x, $y, $w = 0, $h = 0, $type = '', $link = '',
               $paint = true, $constrain = true, $watermark = false,
               $shownoimg = true, $allowvector = true, $alt = null)
```

`$alt = null` means "caller did not provide alt text" (warn + treat as Artifact). `$alt = ''` means explicitly decorative. `$alt = 'text'` means meaningful content → Figure struct element.

#### Two rendering paths — BDC/EMC injection point differs

**Direct `Image()` call (new `$alt` parameter)**: Content writes to the page via `$this->writer->write($outstring)` at line 9074. BDC/EMC must be injected **immediately before and after that call** inside `Image()` itself.

**HTML `<img>` tag**: Already covered by Phase 3c/4 — `Tag/Img.php` stores `$objattr['alt']` from `$attr['ALT']` in the textbuffer object; `printobjectbuffer()` (~line 7381) emits the BDC/EMC around the `Do` operator when flushing.

**These are two separate injection points.** HTML `<img>` and direct `Image()` each need their own BDC/EMC code. The `$alt` parameter on `Image()` does not affect the HTML `<img>` path.

#### BDC/EMC injection in `Image()`

Inject around the existing `$this->writer->write($outstring)` at line 9074:

```php
// --- PDF/UA tagging for Image() ---
$pdfuaTagOpened = false;
if ($this->PDFUA && !$watermark) {
    $inArtifactScope = $this->ua->getStructureTree()->isInArtifact() || $this->ColActive;
    if ($inArtifactScope) {
        // Inside header/footer or column region — suppress inner BDC/EMC entirely.
        // The enclosing Pagination BDC (headers) or Artifact BMC (columns) already covers it.
    } elseif ($alt === '') {
        // Explicitly decorative
        $this->writer->write('/Artifact BMC');
        $pdfuaTagOpened = 'artifact';
    } elseif ($alt !== null) {
        // Meaningful image — create Figure struct element
        $structParents = isset($this->pageDim[$this->page]['structParents'])
            ? $this->pageDim[$this->page]['structParents'] : 0;
        $this->ua->getStructureTree()->open('Figure', ['Alt' => $alt]);
        $mcid = $this->ua->getStructureTree()->addContent($structParents);
        $this->writer->write('/Figure <</MCID ' . $mcid . '>> BDC');
        $pdfuaTagOpened = 'figure';
    } else {
        // null — caller did not provide alt text; warn and treat as Artifact
        $this->ua->addWarning('Image() called without $alt in PDFUA mode — treating as decorative: ' . $file);
        $this->writer->write('/Artifact BMC');
        $pdfuaTagOpened = 'artifact';
    }
}

// ... existing image output: $this->writer->write($outstring) ...

if ($pdfuaTagOpened) {
    $this->writer->write('EMC');
    if ($pdfuaTagOpened === 'figure') {
        $this->ua->getStructureTree()->close();
    }
}
```

**Why watermarks are excluded**: watermark images are already tagged as `/Artifact <</Type /Background>> BDC … EMC` by the `watermarkImg()` method (Phase 4 watermark handling). The `Image()` path for watermarks (`$watermark=true`) must not emit a second BDC/EMC pair.

**Why `ColActive` suppresses BDC/EMC**: Column buffer reordering (`printcolumnbuffer()`) sorts entries by `rel_y`. Each `writer->write()` call is a separate buffer entry. If BDC and EMC are separate entries from the `q … Do … Q` entry, reordering could interleave them — producing invalid PDF. Since column content is treated as Artifact at the block level in Phase 4, suppress per-image tagging in column mode too.

**BDC routing is correct**: `$this->writer->write()` routes through `BaseWriter::endPage()`, which handles `bufferoutput`, `ColActive`, and all other buffer contexts. No direct `$this->pages[$page]` writes needed.

#### Alt text conventions mirror HTML `<img>`

| `$alt` value | PDF/UA treatment |
|---|---|
| `null` (not passed) | Warning added to `$mpdf->ua->getWarnings()`; emit `/Artifact BMC` |
| `''` (empty string) | Decorative; emit `/Artifact BMC` (no dict — BMC is correct) |
| `'text'` (non-empty) | Meaningful; emit `/Figure <</MCID N>> BDC`; create Figure struct element with `/Alt` |

**Note on `isInArtifact()` vs `ColActive` vs watermark**: These three guards are independent. All three suppress BDC/EMC emission from within `Image()`. Their priority order: watermark check first (explicit `$watermark` param), then `isInArtifact()` / `ColActive` (contextual).

**Test**: `testImageMethodWithAlt` — `$mpdf->Image('img.jpg', 10, 10, 50, 50, '', '', true, true, false, true, true, 'A company logo')` produces a `/S /Figure` struct element with `/Alt` = "A company logo" and a `/Figure <</MCID N>> BDC … Do … EMC` sequence in the page stream.

**Test**: `testImageMethodWithEmptyAlt` — `$mpdf->Image(..., $alt='')` produces `/Artifact BMC … Do … Q … EMC`; no struct element.

**Test**: `testImageMethodWithoutAlt` — `$mpdf->Image(...)` (no `$alt`) adds an entry to `$mpdf->ua->getWarnings()` and emits `/Artifact BMC`.

### `AutosizeText()` Direct PHP Method

`AutosizeText($text, $w, $font, $style, $szfont = 72)` (lines 25415–25478, inside `/* -- DIRECTW -- */` block) shrinks a font size in a loop until the text string fits within width `$w`, then renders it via a single `Cell()` call at line 25476. It is a low-level direct-write method — no HTML parsing, no textbuffer, no `finishFlowingBlock()`.

**PDF/UA treatment — `Span` struct element**: The text argument is real, semantically meaningful content rendered as genuine PDF text operators (`BT … Tj … ET`). With a TrueType font (mandatory in PDF/UA mode), ToUnicode CMap provides the character mapping — no `ActualText` attribute is needed. Tag as `Span` since it is an inline text run with caller-controlled positioning, not a block element.

**No new parameter needed**: Unlike `Image()`, the text content itself is the accessible representation. There is no "alt text" concept here.

**BDC/EMC injection point**: Wrap the single `$this->Cell(...)` call at line 25476 with the same pattern used for `Image()`:

```php
// Before Cell() call:
$pdfuaTagOpened = false;
if ($this->PDFUA) {
    $inArtifactScope = $this->ua->getStructureTree()->isInArtifact() || $this->ColActive;
    if (!$inArtifactScope) {
        $structParents = isset($this->pageDim[$this->page]['structParents'])
            ? $this->pageDim[$this->page]['structParents'] : 0;
        $this->ua->getStructureTree()->open('Span');
        $mcid = $this->ua->getStructureTree()->addContent($structParents);
        $this->writer->write('/Span <</MCID ' . $mcid . '>> BDC');
        $pdfuaTagOpened = true;
    }
}

$this->Cell($w, 0, $text, 0, 0, 'C', 0, '', 0, 0, 0, 'M', 0, false, $OTLdata, $textvar);

// After Cell() call:
if ($pdfuaTagOpened) {
    $this->writer->write('EMC');
    $this->ua->getStructureTree()->close();
}
```

**Suppression guards** — identical rationale to `Image()`:
- `isInArtifact()` — if called during header/footer rendering, already inside a Pagination Artifact BDC; no inner BDC/EMC needed
- `ColActive` — column buffer reordering would separate the BDC and EMC entries; suppress and rely on the column-level Artifact marking

**`Cell()` routing is already correct**: `Cell()` uses `$this->writer->write()` which routes through `BaseWriter::endPage()`, handling `bufferoutput`, `ColActive`, table rotation, and all other buffer contexts. The BDC/EMC `writer->write()` calls use the same routing — no special handling required.

**Core font guard**: `AutosizeText()` contains `$this->usingCoreFont` at line 25428 — in PDF/UA mode core fonts are already forbidden (Phase 1 validation), so this branch is never reached.

**Test**: `testAutosizeTextProducesSpanStructElement` — `$mpdf->AutosizeText('Hello', 50, 'DejaVuSans', '')` produces a `/S /Span` struct element; page stream contains `/Span <</MCID N>> BDC … BT … ET … EMC`; BDC/EMC balanced.

### Layers (`BeginLayer()` / `EndLayer()` / `SetVisibility()`)

**How layers work internally**: mPDF layers map to PDF Optional Content Groups (OCGs). `BeginLayer($z)` writes `/OC /ZI<id> BDC` to the page stream via `$this->writer->write()`; `EndLayer()` writes `EMC`. The markers use temporary suffixes (`EMCZ-index`, `EMCBZ-index`, `EMCGZ-index`) during page accumulation, which are cleaned up by two `preg_replace()` calls in `_enddoc()` (lines 10065–10066). Separately, `SetVisibility()` uses three predefined OCGs (`OC1` = print-only, `OC2` = screen-only, `OC3` = hidden) written via `$this->writer->write()` as `/OC /OC1 BDC … EMC` etc.

**Layer content reordering at `_enddoc()`**: Around line 10039, `_enddoc()` extracts all layer-marked BDC/EMC blocks from each page's content string, sorts them by z-index (backgrounds → gradients → main content), removes them from their original positions, and re-appends them at the end of the page stream. This ensures correct visual z-ordering.

**PDF/UA spec position on OCGs (ISO 14289-1:2014 §7.10)**: Optional content is permitted in PDF/UA-1. The requirement is that content visible in the default OCG configuration must be fully tagged. Content in non-default states (hidden by default) must also be tagged for when it becomes visible.

**Layers are NOT forbidden in PDF/UA mode**: The existing `BeginLayer()` guard only blocks `PDFA || PDFX` (Mpdf.php ~line 2773). Do **not** add PDFUA to this condition — layers are valid in PDF/UA.

**Content inside layers must be tagged normally**: Layer BDC/EMC wraps content as an outer marked-content group. MCID-tagged BDC/EMC for struct elements sits **inside** the layer BDC/EMC. This nesting is valid per ISO 32000-1 §14.6:

```
/OC /ZI1 BDC          ← layer outer group (OCG reference, no MCID)
  /P <</MCID 3>> BDC  ← struct element inner group
    BT ... Tj ... ET  ← text
  EMC
EMC
```

No changes are needed to the tag handlers for layer support — the struct element push/pop in Phase 4 fires for all block content regardless of whether it is inside a layer. The layer BDC/EMC is transparent to the tagging logic.

**Layer reordering is safe for MCID-tagged content**: The reordering extracts **entire layer blocks** (everything between the temporary BDC marker and its matching EMC marker). Inner MCID BDC/EMC pairs remain intact within the extracted block and are re-inserted in order. MCID assignments reference their struct elements via ParentTree (not stream position), so moving the block does not invalidate struct tree references.

**Caveat — struct tree reading order after reordering**: After layer reordering, content stream order may differ from the order in which struct elements were pushed. PDF/UA-1 requires the struct tree to define the reading order; AT navigates by struct tree, not by stream position. This is valid. However, z-index layers that contain meaningful content (e.g., a floating sidebar rendered on a higher z-index than the main body) will appear in the struct tree in HTML source order, not in visual stacking order — which is the correct semantic order.

**`SetVisibility()` BDC/EMC — same treatment**: `/OC /OC1 BDC`, `/OC /OC2 BDC`, `/OC /OC3 BDC` wrap content in visibility groups. Content inside these is tagged normally (same as inside a layer). Visibility BDC/EMC is NOT reordered by `_enddoc()` (only z-index layer blocks are reordered). Tag content inside `SetVisibility()` blocks exactly as if the visibility BDC/EMC weren't there.

**Hidden OCG (`OC3`)**: Content wrapped in `/OC /OC3 BDC … EMC` is invisible by default. Tag it normally — if a user changes OCG visibility, the content must be accessible when shown. Do not default hidden content to Artifact.

**Layer names in OCG `/Name` field**: The OCG name (from `$this->layerDetails[$id]['name']`) is already written as a Unicode string in `OptionalContentWriter::writeOptionalContentGroups()`. No changes needed for PDF/UA.

**No new struct element type for layers**: Layers are a presentation/optional-content mechanism, not a semantic document structure element. No `Layer` struct type exists in PDF/UA-1. The struct tree is unaware of OCG boundaries.

**Test**: `testLayerContentIsTagged` — document using `$mpdf->BeginLayer(1)` around a paragraph then `$mpdf->EndLayer()` produces a `/P` struct element with MCID; the page stream contains `/OC /ZI1 BDC … /P <</MCID N>> BDC … EMC … EMC` in the correct nesting; BDC/EMC is balanced.

### `aria-*` Attribute Support

Poorly formed HTML can be improved for tagging using WAI-ARIA attributes. mPDF normalises all attribute names to uppercase in `WriteHTML()` (~line 13795), so always check `$attr['ARIA-HIDDEN']`, `$attr['ARIA-LABEL']`, `$attr['ARIA-LEVEL']`, `$attr['ROLE']`, etc.

**`aria-hidden="true"`** — the element and all its descendants are decorative. Push an artifact suppression context:
```php
if (isset($attr['ARIA-HIDDEN']) && strtolower($attr['ARIA-HIDDEN']) === 'true') {
    $this->ua->getStructureTree()->openArtifact();
    // element renders normally; all addContent() calls will return -1
}
```
Corresponding close handler calls `structureTree->closeArtifact()`.

**`aria-label="text"`** — provides an accessible name:
- On `<Figure>` when `alt` is absent: use `aria-label` as the `Alt` attribute value.
- On `<Span>` when visible text differs: use as `ActualText` attribute.
- Read order: explicit `alt` > `aria-label` > absent (warn/artifact).

**`aria-colspan` / `aria-rowspan`** — ARIA grid attributes expressing logical span for cells in ARIA-authored grids where HTML `colspan`/`rowspan` may be absent or differ. Map to PDF `/ColSpan` / `/RowSpan` on TD and TH struct elements. When both HTML `colspan`/`rowspan` and `aria-colspan`/`aria-rowspan` are present, prefer the HTML values (they are visual reality); use ARIA values only as fallback. Check `$attr['ARIA-COLSPAN']` and `$attr['ARIA-ROWSPAN']` in `Td.php` and `Th.php` alongside the existing colspan/rowspan parsing.

**`role` attribute** — overrides or supplements the HTML tag's implied structure type. Full mapping (WAI-ARIA 1.2 → PDF/UA-1 struct types):

| ARIA role | PDF/UA structure type | Notes |
|---|---|---|
| `heading` (+ `aria-level`) | `H1`–`H6` based on `aria-level`; default `H` | aria-level clamped 1–6 |
| `list` | `L` | |
| `listitem` | `LI` | |
| `table` / `grid` | `Table` | |
| `row` / `gridcell` row | `TR` | |
| `columnheader` | `TH` with `Scope=Column` | |
| `rowheader` | `TH` with `Scope=Row` | |
| `cell` / `gridcell` | `TD` | |
| `figure` | `Figure` | |
| `none` / `presentation` | Artifact | Equivalent to `aria-hidden` |
| `note` | `Note` | Must set unique `/ID` — Matterhorn 09-004 |
| `link` | `Link` | |
| `article` | `Art` | |
| `region` | `Sect` | Usually needs `aria-label` for title |
| `navigation` / `main` / `banner` / `complementary` / `contentinfo` | `Sect` | Landmark roles mapped to generic section |
| `group` | `Div` | |
| `paragraph` | `P` | |
| `term` | `Span` | Part of definition list |
| `definition` | `Span` | |
| `img` | `Figure` | Use `aria-label` as Alt |
| `separator` | `Artifact` | Decorative horizontal rule |
| `doc-footnote` | `Note` | DPUB-ARIA; unique /ID required |
| `doc-chapter` | `Sect` | DPUB-ARIA |
| `doc-title` | `Title` | DPUB-ARIA |

Implementation: check `$attr['ROLE']` after determining `$structType` from the HTML tag name:
```php
if (isset($attr['ROLE'])) {
    $role = strtolower($attr['ROLE']);
    if ($role === 'none' || $role === 'presentation' || $role === 'separator') {
        // treat as Artifact — openArtifact() and skip normal structureTree->open()
    } elseif ($role === 'heading') {
        $level = isset($attr['ARIA-LEVEL']) ? (int) $attr['ARIA-LEVEL'] : 2;
        $structType = 'H' . max(1, min(6, $level));
    } elseif (isset($ariaRoleMap[$role])) {
        $structType = $ariaRoleMap[$role];
    } else {
        // Unmapped custom role: record in RoleMap for StructureWriter to emit.
        // Map to a safe standard type (Div) and let the RoleMap resolve it.
        $this->ua->getStructureTree()->addRoleMapping($role, $structType);
    }
}
```

Custom role values not in the standard set must be recorded in the StructTreeRoot's `/RoleMap` dict. `StructureTree::addRoleMapping($role, $standardType)` stores these for `StructureWriter` to emit.

**ID-referencing ARIA attributes — resolved via two-pass `AriaIdResolver`** (`src/Ua/AriaIdResolver.php`, Phase 4):

The following attributes reference another element by ID and are supported despite mPDF's sequential streaming HTML parsing:

- `aria-labelledby`, `aria-describedby`, `aria-details` — ID-based content reference
- `aria-controls`, `aria-owns`, `aria-flowto`, `aria-activedescendant` — relationship references

mPDF's parser is sequential, so the target element for a forward reference may not be known yet when the referencing element's struct dict is built. The resolver runs a deferred second pass at `_enddoc()` time — safe because `/Alt`, `/E`, and relationship kids live on the in-memory `StructureElement` (not in the already-flushed page content stream), and `_enddoc()` post-processes pages BEFORE `StructureWriter::writeStructTree()` serialises the struct tree (`src/Mpdf.php:10014` → `ResourceWriter::writeResources()` → `structureWriter->writeStructTree()`).

**Resolver API (`src/Ua/AriaIdResolver.php`)**:

```php
namespace Mpdf\Ua;

class AriaIdResolver
{
    private $ua;
    /** @var array<string, StructureElement> id → element */
    private $idMap = [];
    /** @var array list of [element, attrName, targetId] tuples to resolve at _enddoc() */
    private $pending = [];

    public function __construct(UaState $ua) { $this->ua = $ua; }

    private function tree()
    {
        return $this->ua->getStructureTree();
    }

    /** Call from any tag handler's open() when an `id` attribute is seen. */
    public function registerId($id, StructureElement $elem)
    {
        if ($id !== '' && !isset($this->idMap[$id])) {
            $this->idMap[$id] = $elem;  // first-declaration wins on duplicate IDs
        }
    }

    /** Call from tag handlers that see an ID-referencing ARIA attribute. */
    public function queue(StructureElement $elem, $ariaAttrName, $targetIds)
    {
        foreach (preg_split('/\s+/', trim((string) $targetIds)) as $id) {
            if ($id !== '') {
                $this->pending[] = [$elem, $ariaAttrName, $id];
            }
        }
    }

    /** Call once from _enddoc() before StructureWriter::writeStructTree(). */
    public function resolveAll()
    {
        foreach ($this->pending as list($elem, $attr, $id)) {
            if (!isset($this->idMap[$id])) {
                $this->ua->addWarning("Unresolved ARIA reference: $attr=\"$id\" has no matching id=\"$id\" in the document");
                continue;
            }
            $target = $this->idMap[$id];
            switch ($attr) {
                case 'aria-labelledby':
                    if (!isset($elem->getAttributes()['Alt'])) {
                        $elem->setAttribute('Alt', $this->collectText($target));
                    }
                    break;
                case 'aria-describedby':
                case 'aria-details':
                    $elem->setAttribute('E', $this->collectText($target));
                    break;
                case 'aria-controls':
                case 'aria-owns':
                case 'aria-flowto':
                case 'aria-activedescendant':
                    // Register a relationship kid — an OBJR if the target is an annotation,
                    // otherwise a UserProperty /Ref attribute (ISO 32000-1 §14.8.5.3).
                    $elem->addRelationship($attr, $target);
                    break;
            }
        }
    }

    /** Gather concatenated descendant text for Alt/E text sourcing. */
    private function collectText(StructureElement $elem) { /* walk K array, collect text items */ }
}
```

**Tag-handler integration** (mechanical — applies to every tag handler that supports `id` or any ID-referencing ARIA attribute):

```php
// After opening a struct element:
if ($this->mpdf->PDFUA) {
    $elem = $this->ua->getStructureTree()->getCurrent();
    if (!empty($attr['ID'])) {
        $this->ua->getAriaIdResolver()->registerId($attr['ID'], $elem);
    }
    foreach (['ARIA-LABELLEDBY','ARIA-DESCRIBEDBY','ARIA-DETAILS',
              'ARIA-CONTROLS','ARIA-OWNS','ARIA-FLOWTO','ARIA-ACTIVEDESCENDANT'] as $k) {
        if (!empty($attr[$k])) {
            $this->ua->getAriaIdResolver()->queue($elem, strtolower($k), $attr[$k]);
        }
    }
}
```

**`StructureElement` additions** (Phase 2):
- `addRelationship($ariaAttr, StructureElement $target)` — stores `['attr'=>..., 'target'=>...]` for `StructureWriter` to emit as an OBJR kid or `/Ref` UserProperty.
- `setAttribute($key, $value)` — mutates the `$attributes` array.

**`Mpdf::_enddoc()` hook** (insert immediately before the existing OCG-layer post-processing, and before the call to `$this->ua->getStructureWriter()->writeStructTree()` inside `ResourceWriter::writeResources()`):

```php
if ($this->PDFUA) {
    $this->ua->getAriaIdResolver()->resolveAll();
}
```

**Tests** (`tests/Mpdf/Ua/AriaIdResolverTest.php`):

| Test | Assertion |
|---|---|
| `testAriaLabelledbyFillsAlt` | `<img src="x.png" aria-labelledby="lbl"><span id="lbl">Caption</span>` produces a Figure struct element with `/Alt (Caption)` after _enddoc resolution |
| `testAriaDescribedbyFillsExpansionE` | `<abbr aria-describedby="d">HTTP</abbr><span id="d">Hypertext Transfer Protocol</span>` produces a `Span` with `/E (Hypertext Transfer Protocol)` |
| `testAriaControlsAddsRelationship` | `<button aria-controls="panel">` + `<div id="panel">` — the button's struct element carries a relationship entry referencing the panel's struct element |
| `testForwardReferenceResolves` | Reference appears BEFORE the target in source order; resolver still fills the attribute because resolution runs at `_enddoc()` |
| `testUnresolvedReferenceWarns` | `aria-labelledby="missing"` with no matching id produces an entry in `$mpdf->ua->getWarnings()` |
| `testDuplicateIdFirstWins` | Two elements with `id="x"`; only the first is registered |

**Removed from plan**: the interactive-state ARIA attributes (`aria-live`, `aria-atomic`, `aria-busy`, `aria-relevant`, `aria-checked`, `aria-selected`, `aria-pressed`, `aria-expanded`, `aria-sort`) are runtime-DOM concepts without a static-PDF analog and are not discussed in this plan.

### HTML `lang` Attribute → `/Lang` on Struct Elements

PDF/UA-1 §7.2 requires language identification. The document-level language is set from mPDF's `$this->mpdf->getDocMarkup()` config. Per-element language changes (e.g., `<p lang="fr">` within an English document) must propagate to struct elements via the `/Lang` attribute.

In every tag handler's `open()`, check `$attr['LANG']` and if present, pass it in the attributes array to `structureTree->open()`:
```php
$structAttrs = [];
if (isset($attr['LANG'])) {
    $structAttrs['Lang'] = $attr['LANG'];
}
// ... then:
$this->ua->getStructureTree()->open($structType, $structAttrs);
```

`StructureWriter` emits `/Lang (xx-YY)` in the struct element object when `Lang` is in the element's attributes.

### Abbreviation Expansion (`<abbr>`, `<acronym>`)

PDF/UA-1 §7.1 recommends providing expansion text for abbreviations. The HTML `title` attribute on `<abbr>` provides this. In `src/Tag/` for the `abbr` and `acronym` tags, extract `$attr['TITLE']` and store it on the struct element as the `/E` (expansion) attribute:
```php
if (isset($attr['TITLE']) && $attr['TITLE'] !== '') {
    $structAttrs['E'] = $attr['TITLE'];
}
$this->ua->getStructureTree()->open('Span', $structAttrs);
```

`StructureWriter` emits `/E <UTF-16BE title string>` in the struct element.

### `SetProtection()` / PDF Encryption

**PDF/UA-1 does NOT forbid encryption.** Unlike PDF/A-1b and PDF/X-1a (which throw a hard exception at line 9531 of `Mpdf.php`), PDF/UA-1 permits encrypted documents subject to two mandatory constraints in the PDF spec and Matterhorn Protocol. Do NOT add PDFUA to the `if (($this->PDFA || $this->PDFX) && $this->encrypted)` guard.

#### Constraint 1 — Accessibility permission bit must not be cleared (Matterhorn 07-001)

PDF spec permission bit 10 is the "content copying for accessibility" flag, which allows assistive technology to access content in an encrypted document. In mPDF's `Protection.php` this is the `'extract'` permission (value 512, line 83).

If a user calls `SetProtection([])` (no permissions) with PDFUA enabled, bit 10 would be cleared, blocking screen readers — a hard PDF/UA violation.

**Fix**: In `SetProtection()` in `Mpdf.php` (~line 23375), force-add `'extract'` to the permissions array when PDFUA is active:

```php
function SetProtection($permissions = [], $user_pass = '', $owner_pass = null, $length = 40)
{
    if ($this->PDFUA && !in_array('extract', $permissions)) {
        $permissions[] = 'extract';
    }
    $this->encrypted = $this->protection->setProtection($permissions, $user_pass, $owner_pass, $length);
}
```

This is silent (no exception or warning) — it is the correct conforming behaviour, not user error correction.

#### Constraint 2 — XMP metadata stream must NOT be encrypted (PDF spec §14.3.2)

ISO 32000-1 §14.3.2 states: "The XMP data stream shall not be encrypted." PDF/UA requires the XMP metadata (including the `pdfuaid:part=1` entry) to be readable by PDF/UA processors regardless of encryption state.

mPDF currently encrypts ALL streams uniformly via `BaseWriter::stream()` (line 62-71), including the XMP stream written in `MetadataWriter::writeMetadata()` (~line 144):

```php
$this->writer->write('<</Type/Metadata/Subtype/XML/Length ' . strlen($m) . '>>');
$this->writer->stream($m);  // ← currently encrypts XMP — must not for PDFUA
```

**Fix**: The correct mechanism per ISO 32000-1 §7.6.5 is the **Identity crypt filter** — declaring `/Filter [/Crypt] /DecodeParms << /Type /CryptFilterDecodeParms /Name /Identity >>` on the metadata stream dict tells conforming readers to pass the stream bytes through without decryption. Simply skipping RC4 without this declaration causes conforming readers to attempt to decrypt the cleartext bytes, producing garbled XML.

Two-part change:

**Part 1** — Add `/Filter` + `/DecodeParms` to the metadata stream dict in `MetadataWriter::writeMetadata()`:

```php
if ($this->mpdf->PDFUA && $this->mpdf->encrypted) {
    // Declare Identity crypt filter so readers know not to decrypt this stream
    $this->writer->write('<</Type/Metadata/Subtype/XML'
        . '/Filter[/Crypt]'
        . '/DecodeParms<</Type/CryptFilterDecodeParms/Name/Identity>>'
        . '/Length ' . strlen($m) . '>>');
} else {
    $this->writer->write('<</Type/Metadata/Subtype/XML/Length ' . strlen($m) . '>>');
}
```

**Part 2** — Add an optional `$encrypt` parameter to `BaseWriter::stream()` so the metadata stream can be written without RC4:

```php
public function stream($s, $encrypt = true)
{
    if ($this->mpdf->encrypted && $encrypt) {
        $s = $this->protection->rc4($this->protection->objectKey($this->mpdf->currentObjectNumber), $s);
    }
    $this->write('stream');
    $this->write($s);
    $this->write('endstream');
}
```

```php
$this->writer->stream($m, !($this->mpdf->PDFUA && $this->mpdf->encrypted));
```

Both parts are required. The Identity crypt filter declares the intent; skipping RC4 delivers on it. Without Part 1, readers will attempt to decrypt; without Part 2, the bytes will be RC4-encrypted despite the Identity filter declaration.

#### No other encryption interactions

- **Structure tree objects**: Struct elements, StructTreeRoot, the ParentTree NumTree are all dictionary and indirect objects — they use `$this->writer->write()`, not `$this->writer->stream()`. `write()` does not encrypt (only `stream()` does). These objects are unaffected by encryption.
- **Page content streams**: Page content streams ARE encrypted via `stream()`. The BDC/EMC operators they contain are encrypted along with the rest of the content — this is correct; PDF readers decrypt content streams before rendering.
- **mPDF encryption standard**: RC4-40 or RC4-128 only (no AES). This is a pre-existing limitation. PDF/UA does not mandate a specific encryption standard.

**Tests**:
- `testEncryptionForcesExtractPermission` — `SetProtection([], '', 'pass')` with PDFUA=true produces a `/P` value in the encryption dict that has bit 10 set
- `testXmpStreamNotEncryptedWhenPdfuaAndEncrypted` — PDFUA=true + SetProtection active → raw PDF bytes contain readable plaintext XMP namespace declarations (e.g., `pdfuaid:part`) not RC4-ciphertext

### Mixed Page Sizes and Orientation Changes

**No special handling required.** Documents with mixed paper sizes (`AddPage($orientation, ..., $newformat)`) or mid-document orientation changes affect the plan in zero ways.

Why each concern is a non-issue:

- **`/StructParents N` assignment is geometry-independent**: `PageWriter::writePages()` assigns struct parents in a simple `for ($n = 1; $n <= $nb; $n++)` loop. The counter increments once per page regardless of whether that page is A4, Letter, landscape, or portrait. Page dimensions stored in `pageDim[$n]` do not participate in the counter logic.
- **Per-page content streams are already isolated**: Each page accumulates content in its own `$this->pages[$n]` string. MCID numbering restarts per page. A size change on page 3 has no effect on MCIDs written on pages 2 or 4.
- **MCR dicts reference pages by PDF object reference, not by geometry**: The struct tree's `<</Type /MCR /Pg N 0 R /MCID n>>` dicts point to the page object number. Whether that page is landscape or A3 is irrelevant — the reference is to the object, and screen readers follow struct tree hierarchy, not page coordinates.
- **`OrientationChanges[$n]` only swaps `/MediaBox` dimensions**: `PageWriter` detects the flag and emits a swapped `MediaBox` (`[0 0 H W]` instead of `[0 0 W H]`). The struct tree `/StructParents` key is written unconditionally in the same page dict regardless of this swap.

**Implementation note**: The `/StructParents` injection in `PageWriter::writePages()` (Phase 1h) runs before the `OrientationChanges` check. No conditional needed — it fires for every page.

### `OverWrite()` — Forbidden in PDF/UA Mode

`OverWrite($file_in, $search, $replacement, ...)` (lines 27195–27352 of `Mpdf.php`) is a binary string-replacement utility that directly patches existing PDF content streams without re-rendering. It:
- Reads a pre-existing PDF from disk
- Decompresses page content streams, runs `str_replace()` on them, recompresses
- Recalculates the xref table and startxref pointer
- Never touches the struct tree, XMP metadata, or any marked-content operators

**Not worth supporting in PDF/UA mode.** Binary string replacement is fundamentally incompatible with tagged PDF:
1. Replacing text in a content stream (e.g., "Invoice #123" → "Invoice #456") leaves all struct tree entries, MCR dicts, and MCID assignments pointing at stale content — the struct tree claims the content is something it is no longer.
2. Replacement could accidentally split a `BDC … EMC` pair if the search string spans a marked-content boundary.
3. XMP metadata (`pdfuaid:part`, `dc:title`, alternate descriptions) is never updated — immediate veraPDF failure.

**Action**: Add a guard at the top of `OverWrite()`, following the same pattern as the PDF/A encryption check (line 9531):

```php
function OverWrite($file_in, $search, $replacement, $dest = Destination::DOWNLOAD, $file_out = 'mpdf')
{
    if ($this->PDFUA) {
        throw new \Mpdf\MpdfException(
            'OverWrite() is not compatible with PDF/UA-1 mode. Binary string replacement ' .
            'cannot maintain the logical structure tree required for accessibility. ' .
            'Regenerate the PDF using WriteHTML() with the updated content instead.'
        );
    }
    // ... existing implementation
```

No test needed beyond `testOverWriteThrowsInPdfuaMode` — call `OverWrite()` with `PDFUA=true` and assert `MpdfException` is thrown.

### SVG Images

**How mPDF renders SVGs**: SVG files are processed by `Svg.php` and stored as PDF Form XObjects (`/XObject /Subtype /Form`) in `$this->mpdf->formobjects[]`. The page content stream references them via `/FO%d Do`. `FormWriter.php` writes the Form XObject dict (lines 37–48): `/Type /XObject`, `/Subtype /Form`, `/Group`, `/BBox`, optional `/Filter`. The Form XObject object number is stored in `$this->mpdf->formobjects[$file]['n']` at line 35.

#### Two SVG text rendering paths (Svg.php lines 2375–2748)

- **Standard-font text** (lines 2577–2747): calls `$this->mpdf->Text(..., $return=true)` which returns the PDF operators as a string (captured in `$path_cmd`). This string accumulates in `$this->textoutput`, then is flushed to `$this->svg_string` via `svgWriteString()` at lines 4018 and 4182. These are **real `BT … Tj … ET` operators** inside the Form XObject content stream — tagged as `Span` struct elements with MCIDs.
- **SVG-font text** (lines 2386–2574, `svgText()` around line 2480): glyphs are converted to PDF path operators (`m l f`) via `svgPath()`, BUT the source Unicode string is available at `$txt = $this->txt_data[2]` (verified in the source). Each such run is wrapped with `/Span <</ActualText (<utf16be hex>)>> BDC … EMC` so assistive technology can read the original text even though the visual glyphs are paths. No loss of accessibility.

#### Internal tagging is feasible — implement it

ISO 32000-1 §14.7.4.4 allows Form XObjects to contain MCID-tagged content. The Form XObject dict carries `/StructParents N` (where N indexes an array entry in the ParentTree). Struct element MCR dicts inside Form XObject streams use `/Stm N 0 R` (the Form XObject object reference) instead of `/Pg N 0 R`.

**Critical spec constraint (ISO 32000-1 §14.7.4.2)**: "A form XObject shall be either a content item in its entirety or a container for marked-content sequences that are content items, but not both." This means:
- **Method 1** (Form XObject as single content item): Page wraps `Do` in `BDC/EMC`; Form XObject has `/StructParent` (singular). No internal MCIDs.
- **Method 2** (Form XObject as container): Page `Do` is bare (no BDC/EMC around it); Form XObject has `/StructParents` (plural). Internal MCIDs address content inside.

For SVG with internal text, Method 2 is required. The `Do` operator on the page must NOT be wrapped in a Figure BDC/EMC sequence.

**Two-level tagging structure for SVG (Method 2):**

```
Page content stream:
  q ... /FO1 Do Q             ← bare Do — NO BDC/EMC around it

Form XObject content stream (/FO1):
  /Artifact BMC               ← SVG shapes/decorative paths
    m l f ...
  EMC
  /Span <</MCID 0>> BDC       ← standard-font SVG text run 1
    BT ... Tj ... ET
  EMC
  /Span <</MCID 1 /ActualText <FEFF …utf16be…>>> BDC  ← SVG-font text run (D3)
    m l f ... (glyph paths for each character)
  EMC
  /Span <</MCID 2>> BDC       ← standard-font SVG text run 2
    BT ... Tj ... ET
  EMC
```

The Figure struct element exists in the structure tree but has NO page-level MCID. Its K array contains Span children linked via `/Stm` MCR dicts:

```
Figure struct element:
  K: [
    Span (child 1): K = [/Type /MCR /Pg pageRef /Stm svgXObjectRef /MCID 0]
    Span (child 2): K = [/Type /MCR /Pg pageRef /Stm svgXObjectRef /MCID 1]
  ]
  Alt: "description"
```

For SVG without internal text (no standard-font text runs), use Method 1: wrap `Do` in `/Figure <</MCID N>> BDC ... EMC` on the page, and the Form XObject gets `/StructParent M` (singular) instead of `/StructParents`.

#### Implementation — four changes required

**1. `Svg.php` — inject BDC/EMC into `svg_string` around standard-font text**

The seam is where `svgText()` return value is captured into `$this->textoutput` (lines 3956–3958, 4107–4109, 4153–4154, 4191–4192). Wrap `$p_cmd` before appending:

```php
// In Svg.php, before: $this->textoutput .= $p_cmd;
if ($this->mpdf->PDFUA && !$is_svg_font) {
    $mcid = $this->svgNextMcid++;
    $this->svgMcids[] = $mcid;  // track for ParentTree
    $p_cmd = '/Span <</MCID ' . $mcid . '>> BDC' . "\n" . $p_cmd . "\nEMC\n";
}
$this->textoutput .= $p_cmd;
```

For **decorative paths** (SVG shapes — rectangles, circles, freeform paths rendered via `svgPath()` outside of `svgText()`): wrap with `/Artifact BMC … EMC`.

For **SVG-font text** (inside `svgText()` around line 2480): the source Unicode is `$txt = $this->txt_data[2]`. Wrap the `$subpath_cmd` accumulator (all glyph paths for the run) with `/Span <</MCID N /ActualText (<hex>)>> BDC … EMC` and register an MCID just like the standard-font path. The hex is `FEFF` (BOM) + UTF-16BE bytes of `$txt`. Treat SVG-font runs as a second MCID-bearing path alongside standard-font runs — both produce `Span` struct elements; both contribute entries to `$this->svgMcids`.

```php
// Inside svgText(), after building $subpath_cmd for all glyphs of the run:
if ($this->mpdf->PDFUA && $is_svg_font) {
    $mcid = $this->svgNextMcid++;
    $this->svgMcids[] = $mcid;
    $hex = bin2hex("\xFE\xFF" . mb_convert_encoding($txt, 'UTF-16BE', 'UTF-8'));
    $subpath_cmd = '/Span <</MCID ' . $mcid . ' /ActualText <' . $hex . '>>> BDC' . "\n"
                 . $subpath_cmd . "\nEMC\n";
}
```

Add property `$this->svgNextMcid = 0` and `$this->svgMcids = []` to `Svg`.

**2. `Svg.php` — return MCID list from `ImageSVG()`**

Add to the return array (line 3316):
```php
return [
    'x' => ..., 'y' => ..., 'w' => ..., 'h' => ...,
    'data' => $this->svg_string,
    'svgMcids' => $this->svgMcids,  // NEW: list of MCID integers used inside
];
```

`ImageProcessor.php` stores this in `formobjects[$file]` — no change needed there since `$info` is stored wholesale.

**3. `FormWriter.php` — add `/StructParents M` to Form XObject dict**

When `PDFUA=true` and the Form XObject has internal MCIDs, assign a struct parents index and write it:

```php
// FormWriter::writeFormObjects(), after line 35 ($this->mpdf->formobjects[$file]['n'] = $this->mpdf->n):
if ($this->mpdf->PDFUA && !empty($info['svgMcids'])) {
    $structParents = $this->ua->nextStructParents();
    $this->mpdf->formobjects[$file]['structParents'] = $structParents;
    $this->writer->write('/StructParents ' . $structParents);
}
```

**4. `StructureWriter` — build ParentTree entries for SVG Form XObjects**

After writing the page ParentTree entries, also write entries for each SVG Form XObject. For each `$file` in `formobjects` that has a `structParents` key:
- Key: `$formobjects[$file]['structParents']`
- Value: array of struct element refs, one per MCID in `svgMcids`, each wrapped as a `/Stm` MCR dict

The Span struct elements themselves are children of the Figure struct element that owns the `/FO%d Do` call at the page level. `StructureTree` must allow a struct element to have both a page-level MCID (the Figure's MCID) AND child struct elements with `/Stm` MCR dicts.

**Timing**: `writeFormObjects()` is called from `ResourceWriter` before `writeCatalog()`. The Form XObject object number (`formobjects[$file]['n']`) is set during `writeFormObjects()` and is available when `StructureWriter::writeStructTree()` runs later. The `/Stm N 0 R` reference in MCR dicts uses this object number.

#### SVG-font text — accessible via ActualText

SVG-font text is rendered as PDF path operators (`m l f`) rather than text (`Tj`). The source Unicode string is captured at `$txt = $this->txt_data[2]` inside `svgText()` and emitted as an `ActualText` attribute on the wrapping `Span`. Assistive technology reads the original text; no loss of accessibility. SVG-font runs are tagged with MCIDs and flow through the same ParentTree / `/Stm` MCR machinery as standard-font SVG text.

#### Alt text

`Tag/Img.php` stores `$objattr['alt']` from `$attr['ALT']` for both raster and SVG `<img>` tags (Phase 3c fix). For `Image()` direct calls, the `$alt` parameter covers SVG files. The page-level Figure struct element always carries `/Alt` regardless of whether the SVG has internal text tagging.

**Tests**:
- `testSvgWithStandardFontTextTaggedInternally` — SVG with standard-font `<text>` produces `/Span <</MCID 0>> BDC … BT … ET … EMC` inside the Form XObject stream; Form XObject dict contains `/StructParents`; ParentTree contains the Span struct element ref under that index
- `testSvgImageTaggedAsFigureAtPageLevel` — page stream contains `/Figure <</MCID N>> BDC … /FO1 Do … EMC`; Figure struct element in struct tree; BDC/EMC balanced
- `testSvgFontTextHasActualText` — SVG using SVG-font produces `/Span <</MCID N /ActualText <FEFF…>>> BDC … EMC` around the glyph path operators inside the Form XObject stream. The hex-decoded UTF-16BE matches the source text. Each run has a unique MCID; the `Span` struct elements appear in the ParentTree under the Form XObject's `/StructParents` index.

### `PDFUAauto` — Automatic Configuration Adjustment

When `PDFUA=true`, many settings must be forced regardless of what the user configured. Rather than throwing exceptions for every misconfiguration, a companion `PDFUAauto=true` flag (analogous to the existing `PDFAauto`) switches violations from exceptions to warnings + auto-correction.

**`PDFUAauto=false` (default, strict mode)**: violations throw `MpdfException` early.
**`PDFUAauto=true` (permissive mode)**: violations add to `$this->ua->getWarnings()[]` and are auto-corrected where possible.

#### Complete auto-adjustment specification

The following are applied unconditionally (both modes) when `PDFUA=true`:

| Setting | Forced value | Location |
|---|---|---|
| `pdf_version` | `'1.7'` minimum (upgrade if lower) | `__construct()` after config merge |
| `displayDocTitle` | `true` | `MetadataWriter::writeMetadata()` Phase 1f (already in plan) |
| `MarkInfo/Marked` | `true` | `MetadataWriter::writeCatalog()` Phase 1e (already in plan) |
| `MarkInfo/Suspects` | `false` | Phase 1e (already in plan) |
| `/StructParents N` on all pages | sequential 0-based | `PageWriter::writePages()` Phase 1h (already in plan) |
| `/Tabs /S` on **all** pages | present on every page dict | Phase 1h — fix: move out of annotated-pages block (see Matterhorn M-3 below) |
| `/Lang` in catalog | already written from `currentLang`/`default_lang` | Phase 1e — validate non-empty, throw/warn if absent |
| `'extract'` permission bit | forced in `SetProtection()` | Phase 3 (already in plan) |
| XMP stream not encrypted | pass `$encrypt=false` to `stream()` | Phase 3 (already in plan) |

The following are mode-dependent:

| Setting | `PDFUAauto=false` behaviour | `PDFUAauto=true` behaviour |
|---|---|---|
| Empty `title` config | Throw `MpdfException` | Warn + use `'Untitled Document'` as placeholder |
| Core fonts (`mode='c'`) | Throw `MpdfException` | Warn + substitute DejaVu Sans/Serif/Mono |
| Missing `currentLang`/`default_lang` | Warn only (can't know at construct time) | Warn (no default injection — the existing /Lang write is already absent if both are empty) |
| Image with no `alt` | Throw `MpdfException` (Phase 5) | Warn + emit Artifact BMC |
| JavaScript embedded | Warn only | Warn only |
| Embedded PDF attachment | Warn only | Warn only |

#### PDF version forcing (critical — Matterhorn 01-001)

veraPDF performs a hard check that PDF/UA-1 documents are PDF 1.7 or higher. mPDF's default is `'1.4'`. Add to the constructor (or at the start of Phase 1 config processing):

```php
if ($this->PDFUA && version_compare($this->pdf_version, '1.7', '<')) {
    $this->pdf_version = '1.7';
}
```

This mirrors the existing version-forcing done for PDF/X (`1.3`) and layers (`1.5`).

#### Document language (critical — Matterhorn 23-001)

ISO 14289-1 §7.2 requires `/Lang` in the document catalog. **`/Lang` is already written unconditionally** by `MetadataWriter::writeCatalog()` at lines 333–337, using `$this->mpdf->currentLang` (falling back to `$this->mpdf->default_lang`). No new write is needed.

The PDFUA-specific requirement is only to **validate** that a non-empty language is set. Add a validation check (not a write) at the start of PDFUA output processing:

```php
// In MetadataWriter or at the Phase 5 validation hook:
if ($this->mpdf->PDFUA) {
    $lang = $this->mpdf->currentLang;
    if (!is_string($lang) || $lang === '') {
        $lang = $this->mpdf->default_lang;
    }
    if (!is_string($lang) || $lang === '') {
        if ($this->mpdf->PDFUAauto) {
            $this->ua->addWarning('PDF/UA-1 requires a document language. Set <html lang="xx"> or the currentLang property. The /Lang entry will be absent from the catalog.');
        } else {
            throw new \Mpdf\MpdfException(
                'PDF/UA-1 requires a document language. Set <html lang="xx"> or the currentLang property.'
            );
        }
    }
}
```

**No new `/Lang` write in `writeCatalog()` — a second `/Lang` key in a PDF dict is ill-formed per ISO 32000-1 §7.3.7.**

**Test**: `testCatalogContainsLangWhenPdfua` in `MetadataTest.php` — assert the existing `/Lang` entry is present and non-empty when `PDFUA=true` and `currentLang` is set.

### PDF Bookmarks (Document Outline)

PDF bookmarks (the outline/navigation panel) are written by `BookmarkWriter::writeBookmarks()` and are **independent of the structure tree**. They are navigation objects with `/Title` and `/Dest` keys, not content on the page — no `/StructParent` or MCID association is needed.

PDF/UA-1 Matterhorn Protocol §13-001 recommends that documents with headings include a document outline. mPDF's existing behavior (automatically creating bookmark entries from heading tags when `$mpdf->h2bookmarks` is configured, or via `<bookmark>` HTML tags) satisfies this recommendation without any changes.

**No code changes needed** in `BookmarkWriter` for PDF/UA. The H1-H6 struct elements (from Phase 4 `BlockTag.php` changes) cover the accessibility requirement; the bookmarks provide supplementary navigation.

Note: `BookmarkWriter` writes `/Type /BMoutlines` (line 133) which should be `/Type /Outlines` per the PDF spec — this is a pre-existing issue unrelated to PDF/UA.

### Fixed-Position Blocks (`position: fixed` / `position: absolute`)

**How mPDF renders them**: Detection in `BlockTag::open()` (~line 86-97 of `BlockTag.php`) sets `$this->mpdf->inFixedPosBlock = true` and buffers all subsequent HTML into `$this->mpdf->fixedPosBlockSave[]`. The buffer is flushed at the end of `WriteHTML()` (~line 13935) via `WriteFixedPosHTML()`, which renders the content into `$this->pages[$this->page]` **after all normal flow content** on that page.

**Reading order problem**: Because fixed-position content is appended after normal flow in the content stream, its stream position is out of logical reading order. Tagging it as a normal struct element would place its MCID after the main body MCIDs, breaking the reading order implied by the structure tree.

**PDF/UA tagging approach**:
- **Default: Artifact**. Fixed-position blocks are page overlays; treat them as Artifacts unless they explicitly declare real content via ARIA.
- Inside `WriteFixedPosHTML()`, wrap the entire output with `/Artifact BMC … EMC` (no property dict — they're not Pagination sub-type).
- If the element has `role="region"` or `aria-label` (indicating it's a labelled content region), treat it as a `Sect` or `Div` struct element instead, placed at the correct logical position in the structure tree. The MCID is emitted after the main flow MCIDs, but the struct element can reference its page via an MCR dict — PDF/UA does not require stream order to match structure tree order (ISO 32000-1 §14.7.4).
- **Implementation**: Add a PDFUA check at the top of `WriteFixedPosHTML()`:
  ```php
  if ($this->PDFUA) {
      $this->writer->write('/Artifact BMC');
  }
  // ... existing rendering ...
  if ($this->PDFUA) {
      $this->writer->write('EMC');
  }
  ```

### Floated Blocks (`float: left` / `float: right`)

**How mPDF renders them**: Float metadata is stored in `$this->floatDivs[]` and surrounding text margins are adjusted via `GetFloatDivInfo()` (~line 15360). The floated block's content is rendered **inline before** the text that wraps around it in the content stream (stream order: float content → wrapping text). Image floats go via `$this->floatbuffer[]` / `printfloatbuffer()` (~line 25378).

**Reading order problem**: For a typical left-floated figure, stream order (figure → text) often matches reading order. But for right-floated blocks or blocks that appear mid-paragraph, the stream order diverges from logical reading order.

**PDF/UA tagging approach**:
- **Image floats** (`<img style="float:left|right">` / `<figure>` with float): Tag normally as a Figure struct element. The struct element's position in the tree should reflect where the image logically belongs (typically adjacent to its surrounding paragraph). Since image floats appear before the wrapping text in the stream, and readers naturally look at figures before reading their captions, this ordering is usually correct.
- **Block floats** (`<div style="float:right">` containing text/markup): These are structurally ambiguous — the content appears before surrounding text in the stream, but semantically may be a sidebar that reads after the main paragraph. **Default to Artifact** for block floats unless the element has a role (e.g., `role="complementary"` → `Sect` or `role="figure"` → `Figure`).
- **Implementation**: In `BlockTag::open()`, when a float is detected, check whether it's a meaningful content element:
  ```php
  if ($this->mpdf->PDFUA && isset($p['FLOAT']) && $p['FLOAT']) {
      $isContent = isset($attr['ROLE']) || isset($attr['ARIA-LABEL']);
      if (!$isContent) {
          $this->ua->getStructureTree()->openArtifact();
          $this->mpdf->blk[$blklvl]['pdfua_artifact'] = true;
      }
  }
  ```
  In `BlockTag::close()`, call `structureTree->closeArtifact()` if `pdfua_artifact` was set.

### Multi-Column Layout (`SetColumns()` / CSS `column-count`)

**How mPDF routes content during column mode**: `BaseWriter::endPage()` (line 187) redirects ALL page content to `$this->columnbuffer[]` when `$this->ColActive === 1` and not in a header/footer. Each `Cell()` call produces one `columnbuffer` entry with `['s' => $content_string, 'col' => $column_index, 'x' => ..., 'y' => ..., 'h' => ...]`. When columns end, `printcolumnbuffer()` optionally re-orders and repositions entries (for column balancing), then flushes them to `$this->pages[$this->page]`.

**BDC/EMC and column reordering**: `printcolumnbuffer()` sorts position-sensitive entries by `rel_y` across all columns for balancing. This interleaves column 1 and column 2 content in the final stream. Directly emitting BDC/EMC during column collection via `markedContentHelper->begin()`/`->end()` would place them in `columnbuffer[]`, but reordering can split BDC/EMC pairs apart, producing unbalanced markers.

**Solution — Sentinel entries**: During column collection (`ColActive === 1`), tag handlers do NOT call `markedContentHelper->begin()` or `->end()`. Instead they add sentinel entries to `columnbuffer[]`:

```php
// On tag open (inside ColActive=1):
$this->mpdf->columnbuffer[] = [
    's'          => '__PDFUA_BDC__',   // sentinel — not PDF operators
    'pdfua_type' => $structType,
    'pdfua_mcid' => $mcid,             // from structureTree->addContent()
    'col'        => $this->mpdf->CurrCol,
    'x'          => $this->mpdf->x,
    'y'          => $this->mpdf->y,
    'h'          => 0,
    'rel_y'      => null,              // sentinels are not position-sensitive
];

// On tag close (inside ColActive=1):
$this->mpdf->columnbuffer[] = [
    's'     => '__PDFUA_EMC__',
    'col'   => $this->mpdf->CurrCol,
    'x'     => $this->mpdf->x,
    'y'     => $this->mpdf->y,
    'h'     => 0,
    'rel_y' => null,
];
```

The `rel_y` assignment loop in `printcolumnbuffer()` (lines 24495–24511) skips entries whose `'s'` does not match a position-sensitive pattern — sentinels already have `null` for `rel_y`. They are preserved in their original order relative to adjacent content entries and are NOT reordered by the column balancing loop (which orders by `rel_y`, and `null < any int` so sentinels sort before their content — which is correct: BDC precedes its content).

In the final output loop of `printcolumnbuffer()` (lines ~24730 and ~24901), expand sentinels:

```php
if ($s['s'] === '__PDFUA_BDC__' && $this->PDFUA) {
    $out = '/' . $s['pdfua_type'] . ' <</MCID ' . $s['pdfua_mcid'] . '>> BDC' . "\n";
} elseif ($s['s'] === '__PDFUA_EMC__' && $this->PDFUA) {
    $out = 'EMC' . "\n";
} else {
    $out = $columnAdjustedString . "\n";
}
$this->pages[$this->page] .= $out;
```

**StructureTree during column collection:** `structureTree->open()`, `addContent()`, and `close()` are called normally during collection. The struct tree is populated correctly. Only BDC/EMC emission is deferred via sentinels.

**Impact on `markedContentHelper->getDepth()`:** BDC/EMC emitted in `printcolumnbuffer()` bypass `markedContentHelper->begin()` and `->end()`, so the depth counter is not affected by column-mode emissions. The balance assertion at `_enddoc()` remains valid because all column buffers are flushed (via `SetColumns(0)` or page-end handling) before `_enddoc()` runs — as long as every column sentinel BDC has a matching EMC sentinel.

**`columnAdjustPregReplace()` safety:** The regex patterns match position-sensitive PDF operators only. Sentinel strings (`__PDFUA_BDC__`, `__PDFUA_EMC__`) will never match those patterns — safe even if a sentinel accidentally passes through.

**struct elements for column headings**: When `<h1>` etc. appears at the start of a column section *before* `SetColumns()` activates (common pattern: heading → column content), the heading is NOT in column mode and tags normally. Only content *inside* the `SetColumns()` block is affected.

**Column integration test**: `testColumnsLayout` in `IntegrationTest.php` should assert:
1. No PHP errors
2. BDC+BMC count equals EMC count (critical — Artifact BMC/EMC must be balanced even in column mode)
3. Column content does NOT produce struct elements (no `/S /P` etc. inside the column region)
4. Pre-column headings DO produce struct elements
5. `/MarkInfo` and `/StructTreeRoot` present

### Index Feature (`<indexentry>` / `<indexinsert>`)

**How it works**: `<indexentry content="term">` is an invisible inline marker — it records the current page number into `$this->Reference[]` but produces **zero content stream output** (no `Cell()` calls, no drawing). When `<indexinsert>` is encountered, `InsertIndex()` generates an HTML string (divs with class `mpdf_index_main`, `mpdf_index_letter`, `mpdf_index_entry`) and feeds it through `WriteHTML()`.

**PDF/UA impact — `<indexentry>`**: No BDC/EMC operators or struct elements are emitted because no rendering happens. The tag handler creates an `OBJECT_IDENTIFIER` marker in the text buffer; when processed later, it only updates `$this->Reference[]`. **No special handling needed.**

**PDF/UA impact — `<indexinsert>` rendered content**: The generated index HTML flows through the normal `WriteHTML()` pipeline, so Phase 4 block element tagging applies automatically:
- `mpdf_index_main` / `mpdf_index_entry` divs → tagged as Div/P struct elements
- Letter dividers (e.g., "A", "B") → tagged as Div struct elements (not H, since they're purely presentational groupings)
- Page reference numbers embedded in entry text (e.g., "term.....5") → part of the P text content (NOT pagination Artifacts — they are printable text content, not PDF page numbering operators)
- Hyperlinks (`links="on"`) → handled by existing `A.php` Link struct elements (Phase 4)

**Column interaction**: `<indexinsert>` placed inside a `SetColumns()` block (common for book indexes) tags correctly. The column sentinel strategy (§"Multi-Column Layout") preserves struct element ordering through the column-balancing reorder, so index entries inside columns produce `Div`/`P`/`Link` struct elements — not Artifacts. Accessible book-index-in-columns is supported.

**No code changes needed** for the Index feature beyond what Phase 4 already provides. Add a smoke test in `IntegrationTest.php` (see Phase 4 Tests) verifying the index renders without PHP errors and BDC/EMC is balanced.

### Table of Contents (`<tocentry>` / `<toc>` / `<tocpagebreak>`)

**How it works**: `<tocentry content="..." level="0">` is an invisible inline marker like `<indexentry>` — it stores `['t', 'l', 'p', 'link', 'toc_id']` entries in `$this->tableOfContents->_toc[]` but produces **zero-width, zero-height** content stream output (`w = h = 0.00001`). The ToC page is rendered by `insertTOC()` (`src/TableOfContents.php:300`), which generates HTML and feeds it through `WriteHTML()`.

**PDF/UA impact — `<tocentry>`**: No BDC/EMC or struct element. Identical to `<indexentry>`. **No special handling needed.**

**PDF/UA impact — rendered ToC pages**: `insertTOC()` generates HTML like:

```html
<div class="mpdf_toc">
  <div class="mpdf_toc_level_0">
    <a class="mpdf_toc_a" href="…">
      <span class="mpdf_toc_t_level_0">Chapter Title</span>
    </a>
    <dottab/>
    <a class="mpdf_toc_a" href="…">
      <span class="mpdf_toc_p_level_0">5</span>
    </a>
  </div>
</div>
```

**No change to `TableOfContents.php` is needed.** The tag handlers already receive the CSS classes. The `DIV` handler (via `BlockTag::open()`) checks `$attr['CLASS']` through `StructType::fromCssClass()` (see §2c above). The resulting logical structure is:

```
TOC (div.mpdf_toc)
  TOCI (div.mpdf_toc_level_N)
    Link (first a.mpdf_toc_a — links to chapter destination)
      Span (span.mpdf_toc_t_level_N — chapter title text)
    Link (second a.mpdf_toc_a — also links to chapter destination)
      Lbl (span.mpdf_toc_p_level_N — page number text)
```

**CSS class case**: mPDF's CSS pipeline stores class values lowercase — verify the actual value of `$attr['CLASS']` before confirming case. The `$tocClassMap` keys in `StructType` must match exactly.

**`mpdf_toc_a` maps to `Link` (NOT `Reference`)**: `<a class="mpdf_toc_a">` creates a real PDF Link annotation, so it must map to `Link`. `Reference` is for inline text cross-references without annotations. ISO 32000-1 §14.8.4.4.2 Table 338: `Link` requires an OBJR kid pointing at the annotation object.

**OBJR requirement**: Each `Link` struct element from a `mpdf_toc_a` anchor must include an Object Reference (OBJR) kid in the PDF struct dict pointing to the corresponding Link annotation. The `A` close handler calls `structureTree->addObjref()` after the annotation object is written. This is how veraPDF validates that annotations are reachable from the structure tree (Matterhorn 02-003).

**`TOCI` child structure**: `TOCI` is a grouping element so it holds no MCID directly. mPDF emits two `<a class="mpdf_toc_a">` elements per TOCI — one wrapping the title, one wrapping the page number. Both map to `Link`.

**`<dottab>` dot leaders**: Emits visible `Cell()` calls producing dot characters. These are included in the enclosing Link's struct element content — valid and harmless for Phase 4.

**Page numbers in ToC entries** (e.g., `<span class="mpdf_toc_p_level_0">5</span>`): Resolves via `StructType::fromCssClass()` to `Lbl`. These are content text labels, NOT pagination Artifacts.

**`MovePages` and MCR dict validity**: `insertTOC()` calls `MovePages()` to insert ToC pages before the main content. `MovePages()` reorders the logical page list but does NOT renumber PDF objects — page object numbers (`/Pg N 0 R` in MCR dicts) remain valid after reordering. Struct tree references are unaffected.

**Title text span** (`mpdf_toc_t_level_*`): Not in `$tocClassMap` — resolves to `null` from `fromCssClass()` and falls back to `Span` from `fromHtmlTag()`. Correct: the title text lives inside a `Link` as a `Span` child.

### Imported PDFs via FPDI (`setSourceFile` / `importPage` / `useTemplate`)

**FPDI is disabled by default** (`'enableImports' => false` in `ConfigVariables.php` line 110). Only affects users who explicitly enable it.

**How imports work**: `importPage()` copies the source page as a PDF Form XObject. `useTemplate()` emits:
```pdf
q ... cm /TPL0 Do Q
```
into the page content stream. The Form XObject contains the original page's content stream verbatim (including any BDC/EMC operators already present if the source was tagged). FPDI writes the `Do` operator via `vendor/setasign/fpdi/src/FpdiTrait.php:449`.

**Two-tier treatment based on source PDF tagging**:

**Tier 1 — Source PDF is untagged** (no `StructTreeRoot` in its catalog): Wrap the `Do` call as Artifact in `src/FpdiTrait.php::useTemplate()`:
```php
if ($this->PDFUA && !$sourceIsTagged) {
    $this->writer->write('/Artifact <</Type /Layout>> BDC');
    $this->ua->addWarning('Imported PDF page (untagged source) marked as Artifact.');
}
$this->fpdiUseImportedPage($tpl, $x, $y, $width, $height, $adjustPageSize);
if ($this->PDFUA && !$sourceIsTagged) {
    $this->writer->write('EMC');
}
```
Use `$this->writer->write()` (routes through `BaseWriter::endPage()` → `columnbuffer` in column mode).

**Tier 2 — Source PDF is tagged** (source catalog has `/StructTreeRoot`): Merge the source's struct subtree for the imported page into the host document's `StructTreeRoot`. Implemented by `\Mpdf\Ua\Import\FpdiStructMerger`:

**Class `src/Ua/Import/FpdiStructMerger.php`** (new, Phase 4):

```php
namespace Mpdf\Ua\Import;

use Mpdf\Mpdf;
use Mpdf\Ua\StructureTree;
use Mpdf\Ua\StructureElement;

class FpdiStructMerger
{
    private $mpdf;
    private $ua;

    public function __construct(Mpdf $mpdf, UaState $ua)
    {
        $this->mpdf = $mpdf;
        $this->ua   = $ua;
    }

    private function tree()
    {
        return $this->ua->getStructureTree();
    }

    /**
     * Inspect the source PDF catalog — return true if /StructTreeRoot is present.
     */
    public function sourceIsTagged($readerId) { /* read getCatalog()->get('StructTreeRoot') */ }

    /**
     * Merge the struct subtree for one imported page into the host struct tree.
     * Called from FpdiTrait::useTemplate() when sourceIsTagged() returned true.
     *
     * @param string $pageId           FPDI page identifier
     * @param int    $foXObjectObjNum  PDF object number of the host's Form XObject for this imported page
     * @param int    $hostPageObjNum   PDF object number of the host page on which the Do is emitted
     */
    public function mergePageStructSubtree($pageId, $foXObjectObjNum, $hostPageObjNum)
    {
        // 1. Read source catalog → /StructTreeRoot → walk K array, find struct elements
        //    whose /Pg references the source page being imported.
        // 2. Queue every struct object (and its attribute-object indirect refs) onto
        //    $this->mpdf->objectsToCopy[$readerId] — FPDI's writePdfType() rewriter
        //    (src/FpdiTrait.php:344–395) will handle object-number remapping.
        // 3. Translate every MCR dict inside those subtrees:
        //      - Original /Pg refs rewritten to $hostPageObjNum.
        //      - /Stm entry added (or retained) pointing at $foXObjectObjNum.
        //      - /MCID values left unchanged (they address content already inside the
        //        Form XObject stream, which is copied verbatim).
        // 4. For /OBJR kids referencing source annotations: translate /Obj to the
        //    host annotation that was created during importPage() (FPDI already
        //    copies source annotations when groupXObject=true). Drop OBJR kids whose
        //    source annotation was not imported.
        // 5. Merge source /RoleMap entries into the host tree via
        //    $this->tree->addRoleMapping($role, $standardType) — first-wins semantics.
        // 6. Attach the root of the merged subtree as a child of the currently open
        //    host struct element (typically the outer host page's struct stack top).
        //    The attachment is structural only — physical PDF object creation happens
        //    when FPDI processes objectsToCopy during writeImportedPagesAndResolvedObjects().
    }

    /**
     * Called once per useTemplate() call even when the same FPDI pageId is reused
     * across N host pages (SetPageTemplate reuse). Adds new MCR kids differing only
     * by /Pg; the struct subtree is NOT physically duplicated.
     */
    public function addPerPageMcrKids($pageId, $hostPageObjNum, $foXObjectObjNum) { /* ... */ }
}
```

**Hook in `src/FpdiTrait.php::useTemplate()`**:

```php
$sourceIsTagged = $this->ua->getFpdiStructMerger()->sourceIsTagged($this->currentReaderId);

if ($this->PDFUA && $sourceIsTagged) {
    $structParents = $this->ua->nextStructParents();
    // Wrap the Do as a content container — Method 2 from SVG handling. The Do stays
    // bare (no BDC/EMC wrap on the page); the Form XObject carries /StructParents.
    $this->ua->getFpdiStructMerger()->mergePageStructSubtree(
        $tpl,
        $this->importedPages[$tpl]['objectNumber'],
        $this->getCurrentPageObjNum()
    );
}

$newSize = $this->fpdiUseImportedPage($tpl, $x, $y, $width, $height, $adjustPageSize);

if ($this->PDFUA && !$sourceIsTagged) {
    // Legacy Artifact wrap for genuinely untagged sources only
    $this->writer->write('/Artifact <</Type /Layout>> BDC');
    $this->ua->addWarning('Imported PDF page (untagged source) marked as Artifact.');
    $this->writer->write('EMC');
}
```

**`SetPageTemplate()` handling**: When the same FPDI pageId is reused via `SetPageTemplate()` on N host pages, the merger emits **one** struct subtree physically (queued once through `objectsToCopy`) with N MCR kids differing only by `/Pg`. ISO 32000-1 §14.7.4.4 Table 324 explicitly permits `/Pg` and `/Stm` to coexist in the same MCR dict — this is exactly the intended construct. No struct-element duplication.

**`FormWriter.php` changes for Tier 2**: when `$sourceIsTagged` and the Form XObject was produced by FPDI import, emit `/StructParents` on the Form XObject dict using the value assigned by `nextStructParents()` in `useTemplate()`. Existing SVG `/StructParents` emission (see §"SVG Images") is the same pattern; factor a helper on `FormWriter` so both SVG and FPDI reuse it.

**RoleMap conflicts**: source may define `<custom-role, SomeStandardType>` that conflicts with the host's existing mapping for `custom-role`. `StructureTree::addRoleMapping()` has first-wins semantics — the host's mapping wins, and the source's is silently dropped. Document this behaviour in the `FpdiStructMerger` class docblock.

**Indirect-ref rewriter reuse**: `src/FpdiTrait.php:344–395` already walks every `PdfIndirectObjectReference` during `writePdfType()` and translates it into the host object-number namespace via `objectsToCopy`. Struct objects are just plain PDF dicts — they pass through the same rewriter with no special casing. Attribute objects (`/A`) and `/K` arrays likewise flow through unchanged.

**CHANGELOG entry for FPDI support**: announce "Tagged-source PDFs imported via `useTemplate()` / `importPage()` now have their struct subtree merged into the host document's `StructTreeRoot`, producing a single accessible PDF." No limitation caveat.

**`SetPageTemplate()` background templates**: Applied on every page via `_beginPage()` line 3218. Always Artifact-wrap the `Do` call in Phase 4. In practice, page templates (letterheads, backgrounds) are decorative — Artifact wrapping is correct.

### Phase 4 Tests

**`tests/Mpdf/Ua/ContentStreamTest.php`** (extend with structure-type assertions, now that Phase 4 wires them):

| Test method | Assertion |
|---|---|
| `testParagraphProducesPBdc` | Page stream contains `/P <</MCID` and `BDC` |
| `testImageWithAltTextProducesFigureBdc` | Image with `alt="foo"` produces `/Figure <</MCID` BDC in page stream |
| `testAriaHiddenProducesArtifactBmc` | Element with `aria-hidden="true"` produces `/Artifact BMC`, no struct element in tree |
| `testBdcEmcBalanceWithOcgLayers` | `$mpdf->BeginLayer(1)` wrapping a `<p>` paragraph → BDC+BMC count equals EMC count across the full page stream |

**`tests/Mpdf/Ua/StructureElementsTest.php`** (new):

| Test method | Assertion |
|---|---|
| `testH1ProducesH1StructElement` | Output contains `/S /H1` PDF struct element |
| `testParagraphProducesPStructElement` | Output contains `/S /P` struct element |
| `testUnorderedListProducesLStructElement` | `<ul><li>` produces `/S /L` and `/S /LI` struct elements |
| `testOrderedListHasListNumberingAttribute` | `<ol>` struct element has `ListNumbering` attribute `Decimal` |
| `testTableProducesTableStructElement` | `<table>` produces `/S /Table` |
| `testTdProducesTdStructElement` | `<td>` produces `/S /TD` |
| `testThProducesThWithScope` | `<th>` produces `/S /TH` with `Scope` attribute |
| `testColspanAttributeOnTd` | `<td colspan="2">` struct element has `ColSpan` attribute |
| `testNestedListProducesNestedLStruct` | `<ul><li>A<ul><li>B</li></ul></li></ul>` produces outer `L → LI → LBody → L(inner) → LI → LBody` |
| `testNestedTableProducesInnerTableUnderTd` | Nested `<table>` inside `<td>` produces a Table struct element as child of the outer TD |
| `testTableCellBdcCoversFullCellContent` | A `<td>` with `<p>Hello</p>` produces a single `/TD <</MCID N>> BDC … EMC` wrapping the entire cell, and the P struct element exists as a child of TD in the struct tree |
| `testLinkProducesLinkStructElement` | `<a href>` produces `/S /Link` struct element |
| `testLinkAnnotationHasContentsKey` | Link annotation dict contains `/Contents` key (from captured link text) |
| `testStickyNoteAnnotationHasContentsKey` | `<annotation content="See note">` produces annotation object with `/Contents` |
| `testStickyNoteAnnotationHasStructParent` | Annotation object contains `/StructParent N` integer |
| `testStickyNoteAnnotationProducesNoteStructElement` | Output contains `/S /Note` struct element with `/K` containing OBJR reference to annotation |
| `testAnnotationFlagsIncludePrintBit` | Annotation object contains `/F 28` when PDFUA=true |
| `testFormTextFieldHasTuKey` | `<input type="text" title="First name">` Widget annotation contains `/TU (First name)` |
| `testFormTextFieldHasStructParent` | Widget annotation contains `/StructParent N` integer |
| `testFormTextFieldHasFormStructElement` | Output contains `/S /Form` struct element with OBJR reference to the Widget annotation |
| `testFormCheckboxHasStructParent` | Checkbox Widget has `/StructParent N` and corresponding `/S /Form` struct element |
| `testFormSelectHasStructParent` | Select (listbox) Widget has `/StructParent N` and `/S /Form` struct element |
| `testRadioButtonEachKidHasStructParent` | For `<input type="radio">` group, each individual radio Widget has its own `/StructParent N` |
| `testAriaLabelUsedAsFigureAlt` | Image with no `alt` but `aria-label="foo"` produces Figure with `Alt=foo` |
| `testRoleHeadingOverridesTag` | `<div role="heading" aria-level="2">` produces `/S /H2` struct element |
| `testRolePresentationProducesArtifact` | `<div role="presentation">` content is marked as Artifact |
| `testRoleListProducesLStruct` | `<div role="list">` produces `/S /L` struct element |
| `testAriaColspanOnTd` | `<td aria-colspan="3">` (no HTML colspan) produces struct element with `ColSpan=3` |
| `testAriaRowspanOnTh` | `<th aria-rowspan="2">` (no HTML rowspan) produces struct element with `RowSpan=2` |
| `testLangAttributeProducesLangOnStructElement` | `<p lang="fr">` produces a struct element with `/Lang (fr)` |
| `testAbbrTitleProducesExpansionAttribute` | `<abbr title="HyperText Markup Language">HTML</abbr>` produces Span struct element with `/E` expansion text |
| `testRoleNoteProducesNoteStructElement` | `<div role="note">` produces `/S /Note` with a unique `/ID` string |
| `testRoleArticleProducesArtStructElement` | `<article>` or `<div role="article">` produces `/S /Art` struct element |
| `testFixedPositionBlockDefaultsToArtifact` | `<div style="position:fixed; top:10mm; left:10mm">` produces `/Artifact BMC` in the page stream, not a struct element |
| `testFixedPositionBlockWithRoleIsTagged` | `<div style="position:absolute" role="region" aria-label="Sidebar">` produces a `Sect` struct element |
| `testFloatBlockDefaultsToArtifact` | `<div style="float:right">Sidebar</div>` produces `/Artifact BMC` in the page stream |
| `testFloatImageIsTaggedAsFigure` | `<img style="float:left" alt="Tiger">` produces a `Figure` struct element, not an Artifact |
| `testTextCircleProducesSpanStructElement` | `<textcircle top-text="Hello" bottom-text="World" r="20mm">` produces `/S /Span` struct element with `ActualText` containing the full text; BDC/EMC wraps the Cell() calls |
| `testBarcodeProducesFigureStructElement` | `<barcode code="9780954224608" type="EAN13">` produces `/S /Figure` struct element; Alt contains the code; BDC/EMC wraps bars + text rendering |
| `testQrCodeProducesFigureStructElement` | `<barcode code="https://example.com" type="QR">` produces `/S /Figure` with Alt "QR code: https://example.com" |
| `testWatermarkTextIsArtifact` | Page with `<watermarktext content="DRAFT">` produces `/Artifact <</Type /Background>> BDC … EMC` in page stream; BDC/EMC balanced |
| `testWatermarkImageIsArtifact` | Page with `<watermarkimage src="...">` produces Artifact BDC/EMC around the `Do` operator; BDC/EMC balanced |
| `testAutosizeTextProducesSpanStructElement` | `$mpdf->AutosizeText('Hello', 50, 'DejaVuSans', '')` → `/S /Span` struct element; page stream has `/Span <</MCID N>> BDC … BT … ET … EMC`; BDC/EMC balanced |
| `testImageMethodWithAlt` | `$mpdf->Image('img.jpg', 10, 10, 50, 50, '', '', true, true, false, true, true, 'Logo')` → `/S /Figure` struct element; page stream contains `/Figure <</MCID N>> BDC … Do … EMC`; BDC/EMC balanced |
| `testImageMethodWithEmptyAlt` | `$mpdf->Image(..., $alt='')` → `/Artifact BMC … Do … Q … EMC`; no struct element created |
| `testImageMethodWithoutAlt` | `$mpdf->Image(...)` (no `$alt`) → entry added to `$mpdf->ua->getWarnings()`; `/Artifact BMC` emitted |
| `testLayerContentIsTagged` | `$mpdf->BeginLayer(1)` around a paragraph then `$mpdf->EndLayer()` → `/P` struct element with MCID; page stream has `/OC /ZI1 BDC … /P <</MCID N>> BDC … EMC … EMC`; BDC/EMC balanced |
| `testSetVisibilityContentIsTagged` | `$mpdf->SetVisibility('print')` around a paragraph → `/P` struct element with MCID; stream has `/OC /OC1 BDC … /P <</MCID N>> BDC … EMC … EMC`; BDC/EMC balanced |
| `testEncryptionForcesExtractPermission` | `SetProtection([], '', 'pass')` with PDFUA=true → `/P` value in encryption dict has bit 10 set |
| `testXmpStreamNotEncryptedWhenPdfuaAndEncrypted` | PDFUA=true + SetProtection active → raw PDF output contains plaintext XMP namespace declarations (`pdfuaid:part`), not ciphertext |
| `testFloatingExample10` | Rendering `example10_floating_and_fixed_position_elements.php` HTML with PDFUA=true produces balanced BDC/EMC and no PHP errors |
| `testImageMethodSuppressesBdcEmcWhenInArtifactScope` | Direct `Image()` call inside `openArtifact()` / `closeArtifact()` scope produces no `/Figure` struct element and no MCID BDC in the stream |
| `testImageMethodSuppressesBdcEmcWhenWatermark` | `Image()` called with `$watermark=true` produces `/Artifact <</Type /Background>> BDC … EMC` not `/Figure` BDC; no Figure struct element created |
| `testAutosizeTextSuppressesWhenInArtifactScope` | `AutosizeText()` inside `openArtifact()` scope produces no `/Span` struct element and no MCID BDC |
| `testLinkAnnotationHasStructParent` | `<a href>` annotation object contains `/StructParent N` integer (distinct from the `/S /Link` struct element test — this verifies the annotation dict itself) |
| `testTdHeadersAttributeMapsToStructElement` | `<td headers="col1">` produces TD struct element with an attribute dict referencing the `col1` TH element by its struct element ID |
| `testFigureStructElementHasBBox` | `<img alt="foo">` with known dimensions produces a `Figure` struct element that carries a `/BBox` attribute dict |
| `testLiStructureHasLblAndLBody` | `<li>Item</li>` inside `<ul>` produces LI → Lbl (bullet) + LBody (content) child struct elements |
| `testParagraphSplitAcrossPageBreakMcrDicts` | A `<p>` that flows across a page break produces one `/S /P` struct element whose `/K` array contains two MCR dicts — one per page — each with `/Type /MCR /Pg N 0 R /MCID n` |
| `testTableRowAcrossPageBreakMcrDicts` | A `<tr>` that spans a page break produces one TR struct element with MCR dicts for each page in its `/K` array |
| `testBlockquoteProducesBlockQuoteStructType` | `<blockquote>` produces `/S /BlockQuote` struct element (not `/S /BLOCKQUOTE` from raw `strtoupper()`) |
| `testTableWithoutTbodyAutoWraps` | `<table><tr><td>` (no explicit `<tbody>`) produces `/S /TBody` struct element wrapping the TR — veraPDF requires TBody even when absent from HTML |
| `testFootnoteTagProducesNoteStructElement` | `<footnote>` produces `/S /Note` struct element with a globally-unique `/ID` string; content appears at foot of page |
| `testSvgImageTaggedAsFigureAtPageLevel` | `<img src="test.svg" alt="Chart">` produces a `/S /Figure` struct element at the page level; the Figure's Alt entry is `"Chart"` |
| `testSvgInlineFontTextIsArtifact` | Text inside an inline SVG stream is content of the Figure Form XObject and is NOT separately tagged — no additional MCID assigned inside the Form XObject stream |
| `testSvgWithAltTextHasAltOnFigure` | SVG via `<img alt="Logo">` maps the alt text to the Figure struct element's `/Alt` entry, not to any internal SVG element |

### Integration Tests — `tests/Mpdf/Ua/IntegrationTest.php` (new in Phase 4)

These tests render complete real-world HTML (extracted from the mpdf-examples repository) with `PDFUA=true` and assert structural correctness. HTML is embedded inline in the test file — no network or filesystem dependency.

For every integration test the following *invariant assertions* are made unconditionally:
1. `assertStringContainsString('/MarkInfo', $output)` — document is marked
2. `assertStringContainsString('/StructTreeRoot', $output)` — struct tree present
3. BDC+BMC count equals EMC count (regex scoped to PDFUA-emitted operators)
4. Every page dict contains `/StructParents`

| Test method | Example source | Additional assertions |
|---|---|---|
| `testBasicHtmlDocument` | `example01_basic.php` HTML | All heading levels H1–H6 appear as struct elements; hyperlink has `/Contents` |
| `testImagesAllFormats` | `example04_images.php` HTML | Each `<img alt="...">` produces a Figure struct element; decorative images produce Artifact |
| `testSimpleTable` | `example05_tables.php` HTML | `/S /Table`, `/S /TR`, `/S /TD`, `/S /TH` all present |
| `testNestedTables` | `example06_tables_nested.php` HTML | Nested Table struct elements (Table child of outer TD); BDC/EMC balanced with nested blocks |
| `testLists` | `example08_lists.php` HTML | `/S /L`, `/S /LI`, `/S /LBody`, `/S /Lbl` all present |
| `testHtmlHeadersAndFooters` | `example12_paging_html.php` HTML | `/Artifact <</Type /Pagination /Subtype /Header>> BDC` present; no struct elements for header content |
| `testMultiPageDocument` | `example14_page_numbers_ToC_Index_Bookmarks.php` HTML | Every page dict has `/StructParents`; StructParents count equals page count |
| `testColumnsLayout` | `example22_columns.php` HTML | BDC/EMC balanced; pre-column headings produce struct elements; column content itself is tagged as Artifact (no `/S /P` etc. inside column region); no PHP errors |
| `testInvoiceComplexTable` | `example34_invoice_example.php` HTML | Complex real-world table; colspan on header cells present; no PHP errors |
| `testAnnotationsAndAttachments` | `example36_annotations_and_attached_files.php` HTML | Annotation objects contain `/Contents` and `/StructParent`; `/S /Note` struct elements present; BDC/EMC balanced |
| `testCoexistenceWithPdfa1b` | `PDFA=true, PDFAversion='1-B', PDFUA=true` with basic HTML | Output contains both `pdfaid:part` and `pdfuaid:part` XMP blocks; `/OutputIntents` present (from PDFA); `/MarkInfo` present (from PDFUA); `/StructTreeRoot` present |
| `testCoexistenceWithPdfa3b` | `PDFA=true, PDFAversion='3-B', PDFUA=true` | Output contains `pdfaid:part>3</pdfaid:part>` AND `pdfuaid:part>1</pdfuaid:part>`; both blocks present |
| `testCoexistenceWithPdfx` | `PDFX=true, PDFUA=true` with basic HTML | Output contains both `pdfx:GTS_PDFXVersion` and `pdfuaid:part` XMP blocks; struct tree present |
| `testPdfuaDoesNotAddOutputIntents` | `PDFUA=true` without PDFA or PDFX | Output does NOT contain `/OutputIntents` |
| `testRtlDocument` | `example26_RTL.php` HTML | PDFUA output produced without errors; struct tree valid |
| `testMethod2HeadersAreArtifactTagged` | `example16_headers_method_2.php` HTML copied into a PHPUnit test with `mode='c'` **removed** (PDF/UA forbids core fonts; use `PdfUaTestCase::makeMpdf()` so embedded TrueType fonts are selected) | Page content stream contains `/Artifact <</Type /Pagination /Subtype /Header>> BDC … EMC` wrapping the Method-2 header text AND `/Artifact <</Type /Pagination /Subtype /Footer>> BDC … EMC` wrapping the footer; struct tree contains NO struct elements for header/footer content; BDC/EMC balanced |

(The core-font exception is covered by `testCoreFontsNotAllowed` in Phase 1's `MetadataTest.php` — see §"Phase 1 Tests" above. No duplicate test needed in Phase 5.)
| `testEncryptionAccessibilityBit` | `example64_protected_document.php` HTML copied into a PHPUnit test with `mode='c'` **removed** (PDF/UA forbids core fonts; use `PdfUaTestCase::makeMpdf()` so embedded TrueType fonts are selected). Test scenario: `setProtection(['print'])` omits the `'extract'` permission | In strict mode (`PDFUAauto=false`): `MpdfException` thrown. In auto mode (`PDFUAauto=true`): `$mpdf->ua->getWarnings()` non-empty AND the emitted `/P` permissions integer in the `/Encrypt` dict has bit 10 set (accessibility permission force-added by Phase 1). Separately: assert the XMP metadata stream remains unencrypted (raw output contains plaintext `pdfuaid:part`) per Phase 3 Constraint 2 |
| `testIndexFeatureRendersWithoutErrors` | `<indexentry content="foo">` + `<indexinsert>` HTML | No PHP errors; BDC/EMC balanced; index divs produce Div/P struct elements; `<indexentry>` produces no BDC/EMC of its own |
| `testIndexEntryProducesNoBdcEmc` | Document with only `<indexentry content="foo">` (no indexinsert) | Page stream contains no BDC or EMC from the indexentry itself |
| `testTocRendersWithStructElements` | `example14_page_numbers_ToC_Index_Bookmarks.php` HTML | No PHP errors; BDC/EMC balanced after `MovePages`; ToC divs produce Div struct elements; `<tocentry>` produces no BDC/EMC; ToC links produce Link struct elements |
| `testTocEntryProducesNoBdcEmc` | Document with only `<tocentry content="foo">` (no toc) | Page stream contains no BDC or EMC from the tocentry itself |
| `testUntaggedImportedPageIsArtifact` | FPDI import of untagged PDF with PDFUA=true | Page stream contains `/Artifact <</Type /Layout>> BDC … Do … EMC`; `$mpdf->ua->getWarnings()` non-empty; BDC/EMC balanced |
| `testTaggedImportedPagePreservesStructTree` | FPDI import of a tagged PDF page with PDFUA=true | Form XObject dict contains `/StructParents N`; output contains `/S` struct elements reconstructed from source; MCR dicts contain both `/Pg <hostPage>` and `/Stm <foXObject>`; BDC/EMC balanced (no Artifact wrapping in outer stream); RoleMap merged from source (host-wins on conflicts). Required gate — no `@group deferred` skip. |
| `testFpdiTemplateReusedAcrossPagesHasPerPageMcr` | Source-tagged PDF used via `SetPageTemplate()` on 3 host pages | One struct subtree is emitted; three MCR kids differing only by `/Pg`; struct elements are NOT physically duplicated (single object number per source struct element) |
| `testFpdiUntaggedSourceStillArtifactWrapped` | Untagged source PDF imported with PDFUA=true | `Do` is wrapped `/Artifact <</Type /Layout>> BDC … EMC`; `$mpdf->ua->getWarnings()` contains the untagged-import message |
| `testImportedPageWithoutPdfuaNoWrapping` | FPDI import with PDFUA=false | Page stream does NOT contain BDC or EMC around the `Do` operator |

**`tests/Mpdf/Ua/DecorativePathsTest.php`** (new — Matterhorn 04-001: untagged path operators):

All decorative path operators (`re f`, `m l S`, border strokes, background fills) must be Artifact-wrapped. This class tests the M-4 requirement, which is called "Critical" in the Matterhorn section. This is a dedicated class because M-4 affects every table, `<hr>`, and block with a border — a regression here silently breaks veraPDF on almost all documents.

| Test method | Assertion |
|---|---|
| `testHrIsArtifact` | `<hr>` produces `/Artifact BMC` before its path operator and `EMC` after; no `/S /HR` struct element |
| `testTableBorderIsArtifact` | `<table border="1">` page stream contains `/Artifact BMC` around the `re S` (border stroke) path operators; table-content struct elements still present |
| `testBlockBorderIsArtifact` | `<div style="border: 1px solid black">` border path operators wrapped in `/Artifact BMC … EMC`; the `Div` struct element covers content, not the border graphics |
| `testPageBackgroundIsArtifact` | `background` CSS on `<body>` or a block element produces background fill path operators inside `/Artifact <</Type /Background>> BDC … EMC` |
| `testNonPdfuaOutputHasNoDecorativeArtifactWrap` | With `PDFUA=false`, the same `<hr>` HTML produces NO `/Artifact BMC` — the wrapping is PDFUA-only and must not affect non-PDFUA output (regression guard) |

### Phase 4 Completion Gate

```bash
composer test                              # all existing + all prior phase tests must pass
vendor/bin/phpunit --group=snapshot        # snapshot suite must stay green
```

---

## Phase 5: Validation and Warnings

When `PDFUA = true` and `PDFUAauto = false`, throw `MpdfException` for hard violations:
- Core fonts used (Phase 1 already handles this)
- `title` config not set (Phase 1 already handles this)
- Image with no `ALT` attribute — unknown intent; throw when `PDFUAauto=false`

When `PDFUAauto = true`, append to `$this->ua->getWarnings()` via `->addWarning()` instead of throwing.

Additional checks:
- `.notdef` glyph referenced — detect during font subsetting when a character maps to glyph 0; add to `$this->ua->getWarnings()`
- **Encryption bit 10**: If `Protection` is enabled, the "extract text and graphics" permission (bit 10) must remain true. PDF/UA-1 §7.6 — assistive technology must always be able to read content. Check after `Protection` is applied in `BaseWriter`; throw or warn if bit 10 is cleared.

**Ligature ActualText (Matterhorn 24-001)** — see Appendix A6 for the full implementation. Phase 5 activates `\Mpdf\Ua\LigatureActualTextWriter` which wraps OTL-substituted glyph clusters with `/Span <</ActualText …>> BDC … EMC` at emit time.

Warnings are accessible via `$mpdf->ua->getWarnings()` (array of strings).

### Phase 5 Tests

**`tests/Mpdf/Ua/ValidationTest.php`** (new):

| Test method | Assertion |
|---|---|
| `testImageMissingAltAddsWarning` | Image with no `alt` populates `$mpdf->ua->getWarnings()` when `PDFUAauto=true` |
| `testImageMissingAltThrowsWhenStrict` | Image with no `alt` throws `MpdfException` when `PDFUAauto=false` |
| `testEncryptionBit10MustRemainSet` | `setProtection([])` + `PDFUA=true` throws or warns about accessibility permission bit (based on `example64_protected_document.php` HTML, but with `mode='c'` removed — use `PdfUaTestCase::makeMpdf()` for embedded TrueType fonts; the purpose is to exercise the encryption / permission-bit path, not the core-font exception) |
| `testSetColumnsProducesTaggedColumnContent` | `SetColumns(2)` followed by `<p>Column body</p>` with `PDFUA=true` (either mode) produces real `/P <</MCID N>> BDC … EMC` inside the column content (via the sentinel expansion in `printcolumnbuffer()`). No `/Artifact` markers around column body content. `$mpdf->ua->getStructureTree()` contains a `P` child. Works in both strict and auto modes — column tagging is always on. |
| `testIndexInColumnsProducesTaggedStructElements` | `SetColumns(2)` + `<indexinsert>` produces `Div`/`P`/`Link` struct elements inside the column region; BDC/EMC balanced; no `/Artifact` wrappers around index entries |
| `testColumnSentinelsSurviveReorder` | Column balancing moves `rel_y`-sorted entries across columns; sentinel `__PDFUA_BDC__` / `__PDFUA_EMC__` stay adjacent to their content; final output has matched BDC/EMC pairs |
| `testHeadingLevelSkipAddsWarning` | `<h1>A</h1><h3>B</h3>` (skipping H2) with `PDFUAauto=true` adds a warning to `$mpdf->ua->getWarnings()`; with `PDFUAauto=false` throws `MpdfException` (Matterhorn 14-003) |
| `testOverWriteThrowsInPdfuaMode` | Calling `OverWrite()` when `PDFUA=true` throws `MpdfException` regardless of `PDFUAauto` — overwriting invalidates struct tree object references which cannot be repaired |
| `testJavaScriptEmbedAddsWarning` | `<script>` or `$mpdf->SetJS()` with `PDFUA=true, PDFUAauto=true` adds a `PDFUAwarning`; with `PDFUAauto=false` throws `MpdfException` |
| `testFontSubsetToUnicodeCoverage` | Every font used in PDFUA output has a `/ToUnicode` CMap stream — assert each font object in the PDF contains `/ToUnicode` when `PDFUA=true` |
| `testAllExamplesRenderWithoutExceptions` | For each example HTML snippet (01, 04, 05, 06, 07, 08, 12, 22, 34, 26), render with `PDFUAauto=true`; assert no PHP exceptions thrown (warnings only) — comprehensive PHP-level smoke test. **Note**: "no exceptions" ≠ "veraPDF conformant". `PDFUAauto=true` converts violations to warnings, so this test passes even when output would fail veraPDF. It catches PHP errors and fatal misconfigurations only. |

**veraPDF conformance gate** (part of Phase 5): Once veraPDF is available in the CI environment, add:

```
# tests/Mpdf/Ua/VeraPdfValidationTest.php
# @group verapdf — excluded from default 'composer test' run
# Skipped unless VERAPDF_BIN env is set
testAllExamplesPassVeraPdfWithUa1Flavour
```

Run locally with: `VERAPDF_BIN=/path/to/verapdf vendor/bin/phpunit --group=verapdf`

CI must install veraPDF before merging the final PDF/UA-1 phase — the `@group verapdf` tests are part of the Phase 5 completion gate, not a future phase.

### Phase 5 Completion Gate

```bash
composer test                              # full suite green
vendor/bin/phpunit --group=snapshot        # snapshot suite must stay green
```

---

## Spec Compliance Details (Tagged PDF Best Practice Guide + WTPDF 1.0)

### TH Scope attribute (Matterhorn 09-004)

ISO 32000-1 §14.8.5.7 and the Tagged PDF Best Practice Guide §5.4 require a `/Scope` attribute on `TH` struct elements that are **not** in the first row:

- **First-row TH cells:** `/Scope /Column` (implied but should be explicit)
- **Left-column TH cells in subsequent rows:** `/Scope /Row`
- **Corner cells (first row, first col in a complex table):** `/Scope /Both`

**Implementation in the `TH` tag handler (`src/Tag/TableTH.php` or equivalent):**

```php
// Inside open(), when PDFUA is active:
$scope = 'Column';  // default
if ($isHeaderRow === false) {
    // not first row — examine position
    $scope = $isFirstColumn ? 'Row' : 'Column';
}
$attributes = ['Scope' => $scope];
// Pass $attributes to structureTree->open('TH', $attributes)
// StructureWriter then emits /A << /O /Table /Scope /Column >> in the TH struct dict
```

The `StructureElement::$attributes` array carries the Scope value through to `StructureWriter`, which emits it as a PDF `/A` attribute dict entry: `<< /O /Table /Scope /Column >>`.

### Figure → Caption structure order

The Tagged PDF Best Practice Guide §4.3.1 recommends that when a `<figure>` has a caption, the `Caption` struct element should appear **after** the `Figure` child in the parent struct element, not before. This matches typical reading order.

mPDF's `<figure>/<figcaption>` parsing: if `<figcaption>` appears after image content (the common case), the struct tree will naturally have `Figure` before `Caption` — no special handling needed. If `<figcaption>` appears before the image (as a title caption), struct order follows DOM order; this is acceptable.

Natural DOM traversal produces correct struct order — no special handling needed in the tag handler.

### Definition lists (`<dl>`, `<dt>`, `<dd>`)

`StructType::$tagMap` maps `DL → L`, `DT → Lbl`, `DD → LBody` (see §2c above).

The tag handler for `DT` must open an implicit `LI` parent before opening `Lbl` if the current struct parent is `L` (not already inside `LI`). Similarly, `DD` must open an implicit `LI` before `LBody` when not already inside `LI`:

```php
// In DT/DD open handler, when PDFUA active:
if ($this->ua->getStructureTree()->getCurrent()->getType() === 'L') {
    $this->ua->getStructureTree()->open('LI');
    $this->ua->setOpenedImplicitLI(true);
}
$this->ua->getStructureTree()->open('Lbl');  // for DT, or 'LBody' for DD
```

The `$this->ua->isOpenedImplicitLI()` flag tracks whether an implicit `LI` was opened (closed on the next sibling `DT`/`DD` or on `/DL`).

### Spec-mandated invariants

The following mappings and behaviours are direct requirements of ISO 14289-1 / ISO 32000-1 and MUST be preserved by any implementation:

- `BLOCKQUOTE → BlockQuote` (ISO 32000-1 Table 333 standard type)
- `CODE/PRE → Code` (ISO 32000-1 Table 333 standard type)
- Artifact marking for running headers/footers
- Decorative images (empty `alt`) → Artifact
- Per-page MCID reset to 0 (ISO 32000-1 §14.7.4.4)
- `pdfuaid:part` in XMP
- `displayDocTitle` in ViewerPreferences (implemented in Phase 1)
- Heading level validation (no skipping)
- `LI → Lbl + LBody` structure

---

## Implementation Order

1. **Phase 1** — config + metadata + `/StructParents` + PDF version forcing + `/Lang` in catalog + `/Tabs /S` on all pages + `PDFUAauto` flag. Tests: `MetadataTest.php` (created).
2. **Phase 2** — StructureTree classes + ServiceFactory wiring. Tests: `StructureTreeTest.php` (created) + `MetadataTest.php` extended.
3. **Phase 3d** — Header/footer artifact marking (`BDC` with property dict).
4. **Phase 3a/3b** — BDC/EMC helpers + `finishFlowingBlock()` hook.
5. **Phase 3c** — Image tagging (raster + SVG).
6. **Phase 3 tests** — Infrastructure tests in `ContentStreamTest.php`.
7. **Phase 4** — Element-by-element tagging (iterative; each element type independently testable). Includes: HTML tag → struct type mapping, `<th scope>` mapping, Lbl for list bullets, auto-wrap TBody, link annotation `/StructParent`, footnote Note elements, file attachment annotation coverage. Tests: `StructureElementsTest.php`, extend `ContentStreamTest.php`.
   **⚠ Phase 4 gate requires M-4 (Matterhorn 04-001, decorative path operators) to be complete.** M-4 is NOT optional — veraPDF fails every document with a table, `<hr>`, or block border until path operators are Artifact-wrapped. The Phase 4 gate test `testAllExamplesRenderWithoutExceptions` will trivially pass with `PDFUAauto=true` without M-4, but the actual output fails veraPDF. Add `DecorativePathsTest.php` as a dedicated test class for M-4, and include a snapshot-regression check confirming existing table/border rendering is unchanged for non-PDFUA output.
8. **Phase 5** — Validation warnings + Matterhorn gap checks (heading skip, JS warning, embedded file warnings). Tests: `ValidationTest.php`.

After each phase: **`composer test` AND `vendor/bin/phpunit --group=snapshot` must both be green before proceeding.**

---

## Key Non-Obvious Constraints (verified in code)

- **PHP 5.6 compat**: No typed properties, arrow functions, named args, `??` operator, `match`, or nullsafe `?->` in `src/`. Use `isset($x) ? $x : $default` instead of `$x ?? $default`. Use `var $foo;` for all new Mpdf properties (matches existing `var $PDFA;` at line 79); use `var $foo;` in new standalone classes too (they don't use Strict).
- **Strict trait**: All new properties on `Mpdf` must be declared — dynamic assignment throws `MpdfException`. New collaborator classes (StructureTree, StructureElement) do NOT use Strict.
- **`var` properties are public**: Writer code accesses `$this->mpdf->PDFUA` directly — same pattern as `$this->mpdf->PDFA`. Do NOT use `getPDFUA()` — no such getter exists. The only helper methods added live on `\Mpdf\Ua\UaState`: `addWarning($msg)` and `nextStructParents()`. `Mpdf.php` itself gains zero new methods.
- **New config options must be in `ConfigVariables.php`** — constructor merges config using that class as the defaults source.
- **New services in `ServiceFactory`** must appear in both `getServices()` return array and `getServiceIds()` array.
- **MCID assignment in `finishFlowingBlock()`** — not at tag-open time. Tag `open()` pushes the struct element; `finishFlowingBlock()` assigns the MCID using the page's `/StructParents` integer. One MCID per page per struct element.
- **`/StructParents N` on every page dict** — not just annotated pages. Missing = immediate veraPDF failure.
- **ParentTree keys are `/StructParents` integers** (sequential, starting from 0, assigned in `PageWriter`), not 1-based page numbers.
- **Multi-page struct element /K arrays** use MCR dicts (`/Type /MCR /Pg N 0 R /MCID n`), not bare integers.
- **BMC vs BDC**: `BMC` = no property dict (valid for plain `/Artifact BMC`). `BDC` = with property dict (required for `/Artifact <</Type ...>> BDC`). Using `BMC` with a preceding dict is invalid PDF.
- **`newFlowingBlock()` initialises `flowingBlockAttr`** at line 6428 — add `pdfua_struct_open` and `pdfua_type` keys there, not anywhere else.
- **`finishFlowingBlock()` signature**: `function finishFlowingBlock($endofblock = false, $next = '')` at line 6460.
- **Table BDC/EMC injection is in `_tableWrite()`** (~line 21912 in `Mpdf.php`), not in `finishFlowingBlock()`. `finishFlowingBlock()` fires during table-collect (parsing), not rendering. By render time the struct element is off the stack — use `addContentForElement($cell['pdfua_struct_elem'], $structParents)` to assign the MCID to the stored element reference.
- **Nested tables**: recursive `_tableWrite()` produces nested BDC/EMC blocks — valid in PDF (ISO 32000-1 §14.6). No special case needed.
- **Nested lists**: the open/close stack handles them automatically. A nested `<ul>` inside `<li>` becomes a child of `LBody` — correct and requires no special code.
- **P/H/etc inside table cells**: these struct elements exist as children of TD in the struct tree but share the TD's single MCID in Phase 4. This is valid PDF/UA-1. Per-paragraph MCIDs within cells are a future enhancement.
- **`ALT` attribute is entirely absent** from `src/Tag/Img.php` — there is no existing parsing to build on.
- **`aria-*` attributes arrive uppercased** — check `$attr['ARIA-HIDDEN']`, `$attr['ARIA-LABEL']`, `$attr['ARIA-LEVEL']`, `$attr['ARIA-COLSPAN']`, `$attr['ARIA-ROWSPAN']`, `$attr['ROLE']`, `$attr['LANG']`.
- **`aria-labelledby`, `aria-describedby`, `aria-details`, `aria-controls`, `aria-owns`, `aria-flowto`, `aria-activedescendant`** — resolved by `\Mpdf\Ua\AriaIdResolver` in a deferred second pass at `_enddoc()` time (before `StructureWriter::writeStructTree()` runs). `/Alt`, `/E`, and relationship kids mutate in-memory `StructureElement` objects only — no content-stream rewrite required. Forward references resolve correctly.
- **Fixed-position blocks**: Buffered and rendered after normal flow via `WriteFixedPosHTML()` (~line 13935 in `Mpdf.php`). Default to Artifact. The BDC/EMC hook belongs inside `WriteFixedPosHTML()`, not at the `BlockTag::open()` detection point (because content is not rendered at parse time).
- **Floated blocks**: Content rendered before surrounding text in the content stream. Block floats default to Artifact (stream-order mismatch); image floats tagged as Figure (stream order usually matches reading order). Float detection is in `BlockTag::open()` (~line 582-712); metadata in `$this->floatDivs[]`. Image float buffer via `printfloatbuffer()` (~line 25378).
- **`inFixedPosBlock`** flag on `Mpdf` — while this is `true`, tag handlers must not emit struct elements or BDC/EMC operators since the content is being buffered, not rendered.
- **Barcodes**: Rendered as vector graphics (PDF path operators `re f`) via `WriteBarcode()` / `WriteBarcode2()` / `mpdf/qrcode` package. Tag as `Figure` with `Alt = 'Barcode: ' . $code` (or `aria-label` override). MCID + BDC/EMC injected at textbuffer processing point (Mpdf.php ~line 7414), wrapping the entire render call including human-readable text. Use `$this->ua->getMarkedContentHelper()->begin('Figure', $mcid)` / `->end()`.
- **Watermarks**: Rendered in `Footer()` (processingFooter=true) → direct `pages[$page]` write, column buffer bypassed. Tag as `/Artifact <</Type /Background>> BDC … EMC`. Behind-content images injected via `preg_replace()` — include BDC/EMC in replacement string. Text: wrap in `watermark()` method. Image (front): wrap `Image()` call in `watermarkImg()`. Use `/Type /Background` artifact subtype, not Pagination.
- **`<textcircle>`**: Uses real `Cell()` calls → actual PDF text, not graphics. Tag as `Span` with `ActualText` = concatenated `top-text + divider + bottom-text`. MCID and BDC/EMC injected at the textbuffer processing point (~line 7524 in `Mpdf.php`), NOT inside `DirectWrite::CircularText()`.
- **FPDI imported pages**: `enableImports=false` by default. **When enabled**: the source catalog is inspected for `/StructTreeRoot`. Untagged sources are Artifact-wrapped (`/Artifact <</Type /Layout>> BDC … EMC` around the `Do`) with an `$this->ua->addWarning()` call. Tagged sources flow through `\Mpdf\Ua\Import\FpdiStructMerger` — the source struct subtree is queued onto `objectsToCopy` (FPDI's existing indirect-ref rewriter handles object-number remapping), MCR dicts get `/Pg <hostPageObjNum> /Stm <foXObjectObjNum>`, RoleMap is merged first-wins. `SetPageTemplate()` reuse emits one struct subtree with N MCR kids (one per reuse), not N subtree copies. Both paths use `$this->writer->write()` (routes through `BaseWriter::endPage()`).
- **Form Widget `/TU` already present**: `Form.php` always writes `/TU` from HTML `title`/`alt`. No change needed. Add `/StructParent M` (singular, not `/StructParents`) to Widget annotation dicts and create `Form` struct elements with OBJR dicts in `StructureWriter`. Radio buttons: pre-assign per kid Widget, not per group parent. Store assigned PDF object number in `$this->form->forms[$ref]['obj']` immediately after `$this->writer->object()` call in each `_putform_*()` method.
- **`<indexentry>` and `<tocentry>` produce no content stream output** — they only record `$this->Reference[]` / `$this->tableOfContents->_toc[]` entries with zero-width, zero-height object markers. No BDC/EMC or struct element should be emitted for either. `<indexinsert>` and `<toc>`/`<tocpagebreak>` render via `WriteHTML()` and are tagged normally by Phase 4 block element handlers. Index and ToC page-reference numbers are content text, NOT pagination Artifacts.
- **`MovePages()` for ToC pages**: Reorders the logical page list but does NOT renumber PDF objects — `/Pg N 0 R` references in MCR dicts remain valid after `insertTOC()` moves pages. No special handling needed.
- **`ColActive` flag and column buffer routing**: When `$this->ColActive === 1`, `BaseWriter::endPage()` routes ALL content to `$this->columnbuffer[]` instead of `$this->pages[$this->page]`. Tag handlers do NOT call `markedContentHelper->begin()`/`->end()` during column collection. Instead they write `__PDFUA_BDC__` / `__PDFUA_EMC__` sentinel entries to `columnbuffer[]` (see §6 sentinel strategy). These sentinels are expanded to real BDC/EMC operators in the final output loop of `printcolumnbuffer()`, after column reordering. `structureTree->open()`, `addContent()`, and `close()` are called normally during collection — only BDC/EMC emission is deferred.
- **`AutosizeText()` direct method needs BDC/EMC around its single `Cell()` call** (line 25476): tag as `Span`; no new parameter needed (text content IS the accessible representation); same suppression guards as `Image()` — `isInArtifact()` and `ColActive`.
- **`Image()` direct method has its own BDC/EMC injection point**: HTML `<img>` tags emit BDC/EMC in `printobjectbuffer()` (~line 7381); direct `Image()` PHP API calls emit BDC/EMC inside `Image()` itself at line ~9074, immediately around `$this->writer->write($outstring)`. Add `$alt = null` as the final parameter. Three suppression guards: `$watermark=true` (watermark path handles its own tagging), `$this->ua->getStructureTree()->isInArtifact()` (header/footer scope), `$this->ColActive` (column buffer reordering makes BDC/EMC unsafe). **Watermark + alt conflict**: When `$watermark=true` AND `$alt` is a non-null, non-empty string, the `$alt` value is silently discarded (the watermark suppression guard fires first). This is correct behavior — watermarks are always Artifact regardless of the alt argument — but callers passing both `$watermark=true` and a non-empty `$alt` likely have a logical error. When `PDFUA=true`, add a `PDFUAwarning` in this case: `"Image() called with watermark=true and non-empty alt — alt text is ignored for watermark images"`. **No unit test** — covered by the documented behavior note; test would require mocking the warning collection. **Subclass compatibility**: Any `Mpdf` subclass that overrides `Image()` with the original 12-parameter signature will silently lose the `$alt` argument — the parent's PDFUA branch will never receive it, leaving the struct tree unpopulated without any visible error. Document in CHANGELOG and UPGRADING.md that subclasses overriding `Image()` must update their signature. Do not describe this as "backward-compatible" — it is source-compatible for callers but not for overriders. **No unit test for subclass override** — this is a documented-only caveat; verifying it would require creating an anonymous subclass, which adds significant test complexity for marginal benefit.
- **Layers (OCGs) are permitted in PDF/UA**: Do NOT add PDFUA to the `BeginLayer()` PDFA/PDFX guard. Tag content inside layers normally — MCID BDC/EMC nests inside layer BDC/EMC; the nesting is valid per ISO 32000-1 §14.6. Layer content reordering at `_enddoc()` (lines 10065–10066) extracts and re-appends entire layer blocks, preserving inner MCID BDC/EMC pairs. Struct tree references are by MCID number, not stream position — reordering does not invalidate them. `SetVisibility()` BDC/EMC is not reordered and is also transparent to the tagging logic. Tag content inside visibility groups normally.
- **`bufferoutput=true` and header/footer BDC/EMC routing**: During `_puthtmlheaders()`, mPDF sets `$this->bufferoutput = true`, causing `BaseWriter::endPage()` to route all `$this->writer->write()` output to `$this->headerbuffer` instead of `$this->pages[$this->page]`. The `headerbuffer` is later spliced into the page stream at `___HEADER___MARKER___` by `PageWriter::writePages()`. **Never write BDC/EMC for headers/footers directly to `$this->pages[$this->page]`** — that bypasses `bufferoutput` routing and places operators outside the header section, producing a malformed stream. Always use `$this->writer->write()`. All four header/footer types (PHP `SetHTMLHeader/Footer()`, `SetHeader/Footer()`, HTML `<htmlpageheader/footer>`, HTML `<pageheader/footer>`) converge on `_puthtmlheaders()` — one injection point covers all four.
- **`openArtifact()` / `closeArtifact()` in `StructureTree`**: An internal `$artifactDepth` counter on `StructureTree` (not a boolean flag) that, when `> 0`, causes all tag handlers and `finishFlowingBlock()` to skip struct element creation and MCID assignment. A counter is required (not a boolean) so nested artifact scopes work correctly (e.g., `openArtifact()` called for header rendering, then watermark inside header also calls `openArtifact()` — the inner `closeArtifact()` must not prematurely exit the outer scope). Required whenever rendering enters a context that is semantically a single Artifact: headers/footers (content is Pagination Artifact), column regions (entire column block is Artifact in Phase 4), fixed-position blocks, watermarks. Without this, HTML markup inside a header (e.g., `<h1>Company Name</h1>`) would create `/H1` struct elements in the document structure tree — semantically wrong and invalid PDF/UA.
- **HTML `lang` attribute** — maps to `/Lang` on struct elements. Required by PDF/UA-1 §7.2 for language changes within a document.
- **`<abbr title="...">` / `<acronym title="...">`** — the `title` attribute value maps to `/E` (expansion) on the Span struct element. Verify whether mPDF has an `Abbr` tag handler; if not, add one.
- **RoleMap**: Custom `role=` values not in the standard struct type set must be recorded in StructTreeRoot's `/RoleMap` dict. `StructureTree::addRoleMapping()` collects them for `StructureWriter`.
- **`Note` struct elements** must have a globally unique `/ID` string (Matterhorn 09-004). Generate a UUID or document-scoped counter string for each Note.
- **`StructureTree::open()` return value**: `open()` pushes the new element onto the stack and returns `void`. Callers that need a reference to the new element must call `$this->ua->getStructureTree()->getCurrent()` immediately after `open()`. Do NOT rely on `open()` returning the element — it does not. This convention must be consistent across all call sites (tag handlers, watermark, FPDI).
- **`$this->headerbuffer` resets before each header render**: `_puthtmlheaders()` renders each header (odd/even/first, header/footer) in a separate `WriteHTML()` call. Verify that `$this->headerbuffer` is reset to `''` at the start of each header render block before calling `WriteHTML()`. If it accumulates across iterations, the BDC/EMC wrapping at §3d would enclose stale content from previous pages inside the current header's artifact block. Check the existing `_puthtmlheaders()` flow — `headerbuffer` should already reset; confirm in code before Phase 3d is marked complete.
- **`/StructParents N` integer allocation contract** (consolidated): Page dicts receive `/StructParents N` integers assigned sequentially by `PageWriter::writePages()`. The counter is `$this->ua->getStructParentsCounter()` (incremented by `nextStructParents()`). Annotation pre-assignment in the same function uses `StructureTree::$annotParentCounter` (via `nextAnnotStructParent()`). Form XObject `/StructParents` (SVG, FPDI tagged pages) uses `nextStructParents()` from the same counter. `StructureWriter::writeStructTree()` emits `/ParentTreeNextKey` = `$this->ua->getStructParentsCounter()` (its value after all pages are processed equals one more than the highest key used). The `ParentTree` NumTree has one entry per integer: pages → dense array of struct elem refs ordered by MCID; annotations/XObjects → single struct elem ref.
- **Long-term target architecture**: The current design has `MarkedContentHelper` centralizing BDC/EMC emission with routing through `$this->writer->write()`. Any new buffer context (e.g., a new "sticky layout" buffer) requires only that `BaseWriter::write()` knows about it — no per-injection-site changes. New code must always route through `markedContentHelper->begin()`/`->end()`, never write BDC/EMC directly to buffers (except in column sentinel expansion inside `printcolumnbuffer()`, which is a special deferred-emission path).
- **Encryption: PDF/UA allows it; PDF/A and PDF/X do not**: Do NOT extend the `if (($this->PDFA || $this->PDFX) && $this->encrypted)` exception guard to include PDFUA. Two changes are required when PDFUA + encryption coexist: (1) force-add `'extract'` (bit 10, accessibility permission) to the permissions array in `SetProtection()` silently; (2) pass `$encrypt = false` to `BaseWriter::stream()` for the XMP metadata stream — PDF spec §14.3.2 mandates XMP is NOT encrypted. All other objects (page content, struct tree dicts) are handled correctly by existing behaviour.
- **`PDFUA` and `PDFA` can coexist** — the PDFUA XMP block is a separate `if` (not `elseif`) after the `elseif ($this->mpdf->PDFA)` block in `writeMetadata()`. PDFX and PDFA are mutually exclusive via `if/elseif`; PDFUA is independent. PDFUA does NOT need `/OutputIntents` (leave line 386 unchanged). Do not add PDFUA to the `PrintScaling` / `Duplex` / `/Subj` PDFA/PDFX exclusions — PDF/UA is based on PDF 1.7 and allows these. The `/F 28` and `/CA 1` annotation flags and the `/Metadata` catalog reference DO need PDFUA added.
- **`_enddoc()` ordering**: `writeResources()` fires before `writeCatalog()`, so `structTreeRoot` set inside `writeStructTree()` is available when the catalog is written.
- **Link `Contents` key**: Capture link text at `A::close()` time; store on `PageLinks` record; write in `MetadataWriter::writeAnnotations()`.
- **`/StructParent` vs `/StructParents`** (critical distinction): `/StructParents N` on page dicts → ParentTree entry is an **array** of struct elem refs (one per MCID). `/StructParent M` on annotation/XObject objects → ParentTree entry is a **single** struct elem ref. `StructureWriter` must handle both when building the NumTree.
- **OBJR dicts in struct element `/K`**: Annotations are associated via Object Reference dicts (`/Type /OBJR /Obj N 0 R`) in the struct element's `/K` array, not via MCIDs. `StructureElement` needs an `objrefs` array for these. `StructureWriter` emits OBJR dicts alongside or instead of MCID integers.
- **Pre-assigning annotation `/StructParent` indices**: Annotation object dicts are written in a single pass in `writeAnnotations()`. The `/StructParent N` integer must be written at that time. Pre-assign indices in `PageWriter::writePages()` (where annotation counts are known) and store on `$this->mpdf->PageAnnots[$n][$k]['structParent']`.
- **Link `/Contents` is currently commented out** in MetadataWriter.php line ~529. Enable it conditionally for PDFUA, using the link text captured at `A::close()` time (not the URL).
- **`testCoreFontsNotAllowed`**: Trigger core font selection via `['mode' => 'c']` constructor argument — do not inject fake font data.
- **PDF/UA tests must not extend `BaseMpdfTest`** — that base class defaults to `['mode' => 'c']` which throws in PDFUA mode. All PDF/UA test classes extend `PdfUaTestCase` which uses embedded TrueType fonts (no explicit mode — defaults to `utf-8`).
- **Test HTML is embedded inline** — do not fetch from the mpdf-examples repo at test runtime. Extract the `$html` variable from each example file and embed it as a private method returning a string. This keeps tests hermetic.
- **PDF version must be 1.7** (Matterhorn 01-001): Force `$this->pdf_version = '1.7'` in the constructor when `PDFUA=true` if the current version is lower. This mirrors the existing version-forcing for layers (1.5) and PDF/X (1.3). Without this, veraPDF fails on every document.
- **`/Tabs /S` on every page** (Matterhorn 28-001): Write `/Tabs /S` unconditionally alongside `/StructParents N` in the page dict loop — NOT only inside the `if ($annotsnum || $formsnum)` block. Annotated and non-annotated pages both require it.
- **`/Lang` in catalog** (Matterhorn 23-001): ISO 14289-1 §7.2 requires `/Lang` in the document catalog. `MetadataWriter::writeCatalog()` already writes `/Lang` at lines 333–337 from `currentLang`/`default_lang`. No new write is needed. The PDFUA addition is a validation check: throw (strict) or warn (auto) when both language properties are empty/null.
- **Decorative path operators must be Artifact-wrapped** (Matterhorn 04-001): `re f`, `m l S`, and other PDF path operators for table borders, `<hr>`, block borders, and page backgrounds are untagged content operators. All must be wrapped in `/Artifact BMC … EMC`. Failure to do so causes veraPDF to flag every table border and horizontal rule as untagged real content.
- **HTML tag → PDF struct type mapping required in `BlockTag.php`**: `strtoupper($tag)` does NOT produce valid struct types for most HTML5 elements. Use `\Mpdf\Ua\StructType::fromHtmlTag($tag, $attr)` which maps BLOCKQUOTE→BlockQuote, PRE→Code, SECTION→Sect, FIGURE→Figure, FIGCAPTION→Caption, ARTICLE→Art, ASIDE→Sect, NAV→Sect, MAIN→Div, HEADER→Div, FOOTER→Div, ADDRESS→P, MARK/DEL/INS/S/SUB/SUP/SMALL→Span, Q→Quote, DL→L, DT→Lbl, DD→LBody. Unknown tags return `null` — callers decide the fallback (usually Div with a RoleMap entry).
- **SVG images**: Treated identically to raster images from PDF/UA perspective — the entire SVG Form XObject is content of a `Figure` struct element. Alt text from `<img alt="...">` is the accessible representation. Do NOT add `/StructParents` to SVG Form XObject dicts (unlike FPDI imported tagged pages). SVG internal text operators are inside the opaque Form XObject stream and cannot be addressed by page-level MCIDs. **SVG test coverage** — three tests named in SVG prose are NOT in any table yet and must be added to `StructureElementsTest.php`: `testSvgImageTaggedAsFigureAtPageLevel` (SVG via `<img src="...svg">` produces a Figure struct element at the page level), `testSvgInlineFontTextIsArtifact` (text inside inline SVG XML in the page stream is not separately tagged — it is content of the Figure Form XObject; no MCID is assigned to SVG-internal text), and `testSvgWithAltTextHasAltOnFigure` (SVG `<img alt="...">` maps the alt text to the Figure struct element's `/Alt` entry, not to any internal SVG element).
- **`testBdcEmcBalanced`**: Scope the regex to lines containing known PDF/UA tag names (P, H1–H6, Figure, L, LI, LBody, Lbl, TR, TD, TH, Table, Link, Note, Artifact) to avoid counting existing Optional Content Group BDC/EMC operators.

---

## Matterhorn Protocol 1.1 Compliance Gaps

A full audit of the plan against the Matterhorn Protocol 1.1 (136 failure conditions, 31 categories) identified the following items that are not yet addressed in the plan above. These must be resolved before the implementation can pass veraPDF.

### Critical — will cause veraPDF failure on all documents

**M-1: PDF version not forced to 1.7 (Matterhorn 01-001)**
Add to `__construct()` immediately after config merge:
```php
if ($this->PDFUA && version_compare($this->pdf_version, '1.7', '<')) {
    $this->pdf_version = '1.7';
}
```
Already covered in the PDFUAauto section above. Confirmed: this goes in Phase 1a alongside config init.

**M-2: `/Lang` missing from catalog (Matterhorn 23-001)**
Already covered in the PDFUAauto section above (Phase 1e fix). Validated by `testCatalogContainsLang`.

**M-3: `/Tabs /S` only on annotated pages (Matterhorn 28-001)**
Fixed in Phase 1h above — moved to unconditional write alongside `/StructParents`.

**M-4: Decorative path operators not marked as Artifacts (Matterhorn 04-001)**
mPDF emits raw PDF path operators (`re f`, `m l S`, `w`, etc.) for many decorative elements:
- Table borders and cell backgrounds (`_tableWrite()` ~line 21912)
- `<hr>` horizontal rules (`BlockTag.php` HR rendering)
- Page background colours and patterns (`BackgroundWriter.php`)
- Cell background fills (embedded in table rendering)
- Decorative borders on block elements (`BlockTag.php` border drawing)

All of these must be wrapped in `/Artifact BMC … EMC`. Implementation:
- **`<hr>`**: In the HR handling inside `BlockTag.php` (or wherever the HR line draw is emitted), wrap with `$this->mpdf->writer->write('/Artifact BMC')` / `EMC`.
- **Table borders**: In `_tableWrite()` (Mpdf.php ~line 21912), at each point where `re f` or `l S` path operators are written for cell borders, wrap with Artifact BMC/EMC. Use a helper that emits BMC only when not already inside another BDC/EMC sequence.
- **Block element borders** (`BlockTag.php` block-border drawing): same Artifact wrapping.
- **Page backgrounds**: `BackgroundWriter.php` — wrap entire background painting calls with Artifact BMC/EMC via `$this->writer->write('/Artifact BMC')` at the top of the background paint method and `EMC` at the bottom.

Add test `testDecorativeHrIsArtifact` — page with `<hr>` produces `/Artifact BMC … re … f … EMC` in the page stream.

### High — will cause veraPDF failure on common document patterns

**M-5: Heading level skip not validated (Matterhorn 05-002)**
Add to Phase 5 `ValidationTest.php`: a heading level tracker on `StructureTree`. When `open('H2')` fires after `open('H4')` without an intervening H2/H3, add a `PDFUAwarning`. Track in `$this->lastHeadingLevel` on `StructureTree`. Add test `testSkippedHeadingLevelAddsWarning`.

**M-6: `<th scope>` attribute not mapped (Matterhorn 06-003)**
The plan hardcodes `Scope=Column` for all `<th>` cells. In `Th.php`, read `$attr['SCOPE']` and map:

```php
$scope = 'Column'; // default
if (isset($attr['SCOPE'])) {
    $scopeMap = ['col' => 'Column', 'row' => 'Row', 'colgroup' => 'Column', 'rowgroup' => 'Row'];
    $scope = isset($scopeMap[strtolower($attr['SCOPE'])]) ? $scopeMap[strtolower($attr['SCOPE'])] : 'Column';
}
$structAttrs['Scope'] = $scope;
```

**M-7: Non-standard struct types from HTML tag names (Matterhorn 02-003, 21-005)**
`BlockTag.php` derives struct types via `strtoupper($tag)`. Tags producing non-standard struct type strings must be remapped. Add a lookup in `BlockTag::open()` before the struct type is passed to `structureTree->open()`:

```php
static $htmlToStructType = [
    'BLOCKQUOTE' => 'BlockQuote',
    'PRE'        => 'Code',
    'ADDRESS'    => 'P',
    'SECTION'    => 'Sect',
    'ARTICLE'    => 'Art',
    'FIGURE'     => 'Figure',
    'FIGCAPTION' => 'Caption',
    'NAV'        => 'Sect',
    'ASIDE'      => 'Sect',
    'MAIN'       => 'Div',
    'HEADER'     => 'Div',
    'FOOTER'     => 'Div',
    'DETAILS'    => 'Div',
    'SUMMARY'    => 'Div',
    'MARK'       => 'Span',
    'DEL'        => 'Span',
    'INS'        => 'Span',
    'S'          => 'Span',
    'SMALL'      => 'Span',
    'SUB'        => 'Span',
    'SUP'        => 'Span',
    'Q'          => 'Quote',
    'CAPTION'    => 'Caption',
];
$structType = isset($htmlToStructType[$tag]) ? $htmlToStructType[$tag] : $tag;
```

Any remaining unmapped tags not in this list AND not in the standard PDF struct type set must be added to RoleMap mapping to `Div` or `Span` as appropriate. Add test `testBlockquoteProducesBlockQuoteStructType`.

**M-8: Link annotation `/StructParent` not explicitly specified (Matterhorn 18-004)**
The plan covers OBJR dicts for Link struct elements but does not explicitly state that each link annotation object written in `writeAnnotations()` also needs `/StructParent M` (singular) in the annotation dict. Add to Phase 4 Links section: pre-assign struct parent integers for link annotations in `PageWriter::writePages()` (same mechanism as sticky notes), store on `PageLinks[$n][$k]['structParent']`, emit `/StructParent M` in `writeAnnotations()`. Add test `testLinkAnnotationHasStructParent`.

**M-9: `auto-wrap TBody` for tables without explicit `<tbody>` (Matterhorn 06-002)**
When a `<table>` contains `<tr>` rows directly (no `<thead>`/`<tbody>`/`<tfoot>`), the struct tree must still group them under a `TBody`. Detect in `Table.php` tag handler: track whether a grouping element (`THEAD`, `TBODY`, `TFOOT`) was opened. If the `</table>` close fires without one, the `TR` struct elements must be children of an auto-created `TBody`. Add test `testTableWithoutTbodyAutoWraps`.

**M-10: Phase 3c / Phase 5 inconsistency for missing `alt` attribute (Matterhorn 02-004)**
Phase 3c creates a Figure struct element and sets `['Alt' => '']` even when `alt` is absent. Phase 5 says to throw when `PDFUAauto=false`. The validation must fire BEFORE the struct element is created. Correct ordering in `printobjectbuffer()`:
1. Check `$alt === null` (absent) AND `PDFUAauto=false` → throw immediately, no struct element created.
2. Check `$alt === null` AND `PDFUAauto=true` → warn, treat as Artifact (no struct element; BMC).
3. `$alt === ''` → Artifact BMC (no struct element).
4. `$alt !== null && $alt !== ''` → Figure BDC with Alt.

### Medium — specific features cause veraPDF failures

**M-11: `<footnote>` tag not handled as `Note` struct element (Matterhorn 09-001)**
`src/Tag/Footnote.php` places footnote text at the bottom of the page. Add struct element handling in the tag handler and/or rendering point: `structureTree->open('Note', ['ID' => 'footnote-' . $this->footnoteCounter++])`. Note struct elements require unique `/ID` per Matterhorn 09-002 (already in plan for other Note types). Add test `testFootnoteTagProducesNoteStructElement`.

**M-12: `Lbl` struct element injection point not specified (Matterhorn 07-003)**
The plan mentions `Lbl` for list bullets but gives only a vague reference to `BlockTag ~line 354`. Specify: in `BlockTag.php`, when rendering the bullet/number string for an `<li>` element, the bullet `Cell()` call must be preceded by `structureTree->open('Lbl')` + MCID assignment + `$this->ua->getMarkedContentHelper()->begin('Lbl', $mcid)`, and followed by `$this->ua->getMarkedContentHelper()->end()` + `structureTree->close()`. The remaining `<li>` content flows into `LBody`. This is the most granular injection in the list tagging path.

**M-13: Embedded file attachment accessibility (Matterhorn 11-001, 11-002, 18-001, 18-002)**
File attachment annotations (from `<annotation>` tags with file type) need:
- `/Contents` key with the file description (for 18-001)
- `/F 28` print flag (for 18-002) — extend the existing condition in `writeAnnotations()`
- Warning logged when a PDF file is attached (cannot verify its PDF/UA conformance — 11-001)
- `/Subtype` (MIME type) key on the embedded file spec dict (11-002)

**M-14: JavaScript warning (Matterhorn 19-001)**
In `JavaScriptWriter.php` (or wherever JS is accepted for embedding), add:
```php
if ($this->mpdf->PDFUA) {
    $this->ua->addWarning('JavaScript embedded in PDF/UA-1 document may alter content or structure — verify conformance manually.');
}
```

### Matterhorn compliance status summary

| Status | Count |
|---|---|
| COVERED | 38 |
| PARTIAL → now addressed in M-6 to M-14 above | 17 |
| MISSING → now addressed in M-1 to M-5 above | 8 |
| N/A (mPDF does not produce these features) | 18 |

After implementing all items above, all MISSING and high-priority PARTIAL items are resolved. Remaining PARTIAL items (column content as Artifact, ligature ActualText, BCP 47 validation) are either architectural limitations or low veraPDF-failure-risk items documented in this plan.

---

## Verification

```bash
# Must be green before Phase 1 begins and after every phase
composer test
vendor/bin/phpunit --group=snapshot        # requires imagick + ghostscript; diffs written to tmp/artifacts/

# Quick metadata check after Phase 1
php -r "
\$mpdf = new Mpdf\Mpdf(['title'=>'Test','PDFUA'=>true]);
\$mpdf->WriteHTML('<h1>Hello</h1><p>World</p>');
echo \$mpdf->Output('', 'S');
" | grep -a 'pdfuaid\|MarkInfo\|DisplayDocTitle\|StructParents'

# Validate with veraPDF (requires Java)
verapdf --flavour ua1 output.pdf

# PHPStan — add any new violations to phpstan-baseline.neon
vendor/bin/phpstan analyse
```

---

## Appendix: Implementation Details and Matterhorn Requirements

The items below capture ISO 14289-1 / Matterhorn Protocol 1.1 / PDF 32000-1 details that span multiple phases or that would otherwise be easy to miss. Each is a hard requirement for veraPDF `--flavour ua1` conformance.

---

### A1. `/Alt`, `/ActualText`, `/Lang` are direct StructElem dict keys — not inside `/A`

**Placement per ISO 32000-1 §14.7.2 Table 323**:
- **Direct StructElem keys** (written on the struct element dict itself, not inside `/A`): `/Alt`, `/ActualText`, `/E`, `/Lang`, `/T`, `/ID`, `/Pg`, `/K`, `/P`, `/S`, `/A`, `/C`, `/R`
- **Attribute objects** (inside `/A` with appropriate owner `/O`):
  - `/O /Table` (Table 349): `/Scope`, `/ColSpan`, `/RowSpan`, `/Headers`, `/Summary`
  - `/O /List` (Table 348): `/ListNumbering`
  - `/O /Layout` (Table 344): `/Placement`, `/BBox`, `/WritingMode`, etc.

When multiple attribute owners are needed, `/A` is an **array** of attribute dicts, not a single merged dict.

**Fix in StructureWriter**: Emit `/Alt`, `/ActualText`, `/Lang` as direct keys alongside `/S` and `/P`. Emit `/Scope`, `/ColSpan`, `/RowSpan` inside `<</O /Table ...>>`. Do not mix owners.

---

### A2. `/Headers` attribute on `TD` cells (Matterhorn 09-004/09-005)

Not currently in the plan. For tables with complex header associations (spanning headers, irregular layouts), each `TD` struct element must carry `/Headers` — an array of references to `/ID` values on the associated `TH` struct elements. This is required when `/Scope` alone cannot unambiguously associate data cells with headers.

**Required additions**:
- Assign unique `/ID` strings to all `TH` struct elements during `Th.php` tag handling
- In `Td.php`, when a cell has a `headers` HTML attribute, resolve the referenced IDs and write `/A <</O /Table /Headers [...]>>` on the `TD` struct element

Add test `testTdHeadersAttributeMapsToStructElement`.

---

### A3. Widget annotations — `/TU` tooltip and `/Form` struct element (Matterhorn 11-002)

The plan gestures at Widget handling but does not fully specify:
- Every Widget annotation must carry `/TU` (tooltip text, ISO 32000-1 Table 227) — Matterhorn 11-002 fires when absent
- Every Widget must have a corresponding `/Form` struct element (ISO 32000-1 §14.8.4.4 Table 335) referencing the annotation via an OBJR dict `<</Type /OBJR /Obj N 0 R>>`
- The Widget annotation dict must carry `/StructParent M` (singular) pointing into ParentTree

Add to the Phase 4 form field section: validate `/TU` is populated (fall back to `<label>` element text when available), synthesise `/Form` struct element per widget.

---

### A4. Figure struct element `/BBox` (Matterhorn 13-008)

Per ISO 32000-1 Table 344, `/BBox` is **required** for any Figure appearing in its entirety on a single page. `/Placement` is a separate optional attribute (default `Inline`) — it is NOT required alongside `/BBox`.

```php
// In the struct element attribute object for Figure (Layout owner):
'/A <</O /Layout /BBox [' . $llx . ' ' . $lly . ' ' . ($llx + $w) . ' ' . ($lly + $h) . '>>'
// /Placement is optional — omit unless explicitly overriding the Inline default
```

Coordinates are in default user space units. `Image()` already computes `$x`, `$y`, `$w`, `$h` — use these. BBox array is `[x, y, x+w, y+h]`.

Add test `testFigureStructElementHasBBox`.

---

### A5. XMP stream encryption bypass — Identity crypt filter required (U5)

Confirmed by PDF 32000-1:2008 §7.6.5 review. Simply skipping RC4 without declaring the Identity crypt filter is incorrect — a conforming reader will attempt to decrypt any stream in an encrypted file unless the stream dict explicitly carries `/Filter [/Crypt] /DecodeParms << /Type /CryptFilterDecodeParms /Name /Identity >>`. This has been corrected in the Constraint 2 / Phase 3 section above. Both the stream dict declaration AND the `$encrypt=false` path in `BaseWriter::stream()` are required together.

---

### A6. ActualText for ligatures (Matterhorn 24-001) — Phase 5

Matterhorn 24-001 fires when a glyph resulting from OpenType ligature substitution has no corresponding 1:1 Unicode entry in the font's `ToUnicode` CMap. mPDF's `Otl` class substitutes ligature glyphs; the resulting CID codes may not decode 1:1 to Unicode — the classic case is `fi` (U+0066 U+0069) → single glyph CID.

**Implementation (in-scope)** — add `src/Ua/LigatureActualTextWriter.php` in Phase 5:

- **Otl instrumentation**: extend `Otl` so that every substitution it performs is appended to a per-run log keyed by the output glyph cluster: `[outputGlyphId => originalUnicodeCharacters]`. The log is stored on the `$GPOSinfo` / `$useOTL` metadata that `Otl` already attaches to the text buffer entry.
- **Emit-time wrapping**: `Cell()` and `finishFlowingBlock()` check the log for the current run. When a glyph cluster has more than one source character (ligature), the run is split and each ligature sub-run is wrapped via `$this->ua->getMarkedContentHelper()`:
  ```
  /Span <</ActualText <FEFF 00 66 00 69>>> BDC
    BT /F1 12 Tf 100 700 Td <1ED> Tj ET   % fi glyph
  EMC
  ```
  The `ActualText` string is UTF-16BE with a BOM (`FEFF`), hex-encoded.
- **Multi-character mapping**: for glyph clusters covering more than one source character (`ffi`, `ffl`, Devanagari conjuncts, Arabic ligatures), `ActualText` holds the full original UTF-16BE sequence.
- **Skip when ToUnicode covers it**: `FontWriter::writeFonts()` emits `/ToUnicode` CMaps. The `LigatureActualTextWriter` first checks the emitted CMap — if the glyph-cluster → original-string mapping is already covered (`beginbfchar` entry present with the multi-char `dstString`), skip the ActualText wrapper. Many fonts already include this; the wrapper is only needed as a fallback.
- **StructureElement support**: `StructureElement::$attributes['ActualText']` already exists (used by `textcircle` elsewhere in the plan). The Phase 5 writer reuses the same attribute emission path.

**Tests** — add to `tests/Mpdf/Ua/LigatureActualTextTest.php`:

| Test | Assertion |
|---|---|
| `testFiLigatureProducesActualText` | Document with `"find"` shaped with a font that ligates `fi` produces a `/Span <</ActualText <FEFF00660069>>>` wrapper around the ligature glyph in the page content stream |
| `testFfiLigatureProducesActualText` | `"office"` shaped with `ffi` ligature produces a wrapper containing the three-char UTF-16BE sequence |
| `testToUnicodeCoveredSkipsWrapper` | When the font's `/ToUnicode` CMap already maps the ligature glyph to multi-char `dstString`, the plan does NOT emit a redundant ActualText wrapper (tested by counting wrappers) |
| `testNoLigatureNoWrapper` | Plain ASCII text with no OTL substitution produces zero ActualText wrappers |

**Integration in Phase 5 completion gate**: veraPDF `--flavour ua1` must pass on a document containing `<p style="font-family: DejaVuSerif;">fine office difficulty</p>` (DejaVuSerif ligates `fi`, `ffi`, `ffl`).

---

### A7. FPDI imported pages — policy now implemented by `FpdiStructMerger`

Matterhorn 01-007 (a PDF claiming PDF/UA-1 conformance must not contain untagged real content) is satisfied by the two-tier treatment in the §"Imported PDFs via FPDI" section:

- **Tagged source**: `\Mpdf\Ua\Import\FpdiStructMerger` (Phase 4) reads the source catalog's `/StructTreeRoot`, queues the struct subtree for the imported page onto FPDI's `objectsToCopy` (so FPDI's existing `writePdfType()` rewriter at `src/FpdiTrait.php:344–395` handles object-number remapping), rewrites MCR `/Pg` to the host page and adds `/Stm` pointing at the Form XObject. `SetPageTemplate()` reuse on N host pages emits N MCR kids under a single struct subtree.
- **Untagged source**: the `Do` is wrapped as `/Artifact <</Type /Layout>> BDC … EMC` and a warning is appended to `$this->ua->getWarnings()` via `->addWarning()`.

Both paths are in-scope in Phase 4; no future work or plan gaps remain here.

---

### A8. `/Tabs /S` — standard scope clarification (U1)

ISO 14289-1 §7.18.3 strictly requires `/Tabs /S` only on pages **containing annotations**. mPDF will emit it unconditionally on all pages — this is safe and defensive but not technically required on annotation-free pages. Update the plan rationale: "emitted unconditionally to avoid per-page annotation-presence checks; veraPDF accepts it on all pages."

---

### A9. Test coverage gaps — priority additions

The following tests are missing and should be added to the respective phase gate test tables:

| Test | Phase | Matterhorn |
|---|---|---|
| `testNonPdfuaOutputHasNoMarkInfo` | 1 | — |
| `testPdfuaPdfaCoexistenceXmp` | 1 | — |
| `testParagraphSplitAcrossPageBreakMcrDicts` | 4 | 01-006 |
| `testTableRowAcrossPageBreakMcrDicts` | 4 | 01-006 |
| `testBdcEmcBalanceWithOcgLayers` | 4 | — |
| `testTdHeadersAttributeMapsToStructElement` | 4 | 09-004 |
| `testWidgetAnnotationHasTuEntry` | 4 | 11-002 |
| `testFigureStructElementHasBBox` | 4 | 13-008 |
| `testHeaderContentIsArtifactWrapped` | 3 | 17-001 |
| `testFooterContentIsArtifactWrapped` | 3 | 17-001 |
| `testEncryptedOutputHasXmpNotEncrypted` | 3 | — |
| `testLiStructureHasLblAndLBody` | 4 | 21-001 |
| `testJavaScriptEmbedAddsWarning` | 5 | 19-001 |
| `testFontSubsetToUnicodeCoverage` | 5 | 24-001 |

### A10. `@group pdfua` annotation strategy

New PDFUA test files generate full PDFs per test — this will meaningfully slow down `composer test`. Explicitly tag all PDFUA test classes with `@group pdfua` so they can be run selectively. They are NOT excluded from the default `composer test` run (unlike `@group snapshot`), but the `@group` annotation allows `vendor/bin/phpunit --group=pdfua` for isolated runs during development.

### A11. `src/Tag/Abbr.php` does not exist — promote to required task

The plan says "verify `src/Tag/Abbr.php` exists." It does not. Create it (mirroring `src/Tag/Acronym.php`) and register dispatch in `Tag.php`. This is required for `<abbr>` → `Span` + `/E` (expansion text) struct element support.

### A12. `StructureWriter` service registration

Under the `UaState` facade (D10), `ServiceFactory` no longer registers `markedContentHelper`, `structureTree`, `structureWriter` as top-level services — it registers a single `'uaState'` service that references all of them. `ResourceWriter::writeResources()` reaches the struct writer via `$this->ua->getStructureWriter()->writeStructTree()` — no new constructor parameter needed on `ResourceWriter` because it already receives `$mpdf`.

---

### A13. `/ParentTreeNextKey` required on StructTreeRoot (ISO 32000-1 Table 322)

ISO 32000-1 Table 322 lists `/ParentTreeNextKey` as a required entry on the StructTreeRoot dict: "An integer greater than any key in the parent tree, to be used as a key for the next entry added to the tree." `StructureWriter::writeStructTree()` must emit this after all ParentTree entries are written:

```php
$this->writer->write('/ParentTreeNextKey ' . $this->ua->getStructParentsCounter());
```

Since `nextStructParents()` increments the counter and returns the previous value, `$this->ua->getStructParentsCounter()` after all pages and Form XObjects are processed equals one more than the highest key used — exactly the value required.

---

### A14. MCR dict `/Stm` and `/Pg` may coexist (ISO 32000-1 Table 324)

The plan implies `/Stm` replaces `/Pg` for Form XObject content items. The spec says both can appear in the same MCR dict: `/Pg` identifies the page the XObject is rendered on; `/Stm` identifies the Form XObject content stream. For SVG internal text MCR dicts, include both:

```php
'/Type /MCR /Pg ' . $pageObjNum . ' 0 R /Stm ' . $svgXObjectNum . ' 0 R /MCID ' . $mcid
```

This gives readers full context: which page the visual content appears on, and which content stream contains the marked operators.

---

### A15. `BlockQuote` is a grouping element — cannot contain direct text (ISO 32000-1 Table 333)

`BlockQuote` is listed in Table 333 as a **grouping element** (like `Sect`, `Div`, `Art`), not a block-level structure element (BLSE, Table 334). Grouping elements must contain BLSEs, not direct text content items. A `<blockquote>` in HTML typically wraps block-level content such as `<p>` elements, which maps correctly to `BlockQuote > P`. The `BlockTag.php` handler must ensure that when it opens a `BlockQuote` struct element, it does NOT assign MCIDs directly to it — MCIDs go to the `P` child elements rendered inside the blockquote.

Similarly, `Art` (Article, Table 333) should be disjoint — the spec says "Articles should be disjoint; that is, they should not contain other articles as constituent elements." Nested `<article>` elements that map to `Art > Art` violate this constraint. Consider mapping nested articles to `Div` or using RoleMap.
