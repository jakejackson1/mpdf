---
name: PDF/UA-1 and Tagged PDF Requirements
description: Synthesized requirements from ISO 14289-1:2014 (PDF/UA-1) and the PDF Association's Tagged PDF Best Practice Guide (v1.0.1, 2023). Reference when implementing or auditing accessibility features in mPDF.
type: project
---

Sources:
- **ISO 14289-1:2014** — the normative PDF/UA-1 standard (25 pages)
- **Tagged PDF Best Practice Guide: Syntax v1.0.1** (PDF Association, 2023, CC-BY-4.0, 72 pages) — developer-focused implementation guidance

**Why:** mPDF has `$PDFA` / `$PDFX` support already. PDF/UA is the next compliance tier. These notes capture what conformance actually requires at the PDF object level — the non-obvious parts that go beyond "just tag things".

**How to apply:** Use when auditing mPDF's PDF output for accessibility, implementing a `$PDFUA` mode, or reviewing tag/structure output in the Writer layer.

---

## Core Principle (PDF/UA-1 §7.1)

> "Content shall be marked in the structure tree with semantically appropriate tags in a logical reading order."

- **Real content** = all graphics objects in page content with semantic significance → must be tagged
- **Artifacts** = layout byproducts (page numbers, decorative rules, running headers/footers) → must NOT be in structure tree; marked with `/Artifact` property
- Non-standard structure types are allowed but must be role-mapped to the nearest standard type via the RoleMap dictionary. Standard types must not be remapped.

---

## Document-Level Requirements

| Requirement | PDF key / location | Notes |
|---|---|---|
| PDF/UA version flag | XMP metadata stream on Catalog | `pdfuaid:part = 1` (integer); namespace `http://www.aiim.org/pdfua/ns/id/` |
| Document title | XMP `dc:title` | Required; must clearly identify document |
| Display title (not filename) | `ViewerPreferences >> DisplayDocTitle = true` | Key must be present |
| Document language | `Catalog >> Lang` | Not explicitly required but implicitly needed for metadata/outline language |
| `Suspects = false` | `MarkInfo` dict | Required |

---

## Text Requirements (§7.2)

- All character codes **must map to Unicode** (`ToUnicode` CMap or predefined encodings). Exceptions: MacRomanEncoding, MacExpertEncoding, WinAnsiEncoding, or Adobe standard CJK collections.
- Unicode values in `ToUnicode` must be > 0 and ≠ U+FEFF, U+FFFE.
- Natural language must be declared (`Lang` attribute on structure elements or content streams). Language changes must be declared.
- Stretchable characters (parentheses built from multiple glyphs) must use `ActualText`.
- `.notdef` glyph must **never** be referenced from text-showing operators in any content stream.

---

## Font Requirements (§7.21) — Most Stringent in the Spec

- **All fonts used for rendering must be embedded** — no exceptions, including the 14 standard Type 1 fonts.
- Font programs legally embeddable for "unlimited, universal rendering" only — no restricted-license fonts.
- All fonts (including text-rendering-mode-3 invisible fonts) must have a `ToUnicode` entry.
- Glyph width information in font dictionary and embedded font program must be consistent (≤ 1/1000 unit difference).
- Subset fonts: `CharSet` (Type 1) and `CIDSet` (CIDFont) must list **all** glyphs present in the font program, not just those used.
- Composite fonts (Type 0): CIDToGIDMap required on embedded Type 2 CIDFonts; all CMaps except standard ones must be embedded; no CMap may reference another CMap except standard ones.
- Non-symbolic TrueType fonts: must have `MacRomanEncoding` or `WinAnsiEncoding` in `Encoding` key; `Differences` array only if all glyph names in Adobe Glyph List and MS Unicode cmap (3,1) present.
- Symbolic TrueType fonts: no `Encoding` entry; cmap must have exactly one encoding or MS Symbol (3,0).

---

## Structure Types — Creation & Consumption Rules

### Document-level grouping
- `<Document>` → root of structure tree
- `<Part>` → sub-divides large documents; `<Art>` → article; `<Sect>` → section; `<Div>` → division (no semantics, used with `Lang` attribute for language changes)

### Block elements
- `<P>` — most common; do not enclose multiple paragraphs in one `<P>`; do not directly nest `<P>` elements
- `<H1>`–`<H6>` — must not skip levels when descending (H1→H3 is invalid); may repeat same level (H2 H2 is fine); can go above H6 (H7+ allowed, must be role-mapped to `<P>` or `<H6>`)
- `<H>` (strongly-structured heading) — avoid in practice; tools don't support it well
- `<Lbl>` — labels other content (bullets, list numbers, footnote markers, figure labels)
- `<BlockQuote>` — block-level quotes; `<Quote>` — inline quotes within `<P>`

### Lists (§7.6 / §4.2.5)
```
<L ListNumbering="Decimal">   ← ordered lists MUST have ListNumbering attribute
  <Caption>                    ← optional; must be first child if present
  <LI>
    <Lbl>  ← bullet/number
    <LBody>  ← content (including nested <L>)
```
- `<Lbl>` and `<LBody>` are optional per spec but semantically required for proper AT rendering.
- Processors should accept real content as direct child of `<LI>` (treat as if wrapped in `<LBody>`).

### Tables (§7.5 / §4.2.6)
```
<Table>
  <THead>
    <TR><TH Scope="Column" ID="h1"><TH Scope="Column" ID="h2">
  <TBody>
    <TR><TD><TD>
    <TR><TD ColSpan="2">
  <TFoot>
    <TR><TD><TD>
```
- `<TH>` **should** have `Scope` attribute; **must** have it when Header/ID association isn't determinable.
- Empty cells are always `<TD>`, never `<TH>`.
- Repeated header rows in multi-page tables: mark repeated headers as **Artifacts**.
- Multi-page tables: single `<Table>` structure element spanning pages.
- Table cells always part of semantic structures (no table-as-layout).
- Spanning cells must have ColSpan/RowSpan attributes.

### Figures & Illustrations (§7.3 / §4.3.1)
```
<Figure Alt="Description of image">   ← Alt REQUIRED
  {image content}
<Caption>
  <P>{caption text}
```
- `Alt` attribute is mandatory on `<Figure>`. If decorative/non-meaningful → Artifact.
- `<Caption>` recommended after `<Figure>` as sibling (not child in PDF 1.7 — no mechanism to associate).
- Groups of semantically linked graphics → single `<Figure>`.
- `<Formula>` → requires `Alt` attribute; encloses equations/formulas (inline or block).

### Notes & References (§7.9 / §4.2.8, §4.2.9)
- Footnotes/endnotes: `<Note>` tag with **unique `ID` key** (required by PDF/UA).
- Reference in text: `<Reference><Lbl>1)</Lbl></Reference>` matching the `<Lbl>` in the `<Note>`.
- Links for footnote navigation (Reference → Note, Note → Reference) not required but strongly recommended.
- `<Note>` only for footnotes/endnotes — NOT for supplementary "Note:" paragraphs in running text.

### Links (§7.18.5 / §4.2.12)
- Link annotations must be in structure tree as `<Link>` elements.
- `Contents` key on link annotation is **required by PDF/UA-1** (alternate description).
- `Tabs = S` must be on every page dictionary that has an annotation.
- QuadPoints recommended for multi-line links.
- `IsMap = true` in URI action dict → forbidden unless equivalent functionality provided elsewhere.

### Forms (§7.18.4)
- Widget annotations nested inside `<Form>` structure element.
- Widget annotations must have alternate description (`Contents` key or `TU` key).

### Headers & Footers (§7.8)
- Running headers/footers: `Pagination` artifact, subtype `Header` or `Footer`.
- Page numbers: `Pagination` artifact (not `Header`/`Footer` — just general `Pagination`).
- Page Labels number tree (ISO 32000-1, 12.4.2): values should match visible page numbers.

### TOC (§4.1.4)
```
<TOC>
  <TOCI>
    <P>
      <Reference>
        <Link>
          <Link-OBJR>
          <Lbl>{chapter number}
          <Span>{title ... page}
```
- Dot leaders: mark as Artifact.
- `<Link>` inside `<TOCI>`: set `Placement=Block` (to avoid inline-containing-block violation).

---

## Annotations (§7.18)

- Exempt: hidden flag set, rectangle outside CropBox, Subtype=Popup.
- All others: must be in structure tree in reading order.
- `TrapNet` annotation subtype → forbidden.
- `PrinterMark` annotations → Incidental Artifacts.
- Media annotations: must not auto-play; controls must be accessible.
- Every page with an annotation: `Tabs = S` in page dictionary.

---

## Security (§7.16)

- Encrypted files: `P` key in encryption dict; **bit 10 of P must be `true`** (enables AT access to content).
- Should not encrypt in a way that prohibits AT from accessing content.

---

## Navigation (§7.17)

- Document outline (bookmarks) **should** be present, matching reading order and heading levels.
- Page Labels entries should be semantically appropriate.

---

## Optional Content (§7.10)

- All optional content config dicts with a `Configs` entry containing at least one dict: must have non-empty `Name` entry.
- `AS` key **must not** appear in any optional content config dict (prevents automatic state changes).
- Font requirements (§7.21) apply to all fonts in optional content even if not rendered.

---

## Content Spanning Pages

- A paragraph spanning pages = **single `<P>` structure element** with two marked-content sequences (one per page), both linked to the same `<P>` via MCIDs.
- Structure tree is agnostic to pagination.

---

## Empty Structure Elements

Permitted to be empty:
- `<TD>` (maintain table structure)
- `<LI>` (maintain list structure)
- `<Span>` (ActualText for whitespace; attribute containers)
- `<Div>` (metadata/attributes)
- `<Document>` (single-page doc with no content)
- `<NonStruct>` and `<Private>`

All other types: empty elements are semantically inappropriate (but processors should handle them anyway).

`<Private>` — children are also ignored by processors (unlike `<NonStruct>` whose children have significance).

---

## Role Maps

- Custom structure types must be mapped via `RoleMap` in structure tree root.
- Mapping may be indirect (custom → custom → standard) but must eventually resolve to a standard type.
- Standard types **must not** be remapped.
- `<H7>` and above: map to `<P>` or `<H6>` depending on whether heading semantics or level accuracy matters more.

---

## Superscripts, Subscripts, Special Characters (§6)

- Use `ActualText` to provide semantic representation when glyphs don't map to Unicode adequately.
- Symbolic/logo text that isn't natural language: provide `Alt` describing nature/purpose.
- Stretchable characters (brackets from multiple glyphs): use `ActualText`.
- PUA (Private Use Area) Unicode mappings: permitted when no Unicode exists, but use `Alt`/`ActualText` to preserve semantics.

---

## Conformance Identification

XMP metadata on the Catalog's Metadata stream must contain:
```xml
<rdf:Description rdf:about=""
  xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/">
  <pdfuaid:part>1</pdfuaid:part>
</rdf:Description>
```
Also required: `dc:title` in XMP, `ViewerPreferences/DisplayDocTitle=true`.

---

## Key Differences from PDF/A

- PDF/A requires font embedding and metadata — PDF/UA adds structural tagging, reading order, and AT requirements on top.
- PDF/UA does **not** restrict color spaces or transparency (unlike PDF/X).
- A file can be both PDF/A-2 and PDF/UA-1 simultaneously.
- PDF/UA-2 (based on PDF 2.0 / ISO 32000-2) exists but this guide covers PDF/UA-1 only.

---

## Validation Quick Reference

A PDF/UA-1 conforming file must have ALL of:
1. XMP `pdfuaid:part=1`
2. XMP `dc:title`
3. `ViewerPreferences/DisplayDocTitle=true`
4. `MarkInfo/Marked=true`
5. `MarkInfo/Suspects=false`
6. All real content tagged (structure tree)
7. All artifacts marked as Artifact (not in structure tree)
8. Logical reading order via structure tree
9. All fonts embedded with `ToUnicode`
10. No `.notdef` glyph references
11. `Tabs=S` on every page with annotations
12. Language declared on all content
13. `Alt` on every `<Figure>`
14. `Alt` on every `<Formula>`
15. `Contents` key on link annotations
16. `<Note>` elements have unique IDs
17. Ordered `<L>` elements have `ListNumbering` attribute
18. `P` encryption key bit 10 = true (if encrypted)
