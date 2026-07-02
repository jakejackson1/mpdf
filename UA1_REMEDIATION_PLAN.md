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

---

## Phase A — structure & data-loss bugs (P0)

### A1. Anchor `<a name>`/empty-href with `lang`/`aria-label` leaks a Span
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

### A2. Nested `<dl>` corrupts the list structure (veraPDF clause 7.2 FAIL)
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

### A3. One encrypted import silently blanks all later imports (data loss)
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

### B1. Graphical objects inside columns are untagged (veraPDF clause 7.1 FAIL)
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

### B2. Direct `Mpdf::Link()` API calls are untagged (veraPDF clause 7.18.5 FAIL)
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

### B3. Image-map hotspots use the wrong page height across page sizes
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

#### C1b — rt-above-rb visual stacking · committed (remaining ruby work)
- **Files:** `src/Mpdf.php` inline-layout path (`WriteFlowingBlock`/`finishFlowingBlock`,
  the text-measurement and glyph-placement helpers), `src/Tag/Ruby.php`/`Rt.php`/`Rb.php`
  (emit layout linkage alongside the struct push), `src/Otl.php` where inline-run metrics
  are assembled.
- **Scope note:** not a UA-1 conformance item (C1a delivers the tagging veraPDF validates)
  but a real rendering-fidelity gap — mPDF flows the `rt` linearly after the `rb` instead
  of stacking it above. Per the no-deferrals rule it is in scope, not future work.
- **Approach:**
  1. *Parse* — the ruby handlers tag an inline "ruby cluster": mark the `rb` run and its
     `rt` run as an associated pair on the text buffer (a `ruby_group` id on the
     OTLdata/textbuffer entries) carrying the reduced `rt` font size (~0.5em).
  2. *Measure* — a ruby cluster's advance = `max(width(rb), width(rt))`; reserve extra
     ascent above the line equal to the `rt` line-height so the stacked annotation does
     not collide with the previous line.
  3. *Place* — render the `rb` glyphs on the baseline; render the `rt` glyphs at the
     reduced size, horizontally centred over the `rb` cluster, offset up by the base
     ascent + gap. Spread a wider `rt` across the base (mono/jukugo ruby) per W3C Ruby
     Annotation §4.
  4. *`<rp>`* — visually suppress the fallback parentheses while stacking is active (they
     are for non-ruby UAs) but keep the `RP` struct element in the tagged tree.
  5. *Breaking/justification* — exclude ruby clusters from mid-cluster line breaks and
     from inter-word justification stretching so the annotation stays aligned to its base.
- **Verify:** visual snapshot fixtures (rb/rt widths, centring, ascent reservation, rp
  suppression) in the Snapshot suite; the C1a struct-tree assertions and veraPDF PASS must
  be retained unchanged — stacking must not alter the tagged output.
- **Size:** large — the one substantial layout-engine change in the plan.

### C2. Rotated/transformed image maps emit QuadPoints · SHIPPED (this branch)
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

### D1. Ligature `ligature_source` recorded unconditionally (non-UA side effect)
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

### D2. Stale PHPStan baseline — `composer phpstan` is red
- **File:** `phpstan-baseline.neon`
- **Root cause:** 4 orphaned `ignore.unmatched` entries the branch's own fixes made
  obsolete (`Mpdf.php`, `Tag/Table.php`, `MetadataWriter.php` ×2).
- **Fix:** remove the 4 stale entries; re-run `vendor/bin/phpstan analyse --memory-limit=2G`
  to confirm zero unmatched-ignore errors.
- **Verify:** `composer phpstan` green. (Optional, separate: make PHPStan gating in CI —
  audit item I-3.)
- **Size:** trivial.

### D3. Stale `UA1_SECURITY_AUDIT.md` — contradicts remediated code
- **File:** `UA1_SECURITY_AUDIT.md`
- **Root cause:** still says "DO NOT MERGE" and lists all findings VULNERABLE, but every
  H/M finding is fixed and regression-tested under `tests/Mpdf/Ua/Security/`.
- **Fix:** add a resolution addendum (or rewrite the status column) marking H-1…H-3 and
  M-1…M-5 resolved, referencing the security test that pins each. Remove the merge-block
  recommendation.
- **Size:** trivial (doc).

---

## Suggested execution order

1. **Phase A** (A1, A2, A3) — independent, small, stop the corruption/data-loss.
2. **Phase D** (D1, D2, D3) — trivial, unblocks a green `phpstan`/CI signal early.
3. **Phase B** (B3, B1, B2) — conformance FAILs; B3 first (smallest), then B1, then B2.
4. **Phase C** — C1a ✅ shipped; then C2 (rotated image maps), then C1b (ruby visual
   stacking) last: it is the largest change and is rendering-fidelity rather than
   conformance, so it depends on nothing else and blocks nothing else.

Each item is a self-contained commit with its regression fixture. After each phase, run
`composer test`, the UA + security suites, and `VERAPDF_BIN=… @group verapdf`.
