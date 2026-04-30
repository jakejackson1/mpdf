# PDF/UA-1 — Legacy form tagging when `useActiveForms=false`

**Date:** 2026-04-30
**Branch:** `ua1-feature`
**Status:** Plan (not yet executed)
**Author:** review of HIGH-5 fix in commit-pending state
**Supersedes:** the HIGH-5 throw/auto-flip workaround in `Mpdf.php::__construct`

---

## 1. Background

`useActiveForms=false` (the mPDF default) renders `<input>`, `<textarea>`, `<select>`, and `<button>` as drawn chrome — rectangles, text in `Cell()`, glyphs from ZapfDingbats — emitted directly to the page content stream. There is no AcroForm widget annotation behind any of it; the visual is inert (a reader cannot fill it in).

`useActiveForms=true` swaps that path for `SetFormText()` / `SetFormChoice()` / `SetCheckBox()` / `SetRadio()` etc., which emit AcroForm widget annotations the reader treats as live. The PDFUA-aware tagging code already in `src/Form.php` (lines 813, 1456, 1464, 1749, 1796, 1858, 1866) lives **inside** the `useActiveForms=true` branches, so it never fires when the legacy chrome path is used.

### Current (HIGH-5) behaviour

Constructor throws when `PDFUA=true` + `useActiveForms=false` + `PDFUAauto=false`; auto-flips `useActiveForms=true` (with a warning) under `PDFUAauto`. Effectively forbids the combination. Works, but takes a configuration option away from the user.

### What veraPDF flags

When PDFUA + drawn chrome is allowed through, veraPDF rule **7.1#3 (untagged real content)** fires because the content stream contains text-rendering operators (`Tj`, `Cell`, `Rect`-bounded fill) outside any BMC/EMC bracket and outside any structure-bound MCID. ISO 14289-1:2014 §7.1: *“All content shall be marked as either real content or as an artifact.”*

---

## 2. Goal

Make `PDFUA=true` + `useActiveForms=false` a **first-class, conformant combination** by marking every byte the legacy form path emits as an *artifact*. The drawn chrome is decorative (it is not interactive), so artifact wrapping is the spec-correct treatment — ISO 32000-1 §14.8.2.2 explicitly excludes artifacts from logical structure.

The rendered PDF will:
- still pass veraPDF (rule 7.1#3 satisfied because the chrome is artifact-bracketed)
- not advertise any interactive form to the reader
- carry no Form / Annot / OBJR struct kids for the legacy widgets

The HIGH-5 guard becomes obsolete and is removed.

---

## 3. Design

### 3a. The wrapping primitive

`Mpdf\Ua\StructureTree::openArtifact()` and `closeArtifact()` already exist (`src/Ua/StructureTree.php:368, 381`). They emit `/Artifact BMC` and `EMC` and increment/decrement an artifact-depth counter. `isInArtifact()` (line 176) lets nested code skip its own artifact-emission to avoid double-bracketing.

No new infrastructure is needed; the work is wiring `openArtifact()` / `closeArtifact()` around each legacy drawing branch.

### 3b. The wrapping points

Every `else` branch of `if ($this->mpdf->useActiveForms)` in `src/Form.php`:

| Method | Lines (approx) | What it draws |
|---|---|---|
| `print_ob_text` | 226–267 | `<input type=text\|password>` rectangle + masked text |
| `print_ob_textarea` | 337–end | `<textarea>` rectangle + clipped multiline text |
| `print_ob_select` | line ~379 onward | `<select>` rectangle + caret glyph + chosen option |
| `print_ob_radio` | line ~489 onward | radio circle + filled dot |
| `print_ob_checkbox` | line ~513 onward | checkbox square + tick glyph |
| `print_ob_button` | line ~586 onward | button rectangle + caption |
| `print_ob_image_button` | line ~638 onward | image-as-button rectangle + image |

In each `else` branch:
1. If `$this->mpdf->PDFUA` and not already inside an artifact, call `openArtifact()` at the top.
2. Match it with `closeArtifact()` at the bottom (including each early-return path — there are very few).
3. Wrap any inner state save/restore (Cell, Rect, q/Q) **inside** the artifact bracket.

### 3c. The HTML-side path

`<label>` and `<button>`-as-text are NOT drawn-form chrome — they are normal flow text and already get tagged via the regular block/inline machinery. Nothing to change there.

`Tag/Input.php` / `Tag/TextArea.php` / `Tag/Select.php` simply queue the `objattr` and let `Form::print_ob_*` do the rendering at flush time. Since the artifact wrap happens inside `print_ob_*`, the tag classes need no modification.

### 3d. The constructor change

Remove the HIGH-5 `if ($this->PDFUA && !$this->useActiveForms) { throw / auto-flip }` block in `src/Mpdf.php`. Replace with: nothing. The combination is now valid because the rendering path tags itself correctly.

### 3e. Strict-mode caveat

Strict mode (`PDFUAauto=false`) currently throws on missing alt, missing lang, etc., on the principle that intent cannot be inferred. For drawn forms there is no missing-intent question — the user explicitly chose `useActiveForms=false` and gets exactly what they asked for, just bracketed. **No strict-mode throw** is appropriate here. Auto-mode adds no warning either; rendering an artifact is not a violation.

---

## 4. Implementation phases

### Phase 1 — Audit + tests-first

1. Read `src/Form.php` end-to-end and enumerate every `else` branch under `if ($this->mpdf->useActiveForms)`. Record line ranges in this plan’s appendix.
2. Add a failing regression test `tests/Mpdf/Ua/LegacyFormArtifactTaggingTest.php` (group `pdfua`) with one method per widget type:
   - `testInputTextWrappedInArtifact`
   - `testTextareaWrappedInArtifact`
   - `testSelectWrappedInArtifact`
   - `testCheckboxWrappedInArtifact`
   - `testRadioWrappedInArtifact`
   - `testButtonWrappedInArtifact`
   - `testImageButtonWrappedInArtifact`
3. Each test constructs `mPDF` with `PDFUA=true, PDFUAauto=true, useActiveForms=false, mode='en-GB', title='X'`, writes the relevant HTML, and asserts:
   a. The PDF generates without throwing.
   b. `/Artifact BMC` precedes the bytes that draw the widget (regex on raw output).
   c. No `/S /Form` or `/S /Annot` struct kid is emitted by the legacy path.
   d. `EMC` appears between the widget bytes and the next real-content marker.
4. Run the suite — these all fail (HIGH-5 currently throws, or auto-flips and emits widget annotations).

### Phase 2 — Wire the artifact brackets

For each `print_ob_*` method's `else` branch, prepend:
```php
$wrapArtifact = $this->mpdf->PDFUA
	&& !$this->mpdf->ua->getStructureTree()->isInArtifact();
if ($wrapArtifact) {
	$this->mpdf->ua->getStructureTree()->openArtifact();
}
```
and append (just before the method returns):
```php
if ($wrapArtifact) {
	$this->mpdf->ua->getStructureTree()->closeArtifact();
}
```
The `isInArtifact()` guard prevents double-bracketing when the legacy form is rendered inside, e.g., a header/footer that is already an artifact.

### Phase 3 — Remove the HIGH-5 guard

Delete the `if ($this->PDFUA && !$this->useActiveForms) { … throw … addWarning … }` block in `src/Mpdf.php` (currently lines ~1133–1153). Update the inline comment to point to this plan.

Update the test base class `tests/Mpdf/Ua/PdfUaTestCase.php` — remove the `'useActiveForms' => true` default that was added for HIGH-5. Most tests don't care about forms, and removing it broadens the coverage surface.

Roll back the four direct-construction tests in `ValidationTest` and `MetadataTest` that were changed for HIGH-5 — drop the `'useActiveForms' => true` add-on each one received.

Delete the three HIGH-5-specific tests from `HighBugRegressionsTest.php`:
- `testStrictModeThrowsOnPdfuaWithoutActiveForms`
- `testAutoModeFlipsUseActiveFormsAndWarns`
- `testCorrectConfigDoesNotEmitFormsWarning`

These are replaced wholesale by `LegacyFormArtifactTaggingTest`.

### Phase 4 — Active-form cohabitation check

Add to `LegacyFormArtifactTaggingTest`:
- `testActiveFormsStillProduceTaggedAnnotations` — same tests but `useActiveForms=true`, asserts the existing PDFUA-aware AcroForm tagging path still fires unchanged. Catches accidental regression of the active-form path.

### Phase 5 — veraPDF gate

Add a fixture `mpdf-examples/legacy-forms.html` with one of every widget type and `useActiveForms=false`. Hook it into `VeraPdfConformanceTest` (taking the gate from 41 to 42 fixtures). Confirms rule 7.1#3 is satisfied end-to-end.

### Phase 6 — Documentation

- Update `CHANGELOG.md` with the change (HIGH-5 throw replaced by artifact wrapping).
- Update README PDF/UA-1 section if one exists; if not, defer (the audit already recommended writing one).
- Add a one-paragraph note to the legacy-form rendering decision in `Form.php` explaining the artifact wrap and citing ISO 32000-1 §14.8.2.2.

---

## 5. Test matrix

| Config | HTML | Expected output | Phase |
|---|---|---|---|
| PDFUA + auto + !useActiveForms | `<input>` | `/Artifact BMC` … widget bytes … `EMC`; no `/S /Form` | 1, 2 |
| PDFUA + auto + !useActiveForms | `<textarea>` | same | 1, 2 |
| PDFUA + auto + !useActiveForms | `<select>` | same | 1, 2 |
| PDFUA + auto + !useActiveForms | `<input type=checkbox>` | same | 1, 2 |
| PDFUA + auto + !useActiveForms | `<input type=radio>` | same | 1, 2 |
| PDFUA + auto + !useActiveForms | `<button>` | same | 1, 2 |
| PDFUA + auto + !useActiveForms | `<input type=image>` | same | 1, 2 |
| PDFUA + strict + !useActiveForms | any of the above | same — no throw, no warning | 1, 2 |
| PDFUA + auto + useActiveForms | any of the above | unchanged: AcroForm widget + Form/OBJR struct kid | 4 |
| !PDFUA + !useActiveForms | any of the above | unchanged: bare drawn chrome, no brackets | 4 |
| veraPDF on legacy-forms.html | `useActiveForms=false` doc | isCompliant=true | 5 |

---

## 6. Risk + rollout

**Behaviour change**: HIGH-5 throws/auto-flips today. After this plan, the same combination silently produces a conformant PDF with inert chrome. Users who were relying on the throw to surface a config mistake will lose that signal — but they were not getting accessible forms either way. The `CHANGELOG` note is sufficient.

**Performance**: each artifact bracket is two short PDF operators (`/Artifact BMC` / `EMC`). Negligible.

**FPDI imported forms**: if a tagged source PDF imports an AcroForm field via FPDI, the merger preserves the imported tagged-tree as-is — out of scope for this plan; the legacy-vs-active distinction is mPDF-emitted only.

**Rollback**: each phase is independently revertable. Phases 2–3 are the load-bearing ones; if Phase 5 fixture fails veraPDF for an unanticipated reason, Phase 3’s HIGH-5 removal can be deferred and the throw kept as a temporary belt-and-braces.

---

## 7. Out of scope

- Repairing the broader `useActiveForms=false` UX (no actual interactivity). That is a deliberate mPDF feature for printable forms.
- Marking labels associated with form widgets — they’re already tagged as P/Span via the normal flow.
- The MEDIUM/LOW gaps from the 2026-04-30 audit (empty Link, image-only Link, PDFDocEncoding fallback, sanitiseId length cap, Ligature test pollution) — handled in a parallel work stream.
- Any architectural refactor of `Form.php` (it predates DI and is large, but rewriting it is not necessary to fix this).

---

## 8. Spec citations

- ISO 14289-1:2014 §7.1 — every piece of real content must be tagged
- ISO 14289-1:2014 §7.18 — interactive forms
- ISO 32000-1:2008 §14.8.2.2 — artifacts excluded from logical structure
- ISO 32000-1:2008 §14.6 — marked content (BMC/EMC)
- Matterhorn Protocol 1.1 condition 01-006 — content not marked as artifact must be tagged
- veraPDF UA-1 profile rule 7.1#3 — untagged real content
