# PDF/UA-1 — Fix HIGH-1 through HIGH-4

Branch: `ua1-feature`. Today: 2026-04-30.

Four real correctness bugs surfaced by the multi-agent review. None fail the
veraPDF gate on the existing 41 fixtures, but each will silently corrupt
accessibility (or hard-crash) for documents that exercise the affected feature.
Estimated total effort: 4–6 hours including tests.

## Bug summary

| ID | Location | Defect | Severity |
| --- | --- | --- | --- |
| HIGH-1 | `StructureWriter.php:248` ↔ `StructureWriter.php:407` | `/ID` written as UTF-16BE text string `(\xFE\xFF...)`; `/Headers` references written as PDF names `/{id}`. Bytes differ — readers can't resolve TD `/Headers` to TH `/ID`. | Silent AT failure on every document using `<td headers=…>` |
| HIGH-2 | `BlockTag.php:960` | `'doc-title' => 'Title'`. `Title` is not a valid ISO 32000-1 §14.8 struct type → `StructType::isValid()` rejects it → `StructureTree::open()` throws `MpdfException`. | Hard crash on `role="doc-title"` |
| HIGH-3 | `FpdiStructMerger.php:578-590` | Imported `/Alt`, `/ActualText`, `/Lang` byte strings (often already UTF-16BE-with-BOM) are stored verbatim, then `StructureWriter` runs them through `utf16BigEndianTextString()` which prepends a second BOM and re-encodes as if UTF-8. Garbage Alt/ActualText on every imported tagged page with non-ASCII text. | Silent corruption |
| HIGH-4 | `Th.php:71-77` | (a) HTML `id` value is used verbatim as the `/ID` value — characters illegal in PDF names (`(`, `)`, `<`, `>`, `[`, `]`, `{`, `}`, `/`, `%`, `#`, whitespace, NUL) produce malformed `/Headers` names; (b) synthetic `th-{tableLevel}-{row}-{col}` collides between two tables at the same nesting level on the same page. | Malformed PDF + silent header collision |

## Strategy

A central ID-byte-equivalence requirement runs through HIGH-1 and HIGH-4: the
bytes emitted as `/ID (...)` on a TH must match the bytes emitted as a name in
the corresponding `/Headers [/...]` array on a TD. Both bugs share a fix
(sanitise-once, emit-byte-equivalent-everywhere). HIGH-2 and HIGH-3 are
narrower point fixes.

### Sanitisation helper

Introduce `StructureElement::sanitiseIdForPdf($id)`:

- Whitelist `[A-Za-z0-9_.-]` kept literal.
- Every other byte emitted as `#xx` (PDF name #-escape, ISO 32000-1 §7.3.5).
- Result is byte-equivalent in `(...)` byte-string and `/...` name-object
  serialisations because all whitelisted chars and `#`-escapes lie in the
  PDF name unrestricted-character range.

This is applied to:

- `Th::setId()` source (HTML `id` or synthetic) before `StructureElement::setId()`.
- Each token of `headers="..."` in `Td.php` before storing in `$tdAttrs['Headers']`.

### Fix details

1. **HIGH-1** — `StructureWriter::writeStructElement()` line 248: change from
   `utf16BigEndianTextString()` to a paren-delimited byte string (`'(' . $this->writer->escape($id) . ')'`).
   Since IDs are now sanitised to ASCII-safe charset, no escaping is needed
   for parens/backslashes, but `escape()` is harmless on ASCII-safe input.

2. **HIGH-2** — `BlockTag.php:960`: change `'doc-title' => 'Title'` to
   `'doc-title' => 'H1'`. A doc-title is the document's primary heading;
   mapping to `H1` is semantically correct and works with the heading-sequence
   enforcement (a `role="doc-title"` followed by `<h2>` is a valid descent).

3. **HIGH-3** — `FpdiStructMerger::cloneElement()` lines 578-590: introduce a
   private `decodeImportedTextString($raw)` helper that:
   - Detects `\xFE\xFF` BOM prefix → strips BOM and converts UTF-16BE → UTF-8.
   - Detects `\xFF\xFE` BOM prefix → UTF-16LE → UTF-8 (rare but legal).
   - Otherwise treats input as PDFDocEncoding-subset (ASCII passthrough).
   Then `$hostElem->setAttribute($attrKey, $this->decodeImportedTextString(...))`.

4. **HIGH-4** —
   - `Th.php:75`: replace synthetic prefix to include a global counter from
     `AriaIdResolver::nextSyntheticThCounter()` (added). Format:
     `'th-' . $tableLevel . '-' . $row . '-' . $col . '-' . $counter`.
   - `Th.php:77`: pass through `StructureElement::sanitiseIdForPdf()`.
   - `Td.php:427-429`: each token of `$ids = preg_split(...)` runs through
     `StructureElement::sanitiseIdForPdf()` before being stored.

### Tests to add

All extend `BaseMpdfTest` / `PdfUaTestCase`.

1. `tests/Mpdf/Ua/HeadersIntegrityTest.php` — generate a PDF with
   `<th id="abc">…</th><td headers="abc">…</td>`, parse the output bytes,
   assert the `/ID` byte content equals the `/Headers` name byte content for
   that pair.
2. `tests/Mpdf/Ua/HeaderIdSanitisationTest.php` — feed `id="bad id with(spaces)"`
   on a TH plus the matching `headers="bad id with(spaces)"` on a TD. Assert
   both serialise to the same `#xx`-escaped byte sequence.
3. `tests/Mpdf/Ua/AriaRoleDocTitleTest.php` — render `<div role="doc-title">…`
   and assert (a) no exception, (b) the produced struct element type is `H1`.
4. `tests/Mpdf/Ua/MultiTableThIdUniquenessTest.php` — render two tables on the
   same page with no explicit `id` on TH cells. Parse the output, assert no
   two TH struct elements share an `/ID`.
5. `tests/Mpdf/Ua/Import/FpdiTaggedAltUtf16Test.php` — build a tagged PDF
   with `/Alt (\xFE\xFF…)` containing non-ASCII text, import it, assert the
   re-emitted `/Alt` decodes back to the original UTF-8 string. (Synthesise
   the source PDF in the test if no fixture exists.)

### Out of scope for this plan

- Spec gaps S1–S5 (`/IDTree`, `/ListNumbering`, `Note` `/ID`, image-bullet
  `/Lbl`, `H7+` direct API).
- Architecture refactors (UaTagController extraction, UaState rename).
- Test fragility tightening (regex `font dict` count, exact warning counts).

These are tracked separately and do not block merge.

## Validation

After each fix:

```
vendor/bin/phpunit
vendor/bin/phpcs src/Ua src/Tag/Th.php src/Tag/Td.php src/Tag/BlockTag.php \
    src/Writer src/Ua/Import
vendor/bin/phpstan analyse
VERAPDF_BIN=/opt/homebrew/bin/verapdf vendor/bin/phpunit --group=verapdf
```

All four gates must remain green.
