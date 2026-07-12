mPDF 8.3.x
===========================

New features
------------
* PDF/UA-1: `StructureElement::sanitiseIdForPdf()` now truncates overlong ids on `#xx` token boundaries (audit E14). The escaped id is built from single safe bytes and 3-byte `#xx` escapes, but the length cap previously cut it with a fixed-offset `substr($out, 0, 108)`; with a long non-ASCII id (e.g. `str_repeat('a',107) . str_repeat('é',8)`, each `é` expanding to `#C3#A9`) the offset landed one byte into a `#xx` token and produced a bare `#` (or `#` + one hex digit), violating the ISO 32000-1 §7.3.5 name production and corrupting the `/ID` / `/Headers` cross-reference name. The cap now walks the escaped output token-by-token, stops before the first token that would overflow the limit, and appends the `#2D`-joined sha1 suffix — so a `#xx` sequence is never split and the result is always a valid PDF name. New `tests/Mpdf/Ua/StructureElementTest.php` sweeps the ASCII-prefix length across the cut point and asserts the output is a well-formed name at every alignment. (@jakejackson1)
* PDF/UA-1: `FpdiStructMerger` no longer calls `spl_object_id()` (audit E13). That function was only added in PHP 7.2, but `composer.json` still advertises support for PHP 5.6/7.0/7.1, so a tagged FPDI import on those versions fatalled with `Call to undefined function spl_object_id()` (FPDI 2.x itself runs on 5.6+, so the struct-merge path is reachable there). Both cycle/identity keys — the `cloneElement()` clone-cycle key and the `collectSanityCandidates()` sanity-walk key — now use `spl_object_hash()`, available since PHP 5.x, and the two call sites agree so the inline-dict cycle detection still matches across the clone and sanity passes. The `composer.json` `php` constraint is deliberately left unchanged (a maintainer policy decision). New `testTaggedImportUsesPhp56CompatibleObjectKeys` in `FpdiStructMergerTest` drives a tagged import through both cycle-key paths and guards against reintroducing `spl_object_id()`. (@jakejackson1)
* PDF/UA-1: floated block elements are no longer silently demoted to `/Artifact` (audit E10). `BlockTag::open()` previously wrapped any `float:left`/`float:right` block without an explicit `role`/`aria-label` in `/Artifact`, excluding its entire subtree from the structure tree — a plain `<div style="float:left">…real text…</div>` (present in the branch's own `example10` fixture) was dropped from the reading order and skipped by assistive technology (Matterhorn 01-001) with no author-facing signal. A CSS float is a visual-positioning hint, not an accessibility one: floated content is real content and is now tagged in reading order with its normal struct type (e.g. `Div`). `role="presentation"` remains the explicit opt-out. New `testFloatedDivIsTaggedNotArtifact` and `testFloatedDivWithRolePresentationStaysArtifact` regressions in `StructureElementsTest`; the `example10_floating_and_fixed_position_elements` veraPDF conformance case confirms the floats are now tagged (@jakejackson1)
* PDF/UA-1: fixed a malformed ToUnicode CMap for supplementary-plane codepoints in embedded TrueType subsets (audit E4). `FontWriter`'s `/Encoding /Identity-H` bfchar loop wrote the bfchar *source* token as `sprintf('%04X', $codepoint)`, so an astral scalar such as U+1F600 produced a five-hex-digit `<1F600>` token. That odd-width source violates the declared `<0000> <FFFF>` codespacerange (ISO 32000-1 §9.10.3.1/§9.10.3.2), which makes the entire ToUnicode stream unusable and breaks copy/paste text extraction for *every* glyph in the font — in non-PDF/UA output as well as PDF/UA. Any document that put a supplementary-plane character (emoji, CJK Ext-B, math alphanumerics) into a non-SMP/SIP font's subset was affected, because `Mpdf::UTF8StringToArray()` records every codepoint in the subset unconditionally. The source token is now the 2-byte code the content stream actually shows: BMP codepoints are unchanged (identity), and each supplementary codepoint is split into its two UTF-16BE surrogate code units, each mapped to itself so the pair round-trips to the original scalar on extraction. Entries are keyed by source code so surrogate units shared between astral codepoints (e.g. U+1F600 and U+1F601 both begin with high surrogate D83D) are de-duplicated rather than emitting a conflicting duplicate bfchar source, and a guard throws if any source token would exceed the 2-byte codespace. The separate SMP/SIP simple-font subset writer (`/Encoding` 1-byte codespace) was already correct and is unchanged. New `tests/Mpdf/Ua/ToUnicodeCMapTest.php` asserts every source token is 2 bytes, the astral surrogate-pair round-trip, shared-surrogate de-duplication, and BMP identity; a `testDocumentWithAstralCodepointsPassesUa1` case (Aegean SMP font) extends the veraPDF conformance gate. (@jakejackson1)
* PDF/UA-1: encrypted documents now keep their XMP metadata stream genuinely readable (audit E3). Previously `MetadataWriter::writeMetadata()` declared `/Filter [/Crypt] /DecodeParms <</Name /Identity>>` on the plaintext metadata stream while `writeEncryption()` only emitted an RC4 `/V 1|2` handler with no crypt filters (ISO 32000-1 §7.6.5) — a conforming reader had no Identity filter to apply, fell back to the document RC4 default, and decrypted the plaintext into garbage, destroying `dc:title` and `pdfuaid:part` on every encrypted PDF/UA-1 document. `Mpdf::SetProtection()` now switches PDF/UA documents to a `/V 4` security handler (`Protection::useV4WithUnencryptedMetadata()`): RC4 (`CFM /V2`) still encrypts every stream and string via `StdCF`, but `/EncryptMetadata false` plus the Identity crypt filter make the metadata stream a valid per-stream exemption so PDF/UA processors recover `pdfuaid:part`/`dc:title` without the file key. The file encryption key derivation folds in the `0xFFFFFFFF` metadata marker (ISO 32000-1 §7.6.3.3 Algorithm 2 step (g)). Non-PDF/UA encrypted documents are unchanged (still `/V 1|2`). New `tests/Mpdf/Ua/EncryptedUaMetadataTest.php` asserts the `/V 4` dictionary, the Identity metadata exemption, plaintext `pdfuaid:part`/`dc:title`, still-encrypted body content, and the unchanged non-UA path; a `testEncryptedUaMetadataPassesUa1` case extends the veraPDF conformance gate. (@jakejackson1)
* PDF/UA-1: `<a href="javascript:...">` and `<a href="vbscript:...">` are now treated as policy-blocked schemes (audit 2026-05-01 L2). **Strict mode (`PDFUAauto=false`)** throws `MpdfException` citing Matterhorn 17-001 / 28-002 — most PDF readers refuse to execute these URIs, AT announces them as dead links, and they are not keyboard-equivalent (WCAG 2.1 §2.1.1), so a strict-mode document must remove the link, supply a real URL, or opt into auto mode. **Auto mode (`PDFUAauto=true`)** strips the link entirely (no Link annotation, no Link struct element, no `/URI` action), preserves the visible inner text as plain inline content, preserves `lang=` and `aria-label=` as a `Span` struct element when present, and emits a single `addWarning()` per stripped anchor citing the offending href. The annotation writer (`MetadataWriter::writeAnnotations()`) carries a defence-in-depth guard: any `/URI` action whose URI matches the policy regex is dropped from the link annotation, catching third-party `Mpdf::Link()` injections and FPDI-imported policy-blocked URIs that bypass the `Tag\A` entry point. Helper class `Mpdf\Ua\UaPolicy::isPolicyBlockedHref()` centralises the regex (case-insensitive, leading-whitespace tolerant). `data:`, `file:`, `mailto:`, `tel:` and other schemes remain unaffected. New test class `JavascriptUrlHandlingTest` covers strict throws, auto-mode strip, ARIA/lang preservation, the writer-side guard, and the boundary set of permitted schemes; one new probe added to `PoorHtmlAutoModeTest` (now 21 cases). (@jakejackson1)
* PDF/UA-1: encrypted FPDI source detection (audit gap L1 from the 2026-05-01 expert review). FPDI's vendor cross-reference loader rejects any PDF whose trailer carries `/Encrypt` (ISO 32000-1:2008 §7.6) by throwing `CrossReferenceException::ENCRYPTED` (0x010C); previously this exception bubbled out of `setSourceFile()`/`importPage()` aborting the entire render with no PDF/UA-1 context. `FpdiTrait::setSourceFile()` and `setSourceFileWithParserParams()` now intercept the throw under `PDFUA=true`: in **strict mode** (`PDFUAauto=false`) they re-throw as `\Mpdf\MpdfException` with a Matterhorn 01-007 citation explaining that the source must be decrypted upstream (`qpdf --decrypt`) or `PDFUAauto` enabled; in **auto mode** (`PDFUAauto=true`) they record the file as encrypted, return a synthetic page count of 1, and `importPage()` returns a Tier 0 placeholder pageId (`mpdf-ua-encrypted-page:…`) which `useImportedPage()` renders as `/Artifact <</Type /Layout>> BDC … EMC` — no Form XObject, no struct tree, host page still renders. A diagnostic warning citing ISO 32000-1 §7.6 is added via `getPdfUaWarnings()`. Forward-compatibility guard `FpdiStructMerger::verifyAndPrepareMerge()` walks up to 8 candidate `/Alt`, `/ActualText`, and `/Lang` strings on tagged sources before merge, running them through a printable-codepoint sanity gauntlet (≥ 50% legible codepoints, ≤ 4 KiB length, U+FFFD-laden strings rejected) so that future FPDI releases lifting the encryption refusal cannot leak still-encrypted ciphertext into the host StructureTree (ISO 32000-1 §7.6.5 strings-only encryption); failure demotes the page from Tier 2 to Tier 1 in auto mode and throws `MpdfException` in strict mode. Opt-out via `fpdiSkipEncryptedStringSanityCheck=true` for legitimate non-Latin content hitting a false positive. `FpdiStructMerger::sourceIsEncrypted()` exposes the trailer-/Encrypt check directly. `cloneElement()` strict-mode escalation surfaces undecodable `/Alt` / `/ActualText` / `/Lang` attributes as named exceptions instead of silently skipping. New `FpdiEncryptedSourceTest` covers strict throw, auto-mode `/Artifact` placeholder, sanity-gauntlet demotion, the kill-switch flag, and the regression path for non-PDFUA callers (still receive raw `CrossReferenceException`). (@jakejackson1)
* PDF/UA-1 (M2 audit fix): `<a name="x">…</a>` destination anchors and `<a href="">…</a>` (empty / whitespace-only href) anchors no longer open a `Link` struct element. HTML5 §4.5.1 — an `<a>` without a usable hyperlink target is plain inline text plus an optional named destination, not a hyperlink, so emitting a `Link` (which requires an OBJR to a link annotation per ISO 32000-1 §14.8.2.4 Table 335 / Matterhorn 02-003) is incorrect. Pre-fix the empty-href case opened a `Link` whose body provided no MCRs, then either got pruned in `PDFUAauto` mode or threw a misleading "empty link" `MpdfException` in strict mode for what was usually just a templating artefact around a destination anchor. `Tag\A::open()` now treats `href` absent / empty / whitespace-only identically — no `Link`, but a `Span` is opened when the non-hyperlink anchor carries `lang` or `aria-label` so the inline accessibility metadata still has a host element (Matterhorn 11-001/11-002). The `pruneEmptyLinks()` / `findFirstEmptyLinkHref()` defence-in-depth at `StructureWriter::writeStructTree()` is retained for the residual case of authored hyperlinks with non-empty `href` but empty bodies. New cross-call state property `Mpdf::$pdfuaAnchorStructType` lives on `Mpdf` (not `Tag\A`) because the tag dispatcher constructs a fresh `Tag\A` instance per open/close call. Six new regressions in `HighBugRegressionsTest` cover the M2 matrix; the existing MEDIUM-A test docblock is annotated to clarify the new responsibility split. (@jakejackson1)
* PDF/UA-1: HTML ruby annotation tags (`<ruby>`, `<rb>`, `<rt>`, `<rp>`, `<rtc>`) are now parsed instead of stripped — added to `Mpdf::DisableTags()` `enabledtags` and given handler classes in `src/Tag/`. Each ruby part is tagged with its proper standard PDF struct type (ISO 32000-1 §14.8.5.6 Table 337): `<ruby>` → `Ruby`, `<rb>` → `RB`, `<rt>` → `RT`, `<rp>` → `RP`, mapped via `StructType::$tagMap` with the types whitelisted in `StructType::$validTypes` (the `Warichu`/`WT`/`WP` family is whitelisted too so a `role=` override can reach it). `Tag/Ruby.php`, `Tag/Rb.php`, `Tag/Rt.php` and `Tag/Rp.php` open their respective element beneath the enclosing `Ruby`; a bare-text base (no `<rb>`) attaches its content directly to the `Ruby`, which is valid ruby structure. `<rtc>` (HTML5 ruby text container) has no PDF standard type, so `Tag/Rtc.php` renders transparently — its `<rt>` children attach directly to the `Ruby` — and only materialises a `Span` when `lang=`/`aria-label=` forces an element onto it. Closes the audit-2026-05-01 L4 semantic-loss observation by guaranteeing every ruby part is tagged as real content (ISO 14289-1 §7.1) with the correct semantic type rather than dissolving into the surrounding block or an anonymous Span. The rt annotation is visually stacked above the rb: `Tag\Ruby`/`Rt`/`Rp` mark each run's `textparam['ruby']` (`base`|`rt`|`rp`), `RT` gets a `font-size:50%` default and `Tag\Rt` a `text-baseline` raise (reusing the `<sup>` ascent/paint machinery), and `Mpdf::_buildRubyClusters()` drives the placement loops in both `finishFlowingBlock()` and `WriteFlowingBlock()` to advance the cluster by `max(base, annotation)`, centre the raised annotation over the base (a wider annotation centres the base under it), and suppress `<rp>` fallback parentheses while keeping the RP struct element. Ruby runs are excluded from justification stretch so the painted base width matches the cluster advance. All stacking logic is gated on the per-run marker, so non-ruby text is byte-for-byte unchanged; new `tests/Mpdf/Ua/RubyStackingTest.php` (8 cases) asserts the geometry from the content stream. Six probes in `PoorHtmlAutoModeTest` (ruby with rp fallbacks, ruby inside `<h1>`/`<a href>`, bare-text base, nested ruby, `<rtc>`), a struct-element regression in `StructureElementsTest`, a tag-map/valid-types unit test in `StructTypeTest`, and a `testDocumentWithRubyAnnotationsPassesUa1` case in the veraPDF conformance gate (@jakejackson1)
* PDF/UA-1: SVG `<title>` and `<desc>` are now lifted into the rasterised Figure's `/Alt` when the host `<img>` has no alt attribute (W3C SVG 1.1 §5.4 / Matterhorn 13-004). New `Svg::extractAccessibleMetadata()` runs only when `PDFUA=true`, parses just the top-level `<title>`/`<desc>` direct children of the root `<svg>` element via SimpleXML inside `libxml_use_internal_errors(true)` (so malformed SVG cannot raise warnings), normalises whitespace (titles collapse all runs; descriptions preserve newline boundaries), decodes numeric character entities and CDATA, and stashes the result on `svg_info['accessible_title']`/`['accessible_desc']`. The metadata propagates through `ImageProcessor::processSvg()` into `formobjects[$file]` and is consumed by both `Mpdf::printobjectbuffer()` (`<img>`-driven path) and `Mpdf::Image()` (direct-PHP path). Inline `<svg>` markup — which the `WriteHTML()` preprocessing rewrites to `<img src="…tempSVG…svg">` with no alt attribute — therefore now produces a meaningful `/Figure /Alt` instead of being demoted to `/Artifact`. HTML alt precedence is preserved: an explicit non-empty `alt="…"` overrides SVG metadata; `alt=""` still forces `/Artifact BMC` (decorative). Strict-mode (`PDFUAauto=false`) only throws when neither HTML alt nor SVG `<title>`/`<desc>` is available; the throw/warn message text now mentions the SVG title/desc option. New `tests/Mpdf/Ua/SvgAccessibleMetadataTest.php` (13 cases covering auto/strict modes, HTML alt precedence, inline-SVG rewrite path, nested-`<title>` exclusion, CDATA + numeric-entity decoding, malformed SVG safety) plus 9 unit tests on `extractAccessibleMetadata()` in `tests/Mpdf/Image/SvgTest.php`; veraPDF gate extended with `svg-accessible.html` fixture (@jakejackson1)
* PDF/UA-1: HTML image maps (`<img usemap="#name">` + `<map name>` + `<area>`) — closes M3 from the 2026-05-01 expert audit. New `Tag\Map` and `Tag\Area` handlers register every `<area shape coords href alt>` against a per-document registry on `Mpdf::pdfUaImageMaps`; `Tag\Img::open()` carries `pdfua_image_map_name` through `$objattr`; `Mpdf::printobjectbuffer()` queues a deferred emission entry per host image so the registry is consulted *after* `WriteHTML()` finishes parsing (HTML5 §4.8.13 permits the `<map>` to appear after the host `<img>`). At drain time `processDeferredImageMaps()` reuses the existing OBJR/StructParent wiring (`Mpdf::Link()` + `MetadataWriter::writeAnnotations()`): for each area it pushes the host Figure back onto the StructureTree stack, opens a Link struct element with `/Alt` from the area's alt text (Matterhorn 28-002), captures the element on `pdfuaLinkStructElem` so `Mpdf::Link()` threads the StructParent wiring through, and emits a Link annotation whose `/Rect` is the placed-image-relative bounding box of the area's shape (rect / circle / poly / default). Internal `href="#frag"` produces `/Dest`; external `href="https://…"` produces `/A <</S /URI>>`. Strict mode (`PDFUAauto=false`) throws on missing `alt`; auto mode warns and synthesises `'Link to ' + href`. Rotated/transformed host images emit each hotspot as `/QuadPoints` — the area is mapped through the exact `$tr`/`$tr2` render matrix mPDF draws the image with (ISO 32000-1 §8.3.4) so the clickable region tracks the rotation, with `/Rect` as its bounding box. Decorative images (`alt=""`) or unknown maps warn-and-skip with diagnostics on `getPdfUaWarnings()`. New `tests/Mpdf/Ua/ImageMapTest.php` (22 cases) covers byte-level Link annotation, OBJR kid, /Alt encoding, shape conversion, href routing, rotated-hotspot QuadPoints geometry, and the strict/auto policy. New veraPDF fixture `imagemap.html` extends the conformance gate. Two new probes added to `PoorHtmlAutoModeTest`. (@jakejackson1)
* PDF/UA-1: closed three audit gaps from the 2026-05-01 expert review. **H1 (inline language tagging)** — `<span lang="…">` and `<span aria-label="…">` mid-paragraph now emit a Span struct element carrying `/Lang` and/or `/Alt` (ISO 14289-1 §7.2 / Matterhorn 11-001/11-002, Table 322); plumbed via a per-tag `InlineUaStruct` depth stack in `Mpdf` so `InlineTag::open()`/`close()` push and pop the right number of brackets across nested same-name tags, with a `pushInlineUaStructDepth()` subclass hook used by `Tag/Abbr.php` to layer its `/E` Span on top without double-stacking `/Lang`. **H2 (form-grouping struct types)** — `<fieldset>` → `Sect`, `<form>` → `Div`, `<legend>` → `Caption` added to `StructType::$tagMap` so HTML form-grouping elements no longer produce untagged real content (rule 7.1#3). **M1 (TH scope axis)** — `<th scope="rowgroup">` now maps to `/Scope=Row` and `<th scope="colgroup">` to `/Scope=Column` (HTML axis-equivalent), replacing the prior incorrect coercion to `/Scope=Both` (ISO 32000-1 Table 349). Five new probes added to `PoorHtmlAutoModeTest` (now 20 cases, all veraPDF-clean) plus six byte-level regressions in `StructureElementsTest` and a tag-map unit test in `StructTypeTest` (@jakejackson1)
* PDF/UA-1: legacy form chrome (`useActiveForms=false` widgets — `<input>`, `<textarea>`, `<select>`, `<button>`, checkbox, radio, image-button) now emits as `/Artifact BMC … EMC` when rendered at page level (no enclosing tagged BDC), satisfying ISO 14289-1 §7.1 / Matterhorn 01-006 (untagged real content). Each `Form::print_ob_*` else-branch wraps its drawing operators in a marked-content artifact bracket, gated on `MarkedContentHelper::getDepth() === 0` so the wrap is skipped when an enclosing real-content BDC is open (Matterhorn 01-001/002 forbid nesting Artifact inside tagged content). Inert form chrome inside a `<p>` therefore flows as part of the surrounding tag's content; chrome at block-level renders as a marked artifact. Combination `PDFUA=true` + `useActiveForms=false` is now a first-class supported configuration without constructor-side intervention (the prior throw/auto-flip guard in `Mpdf::__construct` is removed). Legacy `<select>` chrome additionally substitutes the active font's U+25BC down-arrow for the ZapfDingbats glyph in PDFUA mode (matching the existing PDFA/PDFX handling), satisfying ISO 14289-1 §7.21 / Matterhorn 14-002 (no core-font embedding). New veraPDF fixture `legacy_forms_useactiveformsfalse.html` extends the conformance gate from 41 → 42 fixtures. Public `Mpdf::getPdfUaStructureTree()` accessor added so collaborators outside the Mpdf class hierarchy (Form.php) can reach the artifact-suppression scope without touching the private `$ua` field. (@jakejackson1)
* PDF/UA-1: extended veraPDF coverage from 27 → 37 mpdf-examples fixtures (examples 03_backgrounds_and_borders, 09_forms, 11_overflow_auto, 18_headers_method_4, 19_page_sizes, 20_justify, 21_hyphenation, 23_orientation, 24_orientation_2, 38_dot_tab); body-level backgrounds (color, gradient, background-image at the `___BACKGROUND___PATTERNS` placeholder) now wrapped as `/Artifact BMC … EMC` in `PrintBodyBackgrounds()` to satisfy ISO 14289-1 §7.1 / Matterhorn 01-002 (was untagged real-content above the page-background BMC); radio-group widget annotations in `Form::_putRadioItems()` now emit `/TU` (alternate description) with the field-name fallback when not supplied, satisfying Matterhorn 19-003 (was missing /TU on radio parent fields) (@jakejackson1)
* PDF/UA-1: cross-page block content (paragraphs, lists, divs, headings, blockquotes, and any element opened via `BlockTag::open()`) now produces one MCID per page that the content touches, satisfying Matterhorn 01-006 (untagged real content) and ISO 32000-1 §14.7.4.4 Table 324 (MCR `/Pg N 0 R /MCID n` per page). Implementation: `Mpdf::ensureBlockBdcOpen()` lazy per-page opener routed through `StructureTree::addContentForElement()` against the block's struct element ref captured onto `$blk[$blklvl]['pdfua_struct_elem']` at tag-open time (mirroring the `Td.php` pattern); `Mpdf::closeBlockBdcIfOpen()` close-fence inserted before every mid-block `AddPage()` call (`finishFlowingBlock`, `WriteFlowingBlock`, `Cell()` auto-page-break) so each page's BDC and EMC reside in the same `/Contents` stream (PDF §14.6); `_beginpage()` pre-allocates the page's `/StructParents` integer at page-creation time so `addContentForElement()` sees the correct per-page key during HTML rendering; same fix incidentally tags multi-line same-page paragraph content that was previously emitted by `WriteFlowingBlock` without BDC bracketing (only the final line reached `finishFlowingBlock(true)` under the old contract); 7 new cross-page tests (paragraph, list, blockquote, div, heading, nested block, artifact block) plus a single-page regression guard (@jakejackson1)
* PDF/UA-1: added 7 priority compliance tests (Matterhorn 01-006, 11-002, 13-008, 21-001, U5; §A6 ligature exemplar; annotation StructParent round-trip); Figure struct elements now carry /BBox [llx lly urx ury] in a /Layout attribute object (Matterhorn 13-008, plan §A4); LI struct elements now emit a Lbl child for position:outside list markers (disc, circle, square, ordered counters) and an LBody child for item content, satisfying Matterhorn 21-001 for the common case.
* PDF/UA-1: veraPDF conformance gate integrated into CI (`@group verapdf`); local invocation documented in CONTRIBUTING.md.
* PDF/UA-1 Phase 5 (Ligature ActualText — Matterhorn 24-001): `Otl::GSUBsubstitute()` now records the source Unicode codepoints of every LookupType 4 (ligature) substitution as `ligature_source` inside `GPOSinfo` (survives `sliceOTLdata()` reindexing); `LigatureActualTextWriter` fully implemented with `buildBdcBytes()`, `buildEmcBytes()`, `getActualTextEncoding()` (UTF-16BE with BOM), and `toUnicodeCovers()` (synthetic multi-char CMap skip check); `applyGPOSpdf()` in `Mpdf` detects ligature positions and splices `/Span <</ActualText <FEFF…>>> BDC … EMC` string fragments around the ligature glyph's TJ segment so BDC/EMC appear as page-description operators between TJ calls within the BT…ET block (ISO 32000-1 §14.6); wrapping is only active when `$this->PDFUA` is truthy; `LigatureActualTextTest` covers fi, ff, ffl ligatures, the no-ligature no-wrapper guard, the ToUnicode-covers skip path, and nested struct ordering (@jakejackson1)
* PDF/UA-1 Phase 4 (FPDI Tier 2 tagged-source struct merger): `FpdiStructMerger::sourceIsTagged()` detects `/StructTreeRoot` in source PDF catalog; tagged sources take Tier 2 path — struct subtree cloned into host `StructureTree` via `mergePageStructSubtree()` (no Artifact wrap); `FpdiTrait::useImportedPage()` branches Tier 1 (untagged → Artifact BDC) vs Tier 2 (tagged → struct merge); Form XObject receives `/StructParents N` injected into `PdfStream` dict at write time; MCR dicts carry `/Stm` referencing the Form XObject per ISO 32000-1 §14.7.4.4 Table 324; `StructureElement::addMcid()` extended with `$stm` param; `StructureElement::patchMcr()` patches placeholder MCR entries with real PDF object numbers after write-time allocation; `StructureWriter` MCR branch emits `/Stm N 0 R` when stm > 0 and fixes bare-integer optimisation to exclude stm-carrying MCRs; `StructureTree::registerImportedMcr()` added for ParentTree back-map from imported MCIDs; `PageWriter` populates `pageDim[$n]['n']` so MCR `/Pg` refs resolve correctly; `Mpdf::getSourcePdfReader()` public accessor wraps vendor protected method; `Mpdf::getPdfUaNextStructParents()` public accessor for cross-class /StructParents allocation; `FpdiStructMerger::recordHostPage()` tracks host page numbers at render time; `FpdiStructMerger::patchMergedSubtreeObjectNumbers()` resolves placeholder pageRef and stm to real object numbers after `writeImportedPagesAndResolvedObjects()`; RoleMap entries merged from source catalog (first-wins per ISO 32000-1 §14.7.3); `addPerPageMcrKids()` handles SetPageTemplate reuse (all MCRs across all elements via `mergedMcrs` slots, not just first) without struct duplication (@jakejackson1)
* PDF/UA-1 Phase 4 (direct PHP methods + ARIA): `AutosizeText()` wraps its `Cell()` call as a `Span` struct element; `SetProtection()` throws in strict mode (`PDFUAauto=false`) or warns and force-adds `extract` in auto mode when the accessibility permission bit is omitted (Matterhorn 07-001); ARIA wiring (`registerId` + `aria-*` queue loop) added to Table, TR, TD, TH, Abbr/Acronym, BarCode, and TextCircle tag handlers; `AriaIdResolver` normalises all IDs to lowercase for case-insensitive matching (fixes `id=` value uppercasing in mPDF's HTML parser); ARIA attributes carried through `$objattr` for render-time Figure/Span struct elements (Img, BarCode, TextCircle); HTML `lang` attribute wires to `/Lang` on struct elements for block and inline tags (@jakejackson1)
* PDF/UA-1 Phase 4 (annotations + misc): sticky-note and file-attachment annotations tagged as `Note` struct elements with OBJR kid and `/StructParent` on annotation dict (`/F 28` and `/CA 1` flags extended to PDFUA); AcroForm widget annotations (text, select, button, radio, checkbox) tagged as `Form` struct elements with OBJR kid and `/StructParent`; radio/checkbox widgets auto-suppress ZapfDingbats (`formUseZapD=false`) in PDFUA mode (ZapfDingbats is a non-embeddable core font); barcodes tagged as `Figure` with `Alt = "Barcode: <code>"`; text watermarks and image watermarks tagged as `/Artifact <</Type /Background>> BDC…EMC`; `<textcircle>` tagged as `Span` with `ActualText`; `StructureTree::reserveAnnotStructParent()` and `registerAnnotStructParent()` added for deferred widget OBJR registration (@jakejackson1)
* PDF/UA-1 Phase 4 (lists + tables): `UL`/`OL` produce L struct elements; `LI` produces LI→LBody wrapper (content BDC under LBody); `DL`/`DT`/`DD` produce L→(implicit LI)→Lbl+LBody structure per Tagged PDF Best Practice Guide §4.2.3; `TABLE`/`TR`/`TD`/`TH` produce Table/TR/TD/TH struct elements with `_tableWrite()` BDC/EMC injection via `addContentForElement()`; `/Headers` attribute on TD cells produces /A <</O /Table /Headers [...]>> (Matterhorn 09-004/05); TH cells carry /Scope and unique /ID for header association; StructureWriter extended to emit /Headers arrays as PDF name-object arrays (@jakejackson1)
* PDF/UA-1 Phase 4: struct element tagging for block/inline/table/list/link/abbr tags; `AriaIdResolver` two-pass resolver for ID-referencing ARIA attributes (`aria-labelledby`, `aria-describedby`, `aria-details`, `aria-controls`, `aria-owns`, `aria-flowto`, `aria-activedescendant`); FPDI-imported pages wrapped as `/Artifact <</Type /Layout>> BDC…EMC` (Matterhorn 01-007 compliance) with diagnostic warning recorded via `getPdfUaWarnings()`; `getPdfUaFpdiStructMerger()` and `getPdfUaWarnings()` public accessors added (@jakejackson1)
* PDF/UA-1 Phase 3: content stream BDC/EMC tagging — `MarkedContentHelper` fully implemented (BDC/EMC operator emission via `BaseWriter::write()` for correct buffer routing); block content tagging infrastructure in `newFlowingBlock()`/`finishFlowingBlock()`; image tagging in `printobjectbuffer()` (Figure with /Alt for descriptive images, /Artifact BMC for decorative/missing-alt images with warning); HTML header/footer artifact marking in `_puthtmlheaders()` with struct-tree suppression via `openArtifact()`/`closeArtifact()`; BDC/EMC depth balance assertion in `_enddoc()` (throws or warns per PDFUAauto mode); `getPdfUaMarkedContentHelper()` public accessor added (@jakejackson1)
* PDF/UA-1 Phase 2: logical structure tree infrastructure — `StructureElement`, `StructureTree`, `StructType`, `StructureWriter`, `UaState` facade fully wired with six collaborators; `MarkedContentHelper`, `AriaIdResolver`, `LigatureActualTextWriter`, `FpdiStructMerger` stubs registered; StructTreeRoot, ParentTree NumTree, and RoleMap serialised to PDF at close time (@jakejackson1)
* PDF/UA-1 (ISO 14289-1:2014) Phase 1 foundation: `PDFUA` and `PDFUAauto` config flags, XMP pdfuaid:part metadata, /MarkInfo /Marked true, /Lang validation, /DisplayDocTitle enforcement, /StructParents + /Tabs /S on every page dict, core-font embedding rejection, PDF 1.7 version header, and `title` config key (@jakejackson1)
* Refactored CssManager (@jakejackson1, #2150)
* Snapshot testing (@jakejackson1, #2148)
* Reduce memory usage by using array buffer (@jorrit #2151)
* Add support for custom `AssetFetcher` via the service container (@splitbrain, #2165) 
* AVIF images Support

Bugfixes
--------
* PDF/UA-1: a multi-page encrypted FPDI source no longer silently loses every page but the first, and that first page is no longer blank (audit E2). `FpdiTrait::handleEncryptedSetSourceFile()` returned a synthetic page count of `1`, so a caller's `for ($i = 1; $i <= setSourceFile($file); $i++)` loop only ever imported page 1; and `drawEncryptedSourcePlaceholder()` emitted zero content operators, so even that page was an invisible zero-content BMC/EMC pair — a total, silent data loss in both modes. In auto mode (`PDFUAauto=true`) `setSourceFile()` now recovers the real page count by scanning the source's cleartext page tree (`/Type /Pages … /Count N`; a standard-security-handler document enciphers only strings and streams per ISO 32000-1 §7.6.2, so name tokens and integers stay readable without the key — new `countEncryptedSourcePages()` / `readSourceBytes()` handle path, resource, and `StreamReader` inputs), so the per-page loop reaches `importPage()` once per page and each placement draws a *visible* Tier 0 placeholder: a faint bordered rectangle (`re … S`) with a caption naming the redacted source and page number, in an embedded font, all inside the `/Artifact <</Type /Layout>> BDC … EMC` wrap (decorative, no tagging required — ISO 14289-1 §7.1). Strict mode (`PDFUAauto=false`) continues to throw `\Mpdf\MpdfException` at `setSourceFile()` time (an encrypted source cannot be made accessible without decryption), sharing one policy with the A3 active-reader import path. The visible-placeholder output validates clean against veraPDF (PDF/UA-1). New `FpdiEncryptedSourceTest` cases cover the 3-page recovery + N visible placeholders + warning in auto mode and the strict-mode throw; the existing single-source and strict-throw cases stay green. (@jakejackson1)
* PDF/UA-1: FPDI Tier 2 tagged-source import no longer drops the imported struct subtree's content (audit E1+E5). `FpdiStructMerger::normaliseKidsToArray()` now handles a bare-integer `/K` (the single-MCID shape mPDF's own writer prefers and most producers emit), so the MCID reaches the per-kid handler instead of the cloned element being an empty `StructElem` skeleton; and `StructureWriter::writeElement()` now prefers the patched `$mcr['pageRef']` (written by `patchMergedSubtreeObjectNumbers()`, previously dead) before the `buildPageRefMap()` fallback, so imported Form-XObject MCRs — whose `/StructParents` key lives on the XObject, not the page map — emit `<</Type /MCR /Pg … /Stm … /MCID …>>` (ISO 32000-1 §14.7.4.4 Table 324) instead of a bare integer that drops `/Pg` + `/Stm`. Neither fix is sufficient alone. Two new `FpdiStructMergerTest` cases and a `testFpdiTier2TaggedImportPassesUa1` veraPDF conformance case (@jakejackson1)
* Fix `TypeError` in `transformRotate` for non-numeric CSS transform values (#2056)
* Fix `TypeError` with non-numeric `rotate` values like `none` or `90deg` on tables (@derrabus, #2178)
* Small change to better support list-style-type on list items within tables
* Fixed parsing sheet-size CSS property for @page
* Conditional calls to functions removed in PHP 8.5

mPDF 8.2.x
===========================

New features
------------
* Watermark text can now be colored using `\Mpdf\Watermark` DTO. `\Mpdf\WatermarkImage` DTO for images. (#1876)
* Added support for `psr/http-message` v2 without dropping v1. (@markdorison, @apotek, @greg-1-anderson, @NigelCunningham #1907)
* PHP 8.3 support in mPDF 8.2.1
* Add support for `page-break-before: avoid;` and `page-break-after: avoid;` for tr elements inside tables

Bugfixes
--------

* Replace character entities with characters when processing the `code` attribute in the `<barcode />` tag
* Escape XML predefined entities in XMP metadata (Fix for #2090)
* Enable Font Subsetting by Default (Fix for #1315)

mPDF 8.1.x
===========================

New features
------------

* Service container for internal services
* Set /Lang entry for better accessibility when document language is available (@cuongmits, #1418)
* More verbose helper methods for `Output`: `OutputBinaryData`, `OutputHttpInline`, `OutputHttpDownload`, `OutputFile` (since v8.1.2)
* Set font-size to `auto` in textarea and input in active forms to resize the font-size (@ChrisB9, #1721)
* PHP 8.2 support in mPDF 8.1.3
* Added support for `psr/log` v3 without dropping v2. (@markdorison, @apotek, @greg-1-anderson, #1857)

Bugfixes
--------

* Better exception message about fonts with MarkGlyphSets (Fix for #1408)
* Updated Garuda font with fixed "k" character (Fix for #1440)
* Testing and suppressing PNG file conversion errors
* Prevent hyphenation of urls starting with https and e-mail addresses (@HKandulla, #1634)
* Colorspace restrictor reads mode from Mpdf and works again (Fix for #1094)
* Prevent exception when multiple columns wrap to next page
* Update default `curlUserAgent` configuration variable from Firefox 13 to 108

mPDF 8.0.x
===========================

* Ability to customize User-Agent header in the HTTP requests sent by cURL (@samuelecat, #1229)
* Add Page Number Myanmar Language Support (@MinKyawNyunt, #1201)
* new `Mpdf\Exception\FontException` extending base `MpdfException` was introduced and is thrown on Font manipulation
* A bit cleaner exception messages for font-related errors
* Use atomic cache writing. (@PATROMO, #1186)
* Fix: "Undefined index: group" when calling MultiCell when using font without OTL data (@Kekos, #1213, #941)
* Add C128RAW barcode type to create any barcode (ex: subtype change in middle of barcode) (#1124)
* Add proxy support to curl
* Fixed date and time format in the informations dictionary (#1083, @peterdevpl)
* Checking allowed stream wrappers in CssManager
* PHP 7.4 support (until final 7.4 release with composer --ignore-platform-reqs)
* Improve debugging of remote content issues (@ribeirobreno)
* Added `exposeVersion` configuration variable allowing to hide mPDF version from Producer tag and HTTP headers
* Added the check for JPEG SOF header 0xFF 0xC1 (extended) (@jamiejones85)
* Allows setting `none` as zoom mode in `SetDisplayMode` method, so that OpenAction is not written (#602)
* Allowed image stream whitelist to be customised (#1005, thanks @jakejackson)
* Fixed parsing of top-left-bottom-right CSS rules with !important (#1009)
* Fixed skipping ordered list numbering with page-break-inside: avoid (#339)
* Compound classes selector support, like `.one.two` or `div.message.special` (#538, @peterdevpl)
* Fixed CMYK colors in text-shadow (#1115, @lexilya)
* Skip non supported wrappers when resolving paths (#1204, @MarkVaughn)
* Fixed SVGs using a style tag, has styles ignored ( Requires ext-dom ) (#450, @antman3351)
* Allows `{nb}`, `{nbpg}`, `{PAGENO}` and `{DATE ...}` substitution in body (#172 and #267, @Dasc3er)
* Cache now creates a dedicated subdirectory `/mpdf`.
* It is possible to disable automatic cache cleanup with `cacheCleanupInterval` config variable
* PHP 8.0 is supported since 8.0.10 (#1263)
* Fix: First header of named page is added twice (@antman3351, #1320)
* Added `curlExecutionTimeout` configuration variable allowing to `CURLOPT_TIMEOUT` when fetching remote content
* Fix: Not all combinations were generated for more than three compound classes (@JeppeKnockaert)
* Added `quiet_zone_left` and `quiet_zone_right` to barcodes which support quiet zones in order to customize its width
* Updated `CssManager` to use the `RemoteContentFetcher` class instead of `curl` natively (@greew)
* Added optional `continue2pages` parameter to `SetDocTemplate` method, allowing a template to continue the last 2 pages alternately (@bmg-ruudv)
* Ensure that all digits of a string are hexadecimal before decoding in ColorConverter (@derklaro)
* Fix: Using mpdf in phar package leads to weird errors (#1504, @sandreas)
* WEBP images support (#1525)


mPDF 8.0.0
===========================

### 15/03/2019

* Updated FPDI dependency to version 2 (thanks a lot, @JanSlabon)
    - removed `SetImportUse` method
    - case of `ImportPage` method changed to `importPage`
    - similarly, case of `setSourceFile` and `useTemplate` was changed to a lowercase first letter.
    - signature of `importPage` changed
    - returned value of `useTemplate` changed
* Moved QRCode generating code portions to external package _mpdf/qrcode_
    - This reduced package size considerably (ca 6MB)
* Fraction sizes without leading zeros allowed for font sizes (#973, thanks @peterdevpl)
* WriteHTML is now strict about used `$mode` parameter (#915, thanks, @tomtomau)
* Fixed regression in nested tables (#860, thanks, @machour)
* Scientific notation handling in CSS font sizes (#753, thanks, @peterdevpl)


mPDF 7.1.x
===========================

* PHAR security issue fixed (thanks, @jakejackson)
* Font temporary data saved as JSON instead of generating PHP files (thanks, @jakejackson)
* cURL handling enhancements (thanks, @jakejackson)
* SVG parsing fixes (thanks, @achretien)
* Write PDF content with *Writer service classes
* PHP 7.3 is supported
* Added myclabs/deepcopy dependency, fixed TOC page numbering (thanks, @jakejackson)
* Custom color for QR codes
* Added support for orientation config key
* Code and tests cleanups and enhancements
    - PHPUnit dedicated assertions (thanks, @carusogabriel)
    - WriteHTML part constants (thanks, @tomtomau)
    - Various notice fixes (kudos to all respective authors)

mPDF 7.0.x
===========================

* Allow passing file content or file path to `SetAssociatedFiles` (#558)
* Allowed ^1.4 and ^2.0 of paragon/random_compat to allow wider usage
* Fix of undefined _getImage function (#539)
* Code cleanup
* Better writable rights for temp dir validation (#534)
* Fix displaying dollar character in footer with core fonts (#520)
* Fixed missed code2utf call (#531)
* Refactored and cleaned-up classes and subnamespaces


mPDF 7.0.0
===========================

### 19/10/2017

Backward incompatible changes
-----------------------------

- PHP `^5.6 || ~7.0.0 || ~7.1.0 || ~7.2.0` is required.
- Entire project moved under `Mpdf` namespace
    - Practically all classes renamed to use `PascalCase` and named to be more verbose
    - Changed directory structure to comply to `PSR-4`
- Removed explicit require calls, replaced with Composer autoloading
- Removed configuration files
    - All configuration now done via `__construct` parameter (see below)
- Changed `\Mpdf\Mpdf` constructor signature
    - Class now accepts only single array `$config` parameter
    - Array keys are former `config.php` and `config_fonts.php` properties
    - Additionally, former constructor parameters can be used as keys
- `tempDir` directory now must be writable, otherwise an exception is thrown
- ICC profile is loaded as entire path to file (to prevent a need to write inside vendor directory)
- Moved examples to separate repository
- Moved `TextVars` constants to separate class
- Moved border constants to separate class
- `scriptToLang` and `langToFont` in separate interfaced class methods
- Will now throw an exception when `mbstring.func_overload` is set
- Moved Glyph operator `GF_` constants in separate `\Mpdf\Fonts\GlyphOperator` class
- All methods in Barcode class renamed to camelCase including public `dec_to_hex` and `hex_to_dec`
- Decimal conversion methods (to roman, cjk, etc.) were moved to classes in `\Mpdf\Conversion` namespace
- Images in PHP variables (`<img src="var:smileyface">`) were moved from direct Mpdf properties to `Mpdf::$imageVars` public property array
- Removed global `_SVG_AUTOFONT` and `_SVG_CLASSES` constants in favor of `svgAutoFont` and `svgClasses` configuration keys
- Moved global `_testIntersect`, `_testIntersectCircle` and `calc_bezier_bbox` fucntions inside `Svg` class as private methods.
    - Changed names to camelCase without underscores and to `computeBezierBoundingBox`
- Security: Embedded files via `<annotation>` custom tag must be explicitly allowed via `allowAnnotationFiles` configuration key
- `fontDir` property of Mpdf class is private and must be accessed via configuration variable with array of paths or `AddFontDirectory` method
- QR code `<barcode>` element now treats `\r\n` and `\n` as actual line breaks
- cURL is prefered over socket when downloading images.
- Removed globally defined functions from `functions.php` in favor of `\Mpdf\Utils` classes `PdfDate` and `UtfString`.
    - Unused global functions were removed entirely.


Removed features
----------------

- Progressbar support
- JpGraph support
- `error_reporting` changes
- Timezone changes
- `compress.php` utility
- `_MPDF_PATH` and `_MPDF_URI` constants
- `_MPDF_TEMP_PATH` constant in favor of `tempDir` configuration variable
- `_MPDF_TTFONTDATAPATH` in  favor of `tempDir` configuration variable
- `_MPDFK` constant in favor of `\Mpdf\Mpdf::SCALE` class constant
- `FONT_DESCRIPTOR` constant in favor of `fontDescriptor` configuration variable
- `_MPDF_SYSTEM_TTFONTS` constant in favor of `fontDir` configuration variable with array of paths or `AddFontDirectory` method
- HTML output of error messages and debugs
- Formerly deprecated methods


Fixes and code enhancements
----------------------------

- Fixed joining arab letters
- Fixed redeclared `unicode_hex` function
- Converted arrays to short syntax
- Refactored and tested color handling with potential conversion fixes in `hsl*()` color definitions
- Refactored `Barcode` class with separate class in `Mpdf\Barcode` namespace for each barcode type
- Fixed colsum calculation for different locales (by @flow-control in #491)
- Image type guessing from content separated to its own class


New features
------------

- Refactored caching (custom `Cache` and `FontCache` classes)
- Implemented `Psr\Log\LoggerAware` interface
    - All debug and additional messages are now sent to the logger
    - Messages can be filtered based on `\Mpdf\Log\Context` class constants
- `FontFileFinder` class allowing to specify multiple paths to search for fonts
- `MpdfException` now extends `ErrorException` to allow specifying place in code where error occured
- Generating font metrics moved to separate class
- Added `\Mpdf\Output\Destination` class with verbose output destination constants
- Availability to set custom default CSS file
- Availability to set custom hyphenation dictionary file
- Refactored code portions to new "separate" classes:
    - `Mpdf\Color\*` classes
        - `ColorConvertor`
        - `ColorModeConvertor`
        - `ColorSpaceRestrictor`
    - `Mpdf\SizeConvertor`
    - `Mpdf\Hyphenator`
    - `Mpdf\Image\ImageProcessor`
    - `Mpdf\Image\ImageTypeGuesser`
    - `Mpdf\Conversion\*` classes
- Custom watermark angle with `watermarkAngle` configuration variable
- Custom document properties (idea by @zarubik in #142)
- PDF/A-3 associated files + additional xmp rdf (by @chab in #130)
- Additional font directories can be added via `addFontDir` method
- Introduced `cleanup` method which restores original `mb_` encoding settings (see #421)
- QR code `<barcode>` element now treats `\r\n` and `\n` as actual line breaks
- Customizable following of 3xx HTTP redirects, validation of SSL certificates, cURL timeout.
    - `curlFollowLocation`
    - `curlAllowUnsafeSslRequests`
    - `curlTimeout`
- QR codes can be generated without a border using `disableborder="1"` HTML attribute in `<barcode>` tag


Git repository enhancements
---------------------------

- Added contributing guidelines
- Added Issue template


mPDF 6.1.0
===========================

### 26/04/2016

- Composer updates
    - First release officially supporting Composer
    - Updated license in composer.json
    - Chmod 777 on dirs `ttfontdata`, `tmp`, `graph_cache` after composer install
- Requiring PHP 5.4.0+ with Composer
- Code style
    - Reformated (almost) all PHP files to keep basic code style
    - Removed trailing whitespaces
    - Converted all txt, php, css, and htm files to utf8
    - Removed closing PHP tags
    - Change all else if calls to elseif
- Added base PHPUnit tests
- Added Travis CI integration with unit tests
- Changed all `mPDF::Error` and `die()` calls to throwing `MpdfException`
- PDF Import changes
    - FPDI updated to 1.6.0 to fix incompatible licenses
    - FPDI loaded from Composer or manually only
- Removed iccprofiles/CMYK directory
- Renamed example files: change spaces to underscores to make scripting easier
- Fixed `LEDGER` and `TABLOID` paper sizes
- Implemented static cache for mpdf function `ConvertColor`.
- Removed PHP4 style constructors
- Work with HTML tags separated to `Tag` class
- Fixed most Strict standards PHP errors
- Add config constant so we can define custom font data
- HTML
    - fax & tel support in href attribute
    - Check $html in `$mpdf->WriteHTML()` to see if it is an integer, float, string, boolean or
      a class with `__toString()` and cast to a string, otherwise throw exception.
- PHP 7
    - Fix getting image from internal variable in PHP7 (4dcc2b4)
    - Fix PHP7 Fatal error: `'break' not in the 'loop' or 'switch' context` (002bb8a)
- Fixed output file name for `D` and `I` output modes (issue #105, f297546)

mPDF 6.0
===========================

### 20/12/2014

New features / Improvements
---------------------------
- Support for OpenTypeLayout tables / features for complex scripts and Advances Typography.
- Improved bidirectional text handling.
- Improved line-breaking, including for complex scripts e.g. Lao, Thai and Khmer.
- Updated page-breaking options.
- Automatic language mark-up and font selection using autoScriptToLang and autoLangToFont.
- Kashida for text-justification in arabic scripts.
- Index collation for non-ASCII characters.
- Index mark-up allowing control over layout using CSS.
- `{PAGENO}` and `{nbpg}` can use any of the number types as in list-style e.g. set in `<pagebreak>` using pagenumstyle.
- CSS support for lists.
- Default stylesheet - `mpdf.css` - updated.

Added CSS support
-----------------
- lang attribute selector e.g. :lang(fr), [lang="fr"]
- font-variant-position
- font-variant-caps
- font-variant-ligatures
- font-variant-numeric
- font-variant-alternates - Only [normal | historical-forms] supported (i.e. most are NOT supported)
- font-variant - as above, and except for: east-asian-variant-values, east-asian-width-values, ruby
- font-language-override
- font-feature-settings
- text-outline is now supported on TD/TH tags
- hebrew, khmer, cambodian, lao, and cjk-decimal recognised as values for "list-style-type" in numbered lists and page numbering.
- list-style-image and list-style-position
- transform (on `<img>` only)
- text-decoration:overline
- image-rendering
- unicode-bidi (also `<bdi>` tag)
- vertical-align can use lengths e.g. 0.5em
- line-stacking-strategy
- line-stacking-shift

mPDF 5.7.4
================

### 15/12/2014

Bug Fixes & Minor Additions
---------------------------
- SVG images now support embedded images e.g. `<image xlink:href="image.png" width="100px" height="100px" />`
- SVG images now supports `<tspan>` element e.g. `<tspan x,y,dx,dy,text-anchor >`, and also `<tref>`
- SVG images now can use Autofont (see top of `classes/svg.php` file)
- SVG images now has limited support for CSS classes (see top of `classes/svg.php` file)
- SVG images - style inheritance improved
- SVG images - improved handling of comments and other extraneous code
- SVG images - fix to ensure opacity is reset before another element
- SVG images - font-size not resetting after a `<text>` element
- SVG radial gradients bug (if the focus [fx,fy] lies outside circle defined by [cx,cy] and r) cf. pservers-grad-15-b.svg
- SVG allows spaces in attribute definitions in `<use>` or `<defs>` e.g. `<use x = "0" y = "0" xlink:href = "#s3" />`
- SVG text which contains a `<` sign, it will break the text - now processed as `&lt;` (despite the fact that this does not conform to XML spec)
- SVG images - support automatic font selection and (minimal) use of CSS classes - cf. the defined constants at top of svg.php file
- SVG images - text-anchor now supported as a CSS style, as well as an HTML attribute
- CSS support for :nth-child() selector improved to fully support the draft CSS3 spec - http://www.w3.org/TR/selectors/#nth-child-pseudo
    [NB only works on table columns or rows]
- text-indent when set as "em" - incorrectly calculated if last text in line in different font size than for block
- CSS not applying cascaded styles on `<A>` elements - [changed MergeCSS() type to INLINE for 'A', LEGEND, METER and PROGRESS]
- fix for underline/strikethrough/overline so that line position(s) are based correctly on font-size/font in nested situations
- Error: Strict warning: Only variables should be passed by reference - in PHP5.5.9
- bug accessing images from some servers (HTTP 403 Forbidden whn accessed using fopen etc.)
- Setting page format incorrectly set default twice and missed some options
- bug fixed in Overwrite() when specifying replacement as a string
- barcode C93 - updated C93 code from TCPDF because of bug - incorrect checksum character for "153-2-4"
- Tables - bug when using colspan across columns which may have a cell width specified
    cf. http://www.mpdf1.com/forum/discussion/2221/colspan-bug
- Tables - cell height (when specified) is not resized when table is shrunk
- Tables - if table width specified, but narrower than minimum cell wdith, and less than page width - table will expand to
    minimum cell width(s) as long as $keep_table_proportions = true
- Tables - if using packTableData, and borders-collapse, wider border is overwriting content of adjacent cell
    Test case:
    ```
    <table style="border-collapse: collapse;">
    <tr><td style="border-bottom: 42px solid #0FF; "> Hallo world </td></tr>
    <tr><td style="border-top: 14px solid #0F0; "> Hallo world </td></tr>
    </table>
    ```
- Images - image height is reset proportional to original if width is set to maximum e.g. `<img width="100%" height="20mm">`
- URL handling changed to work with special characters in path fragments; affects `<a>` links, `<img>` images and
    CSS url() e.g background-image
    - also to ignore `../` included as a query value
- Barcodes with bottom numerals e.g. EAN-13 - incorrect numeral size when using core fonts

--------------------------------

NB Spec. for embedded SVG images:
as per http://www.w3.org/TR/2003/REC-SVG11-20030114/struct.html#ImageElement
Attributes supported:
- x
- y
- xlink:href (required) - can be jpeg, png or gif image - not vector (SVG or WMF) image
- width (required)
- height (required)
- preserveAspectRatio

Note: all attribute names and values are case-sensitive
width and height cannot be assigned by CSS - must be attributes

mPDF 5.7.3
================

### 24/8/2014

Bug Fixes & Minor Additions
---------------------------

- Tables - cellSpacing and cellPadding taking preference over CSS stylesheet
- Tables - background images in table inside HTML Footer incorrectly positioned
- Tables - cell in a nested table with a specified width, should determine width of parent table cell
    (cf. http://www.mpdf1.com/forum/discussion/1648/nested-table-bug-)
- Tables - colspan (on a row after first row) exceeds number of columns in table
- Gradients in Imported documents (mPDFI) causing error in some browsers
- Fatal error after page-break-after:always on root level block element
- Support for 'https/SSL' if file_get_contents_by_socket required (e.g. getting images with allow_url_fopen turned off)
- Improved support for specified ports when getting external CSS stylesheets e.g. www.domain.com:80
- error accessing local .css files with dummy queries (cache-busting) e.g. mpdfstyleA4.css?v=2.0.18.9
- start of end tag in PRE incorrectly changed to &lt;
- error thrown when open.basedir restriction in effect (deleting temporary files)
- image which forces pagebreak incorrectly positioned at top of page
- [changes to avoid warning notices by checking if (isset(x)) before referencing it]
- text with letter-spacing set inside table which needs to be resixed (shrunk) - letter-spacing was not adjusted
- nested table incorrectly calculating width and unnecessarily wrapping text
- vertical-align:super|sub can be nested using `<span>` elements
- inline elements can be nested e.g. text `<sup>text<sup>13</sup>text</sup>` text
- CSS vertical-align:0.5em (or %) now supported
- underline and strikethrough now use the parent inline block baseline/fontsize/color for child inline elements *** change in behaviour
    (Adjusts line height to take account of superscript and subscript except in tables)
- nested table incorrectly calculating width and unnecessarily wrapping text
- tables - font size carrying over from one nested table to the next nested table
- tables - border set as attribute on `<TABLE>` overrides border set as CSS on `<TD>`
- tables - if table width set to 100% and one cell/column is empty with no padding/border, sizing incorrectly
    (http://www.mpdf1.com/forum/discussion/1886/td-fontsize-in-nested-table-bug-#Item_5)
- `<main>` added as recognised tag
- CSS style transform supported on `<img>` element (only)
    All transform functions are supported except matrix() i.e. translate(), translateX(), translateY(), skew(), skewX(), skewY(),
    scale(), scaleX(), scaleY(), rotate()
    NB When using Columns or Keep-with-table (use_kwt), cannot use transform
- CSS background-color now supported on `<img>` element
- @page :first not recognised unless @page {} has styles set
- left/right margins not allowed on @page :first

mPDF 5.7.2
================

### 28/12/2013

Bug Fixes
---------

- `<tfoot>` not printing at all (since v5.7)
- list-style incorrectly overriding list-style-type in cascading CSS
- page-break-after:avoid not taking into account bottom padding and margin when estimating if next line can fit on page
- images not displayed when using "https://" if images are referenced by src="//domain.com/image"
- +aCJK incorrectly parsed when instantiating class e.g. new mpDF('ja+aCJK')
- line-breaking - zero-width object at end of line (e.g. index entry) causing a space left untrimmed at end of line
- ToC since v5.7 incorrectly handling non-ascii characters, entities or tags
- cell height miscalculated when using hard-hyphenate
- border colors set with transparency not working
- transparency settings for stroke and fill interfering with one another
- 'float' inside a HTML header/footer - not clearing the float before first line of text
- error if script run across date change at midnight
- temporary file name collisions (e.g. when processing images) if numerous users
- `<watermarkimage>` position attribute not working
- `<` (less-than sign) inside a PRE element, and NOT start of a valid tag, was incorrectly removed
- file attachments not opening in Reader XI
- JPG images not recognised if not containing JFIF or Exif markers
- instance of preg_replace with /e modifier causing error in PHP 5.5
- correctly handle CSS URLs with no scheme
- Index entries causing errors when repeat entries are used within page-break-inside:avoid, rotated tables etc.
- table with fixed width column and long word in cell set to colspan across this column (adding spare width to all columns)
- incorrect hyphenation if multiple soft-hyphens on line before break
- SVG images - objects contained in `<defs>` being displayed
- SVG images - multiple, or quoted fonts e.g. style="font-family:'lucida grande', verdana" not recognised
- SVG images - line with opacity=0 still visible (only in some PDF viewers/browsers)
- text in an SVG image displaying with incorrect font in some PDF viewers/browsers
- SVG images - fill:RGB(0,0,0) not recognised when uppercase
- background images using data:image\/(jpeg|gif|png);base64 format - error when reading in stylesheet

New CSS support
---------------

- added support for style="opacity:0.6;" in SVG images - previously only supported style="fill-opacity:0.6; stroke-opacity: 0.6;"
- improved PNG image handling for some cases of alpha channel transparency
- khmer, cambodian and lao recognised as list-style-type for numbered lists

SVG Images
----------

- Limited support for `<use>` and `<defs>`

mPDF 5.7.1
================
## 01/09/2013

1) FILES: mpdf.php

Bug fix; Dollar sign enclosed by `<pre>` tag causing error.
Test e.g.: `<pre>Test $1.00 Test</pre> <pre>Test $2.00 Test</pre> <pre>Test $3.00 Test</pre> <pre>Test $4.00 Test</pre>`

-----------------------------

2) FILES: includes/functions.php AND mpdf.php

Changes to `preg_replace` with `/e` modifier to use `preg_replace_callback`
(/e depracated from PHP 5.5)

-----------------------------

3) FILES: classes/barcode.php

Small change to function `barcode_c128()` which allows ASCII 0 - 31 to be used in C128A e.g. chr(13) in:
`<barcode code="5432&#013;1068" type="C128A" />`

-----------------------------

4) FILES: mpdf.php

Using $use_kwt ("keep-[heading]-with-table") if `<h4></h4>` before table is on 2 lines and pagebreak occurs after first line
the first line is displayed at the bottom of the 2nd page.
Edited so that $use_kwt only works if the HEADING is only one line. Else ignores (but prints correctly)

-----------------------------

5) FILES: mpdf.php

Clearing old temporary files from `_MPDF_TEMP_PATH` will now ignore "hidden" files e.g. starting with a "`.`" `.htaccess`, `.gitignore` etc.
and also leave `dummy.txt` alone


mPDF 5.7
===========================

### 14/07/2013

Files changed
-------------
- config.php
- mpdf.php
- classes/tocontents.php
- classes/cssmgr.php
- classes/svg.php
- includes/functions.php
- includes/out.php
- examples/formsubmit.php [Important - Security update]

Updated Example Files in /examples/
-----------------------------------

- All example files
- mpdfstyleA4.css

config.php
----------

Removed:
- $this->hyphenateTables
- $this->hyphenate
- $this->orphansAllowed
Edited:
- "hyphens: manual" - Added to $this->defaultCSS
- $this->allowedCSStags now includes '|TEXTCIRCLE|DOTTAB'
New:
- $this->decimal_align = array('DP'=>'.', 'DC'=>',', 'DM'=>"\xc2\xb7", 'DA'=>"\xd9\xab", 'DD'=>'-');
- $this->h2toc = array('H1'=>0, 'H2'=>1, 'H3'=>2);
- $this->h2bookmarks = array('H1'=>0, 'H2'=>1, 'H3'=>2);
- $this->CJKforceend = false; // Forces overflowng punctuation to hang outside right margin (used with CJK script)


Backwards compatability
-----------------------

Changes in mPDF 5.7 may cause some changes to the way your documents appear. There are two main differences:
1) Hyphenation. To retain appearance compatible with earlier versions, set the CSS property "hyphens: auto" whenever
    you previously used $mpdf->hyphenate=true;
2) Table of Contents - appearance can now be controlled with CSS styles. By default, in mPDF 5.7, no styling is applied so you will get:
    - No indent (previous default of 5mm) - ($tocindent is ignored)
    - Any font, font-size set ($tocfont or $tocfontsize) will not work
    - HyperLinks will appear with your default appearance - usually blue and underlined
    - line spacing will be narrower (can use line-height or margin-top in CSS)

New features / Improvements
---------------------------
- Layout of Table of Content ToC now controlled using CSS styles
- Text alignment on decimal mark inside tables
- Automatically generated bookmarks and/or ToC entries from H1 - H6 tags
- Support for unit of "rem" as size e.g. font-size: 1rem;
- Origin and clipping for background images and gradients controlled by CSS i.e. background-origin, background-size, background-clip
- Text-outline controlled by CSS (compatible with CSS3 spec.)
- Use of `<dottab>` enhanced by custom CSS "outdent" property
- Image HTML attributes `<img>` added: max-height, max-width, min-height and min-width
- Spotcolor can now be defined as it is used e.g. color: spot(PANTONE 534 EC, 100%, 85, 65, 47, 9);
- Lists - added support for "start" attribute in `<ol>` e.g. `<ol start="5">`
- Hyphenation controlled using CSS, consistent with CSS3 spec.
- Line breaking improved to avoid breaks within words where HTML tags are used e.g. H<sub>2<sub>0
- Line breaking in CJK scripts improved (and ability to force hanging punctuation)
- Numerals in a CJK script are kept together
- RTL improved support for phrases containing numerals and \ and /
- Bidi override codes supported - Right-to-Left Embedding [RLE] U+202B, Left-to-Right Embedding [LRE] U+202A,
    U+202C POP DIRECTIONAL FORMATTING (PDF)
- Support for `<base href="">` in HTML - uses it to SetBasePath for relative URLs.
- HTML tag - added support for `<wbr>` or `<wbr />` - converted to a soft-hyphen
- CSS now takes precedence over HTML attribute e.g. `<table bgcolor="black" style="background-color:yellow">`

Added CSS support
-----------------
- max-height, max-width, min-height and min-width for images `<img>`
- "hyphens: none|manual|auto" as per CSS3 spec.
- Decimal mark alignment e.g. text-align: "." center;
- "rem" accepted as a valid (font)size in CSS e.g. font-size: 1.5rem
- text-outline, text-outline-width and text-outline-color supported everywhere except in tables (blur not supported)
- background-origin, background-size, background-clip are now supported everywhere except in tables
- "visibility: hidden|visible|printonly|screenonly" for inline elements e.g. `<span>`
- Colors: device-cmyk(c,m,y,k) as per CSS3 spec. For consistency, device-cmyka also supported (not CSS3 spec)
- "z-index" can be used to utilise layers in the PDF document
- Custom CSS property added: "outdent" - opposite of indent

The HTML elements `<dottab>` and `<textcircle>` can now have CSS properties applied to them.

Bug fixes
---------
- SVG images - path including e.g. 1.234E-15 incorrectly parsed (not recognising capital E)
- Tables - if a table starts when the Y position on page is below bottom margin caused endless loop
- Float-ing DIVs - starting a float at bottom of page and it causes page break before anything output, second new page is forced
- Tables - Warning notice now given in Table footer or header if `<tfoot>` placed after `<tbody>` and table spans page
- Columns - block with border-width wider than the length of the border line, line overflows
- Columns - block with no padding containing a block with borders but no backgound colour, borders not printed
- Table in Columns - when background color set by surrounding block element - colour missing for height of half bottom border.
- TOCpagebreakByArray() when called by function was not adding the pagebreak
- Border around block element - dashed not showing correctly (not resetting linewidth between different edges)
- Double border in table - when background colour set in surrounding block element - shows as black line between the 2 bits of double
- Borders around DIVs - "double" border problem if not all 4 sides equally - fixed
- Borders around DIVs - solid (and double) borders overlap as in tables - now fixed so mitred joins as in browser
    [Inadvertently improves borders in Columns because of change in LineCap]
- Page numbering - $mpdf->pagenumSuffix etc not suppressed in HTML headers/footers if number suppressed
- Page numbering - Page number total {nbpg} incorrect  - e.g. showing decreasing numbers through document, when ToC present
- RTL numerals - incorrectly reversing a number followed by a comma
- Transform to uppercase/lowercase not working for chars > ASCII 128 when using core fonts
- TOCpagebreak - Not setting TOC-FOOTER
- TOCpagebreak - toc-even-header-name etc. not working
- Parsing some relative URLs incorrectly
- Textcircle - when moved to next page by "page-break-inside: avoid"
- Bookmarks will now work if jump more than one level e.g. 0,2,1  Inserts a new blank entry at level 1
- Paths to img or stylesheets - incorrectly reading "//www.domain.com" i.e. when starting with two /
- data:image as background url() - incorrectly adjusting path on server if MPDF_PATH not specified (included in release mPDF 5.6.1)
- Image problem if spaces or commas in path using http:// URL (included in release mPDF 5.6.1)
- Image URL parsing rewritten to handle both urlencoded URLs and not urlencoded (included in release mPDF 5.6.1)
- `<dottab>` fixed to allow color, font-size and font-family to be correctly used, avoid dots being moved to new page, and to work in RTL
- Table {colsum} summed figures in table header
- list-style-type (custom) colour not working
- `<tocpagebreak>` toc-preHTML and toc-postHTML can now contain quotes

mPDF 5.6
===========================

### 20/01/2013

Files changed
-------------
- mpdf.php
- config.php
- includes/functions.php
- classes/meter.php
- classes/directw.php


config.php changes
------------------

- $this->allowedCSStags - added HTML5 tags + textcircle AND
- $this->outerblocktags - added HTML5 tags
- $this->defaultCSS  - added default CSS properties

New features / Improvements
---------------------------
CSS support added for for min-height, min-width, max-height and max-width in `<img>`

Images embedded in CSS
- `<img src="data:image/gif;base64,....">` improved to make it more robust, and background: `url(data:image...` now added to work

HTML5 tags supported
- as generic block elements: `<article><aside><details><figure><figcaption><footer><header><hgroup><nav><section><summary>`
- as in-line elements: `<mark><time><meter><progress>`
- `<mark>` has a default CSS set in config.php to yellow highlight
- `<meter>` and `<progress>` support attributes as for HTML5
- custom appearances for `<meter>` and `<progress>` can be made by editing `classes/meter.php` file
- `<meter>` and `<progress>` suppress text inside the tags

Textcircle/Circular
- font: "auto" added: automatically sizes text to fill semicircle (if both set) or full circle (if only one set)
    NB for this AND ALL CSS on `<textcircle>`: does not inherit CSS styles
- attribute: divider="[characters including HTML entities]" added
- `<textcircle r="30mm" top-text="Text Circular Text Circular" bottom-text="Text Circular Text Circular"
    divider="&nbsp;&bull;&nbsp;" style="font-size: auto" />`

&raquo; &rsquo; &sbquo; &bdquo; are now included in "orphan"-management at the end of lines

Improved CJK line wrapping (if CJK character at end of line, breaks there rather than previous wordspace)

NB mPDF 5.5 added support for `<fieldset>` and `<legend>` (omitted from ChangeLog)

Bug fixes
---------

- embedded fonts: Panose string incorrectly output as decimals - changed to hexadecimal
    Only a problem in limited circumstances.
    *****Need to delete all ttfontdata/ files in order for fix to have effect.
- `<textCircle>` background white even when set to none/transparent
- border="0" causing mPDF to add border to table CELLS as well as table
- iteration counter in THEAD crashed in some circumstances
- CSS color now supports spaces in the rgb() format e.g. border: 1px solid rgb(170, 170, 170);
- CJK not working in table following changes made in v5.4
- images fixed to work with Google Chart API (now mPDF does not urldecode the query part of the src)
- CSS `<style>` within HTML page crashed if CSS is too large  (? > 32Kb)
- SVG image nested int eht HTML failed to show if code too large (? > 32Kb)
- cyrillic character p &#1088; at end of table cell caused cell height to be incorrectly calculated

mPDF 5.5
===========================

### 02/03/2012

Files changed
-------------

- mpdf.php
- classes/ttfontsuni.php
- classes/svg.php
- classes/tocontents.php
- config.php
- config_fonts.php
- utils/font_collections.php
- utils/font_coverage.php
- utils/font_dump.php

Files added
-----------

classes/ttfontsuni_analysis.php

config.php changes
------------------

To avoid just the border/background-color of the (empty) end of a block being moved on to next page (`</div></div>`)

`$this->margBuffer = 0; // Allow an (empty) end of block to extend beyond the bottom margin by this amount (mm)`

config_fonts.php changes
------------------------

Added to (arabic) fonts to allow "use non-mapped Arabic Glyphs" e.g. for Pashto
    'unAGlyphs' => true,

Arabic text
-----------

Arabic text (RTL) rewritten with improved support for Pashto/Sindhi/Urdu/Kurdish
    Presentation forms added:
    U+0649, U+0681, U+0682, U+0685, U+069A-U+069E, U+06A0, U+06A2, U+06A3, U+06A5, U+06AB-U+06AE,
    U+06B0-U+06B4, U+06B5-U+06B9, U+06BB, U+06BC, U+06BE, U+06BF, U+06C0, U+06CD, U+06CE, U+06D1, U+06D3, U+0678
    Joining improved:
    U+0672, U+0675, U+0676, U+0677, U+0679-U+067D, U+067F, U+0680, U+0683, U+0684, U+0687, U+0687, U+0688-U+0692,
    U+0694, U+0695, U+0697, U+0699, U+068F, U+06A1, U+06A4, U+06A6, U+06A7, U+06A8, U+06AA, U+06BA, U+06C2-U+06CB, U+06CF

Note - Some characters in Pashto/Sindhi/Urdu/Kurdish do not have Unicode values for the final/initial/medial forms of the characters.
However, some fonts include these characters "un-mapped" to Unicode (including XB Zar and XB Riyaz, which are bundled with mPDF).
    `'unAGlyphs' => true`, added to the config_fonts.php file for appropriate fonts will

This requires the font file to include a Format 2.0 POST table which references the glyphs as e.g. uni067C.med or uni067C.medi:
    e.g. XB Riyaz, XB Zar, Arabic Typesetting (MS), Arial (MS)

NB If you want to know if a font file is suitable, you can open a .ttf file in a text editor and search for "uni067C.med" - if it exists, it may work!
Using "unAGlyphs" forces subsetting of fonts, and will not work with SIP/SMP fonts (using characters beyond the Unicode BMP Plane).

mPDF maps these characters to part of the Private Use Area allocated by Unicode U+F500-F7FF. This could interfere with correct use
if the font already utilises these codes (unlikely).

mPDF now deletes U+200C,U+200D,U+200E,U+200F zero-widthjoiner/non-joiner, LTR and RTL marks so they will not appear
even though some fonts contain glyphs for these characters.


Other New features / Improvements
---------------------------------
Avoid just the border/background-color of the (empty) end of a block being moved on to next page (`</div></div>`)
using configurable variable: `$this->margBuffer`;


The TTFontsUni class contained a long function (extractcoreinfo) which is not used routinely in mPDF

This has been moved to a new file: classes/ttfontsuni_analysis.php.

The 3 utility scripts have been updated to use the new extended class:

- utils/font_collections.php
- utils/font_coverage.php
- utils/font_dump.php


Bug fixes
---------
- Border & background when closing 2 blocks (e.g. `</div></div>`) incorrectly being moved to next page because incorrectly
    calculating how much space required
- Fixed/Absolute-positioned elements not inheriting letter-spacing style
- Rotated cell - error if text-rotate set on a table cell, but no text content in cell
- SVG images, text-anchor not working
- Nested table - not resetting cell style (font, color etc) after nested table, if text follows immediately
- Nested table - font-size 70% set in extenal style sheet; if repeated nested tables, sets 70% of 70% etc etc
- SVG setting font-size as percent on successive `<text>` elements gives progressively smaller text
- mPDF will check if magic_quotes_runtime set ON even >= PHP 5.3 (will now cause an error message)
- not resetting after 2 nested tags of same type e.g. `<b><b>bold</b></b>` still bold
- When using charset_in other than utf-8, HTML Footers using tags e.g. `<htmlpageheader>` do not decode correctly
- ToC if nested > 3 levels, line spacing reduces and starts to overlap

Older changes can be seen [on the documentation site](https://mpdf.github.io/about-mpdf/changelog.html).
