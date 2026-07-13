# PDF/X-4 Support Plan for mPDF

**Target:** PDF/X-4 (ISO 15930-7:2010) — the ICC-based, transparency-capable
print-production profile of PDF, built on PDF 1.6.

mPDF today ships **PDF/X-1a:2003** support behind the `PDFX` boolean. X-1a is a
strict, "safe" print profile: no transparency, no layers, CMYK/spot only, all
colour flattened. PDF/X-4 is the modern successor and differs in exactly the
places that make X-1a painful for real-world print work:

| Capability | PDF/X-1a:2003 (have) | PDF/X-4 (target) |
|---|---|---|
| Base PDF version | 1.3/1.4 | **1.6** |
| Live transparency / blend modes / soft masks | prohibited (flattened) | **permitted** |
| Optional content (layers / OCG) | prohibited | **permitted** |
| Colour | Device CMYK / spot only | ICC-based; CMYK/spot + calibrated RGB against the output intent |
| Output intent | `GTS_PDFX`, may cite a registered condition without embedding | `GTS_PDFX` with an **embedded** `DestOutputProfile` (X-4p allows an external ref) |
| PDF/X identification | Adobe `pdfx:1.3` XMP schema | **`pdfxid`** XMP schema (`GTS_PDFXVersion = "PDF/X-4"`) |

Because so much of the shared machinery already exists for X-1a, this is largely
a **version-awareness** exercise: today every gate keys on the bare `$PDFX`
boolean and assumes "X-1a", so it strips transparency, forbids layers, and
stamps `PDF/X-1a:2003` unconditionally. The work is to let the existing `PDFX`
config key carry the version (`true`/`'1a'` ⇒ X-1a, `'4'` ⇒ X-4), split the
gates that X-4 relaxes from those it keeps, and complete the X-4-specific
output-intent and XMP requirements.

This plan honours the project's **no-deferrals** rule: every X-4 requirement
gets a concrete item, strict mode (`PDFXauto=false`) throws on a violation it
cannot silently fix, and auto mode (`PDFXauto=true`) produces conformant output.
Nothing is pushed to "future work".

## Verification reality — veraPDF does NOT validate PDF/X

The UA-1 and PDF/A-3 plans lean on veraPDF. **That does not carry over here.**
veraPDF 1.30's flavour list is `[1a, 1b, 2a, 2b, 2u, 3a, 3b, 3u, 4, 4f, 4e,
ua1, ua2, wt1r, wt1a]` — the `4/4f/4e` entries are **PDF/A-4** (ISO 19005-4),
*not* PDF/X-4. veraPDF ships no PDF/X profile whatsoever. So verification for
this plan is two-tier:

1. **Deterministic structural assertions (primary, CI-gating).** A new
   `PdfX4StructureTest` parses the emitted bytes and asserts each mechanical
   requirement directly: header/catalog version ≥ 1.6, exactly one
   `/GTS_PDFX` OutputIntent carrying an embedded `/DestOutputProfile`,
   `pdfxid:GTS_PDFXVersion (PDF/X-4)` in the XMP packet, `/Trapped` present,
   a `/TrimBox` on every page, and the *absence* of prohibited constructs
   (`/JavaScript`, `/AA`, `/Interpolate true`, `/Encrypt`). These need no
   external tool and run in the normal test job.
2. **External preflight (optional, gated).** Mirror the `VERAPDF_BIN` pattern
   with a `PDFX_PREFLIGHT_BIN` env gate for a real PDF/X validator — callas
   pdfToolbox CLI or Ghostscript with a PDF/X-4 check profile — skipped when
   unset, exactly like `VeraPdfConformanceTest` is skipped without
   `VERAPDF_BIN` (`tests/Mpdf/Ua/VeraPdfConformanceTest.php:53-66`).

Every item below states which tier covers it.

---

## What already conforms — do not re-implement

Recon of the current tree confirms a substantial X-1a base that X-4 reuses
unchanged. Do **not** rebuild these:

- **`GTS_PDFX` output intent with embedded CMYK ICC.**
  `MetadataWriter::writeOutputIntent()` (`src/Writer/MetadataWriter.php:227-298`)
  writes `/S /GTS_PDFX`, sets `/N 4` for the CMYK profile (`:285-289`), and
  embeds the user's `ICCProfile` as `/DestOutputProfile` (`:249-253`, `:268-297`).
  The X-4 gap is only in the *no-profile* fallback (see **B1/B2**).
- **Info-dict PDF/X keys.** `/Trapped/False` and `/GTS_PDFXVersion`
  (`src/Writer/MetadataWriter.php:221-224`) — the version string is hardcoded
  (**B4**) but the mechanism is right.
- **Catalog wiring.** `/Metadata` reference and the `/OutputIntents [n 0 R]`
  array (`src/Writer/MetadataWriter.php:453-471`) are emitted for `PDFX`.
- **Page geometry.** Every page dict emits `/MediaBox` plus `/TrimBox` (and a
  conditional `/BleedBox`) (`src/Writer/PageWriter.php:150-197`). X-4 requires a
  TrimBox; it is already unconditional. (Item **A5** adds a regression assert.)
- **Colour restriction to CMYK/spot.** `ColorSpaceRestrictor.php:116-199`,
  `ColorConverter.php:203`, and image RGB→CMYK conversion
  (`ImageProcessor.php:679`, `:856-862`) already keep DeviceRGB out of the
  content stream. This is conformant for an X-4 doc with a CMYK output intent;
  **E1** only *broadens* it, it does not fix a defect.
- **Font embedding enforcement.** Core/CJK/symbol fonts that cannot embed throw
  or substitute (`src/Mpdf.php:4263-4269`, `:4430-4431`, `:12076-12077`;
  `src/Writer/FontWriter.php:140`). X-4's "all fonts embedded" rule is met.
- **Encryption guard.** `src/Mpdf.php:10645` blocks `PDFX + encrypted`. Kept
  for X-4 (message parity only — **C5**).
- **Strict/auto harness.** The `PDFAXwarnings` collect-then-throw pipeline with
  the `PDFXauto` escape hatch (`src/Mpdf.php:10649-10667`) is the enforcement
  spine every item below plugs into.

---

## Phase A — Version selection & mode plumbing (P0)

Nothing downstream can distinguish X-4 from X-1a until the mode itself exists.

### A1 — Overload the `PDFX` key to carry the version (no new config key)

- **Root cause:** `PDFX` is a bare boolean (`src/Config/ConfigVariables.php:144`,
  `src/Mpdf.php:76`) meaning "X-1a:2003". There is no way to ask for X-4.
- **Approach:** Keep the single `PDFX` key and let it accept `true`, `'1a'`, or
  `'4'` (case-insensitive; also tolerate `'X-4'`/`'PDF/X-4'`). `true` and `'1a'`
  select X-1a (today's behaviour); `'4'` selects X-4; `false` stays off. A second
  key would only create a coherence hazard (two switches that can disagree) —
  the code already treats `PDFX` as *the* single X switch, so the version rides
  on it.
  **Normalize once in the constructor** (alongside the PDFUA 1.7 bump at
  `src/Mpdf.php:1084-1089`), right after `initConfig()` has assigned the raw
  value: derive a private `$this->pdfxVersion` (`'1a'` or `'4'`) from the raw
  input, then coerce `$this->PDFX` back to a plain `bool`. This keeps `$this->PDFX`
  a strict boolean, so all ~60 shared `$this->PDFA || $this->PDFX` gates are
  untouched and never come to depend on `'4'` being a truthy string.
  *(Recon confirms this coercion is behaviour-preserving: there are zero
  `PDFX === true` / `== false`-style comparisons in `src` or `tests`; every
  consumer is a truthy check.)* Add two private helpers on `Mpdf`:
  `pdfxAllowsTransparency()` = `$this->PDFX && $this->pdfxVersion === '4'`, and
  `pdfxVersionLabel()` = `'PDF/X-4'` or `'PDF/X-1a:2003'`. Every version-sensitive
  gate below calls these rather than re-parsing anything. Validate the raw token
  in the constructor (throw on anything other than the accepted set, mirroring
  the `PDFAversion` check at `src/Writer/MetadataWriter.php:130-132`).
- **Files:** `src/Mpdf.php` (property `$pdfxVersion` + two helpers + constructor
  normalization/validation). `ConfigVariables.php:144` default stays `false`;
  only its doc comment (`:141-146`) needs the accepted-values note. No new key.
- **Verify:** Tier 1 unit test — `PDFX=true` and `PDFX='1a'` ⇒ label
  `PDF/X-1a:2003`; `PDFX='4'` ⇒ label `PDF/X-4`; `PDFX=false` ⇒ off; junk ⇒ throws.
- **Size:** ~35 LOC.

### A2 — Assert PDF 1.6 for X-4 in the header and catalog

- **Root cause:** `pdf_version` defaults to `1.4`
  (`src/Config/ConfigVariables.php:460`) and the header writes it verbatim
  (`src/Mpdf.php:2133`). Only PDFUA bumps it (to 1.7, `src/Mpdf.php:1087-1088`).
  X-4 requires the 1.6 feature base to be asserted.
- **Approach:** In the constructor, after the existing PDFUA bump, add: if
  `pdfxAllowsTransparency()` and the configured `pdf_version` is below `1.6`,
  raise it to `1.6`. Also emit `/Version /1.6` in the catalog for X-4, reusing
  the existing catalog-override pattern the PDF/A path uses for 1.7
  (`src/Writer/MetadataWriter.php:373-380`) so the higher of header/catalog
  always wins. Leave X-1a on 1.4.
- **Files:** `src/Mpdf.php` (constructor, near `:1087`),
  `src/Writer/MetadataWriter.php:373-380` (extend the `/Version` block).
- **Verify:** Tier 1 — header line `%PDF-1.6` and catalog `/Version /1.6`
  present for X-4, unchanged (`1.4`) for X-1a.
- **Size:** ~15 LOC.

### A3 — Make the strict/auto harness label version-aware

- **Root cause:** The failure banner and the `$option` hint hardcode
  `PDFX/1-a ` / `$mpdf->PDFXauto` (`src/Mpdf.php:10654-10655`), and dozens of
  warning strings say "PDFX/1-a" (e.g. `:4269`, `:11123`,
  `ImageProcessor.php:882`, `Form.php:529`). Under X-4 these read wrong.
- **Approach:** Replace the literal in the banner with `pdfxVersionLabel()`.
  For the per-violation warnings, thread the label through (or post-process the
  collected `PDFAXwarnings` to swap the token). Keep `$PDFXauto` as the option
  name — it is unchanged.
- **Files:** `src/Mpdf.php:10649-10667`; touch the warning strings pinned in
  **E3** (grouped there to avoid churn here).
- **Verify:** Tier 1 — trigger a violation in strict X-4, assert the exception
  log carries "PDF/X-4", not "PDFX/1-a".
- **Size:** ~10 LOC here (+ E3).

### A4 — Test scaffolding: structural harness + optional preflight gate

- **Root cause:** There is no PDF/X test harness; `VeraPdfConformanceTest`
  cannot validate PDF/X (see the verification note above).
- **Approach:** Add `tests/Mpdf/PdfX/PdfX4StructureTest.php` with helpers to
  render a fixture to bytes and assert on the raw stream (the OutputIntent
  block, XMP packet, page boxes, and prohibited-token absence). Add a separate
  `PdfX4PreflightTest` gated on `PDFX_PREFLIGHT_BIN` that shells out to a real
  validator and is skipped when the env var is unset — copy the skip/gate logic
  from `tests/Mpdf/Ua/VeraPdfConformanceTest.php:43-66` verbatim.
- **Files:** new `tests/Mpdf/PdfX/PdfX4StructureTest.php`,
  `tests/Mpdf/PdfX/PdfX4PreflightTest.php`.
- **Verify:** the tests are themselves the verification vehicle for the rest of
  the plan.
- **Size:** ~120 LOC (harness + gate).

### A5 — End-to-end X-4 acceptance fixture

- **Root cause:** No fixture exercises the transparency + layers + CMYK
  combination that distinguishes X-4 from X-1a.
- **Approach:** Author an HTML fixture that uses a semi-transparent element, a
  named layer, an embedded raster image, and embedded fonts, rendered with
  `['PDFX' => '4', 'ICCProfile' => <cmyk.icc>]`. Assert
  (Tier 1) that transparency and the OCG survive (are *not* stripped) and that
  the structural checks from A4 pass. Assert every page carries a `/TrimBox`
  (locks in the `PageWriter.php:150-197` behaviour).
- **Files:** `tests/fixtures/pdfx4/*`, wired into `PdfX4StructureTest`.
- **Size:** ~60 LOC.

---

## Phase B — Output intent & PDF/X identification (P0)

The output intent and the `pdfxid` identifier are what *make* the file X-4.
These are hard conformance blockers.

### B1 — Require an embedded `DestOutputProfile` for X-4

- **Root cause:** When no `ICCProfile` is supplied, `writeOutputIntent()` writes
  a registry-name-only intent (`CGATS TR 001`) and **returns early without
  embedding a profile** (`src/Writer/MetadataWriter.php:254-266`). PDF/X-4
  (unlike X-4p) requires `DestOutputProfile` to be present.
- **Approach:** Split the fallback by version. For X-4, never take the
  early-return branch — always emit an embedded `/DestOutputProfile`. If the
  user gave no `ICCProfile`, fall back to the bundled default CMYK profile from
  **B2**. Keep the X-1a registry-name behaviour untouched.
- **Files:** `src/Writer/MetadataWriter.php:247-297`.
- **Verify:** Tier 1 — X-4 output always contains one `/DestOutputProfile n 0 R`
  and the referenced stream is a valid ICC (`/N 4`). Tier 2 preflight.
- **Size:** ~25 LOC.

### B2 — Bundle a default CMYK ICC profile and wire it as the X-4 default

- **Root cause:** `data/iccprofiles/` ships only `sRGB_IEC61966-2-1.icc` (RGB).
  With B1 requiring an embedded CMYK profile, an X-4 doc that omits `ICCProfile`
  has nothing to embed.
- **Approach:** Add a redistributable CMYK profile (e.g. a coated FOGRA/GRACoL
  class profile under a licence compatible with mPDF's GPL-2.0-or-later, or the
  ICC's freely-redistributable reference) to `data/iccprofiles/`, and have B1's
  fallback load it. The `/OutputConditionIdentifier` should name the profile's
  characterization. Document how to override with a house profile via
  `ICCProfile`.
- **Files:** `data/iccprofiles/<cmyk>.icc` (new),
  `src/Writer/MetadataWriter.php` (fallback path), a short note in the config
  docs.
- **Verify:** Tier 1 — X-4 with no `ICCProfile` still embeds a 4-component ICC.
- **Size:** ~15 LOC + the profile asset. *(Licence check on the profile is the
  one non-code gate — resolve before merge; the ICC reference CMYK profiles are
  redistributable.)*

### B3 — Emit the `pdfxid` XMP identifier for X-4

- **Root cause:** The XMP PDF/X block is hardcoded to the deprecated Adobe
  `pdfx:1.3` schema with `GTS_PDFXVersion="PDF/X-1:2003"`
  (`src/Writer/MetadataWriter.php:124-126`). X-4 files are identified by the
  `pdfxid` schema (`xmlns:pdfxid="http://www.npes.org/pdfx/ns/id/"`,
  `pdfxid:GTS_PDFXVersion="PDF/X-4"`).
- **Approach:** Branch the XMP writer on `pdfxAllowsTransparency()`. For X-4,
  emit an `rdf:Description` with the `pdfxid` namespace and
  `pdfxid:GTS_PDFXVersion = "PDF/X-4"`. (X-4 has no A/U-style conformance
  suffix, so no `GTS_PDFXConformance` is written.) Keep the X-1a block for the
  legacy path. This mirrors how the `pdfaid`/`pdfuaid` blocks are selected at
  `src/Writer/MetadataWriter.php:128-161`.
- **Files:** `src/Writer/MetadataWriter.php:124-142`.
- **Verify:** Tier 1 — XMP packet contains `pdfxid:GTS_PDFXVersion` = `PDF/X-4`
  and does **not** contain the `pdfx:1.3` Apag string. Tier 2 preflight.
- **Size:** ~15 LOC.

### B4 — Version-aware Info-dict `GTS_PDFXVersion`

- **Root cause:** `writeInfo()` writes `/GTS_PDFXVersion(PDF/X-1a:2003)`
  unconditionally (`src/Writer/MetadataWriter.php:223`). The Info-dict string
  must agree with the XMP identifier.
- **Approach:** Emit `pdfxVersionLabel()` (`PDF/X-4`) here. Keep `/Trapped`.
- **Files:** `src/Writer/MetadataWriter.php:221-224`.
- **Verify:** Tier 1 — Info `/GTS_PDFXVersion` matches the XMP identifier.
- **Size:** ~3 LOC.

### B5 — Drop the deprecated Adobe `pdfx:1.3` Apag schema for X-4

- **Root cause:** The `pdfx:Apag_PDFX_Checkup="1.3"` attributes
  (`src/Writer/MetadataWriter.php:126`) belong to the old Adobe checkup schema.
  Leaving them in an X-4 packet alongside `pdfxid` is at best noise and can trip
  strict preflight.
- **Approach:** The B3 branch already omits them for X-4; this item is the
  explicit assertion that the X-4 packet is free of the `pdfx:1.3` namespace.
- **Files:** covered by B3; add the negative assertion in the test.
- **Verify:** Tier 1 — no `ns.adobe.com/pdfx/1.3` substring in an X-4 packet.
- **Size:** test-only.

---

## Phase C — Prohibited features (P1)

X-4 shares most prohibitions with the PDF/A work. Where the PDF/A-3 plan already
specifies a removal, this reuses the same code path and only adds the X-specific
trigger and the box-position rule that PDF/A does not have.

### C1 — Strip JavaScript, `OpenAction`, and additional actions

- **Root cause:** PDF/X permits no JavaScript and no `/AA` additional-action
  dictionaries. mPDF can emit document/field JS and AA actions (the same sites
  the PDF/A-3 plan flags: `src/Mpdf.php` JS embedding, `JavaScriptWriter.php`,
  `Form.php` AA actions).
- **Approach:** Extend the exact guards proposed for PDF/A so their condition is
  `$this->PDFA || $this->PDFX` (several already are). In strict mode throw; in
  `PDFXauto` mode drop the action and record a warning. No new mechanism — this
  is widening an existing predicate.
- **Files:** shared with the PDF/A-3 plan's JavaScript/action items
  (`src/Mpdf.php`, `src/Writer/JavaScriptWriter.php`, `src/Form.php`).
- **Verify:** Tier 1 — no `/JavaScript`, `/AA`, or JS `/OpenAction` in X-4
  output.
- **Size:** ~20 LOC (mostly predicate widening).

### C2 — Keep annotations outside the BleedBox/TrimBox; forbid disallowed subtypes

- **Root cause:** PDF/X requires every annotation to sit wholly **outside** the
  BleedBox (or TrimBox where there is no bleed) and forbids subtypes such as
  `/FileAttachment`, `/Sound`, `/Movie`, `/Screen`. mPDF places link and text
  annotations at content coordinates (`src/Writer/MetadataWriter.php` annotation
  writer, `:640-940`) with no print-box check.
- **Approach:** When `PDFX`, validate each annotation's `/Rect` against the
  page's BleedBox/TrimBox (already computed in `PageWriter.php:150-197`; expose
  the trim/bleed rect for reuse). Reject the prohibited subtypes. Strict:
  throw; auto: drop the annotation (or clamp a link outside the trim area) with
  a warning. Link annotations are the common real case — most sit inside the
  content area and must be handled, not silently emitted.
- **Files:** `src/Writer/MetadataWriter.php` (annotation writer),
  `src/Writer/PageWriter.php` (share the trim/bleed rect).
- **Verify:** Tier 1 — an annotation inside the trim area triggers throw/drop;
  prohibited subtypes never appear. Tier 2 preflight.
- **Size:** ~40 LOC.

### C3 — Prohibit image interpolation (all PDF/X parts)

- **Root cause:** `ImageWriter` emits `/Interpolate true` whenever the image
  carries interpolation (`src/Writer/ImageWriter.php:44-46`). PDF/X (every part)
  forbids `/Interpolate true`.
- **Approach:** Suppress the `/Interpolate true` emission when `$this->PDFX`
  (unconditional across X versions — this is not one of X-4's relaxations).
  Aligns with the PDF/A-3 plan's identical item.
- **Files:** `src/Writer/ImageWriter.php:44-46`.
- **Verify:** Tier 1 — no `/Interpolate true` in X-4 (or X-1a) output.
- **Size:** ~4 LOC.

### C4 — Forbid transfer functions and halftone overrides

- **Root cause:** PDF/X prohibits transfer functions (`/TR`, `/TR2` other than
  `/Default`) and custom halftones (`/HT`, `/HTP`) in ExtGState. mPDF's default
  output does not set these, but the guard should be explicit so future ExtGState
  additions cannot regress conformance.
- **Approach:** Add an assertion/guard in the ExtGState writer
  (`src/Mpdf.php:_putextgstates()` near `:24814`) that, under `PDFX`, no `/TR`,
  `/TR2`, `/HT`, or `/HTP` key is written (strict: throw; auto: drop the key).
  Primarily a regression fence plus a Tier-1 negative assertion.
- **Files:** `src/Mpdf.php:24814-24830`.
- **Verify:** Tier 1 — none of those keys appear in X-4 ExtGStates.
- **Size:** ~10 LOC.

### C5 — Encryption guard message parity

- **Root cause:** `src/Mpdf.php:10645` already blocks `PDFX + encrypted` but the
  surrounding banner says "PDFX/1-a".
- **Approach:** Covered by A3's label swap; listed here so the encryption
  prohibition is explicitly accounted for against X-4.
- **Files:** `src/Mpdf.php:10645-10667` (via A3).
- **Verify:** Tier 1 — requesting `PDFX + encryption` throws for X-4 with the
  correct label.
- **Size:** covered by A3.

---

## Phase D — Transparency & optional content (X-4's defining capability)

These are the gates X-4 **relaxes**. Today they fire on the bare `$PDFX`
boolean and assume X-1a's "no transparency, no layers". Each must consult
`pdfxAllowsTransparency()` so X-1a still strips while X-4 preserves. For pure
conformance an X-4 file *without* transparency is already valid — but shipping
X-4 that behaves identically to X-1a would make the mode pointless, so under the
no-deferrals rule these are required, not optional.

### D1 — Stop forcing opacity to 1.0 under X-4

- **Root cause:** `SetAlpha()` forces `alpha=1` for `PDFA || PDFX`
  (`src/Mpdf.php:2048-2053`), flattening all transparency.
- **Approach:** Gate the strip on `!pdfxAllowsTransparency()` (keep it for
  `PDFA` and for X-1a; skip it for X-4). Under X-4 the ExtGState `/ca`, `/CA`,
  and `/BM` pass through unchanged.
- **Files:** `src/Mpdf.php:2048-2053`.
- **Verify:** Tier 1 (A5 fixture) — a `ca < 1` ExtGState survives in X-4 output
  but not in X-1a. Tier 2 preflight.
- **Size:** ~6 LOC.

### D2 — Allow watermarks under X-4

- **Root cause:** `SetWatermarkText()`/`SetWatermarkImage()` throw outright for
  `PDFA || PDFX` (`src/Mpdf.php:11746-11747`, `:11842-11843`) because watermarks
  use transparency.
- **Approach:** Replace the unconditional throw with `PDFA ||
  (PDFX && !pdfxAllowsTransparency())`. X-4 watermarks render normally; X-1a and
  PDF/A keep throwing.
- **Files:** `src/Mpdf.php:11746-11747`, `:11842-11843`.
- **Verify:** Tier 1 — a watermark renders in X-4, still throws in X-1a.
- **Size:** ~4 LOC.

### D3 — Preserve PNG alpha and semi-transparent annotation markers under X-4

- **Root cause:** PNG alpha channels are removed for `PDFA || PDFX`
  (`src/Image/ImageProcessor.php:871-883`), and semi-transparent annotation
  markers are pushed to the margin (`src/Mpdf.php:11121-11123`).
- **Approach:** Gate both on `!pdfxAllowsTransparency()`. Under X-4 the PNG
  SMask survives (the `/SMask` wiring in `ImageWriter.php:48-50` already exists)
  and markers keep their opacity.
- **Files:** `src/Image/ImageProcessor.php:871-883`, `src/Mpdf.php:11121-11123`.
- **Verify:** Tier 1 — a PNG with alpha keeps its `/SMask` in X-4.
- **Size:** ~8 LOC.

### D4 — Allow optional content (layers/OCG) under X-4

- **Root cause:** `BeginLayer()` refuses layers for `PDFA || PDFX`
  (`src/Mpdf.php:2974-2976`); visibility changes are also blocked
  (`src/Mpdf.php:2100-2101`).
- **Approach:** Gate the refusal on `!pdfxAllowsTransparency()`. When X-4 layers
  are used, ensure the catalog `/OCProperties` carries a valid default
  configuration `/D` (X-4 requires OCGs to have a default config and forbids
  OCGs whose state is driven only by non-print events). mPDF already builds
  `/OCProperties` for the non-PDF/X path; confirm the `/D` dict is emitted and
  that no `/AS` usage-application ties visibility to `/View`-only. Bump
  `pdf_version` to at least 1.6 (A2 already guarantees this for X-4, so the
  `1.5` downgrade branch at `:2980` must not run for X-4).
- **Files:** `src/Mpdf.php:2964-2987` (`BeginLayer`), `:2100-2106` (visibility),
  the `/OCProperties` catalog writer.
- **Verify:** Tier 1 — a named layer produces an OCG with a `/D` default config
  in X-4; still refused in X-1a. Tier 2 preflight.
- **Size:** ~25 LOC.

### D5 — Transparency blending colour-space conformance

- **Root cause:** X-4 requires that transparency groups and soft masks resolve
  in a device-independent (ICC/CIE) or output-intent-consistent blending colour
  space; a bare device colour space on a transparency group can fail preflight.
- **Approach:** When X-4 emits a transparency group (`/Group << /S /Transparency
  ... >>`), set the group colour space `/CS` to the ICC-based space backing the
  output intent (or a CIE space), rather than leaving it device-dependent.
  Audit the group-emitting sites (watermark overlay, form XObjects) and attach
  `/CS`. This is the subtle part of "transparency is allowed" — allowed *only*
  with a well-defined blending space.
- **Files:** transparency-group emission in `src/Mpdf.php` /
  `src/Writer/*` (the XObject `/Group` writers touched by D1–D2).
- **Verify:** Tier 2 preflight is authoritative here; Tier 1 asserts a `/CS`
  accompanies every `/S /Transparency` group in X-4.
- **Size:** ~30 LOC.

---

## Phase E — Colour policy & metadata parity

### E1 — Colour-space policy keyed to the output-intent profile

- **Root cause:** mPDF forces **all** colour to CMYK whenever `PDFX` is set
  (`ColorSpaceRestrictor.php:116-199`, `ImageProcessor.php:679`, `:856`). That is
  conformant for a CMYK output intent, but X-4 also permits calibrated/ICC RGB
  against an **RGB** output intent — which the hard CMYK conversion makes
  impossible. Per no-deferrals this is addressed, not labelled a limitation.
- **Approach:** Derive the permitted colour policy from the output intent's
  component count (`/N`, set at `MetadataWriter.php:285-289`): with a CMYK intent
  keep today's CMYK forcing; with an RGB intent (`/N 3`, e.g. a user-supplied
  RGB `ICCProfile`) allow ICC/calibrated RGB to pass through and skip the
  RGB→CMYK conversion. DeviceRGB with no defining space stays prohibited in both
  cases. Route the decision through the `pdfxAllowsTransparency()`/version
  helper plus the intent `/N` so X-1a is untouched.
- **Files:** `src/Color/ColorSpaceRestrictor.php`, `src/Color/ColorConverter.php:203`,
  `src/Image/ImageProcessor.php:679`, `:856`, `src/Writer/ImageWriter.php:59-73`.
- **Verify:** Tier 1 — with a CMYK intent, RGB is converted (unchanged); with an
  RGB intent, an ICC-tagged RGB image passes through untouched. Tier 2 preflight
  on both.
- **Size:** ~50 LOC.

### E2 — XMP ↔ Info parity (Trapped, timestamps, IDs)

- **Root cause:** X preflight expects the XMP and Info dictionaries to agree.
  `writeInfo()` sets `/Trapped` and dates (`MetadataWriter.php:218-224`) but the
  XMP packet does not carry a matching `pdf:Trapped`, and CreationDate/ModDate
  are generated independently in Info vs XMP (mirror of the PDF/A-3 plan's
  metadata-parity items).
- **Approach:** Add `pdf:Trapped` to the XMP `pdf:` description matching the
  Info `/Trapped`; compute one timestamp and reuse it for Info `/CreationDate`,
  `/ModDate`, and the XMP `xmp:CreateDate`/`xmp:ModifyDate`; keep the
  `xmpMM:DocumentID`/`InstanceID` pair (`:163-165`) consistent.
- **Files:** `src/Writer/MetadataWriter.php:163-165`, `:186-225`, XMP builder
  `:70-118`.
- **Verify:** Tier 1 — Info and XMP report identical Trapped and timestamps.
- **Size:** ~25 LOC.

### E3 — Pin the remaining X-1a literal strings

- **Root cause:** Warning/exception strings scattered across the codebase say
  "PDFX/1-a" (`src/Mpdf.php:4269`, `:11123`, `:12077`; `Form.php:529`;
  `Image/Bmp.php:52`; `ImageProcessor.php:882`; `ImageWriter.php:60`, `:73`).
  Under X-4 they misreport the profile.
- **Approach:** Route these through `pdfxVersionLabel()` (or a shared message
  helper). Mechanical, but do it in one pass so no user-facing string lies about
  the active profile. This is the bulk of A3's "touch the warning strings".
- **Files:** the sites listed above.
- **Verify:** Tier 1 — grep the emitted log for a forced violation in X-4 mode;
  no "PDFX/1-a" remains.
- **Size:** ~15 LOC.

---

## Suggested execution order

1. **A1 → A2 → A3** — the mode exists, asserts 1.6, and reports itself
   correctly. **A4** in parallel (scaffolding unblocks everything).
2. **B1 → B2 → B3 → B4 → B5** — the file now *is* identifiable X-4 with a valid
   embedded output intent. At this point an X-4 doc with no transparency should
   pass Tier-1 and external preflight. **A5** fixture lands here.
3. **C1–C5** — close the prohibited-feature holes (shares code with the PDF/A-3
   plan; land whichever lands first and widen the predicate for the other).
4. **D1 → D2 → D3 → D4 → D5** — turn on the X-4 capabilities that justify the
   mode; D5 is the correctness-critical companion to D1–D2.
5. **E1 → E2 → E3** — colour-policy generalisation and metadata polish.

**Run after each phase:**

- Tier 1: `vendor/bin/phpunit tests/Mpdf/PdfX` (deterministic, always on).
- Tier 2 (when available): `PDFX_PREFLIGHT_BIN=/path/to/preflight
  vendor/bin/phpunit --group=pdfx-preflight`.
- Regression: the existing `PDFX=true` (X-1a) suite must stay green — every
  version-gated change keeps the legacy path byte-for-byte where practical.

## Relationship to the other conformance plans

- **Shared with PDF/A-3** (`PDFA3_REMEDIATION_PLAN.md`): JavaScript/action
  removal (C1), `/Interpolate` prohibition (C3), annotation appearance handling,
  encryption guard, and metadata-parity mechanics (E2). Land the shared code
  once and widen the predicate to `PDFA || PDFX`; do not fork it.
- **Coexistence:** PDF/X-4 and PDF/A can share one file (an X-4 doc that is also
  PDF/A-3 is common in print archival). The `pdfaid`/`pdfxid`/`pdfuaid` XMP
  blocks are already independent `if`s (`MetadataWriter.php:128-161`), and a
  single embedded ICC can serve both output intents. This plan keeps the X-4
  additions orthogonal so a future combined mode is a wiring exercise, not a
  rewrite.
