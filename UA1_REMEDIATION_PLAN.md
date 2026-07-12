# PDF/UA-1 Remediation Plan — `ua1-support`

Fixes every issue from the branch audit. Ordered by severity and dependency.
Each item: root cause → approach → files → verification → size.

Ground rules (per project requirement — no feature may be deferred or shipped as a
documented limitation):

- Every fix ships with a regression fixture that reproduces the defect, added to the
  relevant test class **and** to the veraPDF conformance gate where a validator FAIL
  is involved.
- The full suite (`composer test`), the UA suite, the security suite, `@group verapdf`,
  and `composer phpstan` must all be green before the branch is considered done.
- No `warn-and-skip` or `out of scope` paths survive: strict mode throws, auto mode
  produces conformant output.

Severity legend: **P0** document corruption / silent data loss · **P1** hard veraPDF
FAIL on common input · **P2** requirement-gap deferral · **P3** hygiene.

**Status (2026-07-13):** Phases **A–D complete** — every A/B/C/D item is committed and the
plan's done-gates are green on the CI PHP 8.2 toolchain: `phpstan` 0 errors · full suite
1395 tests / 3238 assertions · veraPDF `@group verapdf` 49/49 · security `@group security`
72 tests. **Phase E** (second-round audit, 24 items) is outstanding. (phpstan's baseline is
generated for PHP 8.2; on PHP 7.4 it reports 4 version-specific `ignore.unmatched` entries —
expected, not a regression.)

---

## Phase A — structure & data-loss bugs (P0)

### A1. Anchor `<a name>`/empty-href with `lang`/`aria-label` leaks a Span · ✅ DONE (`2242db30`)
- **File:** `src/Tag/A.php` (non-hyperlink branch ~158–186; `close()` ~189–207)
- **Root cause:** the branch opens a `Span` struct element (line 173) but never calls
  `pushStripFrame()`. `close()` only pops when `hasStripFrames()` is true, so the Span
  is never closed and every later `close()` pops the wrong element — reading order is
  corrupted for the rest of the document.
- **Fix:** in the non-hyperlink branch, after opening the Span, push a matching frame:
  `$this->ua->getAnchorState()->pushStripFrame(true, 1);` (treat it exactly like the
  stripped-anchor path, which already pops `spanDepth` Spans correctly). When no Span
  is opened, push `pushStripFrame(true, 0)` so `close()` is always balanced 1:1 with
  `open()`. The AnchorState strip-stack model already supports this — no new state.
- **Verify:** new `StructureElementsTest` case asserting the struct tree for
  `<h1>…</h1><p>x <a name="a" lang="fr">y</a> z</p><p>second</p>` nests the second `<p>`
  as a sibling of the first (not a child). Add a probe to `PoorHtmlAutoModeTest`.
- **Size:** ~5 lines. Small.

### A2. Nested `<dl>` corrupts the list structure (veraPDF clause 7.2 FAIL) · ✅ DONE (`1153c13c`)
- **Files:** `src/Ua/UaState.php` (the `$openedImplicitLI` bool + accessors),
  `src/Tag/Dl.php`, `src/Tag/Dt.php`, `src/Tag/Dd.php`
- **Root cause:** `openedImplicitLI` is a single global bool. A `<dl>` inside a `<dd>`
  shares one flag across nesting levels, so the inner `<dt>` closes the wrong element.
- **Fix:** replace the bool with a per-`<dl>` stack in `UaState`:
  `pushImplicitLIFrame()` / `popImplicitLIFrame()` / `isImplicitLIOpen()` /
  `setImplicitLIOpen(bool)` operating on the top frame.
  - `Dl::open()` (add an `open()` override) pushes a frame (`false`).
  - `Dt::open()` / `Dd::open()` read and set the **top** frame instead of the global.
  - `Dl::close()` closes an open implicit LI for its frame, then pops the frame.
  This mirrors how `Ul`/`Ol`/`Li` already nest correctly via the struct tree itself.
- **Verify:** nested-`<dl>` fixture → veraPDF PASS (currently FAIL clause 7.2 test 18);
  keep the flat-`<dl>` case green. Add both to `StructureElementsTest` and the
  conformance gate.
- **Size:** ~30 lines across 4 files. Small–medium.

### A3. One encrypted import silently blanks all later imports (data loss) · ✅ DONE (`10e1d73c`)
- **File:** `src/FpdiTrait.php` (`currentEncryptedSourceKey()` ~579; `importPage()` ~535)
- **Root cause:** `currentEncryptedSourceKey()` returns `end($keys)` — the most-recently
  *flagged* encrypted key — without checking which FPDI reader is active. Once any
  source is encrypted, every subsequent `importPage()` from a valid source hits the
  Tier-0 placeholder fast path and its page content is dropped with no error.
- **Fix:** resolve the key for the **currently active** reader and only take the
  placeholder path when *that* source is flagged. FPDI exposes the active reader id via
  `getPdfReaderId()` / the current reader; build the same key shape `encryptedSourceKey()`
  uses from the active source and test membership in `$encryptedSourceFiles`, rather than
  `end($keys)`. If the active reader can't be resolved, fall through to the normal
  import (do **not** assume encrypted).
- **Verify:** `FpdiEncryptedSourceTest` case: `setSourceFile(enc)` then
  `setSourceFile(valid)`; assert `importPage()` on the valid source returns a real page
  id and the output contains the imported Form XObject (not a placeholder). Keep the
  single-encrypted-source and strict-throw cases green.
- **Size:** ~20 lines. Small–medium (need to confirm the FPDI reader-id accessor).

---

## Phase B — conformance FAILs on common input (P1)

### B1. Graphical objects inside columns are untagged (veraPDF clause 7.1 FAIL) · ✅ DONE (`0a7664a1`)
- **File:** `src/Mpdf.php` — barcode ~8113, textcircle ~8286, direct `Image()` ~9909,
  `WriteBarcode` cell ~26743
- **Root cause:** each site adds `|| $this->ColActive` to the artifact-scope guard and
  emits *nothing* (no Figure, no Artifact), relying on "column-level Artifact marking"
  that does not exist. Result: untagged real content in multi-column layouts.
- **Fix:** text already tags correctly inside columns (text columns pass veraPDF), which
  proves column buffer/replay preserves marked content. Primary approach: **drop
  `|| $this->ColActive`** from the four guards so these objects open their normal
  `Figure` (with `/Alt`) or `/Artifact` bracket. Add a spike first: confirm the
  Figure BDC/EMC survives the column-buffer replay in `printcolumnbuffer()`; if a
  column boundary can split a BDC…EMC pair across streams, bracket per-column the same
  way block content already is. Fallback (only if BDC cannot survive replay): wrap as
  `/Artifact` — still conformant — but the `Figure`/`Alt` path is preferred because a
  barcode carries meaning.
- **Verify:** barcode-in-2-columns fixture → veraPDF PASS (currently FAIL clause 7.1
  test 3); repeat for textcircle, an `Image()` call, and a `WriteBarcode` cell. Add to
  the conformance gate + `AnnotationsAndMiscTest`.
- **Size:** guard change ~8 lines; spike + possible per-column bracketing medium.

### B2. Direct `Mpdf::Link()` API calls are untagged (veraPDF clause 7.18.5 FAIL) · ✅ DONE (`90c2406e`) — see **E7** (artifact-scope regression found in round 2)
- **Files:** `src/Writer/MetadataWriter.php` (`writeAnnotations()` ~640–680), `src/Mpdf.php`
  (`Link()` ~4551)
- **Root cause:** link annotations created outside an `<a href>` scope get no
  `/StructParent` or OBJR wiring. The code comment admits "verapdf will flag it." Any
  `$mpdf->Link(...)` call produces a non-conformant link.
- **Fix:** make every link annotation self-tagging rather than depending on `Tag\A`
  having set the anchor struct element. When a PageLinks entry has no associated Link
  struct element in PDFUA mode, synthesize one: open a `Link` struct element at the
  point the annotation is registered (or lazily during `writeAnnotations()`), attach the
  OBJR kid and allocate `/StructParent`, and use the link target for `/Alt` in auto mode
  (Matterhorn 28-002). In strict mode with no accessible name available, throw. Reuse the
  existing OBJR/StructParent wiring that `Tag\A` links already use.
- **Verify:** `$mpdf->Link(...)` fixture → veraPDF PASS (currently FAIL clause 7.18.5
  test 1). Add to conformance gate + a new `DirectPhpAndAriaTest` case.
- **Size:** medium — touches annotation registration and the writer; must not
  double-tag links that already come through `Tag\A`.

### B3. Image-map hotspots use the wrong page height across page sizes · ✅ DONE (`958d6031`)
- **File:** `src/Ua/ImageMap/ImageMapRegistry.php` (`drain()` ~336)
- **Root cause:** `drain()` restores `$mpdf->page` but `Mpdf::Link()` flips y with the
  live `$this->hPt`, which at drain time holds the *final* page's height. Rects land on
  the wrong page height when sizes/orientations differ.
- **Fix:** capture the correct page height at **queue** time (in `printobjectbuffer()`,
  `$this->hPt` is the host image's page height) — add `'pageHpt' => $this->hPt` to the
  queued entry. In `drain()`, set `$mpdf->hPt = $entry['pageHpt']` around the `Link()`
  call and restore afterward (alongside the existing `page` save/restore).
- **Verify:** mixed portrait/landscape fixture with `<img usemap>` on the portrait page;
  assert the Link `/Rect` yTop matches the portrait page height. New `ImageMapTest` case.
- **Size:** ~8 lines. Small.

---

## Phase C — requirement-gap deferrals (P2)

### C1. Ruby annotations — proper standard struct types + visual stacking

#### C1a — standard struct types · ✅ SHIPPED (this branch)
- **Files:** `src/Ua/StructType.php`, `src/Tag/Ruby.php`, `Rb.php`, `Rt.php`, `Rp.php`,
  `Rtc.php`, `CHANGELOG.md` (+ tests below)
- **Root cause (fixed):** `RUBY/RB/RT/RP/RTC` all mapped to `Span`; the proper standard
  struct types were parked as "v2 deferred".
- **What shipped:**
  - `StructType::$tagMap` now maps `RUBY→Ruby`, `RB→RB`, `RT→RT`, `RP→RP`; `RTC→Span`
    (HTML5 `<rtc>` has no PDF standard type — it renders transparently, its `<rt>`
    children attach directly to the `Ruby`; a `Span` materialises only when
    `lang=`/`aria-label=` forces a host element).
  - `StructType::$validTypes` gains `Ruby, RB, RT, RP, Warichu, WT, WP`
    (ISO 32000-1 §14.8.5.6 Table 337) — the Warichu family is whitelisted so a
    `role="Warichu"` override can reach it.
  - `Tag/Ruby.php`/`Rb.php`/`Rt.php`/`Rp.php` open their element beneath the enclosing
    `Ruby`; a bare-text base (no `<rb>`) attaches content directly to `Ruby`. `Rtc.php`
    stays transparent. Redundant `close()` overrides removed.
  - CHANGELOG "v2 deferred" language removed.
- **Verified:** veraPDF ua1 PASS on every shape — `rb`+`rt`, `rp` fallback, bare-text
  base, `lang=`-tagged `rt` (interposed `Span` accepted), `<rtc>`, nested `<ruby>`, ruby
  in `<h1>`, ruby in `<a href>`. Full suite 1374 green; veraPDF gate 45 green (new
  `testDocumentWithRubyAnnotationsPassesUa1`; updated `StructTypeTest` and
  `StructureElementsTest`; the six `PoorHtmlAutoModeTest` ruby probes stay green).

#### C1b — rt-above-rb visual stacking · ✅ SHIPPED (this branch)
- **Files:** `src/Css/DefaultCss.php` (RT font-size), `src/Tag/Ruby.php`/`Rt.php`/`Rp.php`
  (per-run `textparam['ruby']` markers + rt raise), `src/Mpdf.php`
  (`_buildRubyClusters()` + `finishFlowingBlock()` / `WriteFlowingBlock()` placement),
  + tests below.
- **Scope note (resolved):** not a UA-1 conformance item (C1a delivers the tagging veraPDF
  validates) but a real rendering-fidelity gap — mPDF flowed the `rt` linearly after the
  `rb` instead of stacking it above. Per the no-deferrals rule it was in scope.
- **What shipped:**
  - *Parse* — `Tag\Ruby`/`Rt`/`Rp` mark each run's `textparam['ruby']` (`base`|`rt`|`rp`,
    carried end-to-end via saveFont/restoreFont). `RT` gains a DefaultCss `font-size:50%`
    and `Tag\Rt` sets a `text-baseline` raise — reusing the `<sup>` machinery so
    `_setInlineBlockHeights()` reserves the ascent and `Cell()` paints it raised for free.
  - *Measure/Place* — `_buildRubyClusters()` pairs consecutive ruby runs into clusters
    (cluster advance = `max(base, annotation)`), and the placement loops in **both**
    `finishFlowingBlock()` and `WriteFlowingBlock()` centre the base group, centre the
    raised annotation over it with zero net advance, and suppress `rp` runs. A wider
    annotation centres the base under it (jukugo-style).
  - *`<rp>`* — fallback parentheses are painted-suppressed; the `RP` struct element is
    retained (C1a).
  - *Breaking/justification* — ruby runs have no internal break opportunity (natural
    cohesion) and are excluded from justification stretch (`SetSpacing(0,0)`), so the
    painted base width matches the cluster advance (no overlap of following text).
- **Verified:** geometry asserted directly from the content stream — annotation raised +
  centred, narrow annotation adds no advance, wide annotation expands the cluster, ascent
  reserved, `rp` suppressed, wrapped-line ruby stacks, justified ruby does not overlap
  (`RubyStackingTest`, 8 cases). C1a struct tags + veraPDF ua1 retained unchanged; full
  suite 1395 green; veraPDF gate 49 green; non-ruby text byte-identical (all logic gated on
  a per-run ruby marker).
- **Size:** large — the one substantial layout-engine change in the plan.

### C2. Rotated/transformed image maps emit QuadPoints · ✅ SHIPPED (this branch)
- **Files:** `src/Mpdf.php` (`printobjectbuffer()` image branch, `Link()`),
  `src/Ua/ImageMap/ImageMapRegistry.php`, `src/Writer/MetadataWriter.php`
  (`writeAnnotations()`), + tests below
- **Root cause (fixed):** for rotated/transformed host images the link regions were
  dropped with only a warning, and strict mode did not throw — inconsistent with every
  other strict-mode violation, and a documented limitation.
- **What shipped:**
  - `printobjectbuffer()` captures the exact `$tr` (rotate) + `$tr2` (CSS transform)
    content-stream matrix mPDF renders the image with into the queued entry
    (`'transformCm'`); the warn-and-skip branch is removed.
  - `ImageMapRegistry::buildHotspotMatrix()` replays that placement (image `cm` folded
    with the captured matrices, ISO 32000-1 §8.3.4) to map each area's pixel-space
    corners into device space; `emitForImage()` emits them as a `/QuadPoints` quad
    (bounding box → `/Rect`). Axis-aligned images keep the plain `/Rect` path unchanged.
  - `Mpdf::Link()` gained an optional device-space `$quadPoints` (7th PageLinks slot);
    `writeAnnotations()` emits `/QuadPoints` when present.
- **Verified:** hotspot quads land on the rendered image corners to <0.001pt (extracted
  from the PDF's own `cm` operators, independent of the production code) for rotate
  90/-90/180, CSS `rotate`, and CSS `skewX`. veraPDF ua1 PASS. Full suite 1387 green;
  veraPDF gate 49 green (new `testRotatedImageMapPassesUa1`; new `ImageMapTest` cases
  `testRotatedImageMapEmitsQuadPoints`, `testAxisAlignedImageMapHasNoQuadPoints`,
  `testRotatedImageMapQuadAlignsWithRenderedImage`).

---

## Phase D — hygiene (P3)

### D1. Ligature `ligature_source` recorded unconditionally (non-UA side effect) · ✅ DONE (`7600681f`)
- **File:** `src/Otl.php` (`GSUBsubstitute()` ~2668–2686)
- **Root cause:** `ligature_source` is written into `GPOSinfo` with no `PDFUA` guard.
  Because `Mpdf.php` render routing (4641/5433/5447) keys on `!empty($OTLdata['GPOSinfo'])`,
  a previously-empty GPOSinfo becoming non-empty can push non-PDFUA ligature runs off the
  `Tj` fast path onto `applyGPOSpdf()`. (No visible/byte regression reproduced with
  DejaVu, but the side effect is real and unnecessary outside PDFUA.)
- **Fix:** gate the recording — `Otl` already holds the `Mpdf` ref
  (`__construct(Mpdf $mpdf, …)`), so wrap the block in `if ($this->mpdf->PDFUA)`.
- **Verify:** assert a non-PDFUA ligature render is byte-identical to `development`;
  PDFUA ligature ActualText tests stay green (`LigatureActualTextTest`).
- **Size:** ~3 lines. Trivial.

### D2. Stale PHPStan baseline — `composer phpstan` is red · ✅ DONE (`befcaf5b`)
- **File:** `phpstan-baseline.neon`
- **Root cause:** 4 orphaned `ignore.unmatched` entries the branch's own fixes made
  obsolete (`Mpdf.php`, `Tag/Table.php`, `MetadataWriter.php` ×2).
- **Fix:** remove the 4 stale entries; re-run `vendor/bin/phpstan analyse --memory-limit=2G`
  to confirm zero unmatched-ignore errors.
- **Verify:** `composer phpstan` green. (Optional, separate: make PHPStan gating in CI —
  audit item I-3.)
- **Size:** trivial.

### D3. Stale `UA1_SECURITY_AUDIT.md` — contradicts remediated code · ✅ DONE (`bbfd1390`)
- **File:** `UA1_SECURITY_AUDIT.md`
- **Root cause:** still says "DO NOT MERGE" and lists all findings VULNERABLE, but every
  H/M finding is fixed and regression-tested under `tests/Mpdf/Ua/Security/`.
- **Fix:** add a resolution addendum (or rewrite the status column) marking H-1…H-3 and
  M-1…M-5 resolved, referencing the security test that pins each. Remove the merge-block
  recommendation.
- **Size:** trivial (doc).

---

## Phase E — second-round audit (max-effort review of the merged branch)

Findings from a follow-up multi-agent review of the whole `ua1-support` diff, after
Phases A–D landed. These are **distinct from** the first-round items above — several sit
inside the code the earlier phases added (the B2 link self-tagging, the ruby work, the
FPDI struct merge). Same ground rules apply: every fix ships a regression fixture, strict
mode throws, auto mode produces conformant output, no `warn-and-skip` survives. Line
numbers are as of the review and approximate.

### P0 — document corruption / silent data loss

#### E1. FPDI merge silently drops every bare-integer `/K` MCID (imported tagged content has no content refs)
- **File:** `src/Ua/Import/FpdiStructMerger.php` (`normaliseKidsToArray()` 1036–1053)
- **Root cause:** the helper handles `/K` as a `PdfArray` (returns its values) or a
  `PdfDictionary` (wraps it), and returns `[]` for anything else — including a bare
  `PdfNumeric`. A single-MCID struct element serialises its `/K` as a bare integer
  (`/K 5`), which is exactly the shape mPDF's own writer prefers (`StructureWriter.php:396`,
  "bare integer is allowed and preferred") and most producers emit. Those elements are
  cloned with **no content reference at all** — no `/K`, no MCR — so the merged subtree is
  a skeleton of empty `StructElem`s. The per-kid loop at 1288 already handles bare integers
  correctly; the gap is purely the single-integer `/K` never reaching that loop.
- **Fix:** add a `PdfNumeric` (and `PdfNumericObject`) branch to `normaliseKidsToArray()`
  that returns `[$resolved]` so the existing 1288 `PdfNumeric` handler runs. One branch,
  three lines.
- **Verify:** import a tagged PDF whose `/K` is a bare integer (mPDF's own single-MCID
  output round-tripped) → merged element carries the MCID, `/Pg` resolves, veraPDF ua1
  PASS. New `FpdiStructMergerTest` case + conformance gate. Keep the array-of-MCIDs and
  MCR-dict cases green.
- **Size:** ~3 lines. Trivial — but it is the difference between "FPDI tagged import works"
  and "every FPDI tagged import fails".

#### E2. One encrypted source blanks the whole page and fakes the page count (data loss)
- **File:** `src/FpdiTrait.php` (`importPage()` placeholder path ~421;
  `handleEncryptedSetSourceFile()` `return 1;` ~271; `drawEncryptedSourcePlaceholder()` ~537)
- **Root cause:** an encrypted source renders an **empty** placeholder
  (`drawEncryptedSourcePlaceholder()` emits zero content operators) wrapped in
  `/Artifact <</Type /Layout>> BDC … EMC`, and `handleEncryptedSetSourceFile()` returns a
  synthetic page count of `1`. A multi-page encrypted source therefore loses every page but
  the first, and that page is blank — with no error in either mode. (Related to but distinct
  from **A3**, which is about the *active-reader key confusion* that blanks a *valid* source
  after an encrypted one; E2 is the encrypted source's own content/paging loss.)
- **Fix:** in PDFUAauto, still draw a *visible* placeholder (border + caption) so the loss
  is not silent, and either honour the real source page count or emit one placeholder per
  source page. In strict mode throw (an encrypted source cannot be made accessible without
  decryption). Coordinate with A3 so the two encrypted-import paths share one policy.
- **Verify:** multi-page encrypted source → auto mode emits N visible placeholders + a
  `getPdfUaWarnings()` entry; strict throws. `FpdiEncryptedSourceTest`.
- **Size:** small–medium.

#### E3. Encrypted PDF/UA documents ship corrupt XMP (`/Crypt` `Identity` on an RC4 doc)
- **File:** `src/Writer/MetadataWriter.php` (`writeMetadata()` 170–182)
- **Root cause:** for `PDFUA && encrypted` the XMP stream is written **plaintext** with
  `/Filter [/Crypt] /DecodeParms <</Name /Identity>>`. Crypt filters require an encryption
  dictionary of `/V 4` or `/V 5` with a matching `/CF` entry, but mPDF's `writeEncryption()`
  only emits RC4 `/V 1|2` (no `/CF`). A conforming reader has no `Identity` crypt filter to
  apply, falls back to the document's default (RC4), and RC4-"decrypts" the plaintext into
  garbage — destroying `dc:title` and `pdfuaid:part` on every encrypted UA document.
- **Fix:** the XMP stream must genuinely be unencrypted *and* the dictionary must say so in a
  way valid for the actual encryption revision. With RC4 V1/V2 there is no valid per-stream
  bypass, so either (a) leave the metadata stream out of encryption via the document's
  security handler and write it with **no** `/Filter /Crypt` (the string/stream must be
  excluded from the RC4 pass — check `BaseWriter`/`writeEncryption` object-number exemption),
  or (b) require `/V 4` (AESV2/RC4 crypt-filter) whenever `PDFUA && encrypted` and emit the
  `Identity` `/CF` so the declared bypass is real. Option (b) is cleaner and future-proofs
  AES. Do not ship the current `/Crypt` + plaintext combination.
- **Verify:** `PDFUA=true` + `SetProtection(['print'])` → extract the XMP with a conforming
  reader/veraPDF and assert `pdfuaid:part = 1` and `dc:title` are intact; veraPDF ua1 PASS.
  New `EncryptedUaMetadataTest` + conformance gate.
- **Size:** medium — touches the encryption/metadata boundary; confirm the exemption path.

#### E4. ToUnicode CMap emits malformed source codes for astral codepoints (breaks text extraction for *all* docs)
- **File:** `src/Writer/FontWriter.php` (bfchar loop 350–369; source token 368)
- **Root cause:** the source token is `sprintf('%04X', $toUniU)` where `$toUniU` is the
  Unicode scalar. For a supplementary-plane character (`> 0xFFFF`: emoji, CJK Ext-B, math
  alphanumerics) this yields 5–6 hex digits — `<1F600>` — an odd-width token that violates
  the declared `<0000> <FFFF>` 2-byte `codespacerange`. The resulting ToUnicode stream is
  syntactically invalid: veraPDF fails it and readers ignore the CMap, breaking copy/paste
  extraction. This runs unconditionally, so it is a regression versus `development` for
  **non-UA** output too. (The destination already surrogate-pair-encodes correctly at
  357–364; only the source token is wrong.)
- **Fix:** the source token must be the 2-byte code the content stream actually shows for
  that glyph (the Identity subset GID / char code), not the Unicode scalar — confirm what
  `Tj` emits for these glyphs and use that, formatted `%04X`. Astral glyphs still map to a
  surrogate-pair *destination*. Add a guard so no source token can exceed the codespace
  width.
- **Verify:** a document with an emoji / CJK-Ext-B glyph in an embedded subset TTF →
  extract `/ToUnicode`, assert every source token is 2 bytes and the astral char round-trips
  to its surrogate pair; veraPDF ua1 PASS; pdftotext returns the character. New
  `ToUnicodeCMapTest`; add an astral fixture to the conformance gate.
- **Size:** small–medium (needs the content-stream code confirmation).

### P1 — hard veraPDF FAIL on common input

#### E5. Merged Form-XObject MCRs lose `/Pg` and `/Stm` (companion to E1)
- **File:** `src/Ua/StructureWriter.php` (`writeElement()` MCR branch 397–414)
- **Root cause:** the MCR loop resolves the page object number only via
  `buildPageRefMap()[$mcr['page']]`, keyed on the *page-level* `/StructParents`. Imported
  Form-XObject MCRs carry the XObject's `structParents` in `['page']` (absent from the page
  map), so `$pageObjNum` is `0`, the `if ($pageObjNum > 0)` guard fails, and the entry falls
  to the bare-integer fallback (412) — dropping both `/Pg` and `/Stm`. Meanwhile
  `FpdiStructMerger::patchMcr()` writes the correct host page object number into
  `$mcr['pageRef']` (and the stream number into `$mcr['stm']`), which `writeElement()` never
  reads — making `patchMergedSubtreeObjectNumbers()` effectively dead.
- **Fix:** prefer the patched `$mcr['pageRef']` when `> 0` before the `buildPageRefMap()`
  fallback, in both the `$singleSimpleMcid` `/Pg` emit (392) and the MCR loop (399). The
  `/Stm` value at 400 is already read once the page ref is non-zero. Per Table 324 a
  Form-XObject MCR then emits `<</Type /MCR /Pg … /Stm … /MCID …>>`.
- **Verify:** import a tagged PDF whose content lives in a Form XObject → merged MCRs carry
  `/Pg` + `/Stm`, ParentTree has the XObject `/StructParents` key; veraPDF ua1 PASS.
  `FpdiStructMergerTest` + conformance gate. (Land with E1 — neither is sufficient alone.)
- **Size:** ~6 lines.

#### E6. Inline struct elements never receive their own content (`Link` / lang-`Span` / `Abbr` / Ruby `RB`·`RT` are empty)
- **File:** `src/Mpdf.php` (`ensureBlockBdcOpen()` ~6829–6857; the `pdfua_struct_elem`
  block-capture path)
- **Root cause:** every flowing-text MCID is deliberately attached to the **block** element
  (`addContentForElement($blockElem, …)`, 6854 — "against the BLOCK element (not the
  StructureTree stack top, which may belong to a nested inline span/link)"). An MCID maps to
  exactly one struct element via the ParentTree, so when the block owns the text, an
  enclosing `Link`/lang-`Span`/`Abbr`/Ruby `RB`/`RT` owns **no** content item: the `Link`
  gets only its OBJR, and the `Lang`/`Alt`/`ActualText` on the inline element apply to
  nothing. veraPDF flags a `Link`/`Span` with no content, and the accessible metadata is
  lost.
- **Fix:** when an inline struct element is open on the stack, marked content for the text
  inside it must be bracketed in its own BDC and its MCID attributed to that inline element,
  not the block. Track the active inline struct element (the anchor/span/abbr/ruby stack
  already exists) and target it in `ensureBlockBdcOpen()`/the inline emit path; resume the
  block's BDC after the inline closes. This is the substantive item in Phase E.
- **Verify:** `<p>see <a href="…">the report <span lang="fr">rapport</span></a> today</p>` →
  the `Link` element's `/K` contains both the text MCID(s) and the OBJR; the lang-`Span`
  owns its own MCID; `<abbr>` `/E` and Ruby `RB`/`RT` each own their text. veraPDF ua1 PASS.
  New `InlineStructContentTest` covering link/span/abbr/ruby + conformance gate.
- **Size:** large (layout-engine / marked-content change).

#### E7. Link annotations built in an artifact scope attach their OBJR to the Document root
- **Files:** `src/Tag/A.php` (hyperlink branch, `open('Link')` + `getCurrent()` +
  `setLinkStructElem()` ~130–156), `src/Writer/MetadataWriter.php` (`writeAnnotations()` ~413)
- **Root cause:** inside a running header/footer or `aria-hidden` subtree,
  `StructureTree::open('Link')` is a suppressed no-op, but `getCurrent()` still returns the
  artifact-scope stack top (the Document root at header-render time). `setLinkStructElem()`
  captures that wrong element, and `writeAnnotations()` later hangs the annotation OBJR /
  `/StructParent` off the Document root with no `Link` struct element — a silent 7.18.5 /
  Matterhorn 02-003 FAIL. This is a gap in the **B2** self-tagging mechanism, exposed only in
  artifact scope.
- **Fix:** in an artifact scope a link's visible content is itself an artifact, so the
  annotation must be marked as an artifact annotation (no OBJR, `/Contents` only) rather than
  wired into the structure tree — detect `isInArtifact()` in the anchor/annotation path and
  skip the struct wiring, or (auto) drop the link. Never attach an OBJR to the Document root.
- **Verify:** `SetHTMLHeader('<div>… <a href="https://example.com">x</a></div>')` → no OBJR
  on the Document root, the header link is an artifact; a body link still self-tags. veraPDF
  ua1 PASS. Extend `DirectPhpAndAriaTest` / a header-footer fixture + conformance gate.
- **Size:** small–medium.

#### E8. `aria-labelledby` / `aria-describedby` resolve to an empty `/Alt` (actively hides content)
- **File:** `src/Ua/AriaIdResolver.php` (`collectText()` 267–281; `resolveAll()` ~231)
- **Root cause:** `collectText()` is a self-described stub that returns only the target
  element's own `ActualText`/`Alt`; descendant text is stored as MCIDs, not text-node
  children, so for an ordinary text target it returns `""`. `resolveAll()` writes that empty
  string unconditionally, so `<p id="cap">The caption</p><div aria-labelledby="cap">` emits
  `/Alt (\376\377)` — a BOM-only empty string that, per Table 322, **replaces** the element's
  content for AT and hides it. No warning in either mode.
- **Fix:** give `collectText()` real text by having the struct tree retain the text runs it
  already emits as MCIDs (record the string alongside `addMcid`/`addContentForElement`), then
  concatenate descendants. Until the tree stores text, at minimum: never write an empty
  `/Alt`/`/E` — if resolution yields `""`, throw in strict and warn in auto (Matterhorn
  13-004/28-002) instead of emitting the harmful empty value.
- **Verify:** the caption fixture → `/Alt` equals "The caption" (UTF-16BE); an unresolved or
  empty target → strict throws / auto warns, never an empty `/Alt`. veraPDF ua1 PASS. New
  `AriaNameResolutionTest`.
- **Size:** medium (text-run capture) — or small for the empty-guard interim.

#### E9. Block-level tags inside a table cell get no struct element
- **File:** `src/Tag/BlockTag.php` (`open()` PDFUA gate 913: `PDFUA && !tableLevel`)
- **Root cause:** the whole struct-element-creation block is gated on `!$this->mpdf->tableLevel`,
  so any block tag inside a table (`<td><h2>…</h2><ul><li>…</li></ul></td>`) produces only
  `Table▸TR▸TD` with direct MCIDs — no `H2`, no `L`/`LI` — and the heading-sequence tracker
  never sees those headings. Common in real invoices/reports; silent both modes.
- **Fix:** remove the `!tableLevel` gate and let block tags open their normal struct element
  beneath the current `TD`/`TH`. The struct tree already nests arbitrarily; confirm the cell
  content path (`_tableWrite()`) attributes MCIDs to the nested element (same fix family as
  E6). Guard only the genuinely unsupported cases, not "inside a table" wholesale.
- **Verify:** heading + list inside a cell → `TD ▸ H2` and `TD ▸ L ▸ LI` present, heading
  tracker sees the H2; veraPDF ua1 PASS. New `TableCellStructureTest` + conformance gate.
- **Size:** medium (needs the cell-content MCID attribution).

#### E10. Floated blocks are silently demoted to Artifact (real content dropped)
- **File:** `src/Tag/BlockTag.php` (float→artifact 978–983)
- **Root cause:** `if ($isFloat && !isset(ROLE) && !isset(ARIA-LABEL)) { $structType = '__artifact__'; }`
  with no throw/warn — unlike the heading-sequence code just below it. A plain
  `<div style="float:left">…real text…</div>` (present in the branch's own `example10`
  fixture) has its entire subtree marked `/Artifact` and excluded from the tree; AT skips it
  (Matterhorn 01-001). Authors get no signal.
- **Fix:** a float is a visual-positioning hint, not an accessibility one — floated content
  is still real content and must be tagged in reading order. Drop the auto-artifact demotion;
  tag floats with their normal struct type. If a specific float genuinely must be an artifact
  the author uses `role="presentation"`. If any reading-order concern remains, warn in auto /
  throw in strict rather than silently dropping.
- **Verify:** floated `<div>` with text → content tagged and present in the tree; veraPDF ua1
  PASS; `example10` no longer drops its floats. `StructureElementsTest` + conformance gate.
- **Size:** small (guard removal) + a fixture.

#### E11. Image with no `alt` but with ARIA naming aborts (strict) or is hidden (auto)
- **File:** `src/Mpdf.php` (`printobjectbuffer()` image branch 8103–8158)
- **Root cause:** the `alt`-absent branch (8107) throws (strict) or `addArtifact()`s (auto)
  **without consulting the ARIA naming attributes** — the `aria_labelledby`/`aria_describedby`
  queue lives only in the non-empty-`alt` `else` (8143). `<img src="chart.png"
  aria-labelledby="figcap">` (no `alt`) aborts strict or hides a meaningful image in auto,
  even though an accessible name is available.
- **Fix:** before the throw/artifact demotion, check for `aria-labelledby`/`aria-describedby`
  (and `aria-label`, `title`); if present, open the `Figure` and resolve the accessible name
  the same way the non-empty-`alt` path does. Only when *no* name source exists does strict
  throw / auto demote.
- **Verify:** `<p id="figcap">Q3 revenue chart</p><img src=… aria-labelledby="figcap">` →
  `Figure` with `/Alt` "Q3 revenue chart", no throw; veraPDF ua1 PASS. `DirectPhpAndAriaTest`.
- **Size:** small–medium (share the ARIA-resolution helper with the `else` branch — see the
  P3 duplication note).

#### E12. Header-cell (`<th>`) associations are broken three ways
- **Files:** `src/Tag/Th.php` (`open()` 24–83), `src/Tag/Td.php` (`open()` id binding ~471)
- **Root cause:** `Th::open()` replays `Td::open()`, which registers the HTML `id` against the
  *temporary TD* struct element and parses `Headers`, then discards that TD (`discardTop()`)
  and rebuilds a TH keeping only `Scope/ColSpan/RowSpan`. Net effects: (a) the `id` is bound
  to an orphaned TD (`registerId` is first-wins, so the real TH never gets it) whose `objNum`
  stays 0; (b) `headers="…"` associations are dropped; (c) `Th::open()`'s PDFUA block has no
  artifact-scope guard, so a `<th>` in a running header/footer attaches to the Document root.
- **Fix:** stop round-tripping through TD's id/Headers registration — build the TH element
  once and register its `id`/`Headers`/`Scope` against *it*; add the `isInArtifact()` guard
  Th shares with the other cell tags. Keep `Scope`/`ColSpan`/`RowSpan` emission.
- **Verify:** `<th id="h1" scope="col">` + a `<td headers="h1">` → `/A <</O /Table /Scope>>`
  on the TH, header association intact, TH `objNum` non-zero, `id` resolves to the TH;
  `<th>` in a header renders as artifact. veraPDF ua1 PASS. `TableHeadersTest`.
- **Size:** medium.

#### E13. `spl_object_id()` (PHP 7.2+) fatals on supported PHP 5.6/7.0/7.1
- **File:** `src/Ua/Import/FpdiStructMerger.php` (cycle keys 1142; `collectSanityCandidates()` ~824)
- **Root cause:** `composer.json` declares `"php": "^5.6 || ^7.0 || ~8.0 …"`, but
  `spl_object_id()` was added in PHP 7.2. Any FPDI struct merge on 5.6/7.0/7.1 raises a fatal
  `Call to undefined function`. (FPDI 2.x itself runs on 5.6+, so the path is reachable.)
- **Fix:** use `spl_object_hash()` (5.x+) for the cycle/identity keys, or bump the composer
  `php` constraint to `>=7.2` if the project has actually dropped 5.6/7.0/7.1 — a policy
  decision to confirm with the maintainer, not to assume. Whichever, the two callers must
  agree.
- **Verify:** `composer.json` platform check green on the minimum supported PHP; a tagged
  import runs without a fatal on 7.1 (CI matrix or a `polyfill` shim test).
- **Size:** trivial (function swap) — unless the constraint is bumped.

#### E14. `sanitiseIdForPdf()` truncation can split a `#XX` escape → invalid PDF name
- **File:** `src/Ua/StructureElement.php` (`sanitiseIdForPdf()` 214/243)
- **Root cause:** the escaped id (built from 3-byte `#XX` tokens) is truncated with
  `substr($out, 0, 108)` at a fixed byte offset with no alignment check, so a cut can land
  mid-token and produce `…#` or `…#C` — a `#` not followed by two hex digits, violating ISO
  32000-1 §7.3.5 name production. Reproduced with long non-ASCII ids.
- **Fix:** truncate on token boundaries — build the escaped output token-by-token and stop
  before a token that would overflow `$truncTo`, then append the hash suffix. Never split a
  `#XX` sequence.
- **Verify:** `sanitiseIdForPdf(str_repeat('a',107).str_repeat("é",8))` and the other
  reproduced inputs → output ends on a complete token; a parser accepts the name. Unit test
  in `StructureElementTest`.
- **Size:** small.

### P2 — requirement-gap deferrals

#### E15. Imported **untagged** PDF pages are wrapped wholesale as `/Artifact` (invisible to AT)
- **File:** `src/FpdiTrait.php` (Tier-1 import 483)
- **Root cause:** Tier-1 wraps the entire real content of an imported untagged page in
  `/Artifact <</Type /Layout>> BDC … EMC`. Artifact-marking untagged imported content is a
  *conformant* way to satisfy Matterhorn 01-005/01-006 (no untagged real content), but it
  makes the imported page wholly inaccessible — which the "no deferrals / fully automatic"
  rule treats as a gap, not a solution.
- **Fix:** at minimum, surface it: warn in auto that an imported untagged page was rendered
  as an artifact (with the source/page), and let strict throw so the producer must supply a
  tagged source or an explicit `role`. Fuller: allow an author-supplied structure/`/Alt` for
  the imported page (a captioned `Figure`) so the content carries an accessible name rather
  than vanishing. Decide the policy with the maintainer and document it in the CHANGELOG (not
  as a limitation — as a supported, signalled behaviour).
- **Verify:** untagged import in auto → `getPdfUaWarnings()` names the page; strict throws or
  the author-supplied `Figure`/`Alt` path produces a named artifact. veraPDF ua1 PASS.
  `FpdiImportTest`.
- **Size:** small (signalling) — medium (author-supplied structure).

#### E16. `THead`/`TBody`/`TFoot` grouping elements are never emitted
- **File:** `src/Tag/Tr.php` (~83, deferred in a comment)
- **Root cause:** the row-group elements are parked ("when those are supported") so tables
  emit `Table ▸ TR` directly. `TR` under `Table` is valid, but the row grouping (and
  `THead` repetition semantics) is lost — a structure-completeness gap under the no-deferral
  rule.
- **Fix:** push `THead`/`TBody`/`TFoot` struct elements from `Thead`/`Tbody`/`Tfoot` tag
  handlers (or synthesise a `TBody` when the HTML omits them) and nest `TR` beneath them.
- **Verify:** a table with `<thead>/<tbody>/<tfoot>` → `Table ▸ THead ▸ TR`, etc.; veraPDF
  ua1 PASS. `TableStructureTest`.
- **Size:** small–medium.

#### E17. `/A <</O /List /ListNumbering>>` is never produced (dead attribute branch, no producer)
- **File:** `src/Ua/StructureWriter.php` (`buildAttrObject` `/List` branch 322–350)
- **Root cause:** the writer only emits the `List` attribute object when
  `$attrs['ListNumbering']` is set, but no tag handler ever sets that key (grep finds only
  the writer and a comment). Ordered lists therefore never carry `/ListNumbering`
  (`Decimal`/`UpperAlpha`/…), which PDF/UA expects for `L` elements with an ordered marker.
- **Fix:** have the `Ol`/`Ul` struct path set `ListNumbering` from the CSS `list-style-type`
  (map `decimal→Decimal`, `lower-alpha→LowerAlpha`, `disc→Disc` → `Unordered`, etc.) so the
  existing writer branch fires.
- **Verify:** `<ol type="a">` → `L` element with `/A <</O /List /ListNumbering /LowerAlpha>>`;
  veraPDF ua1 PASS. `ListStructureTest`.
- **Size:** small.

#### E18. Resolved ARIA relationships are stored but never written
- **Files:** `src/Ua/StructureWriter.php` (~310), `src/Ua/StructureElement.php`
  (`addRelationship()` 421–426)
- **Root cause:** resolved `aria-controls`/`aria-owns`/`aria-flowto`/`aria-activedescendant`
  targets are stored under `attributes['_aria_*']` but no writer path emits them — the
  relationships are silently dropped.
- **Fix:** decide the target representation (PDF has no direct equivalent for all of these;
  `aria-owns`/`flowto` can inform reading order / `/Ref`) and emit what maps
  (`/Ref` for owns/controls where applicable), or explicitly and *visibly* document that a
  given ARIA relationship has no PDF representation and warn — do not store-and-drop.
- **Verify:** an element with `aria-controls` → either a `/Ref` (or chosen mapping) is
  emitted, or a warning is recorded; assert no `_aria_*` key leaks into output. `AriaRelTest`.
- **Size:** medium (needs a mapping decision).

#### E19. Image-map violations warn-only — strict mode never throws
- **File:** `src/Ua/ImageMap/ImageMapRegistry.php` (`drain()`/`emitForImage()` 228, 287, 374;
  `warn()` 558)
- **Root cause:** unknown `usemap` target, malformed `<area coords>`, and the >1024-iteration
  disambiguation all call `warn()` (→ `addWarning()`) unconditionally; the registry never
  reads `PDFUAauto`. This violates the branch's own strict/auto contract (strict should
  throw) and can emit partial/incorrect link output silently.
- **Fix:** route these through the same strict-throws / auto-warns policy the tag handlers use
  (pass `PDFUAauto` into the registry or return violations to a caller that applies the
  policy). Strict throws with the offending map/area; auto warns and skips.
- **Verify:** `<img usemap="#missing">` in strict → throws; in auto → warns + no annotation.
  `ImageMapTest`.
- **Size:** small.

#### E20. Polygon image-map hotspots are collapsed to their bounding box
- **File:** `src/Ua/ImageMap/ImageMapRegistry.php` (`shapeToRect()` 534)
- **Root cause:** `poly`/`polygon` areas are reduced to a bounding `/Rect`, so the link
  activation region covers non-hotspot area — a fidelity/requirement gap for polygon maps.
- **Fix:** emit `/QuadPoints` tiling the polygon (the branch already added a `/QuadPoints`
  path for rotated maps in **C2** — reuse it) so the active region approximates the polygon;
  keep `/Rect` as the bounding box.
- **Verify:** a triangular `poly` area → `/QuadPoints` covers the triangle, not the full
  bbox; veraPDF ua1 PASS. `ImageMapTest`.
- **Size:** small–medium (reuses C2 machinery).

#### E21. `OverWrite()` throws even in `PDFUAauto` mode
- **File:** `src/Mpdf.php` (`OverWrite()` 28691)
- **Root cause:** the guard tests only `$this->PDFUA`, with no `PDFUAauto` branch, so
  `['PDFUA'=>true,'PDFUAauto'=>true]` throws — violating the branch's own two-path contract
  (cf. `SetProtection()` 24834, which warns in auto).
- **Fix:** in auto, `addWarning()` that binary overwrite cannot preserve the structure tree
  and proceed (or no-op the accessibility guarantee for that call) rather than throwing; keep
  the strict throw.
- **Verify:** `OverWrite()` under `PDFUAauto` warns and returns; strict throws.
  `PdfUaModeTest`.
- **Size:** trivial.

#### E22. `SetVisibility()` version preservation not extended to PDF/A · PDF/X
- **File:** `src/Mpdf.php` (`SetVisibility()` ~2098–2103)
- **Root cause:** the guard was updated to preserve `pdf_version` for `PDFUA`, but the sibling
  `PDFA`/`PDFX` optional-content path was not, so an OCG under PDF/A or PDF/X can still bump
  the version inconsistently.
- **Fix:** apply the same version-preservation to the `PDFA`/`PDFX` branch (or factor the
  guard so all three share it).
- **Verify:** `SetVisibility()` under PDF/A keeps the declared version. `PdfaTest`.
- **Size:** trivial.

#### E23. `writeParentTree()` builds `/Nums` positionally and may misalign with sparse `StructParents`
- **File:** `src/Ua/StructureWriter.php` (`writeParentTree()` 503)
- **Root cause:** each page-level `/Nums` value is appended after `ksort()`, which yields a
  correct number-tree only if the `StructParents` keys are dense and zero-based; a gap
  (skipped key) shifts every later entry, mis-mapping MCIDs to elements.
- **Fix:** emit explicit `key value` pairs from the sorted map rather than relying on
  positional order, so gaps are preserved.
- **Verify:** a document whose `StructParents` keys are non-contiguous (e.g. an imported page
  reserving a key) → ParentTree lookups resolve correctly; veraPDF ua1 PASS. Targeted
  `StructureWriterTest`.
- **Size:** small.

#### E24. `buildAttrObject()` emits `/BBox` and numeric attrs with locale-sensitive float concat
- **File:** `src/Ua/StructureWriter.php` (`buildAttrObject()` 454)
- **Root cause:** `/BBox` (via `implode(' ', $value)`) and numeric attributes are written by
  raw string concatenation of PHP floats; under a locale with a comma decimal separator this
  can emit `1,5` and corrupt the value.
- **Fix:** format all numerics with `sprintf('%.3F', …)` (or the project's existing
  locale-safe number formatter) — mirror how coordinates are written elsewhere.
- **Verify:** set a comma-decimal locale in a test and assert `/BBox` uses `.`; veraPDF ua1
  PASS. Unit test.
- **Size:** trivial.

### P3 — hygiene (grouped)

Mechanical cleanups — no conformance impact, but in scope under the no-slop / no-deferral
rules. One commit per cluster is fine.

- **Scope / behaviour changes to confirm or gate:**
  - `Mpdf.php:20454` — `<abbr><area><map><rb><rp><rt><rtc><ruby>` added to the **default**
    `enabledtags` whitelist unconditionally (runs for every instance), changing parsing for
    non-UA users. Confirm intended; add a CHANGELOG entry (or gate on PDFUA).
  - `Tag/A.php:61` — the `isHyperlink` gate leaves an empty/whitespace `href` matching **no**
    branch when PDFUA is off, so anchor CSS is silently not applied. Restore a fallback.
  - `Mpdf.php:1970` — `AddCustomProperty()` now throws for empty/over-long keys for **all**
    documents, not just PDFUA. Scope the new validation to PDFUA or document the API change.
  - `Writer/MetadataWriter.php:150` — `empty($this->mpdf->title)` treats a literal title
    `"0"` as missing (drops DisplayDocTitle/`dc:title`). Use `=== '' || === null`.
  - `Tag/Area.php:28` vs `:34` — `$this->ua` is dereferenced unguarded at 28 but null-checked
    at 34. Remove the dead guard **or** add the guard at 28 (pick one; they contradict).
- **Dead code:**
  - `Ua/Import/FpdiStructMerger.php:1368` — `method_exists($this->tree, 'registerImportedMcr')`
    guard is always true; drop it.
  - `Ua/Import/FpdiStructMerger.php:709` — `mergeRoleMap()` dead-reads `/MarkInfo` into
    `$roleMapRef` then immediately overwrites it; remove the read.
  - `Ua/LigatureActualTextWriter.php:50` — constructor stores `BaseWriter` +
    `MarkedContentHelper` that no method uses; drop the unused deps.
  - `Ua/MarkedContentHelper.php:71` — `begin()`'s `$altText` param is "reserved — not
    currently emitted" and no caller passes it; remove until it is wired.
- **Performance:**
  - `Ua/StructureWriter.php:376` — `buildPageRefMap()` (a full `pageDim` scan) is rebuilt once
    per struct element. Build it once per `writeParentTree`/document and cache.
- **Duplication (extract one helper each):**
  - ARIA `registerId` + queue loop is copy-pasted across `Tag/A.php:165`, `Tag/InlineTag.php:221`,
    and the image path (`Mpdf.php:8143`) — one `queueAriaRefs($elem, $attr)` helper.
  - SVG `title`/`desc` → `/Alt` promotion + `''`/`null`/text triage duplicated between
    `printobjectbuffer()` (`Mpdf.php:8073`) and `Image()` (`Mpdf.php:10130`) — share it (ties
    into E11).
  - Typed/hand-rolled artifact BDC/EMC brackets duplicated across `Mpdf.php:18042` (PaintDivBB,
    `_tableRect`, `Image()`, watermark/header) — one `withArtifact(callable)` bracket.
  - Ruby-cluster `contentWidth`/placement fold duplicated (`Mpdf.php:9344`); "Bare `Tf`"
    comment+`sprintf` copy-pasted ×4 (`Mpdf.php:4412`); `BlockTag::close()` re-implements
    `Mpdf::restoreFlowingBlockPdfuaState()` inline (`BlockTag.php:1442`); `MetadataWriter.php:629`
    assigns the same `'Internal link'` `/Contents` in both branches; `ServiceFactory.php:191`
    wires `ImageMapRegistry` via a lazy closure while `StructureTree` uses a solver — pick one.

**Not upheld by the review (recorded so they are not re-opened):** the list-marker-default
claim (`BlockTag.php:1213`) and the 127-byte custom-property-key claim (`Mpdf.php:1982`) were
**refuted**; the `strtolower()` locale `javascript:`-scheme bypass (`Ua/UaPolicy.php:148`) is
**plausible but env-gated** (only PHP < 8 under specific locales) — worth a `mb_strtolower`/
byte-safe fold as defence-in-depth, but no reproduction on the supported matrix.

---

## Suggested execution order

Phases **A–D are complete** (all committed; gates green — see the Status banner at the top).
The remaining work is Phase E. For the record, A–D landed in this order: Phase A (A1, A2, A3),
Phase D (D1, D2, D3), Phase B (B3, B1, B2), Phase C (C1a, C2, C1b).

**Remaining — Phase E** — second-round findings. Sequence within the phase:
   - **E1 + E5 together** first (FPDI tagged import is fully broken without both; E1 is
     ~3 lines, E5 ~6) — highest impact-to-effort in the plan.
   - Then the other **P0s**: E3 (encrypted XMP), E4 (ToUnicode astral), E2 (encrypted
     import — coordinate with A3 so the two encrypted-import paths share one policy).
   - Then the small/independent **P1s**: E7, E10, E11, E13, E14, then E9 and E12
     (both touch cell-content MCID attribution — do after E6's pattern lands), and E8.
   - **E6 last of the P1s** — the one large layout/marked-content change; E9/E11 reuse its
     inline-MCID attribution, so land its pattern before finishing them.
   - **P2s** (E15–E24) — mostly small and independent; E20 reuses the C2 `/QuadPoints`
     machinery, so do it after C2.
   - **P3** hygiene clusters — fold into the commits that touch each file, or batch at the
     end; the E11↔SVG-dedup and E6↔`restoreFlowingBlockPdfuaState` cleanups land with their
     P0/P1 items.

Each item is a self-contained commit with its regression fixture. After each phase, run
`composer test`, the UA + security suites, and `VERAPDF_BIN=… @group verapdf`.
