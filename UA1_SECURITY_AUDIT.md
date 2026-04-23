# Security Audit — `ua1-document-support` Branch

**Branch:** `ua1-document-support` vs `development`
**Scope:** ~18,700 LOC added across 114 files (PDF/UA-1 accessibility support)
**Audit date:** 2026-05-02
**Auditors:** three parallel agents — `security-auditor` (new `src/Ua/`), `code-reviewer` (modified core diff), `penetration-tester` (active exploitation, PHP 7.4.33)
**PoCs preserved at:** `/tmp/ua1-audit/`

---

## Executive summary

| Severity | Count | Gating |
|---|---|---|
| **HIGH** | **3** | **Block merge — fix required** |
| **MEDIUM** | **5** | **Block merge — fix required** |
| LOW | 6 | Fix in follow-up; not blocking |
| INFO / hardening | 6 | Defer to backlog |

**Recommendation: do not merge until the three HIGH findings (#1–#3) are remediated.** All three have working PoCs in `/tmp/ua1-audit/`. The five MEDIUM findings should also be fixed before merge — together they undermine PDF/UA-1 conformance claims (#5, #6, #8) and create a 300× memory amplification path from untrusted HTML (#4).

Threat-model boundaries (used to scope severity):
- **B1** — untrusted HTML / CSS / SVG
- **B2** — untrusted imported PDFs (FPDI)
- **B3** — untrusted font files

---

## HIGH — fix before merge

### H-1. `UaPolicy::isPolicyBlockedHref` regex bypassed by NBSP / ZWSP / HTML-entity prefix and missing schemes

- **File:** `src/Ua/UaPolicy.php:43-49`
- **Boundary:** B1 (untrusted HTML)
- **PoC:** `/tmp/ua1-audit/01c_jsbypass_dotted.php`
- **Status:** VULNERABLE end-to-end — dangerous bytes reach PDF `/A <</S /URI /URI (...)>>` annotation.

The regex `'@^\s*(?:javascript|vbscript)\s*:@i'` has three independent bypass classes, all confirmed:

| Bypass | href fed into `<a href>` | bytes landing in PDF `/URI` |
|---|---|---|
| NBSP prefix | `\xC2\xA0javascript:alert(1.0)` | `\xC2\xA0javascript:alert(1.0)` |
| ZWSP prefix | `\xE2\x80\x8Bjavascript:alert(1.0)` | `\xE2\x80\x8Bjavascript:alert(1.0)` |
| HTML entity | `&Tab;javascript:alert(1.0)` | literal `&Tab;javascript:alert(1.0)` |
| `data:text/html` | `data:text/html,<script>alert(1)</script>.` | full payload |
| `livescript:` | `livescript:alert(1.0)` | full scheme |
| `mocha:` | `mocha:alert(1.0)` | full scheme |

PHP's `\s` does not match U+00A0 / U+200B / U+FEFF unless `/u` is set. mPDF feeds the raw href to `UaPolicy` without entity-decoding or percent-decoding. `data:text/html`, `livescript:`, `mocha:`, `vbs:` are simply not on the deny list. The mPDF emitter at `Mpdf.php:17261` requires a `.` in the href before treating it as external (so `javascript:alert(1)` without a dot becomes an internal anchor and is benign — but `javascript:alert(1.0)` is sufficient to land in `/URI`).

**Fix outline:**
1. Switch the regex to `/u` and add explicit NBSP / zero-width / BOM / C0 control bytes.
2. Normalise the href before policy evaluation: `htmlspecialchars_decode($href, ENT_QUOTES|ENT_HTML5)` plus a percent-decode pass.
3. Extend the deny list: `data:text/html`, `data:application/x-javascript`, `data:application/xhtml+xml`, `livescript:`, `mocha:`, `vbs:`, `view-source:`.
4. Re-apply the policy in `MetadataWriter::writeAnnotations()` on the output side, not just at parse time, against the decoded form.
5. Extend `tests/Mpdf/Ua/JavascriptUrlHandlingTest.php` with each bypass above as a regression test.

### H-2. FPDI cyclic `/K` references segfault the PHP process

- **File:** `src/Ua/Import/FpdiStructMerger.php:1041` (`cloneElement()`)
- **Boundary:** B2 (untrusted imported PDF)
- **PoC:** `/tmp/ua1-audit/03_fpdi_cycle.php` — exit code 139 (SIGSEGV)
- **Status:** VULNERABLE — one-shot DoS per worker.

A source PDF whose structure-element tree contains a cycle (e.g. element 6's `/K [7 0 R]` and 7's `/K [6 0 R]`) sends `cloneElement()` into infinite recursion. PHP segfaults with exit 139. A long-running mPDF web service that imports PDFs supplied by users will have workers killed on every malicious request. FPDI's `PdfType::resolve()` (`vendor/setasign/fpdi/src/PdfParser/Type/PdfType.php:50-55`) breaks indirect-ref cycles for object resolution, but the merger's recursion through resolved children does not track visited nodes.

The auditor's separate finding F13 (depth cap) is partly subsumed here — pure deep nesting (16,000 levels) does NOT crash today (`/tmp/ua1-audit/03c_deep.php` ran cleanly in 3 s / 124 MB). It is specifically the cycle case that crashes.

**Fix outline:** in `cloneElement()`, maintain a per-import `$visited[spl_object_id($resolved)]` (or use the indirect-object number where available); skip on revisit and log a warning. Add a hard depth cap (1024) as defence in depth. Mirror the same guards in `collectSanityCandidates()` (`src/Ua/Import/FpdiStructMerger.php:682-757`).

### H-3. `AddCustomProperty` key injects arbitrary entries into PDF `/Info` dict

- **File:** `src/Writer/MetadataWriter.php:210-212`
- **Boundary:** B1 (custom-property key from application — but if the application exposes property naming to its end users, this becomes B1)
- **PoC:** `/tmp/ua1-audit/08b_xmp.php`
- **Status:** VULNERABLE — well-formed PDF emerges with attacker-controlled `/Producer`, `/Author`, etc.

The custom-property loop emits the key raw:

```php
foreach ($this->mpdf->customProperties as $key => $value) {
    $this->writer->write('/' . $key . ' ' . $this->writer->utf16BigEndianTextString($value));
}
```

`AddCustomProperty("good\n/MaliciousKey (injected!) /SecondKey", 'val')` produces:

```
17 0 obj
<<
/Producer (…)
/Title (…)
/good
/MaliciousKey (injected!) /SecondKey (FE FF 00 76 00 61 00 6C)
…
>>
```

Dict-syntax round-trips (matched `<<`/`>>`). Detection requires semantic inspection. An attacker controlling key strings can override metadata that downstream tools may trust. The XMP packet itself is safe (values pass through `htmlspecialchars(... ENT_XML1)`) — only `/Info` is affected.

**Fix outline:** validate or `#xx`-escape every byte of the key against the PDF name production (`[A-Za-z][A-Za-z0-9_]*` is a safe practical subset). Reuse the algorithm from `StructureElement::sanitiseIdForPdf()` (`src/Ua/StructureElement.php:195-241`) — single canonical sanitiser already in the codebase.

---

## MEDIUM — fix before merge

### M-1. `aria-labelledby` 300× memory amplification (DoS)

- **File:** `src/Ua/AriaIdResolver.php:142-151`
- **Boundary:** B1
- **PoC:** `/tmp/ua1-audit/06c_aria_dos.php` — 1 MB HTML → 302 MB peak memory in 0.29 s
- `preg_split('/\s+/', trim((string) $targetIds))` is uncapped; each token becomes a 3-tuple in `$pending`. 500,000 tokens in a 1 MB document push peak memory above default `memory_limit`.

**Fix:** cap the split with `preg_split('/\s+/', $v, 256, PREG_SPLIT_NO_EMPTY)` and reject `$targetIds` strings longer than ~16 KiB at the call site. Emit a warning when truncated.

### M-2. `StructureElement::sanitiseIdForPdf()` 28-bit suffix collides at birthday bound

- **File:** `src/Ua/StructureElement.php:195-241`
- **Boundary:** B1 (TH `id="…"` + TD `headers="…"` from authored HTML)
- **PoC:** `/tmp/ua1-audit/05c_struct.php` — 5 collisions inside 58,050 distinct overlong inputs; `/tmp/ua1-audit/05d_struct_e2e.php` confirms TD `/Headers` cross-reference breaks in the produced PDF.
- 7 hex chars = 28 bits. Birthday-bound: ~2% collision at 10⁵ overlong IDs, ~86% at 10⁶. Pen-test demonstrated practical collision well below 60 k.
- Impact: silent break of `/Headers` cross-references → Matterhorn 09-002 / 09-004 / 14-005 violation. AT (NVDA / JAWS) fails to announce column header. Not memory-safety; PDF/UA-1 conformance regression.

**Fix:** widen suffix to ≥ 16 hex chars (64 bit), or hash the entire id and base-32 it to fit ≤ 32 bytes.

### M-3. SVG `extractAccessibleMetadata()` resolves `file://` external entities

- **File:** `src/Image/Svg.php:3383`
- **Boundary:** B1 (untrusted SVG)
- **PoC:** `/tmp/ua1-audit/02b_xxe_direct.php` — leaks `/etc/hosts` content into the returned title on PHP 7.4
- **End-to-end exploitability:** **NOT reachable today via `WriteHTML()`** because `Svg::ImageSVG()` runs `preg_replace('/^.*?<svg([> ])/is', '<svg\\1', $data)` (`src/Image/Svg.php:3098`) which strips the `<!DOCTYPE … [...]>` block before `extractAccessibleMetadata()` runs. The DOCTYPE-stripping defence is incidental, not deliberate.
- Severity is MEDIUM (not HIGH) because the attacker cannot land a DOCTYPE through the documented entry path. But `extractAccessibleMetadata()` is `public` and any future caller — third-party integration, internal refactor — that bypasses `ImageSVG()` reaches the unsafe parser. mPDF's `composer.json` declares `php: ^5.6 || ^7.0 || ^8.0`; on PHP < 8.0 the libxml external-entity loader is enabled by default (deprecated `libxml_disable_entity_loader()` governs it).

**Fix:** drop `LIBXML_NOENT` (entity content is not a legitimate accessible-name source). For PHP < 8.0, also call `libxml_disable_entity_loader(true)` around the call (saved/restored). The pre-existing `mergeStyles()` at `src/Image/Svg.php:3022` has the same gap and should be hardened in the same change for consistency, even though it is outside this branch's diff.

### M-4. `FpdiStructMerger::collectSanityCandidates()` recursion bounded by candidate count, not node count

- **File:** `src/Ua/Import/FpdiStructMerger.php:682-757`
- **Boundary:** B2
- The early-exit checks `count($candidates) >= $limit`; it does not cap nodes visited or recursion depth. A wide DAG with `/Alt`/`/ActualText`/`/Lang` only on the deepest leaves forces a full-tree walk every page.
- FPDI's `PdfType::resolve()` does break indirect-ref cycles, so this is a CPU-amplification concern rather than a crash. Combined with H-2 (no cycle detection in `cloneElement`) and the fact that the merger walks the whole tree per imported page, a malicious 100 k-element source can be expensive.

**Fix:** thread a shared `$visited` integer ceiling (e.g. 50,000) through both walkers and abort early.

### M-5. `FpdiStructMerger::stringPassesSanityGauntlet` 50% threshold is permissive

- **File:** `src/Ua/Import/FpdiStructMerger.php:772-849`
- **Boundary:** B2 (currently unreachable — see below)
- **PoC:** `/tmp/ua1-audit/03e_sanity.php` — a string of 50 ASCII + 50 `\x01` bytes passes (threshold is strict `> 0.5`).
- **Reachability today:** unreachable. FPDI hard-refuses encrypted source PDFs at parse time, so a still-encrypted `/Alt` cannot land in the merger. The gauntlet is a forward-compatibility guard against a future FPDI release that lifts the encrypt refusal.
- Listed as MEDIUM rather than INFO because the regression is silent and would only manifest after a future FPDI upgrade.

**Fix:** tighten to `>= 0.5`, drop the 4096-byte cap to ~1024, and require ≥ 75% codepoints in the Letter / Number Unicode general categories. Or retire the heuristic and rely on a hard length cap plus the FPDI encrypt refusal as the sole control.

---

## LOW — follow-up fixes

| ID | Title | File:line | Notes |
|---|---|---|---|
| L-1 | `addContent()` auto-vivifies sparse ParentTree | `src/Ua/StructureTree.php:301-336` | No untrusted reach today; `is_int && >= 0` guard plus the only caller `UaState::nextStructParents()` keep it safe. Add `$structParentsIndex <= getStructParentsCounter()` upper bound for defence in depth. |
| L-2 | `pdfDocEncodingToUtf8()` `@pack('H*', $hex)` suppresses errors | `src/Ua/Import/FpdiStructMerger.php:1153-1170` | Spec-correct; drop `@` and check return. |
| L-3 | PDFUAauto silent `/Alt` loss when imported attribute is undecodable | `src/Ua/Import/FpdiStructMerger.php:1009-1024` | Strict path throws; auto path silently unsets without `addUntaggedWarning()`. Matterhorn 13-004 violation goes unobserved. |
| L-4 | `LigatureActualTextWriter` accepts out-of-range codepoints | `src/Ua/LigatureActualTextWriter.php:113-125` | Surrogate math correct for valid input; clamp to `[0, 0x10FFFF]` and reject `[0xD800, 0xDFFF]`. |
| L-5 | Synthetic encrypted-source pageId echoes `realpath($file)` | `src/FpdiTrait.php:580-593` | `importPage()` return value carries an absolute server path; if logged or echoed, leaks filesystem layout. Replace with `sha1(realpath($file))` or `basename(realpath($file))`. |
| L-6 | `ImageMapRegistry::emitForImage` `while(array_key_exists)` loop has no defensive cap | `src/Ua/ImageMap/ImageMapRegistry.php:303-310` | Provably bounded by `count($internallink)` and pen-test confirmed it terminates. Add a defensive 1024-iteration cap for clarity. |

---

## INFO / hardening recommendations

| ID | Title | Notes |
|---|---|---|
| I-1 | `MarkedContentHelper::begin` accepts any `$structType` string | `src/Ua/MarkedContentHelper.php:71-83`. Canonical caller `StructureTree::open()` validates, but other render-time callers should not be trusted to. Add `assert(StructType::isValid($structType) || $mcid === -1)` inside `begin()`. |
| I-2 | `StructureWriter` direct concatenation of `/ID` and `/Headers` | `src/Ua/StructureWriter.php:289, 444-447`. Today every caller routes through `sanitiseIdForPdf()`. Add an `assert()` re-running the sanitiser inside `setId()` so future callers cannot regress. |
| I-3 | No SAST in CI | `.github/workflows/tests.yml` runs functional + veraPDF only. Add PHPStan run, `composer audit`, and ideally Psalm taint analysis. The PHPStan baseline already shrank by 12 lines on this branch — make it gating. |
| I-4 | No adversarial test fixtures | All 33 fixtures in `tests/data/html/pdfua-examples/` are happy-path. Add fixtures derived from the H-1 / H-3 / M-1 / M-2 PoCs to a new `tests/Mpdf/Ua/Security/` suite. |
| I-5 | `JavascriptUrlHandlingTest` strong baseline but missing Unicode/entity cases | The 19 existing cases miss NBSP / ZWSP / HTML-entity-prefix / `data:text/html` / `livescript:` / `mocha:` — add each as a regression case alongside the H-1 fix. |
| I-6 | Information disclosure via `getPdfUaWarnings()` | The four `addWarning()` call sites in `FpdiTrait.php` do not embed paths today — verified — but adopt a path-redaction helper before logging any `$file` / `$sourceKey` to make this a structural guarantee. |

---

## Findings vs existing test coverage

| Finding | Existing regression test? | Action |
|---|---|---|
| H-1 (URL scheme bypass) | Partial — `JavascriptUrlHandlingTest` covers 19 happy cases; misses NBSP / ZWSP / HTML entity / `data:text/html` / `livescript:` / `mocha:` | Add the 6 PoC cases once H-1 is fixed |
| H-2 (FPDI cycle segfault) | No | Add fixture under `tests/Mpdf/Ua/FpdiStructMergerTest` with cyclic `/K`; assert graceful warning instead of crash |
| H-3 (`/Info` injection via custom property) | No | Add unit test asserting key bytes outside `[A-Za-z0-9_]` are escaped or rejected |
| M-1 (aria DoS) | No | Add memory-budgeted test asserting truncation kicks in |
| M-2 (ID hash collision) | No | Add test using two prefix-shared overlong IDs that now produce distinct sanitised forms |
| M-3 (SVG XXE) | No (existing `SvgAccessibleMetadataTest` is happy-path only) | Add a `<!DOCTYPE … SYSTEM "file://…">` fixture asserting no entity content leaks into title |
| M-4 (sanity-walk node cap) | No | Add fixture exercising the node cap; assert early abort warning |
| M-5 (sanity-gauntlet threshold) | No | Add unit test pinning the new threshold |
| L-1 .. L-6 | No | Add as part of follow-up PR |

---

## Verification of plan-stated criteria

1. **All findings cite file:line.** ✓
2. **Every Agent-C exploit attempt has a reproducer in `/tmp/ua1-audit/`.** ✓ — 13 PoC scripts, listed in the findings table above.
3. **Internally consistent across the three audits.** ✓ — five overlap points (H-1 / M-1 / M-2 / M-4 / M-5) all agree; the one tension was H-2 (security-auditor rated INFO based on FPDI cycle handling for indirect refs only; pen-tester proved the merger's resolved-child recursion is the unguarded path) — adjudicated by the working PoC.
4. **Findings map to threat-model boundaries.** ✓ — H-1 / M-1 / M-2 → B1; H-2 / M-4 / M-5 → B2; H-3 → B1 if app exposes property naming; M-3 → B1 (defence-in-depth).
5. **Spot-check reproduction:** any finding can be reproduced by running its PoC under `php /tmp/ua1-audit/<file>.php` from the repo root with `vendor/autoload.php` in place. The pen-tester ran on PHP 7.4.33 against this checkout.
6. **No production data, no committed binary artefacts.** ✓ — all artefacts under `/tmp/ua1-audit/`.

---

## Sign-off recommendation

- **DO NOT MERGE** until H-1, H-2, H-3 are fixed.
- **STRONGLY RECOMMEND** fixing M-1 through M-5 in the same PR — they are all narrow patches and the test gaps (I-4, I-5) should be filled alongside.
- **OPTIONAL** for this PR: L-1 through L-6 and I-1, I-2, I-6 can land as a follow-up.
- **SEPARATE PR** suggested for I-3 (CI hardening: PHPStan + `composer audit`).

PoC working tree at `/tmp/ua1-audit/` is preserved for the maintainer's inspection. Clean up with `rm -rf /tmp/ua1-audit` once findings are reviewed.
