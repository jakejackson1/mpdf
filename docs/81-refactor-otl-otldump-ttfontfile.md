# Refactor `Otl`, `OtlDump` and `TTFontFile` (#81)

Goals, in the order they settle arguments:

1. **Reading binary is one job, done once.** Today it is done three times, by hand, in three classes.
2. **Every parser section corresponds to a spec table**, checkable field by field.
3. **Behaviour does not change.** Every phase is verified against a golden master, not against judgement.

Measurements below are at `31abf2d`, the tip of `feature/80-remaining-otl-lookups` and the base of this plan. Repo-wide constraints — PHP 5.6–8.5, PSR-2 with tabs, `Mpdf\Strict`, the test commands, the snapshot mechanics — are in CLAUDE.md and are not restated here.

| File | Lines | Methods |
| --- | --- | --- |
| `src/Otl.php` | 6,634 | 55 |
| `src/TTFontFile.php` | 5,109 | 54 |
| `src/OtlDump.php` | 4,474 | 60 |

## 1. What is actually wrong

### 1.1 `OtlDump` is a second copy of `TTFontFile`

47 method names are shared. 18 are byte-identical after normalising comments and whitespace, 16 more are ≥90% similar, and the large ones have drifted instead of being kept in step: `extractInfo`, `_getGSUBtables`, `_getGPOStables`, `_getGDEFtables`.

`OtlDump` is reachable from exactly one place, `utils/font_dump_otl.php`. It is a debugging tool that is also a second parser, free to disagree with the one that renders — useless precisely when it is needed. It does not `use Strict`, so it can invent properties `TTFontFile` would reject.

### 1.2 Subtable offsets are stored in the wrong unit

`TTFontFile::_getGSUBtables()` stores `$GSLookup[$i]['Subtables'][$c] = $Offsets[$i] + $this->read_ushort()` — an **absolute file offset**. `MetricsGenerator` persists that array as-is. `Otl` then reads a cache file in which GSUB starts at zero, so every consumer converts back:

```php
($subtable_offset - $this->GSUB_offset)                          // 12 sites
($subtable_offset - $this->GPOS_offset + $this->GSUB_length)     //  5 sites
```

These are not 17 problems. They are one unit mismatch at the producer, paid for at every consumer, and the GPOS variant is enough of a tripwire that #80 warns implementers not to copy the GSUB form.

Two things cause it, both at the producer:

- Offsets are recorded absolute rather than relative to their table.
- `TTFontFile` writes the GSUB and GPOS tables into **one** cache file, `<fontkey>.GSUBGPOStables.dat`, concatenated — which is the sole reason the GPOS form needs `+ $this->GSUB_length`.

Fix both and the arithmetic has nowhere left to live. Note that the inner reads are already spec-idiomatic: the 63 `$subtable_offset + …` sites inside the dispatchers — 51 reading the Offset16 inline, 12 adding one already read — are exactly the spec's "Offset16, from beginning of the subtable", written as plain arithmetic. They need no change at all.

### 1.3 Two dispatchers carry the whole of GSUB and GPOS

`Otl::_applyGSUBsubtable` is 793 lines, 14 parameters, nested 10 deep; `_applyGPOSsubtable` is 883 lines, 13 parameters, nested 10 deep. Each is one `if ($Type == 1) … elseif …` chain with nested format chains inside. The tail applying a matched context's nested lookups is repeated at each of 11 `OMIT_OTL_FIX_3` guards.

#80 extracted the GPOS tail as `_applyGPOSlookupRecords` (`src/Otl.php:4492`), with a docblock explaining the shared structure. That is the idiom to follow, and phase 7 must not re-extract it.

### 1.4 Coverage is parsed twice, by two readers, one knowingly incomplete

`GSLuCoverage` is built at font-build time into `.GSUBdata.json` as unicode→coverage-index; the same coverage tables are re-read at shape time from the cache by `Otl::_getCoverage`/`_getCoverageGID` as hex/GID lists. Both feed the same lookup. The build-time one is marked in the source as partial:

```php
// NB Coverage only looks at glyphs for position 1 (i.e. 5.3 and 6.3)	// NEEDS TO READ ALL ********
```

That note is pasted three times — `TTFontFile.php:1610` and `:3593`, `OtlDump.php:1360` — and the second covers GPOS 7.3 and 8.3, so the shortcut is not confined to substitution.

Two coverage parsers that must agree, one knowingly wrong, is the same defect class as 1.1.

### 1.5 `Otl` holds three algorithms that are not OpenType layout

- **Unicode Bidi (UAX #9)**: `bidiSort`, `bidiPrepare`, `bidiReorder` — ~1,120 lines.
- **Arabic joining (`ArabicShaping.txt`)**: `arabic_initialise`, `arabic_shaper`, `get_arab_glyphs` — ~350 lines.
- **Dictionary line breaking**: `tibetanLineBreaking`, `seaLineBreaking`, `checkwordmatch` — ~200 lines.

None of them reference `read_ushort`, `seek`, `GSUB_offset` or `LuDataCache`, so they can leave independently of everything else here. `Shaper\{Indic,Myanmar,Sea}` already holds this kind of per-script logic.

Despite 42 declared properties, code outside `Otl` touches only 11 members, of which two are properties (`OTLdata`, `lastBidiStrongType`).

## 2. Target shape

Two rules keep this from becoming a rewrite.

**Fix units at the producer, do not abstract over them.** An earlier draft of this plan proposed a `Reader::at($base)` window so offsets could be read spec-relative. That is the wrong depth: it leaves the producer emitting the wrong unit, so every future consumer of `GSUBLookups` inherits the rebasing duty, and it allocates a window per coverage lookup in the hottest loop in the renderer. Storing table-relative offsets and splitting the cache file removes the need for any of it.

**One reader, two backends, four verbs.** `TTFontFile` and `OtlDump` read a font handle; `Otl` reads a cached string, and `ord($s[$p])` versus `fread` is a real difference in the hot path, so keep both. But the interface is only what `Otl` actually has — `seek`, `skip`, `read_short`, `read_ushort`; the file-only verbs (`read_tag`, `read_ulong`, `get_chunk`, `get_ushort`, `get_ulong`) belong on the stream backend alone. Do not name the classes `StreamReader`: `setasign/fpdi` is a hard dependency and already has one, reached from `Mpdf::getStreamReader()`. `FileReader` and `BlobReader`.

Readers return the plain arrays the code builds today. `Table\Coverage` and `Table\ClassDef` are worth having because three classes parse them; `_getValueRecord`, `_getAnchorTable` and `_getMarkRecord` exist only in `Otl` and `OtlDump`, so after phase 3 there is one copy of each in one class and hoisting it elsewhere is pure movement. Leave them.

Do not load whole fonts into memory to avoid the two backends — `makeSubset` walks `glyf`, which is megabytes.

## 3. Phases

Each is a PR that lands green alone and is revertable without unpicking the next. Cheap and independent work comes first, so the file every later phase is reviewed against is already smaller.

**Phase 0 — safety net and two prerequisites.** Section 4, plus:

- **A cache format version.** `Mpdf` regenerates a font cache only when the font file's size changes or `useOTL`/`BMPselected`/`fontmetrics` change. Nothing versions the *shape* of `.mtx.json`, `.GDEFdata.json`, `.GSUBdata.json` or the blob. Phases 3–5 all change a persisted shape, and without a version every existing install is served a stale cache and shapes text from offsets that no longer mean what they say — silent corruption, not an exception. Land a `cacheFormat` integer with regeneration on mismatch **before** anything touches a persisted shape.
- **Namespace the `LuDataCache` keys.** `_getCoverage`, `_getClasses` and `_getClassDefinitionTable` share `$LuDataCache[$fontkey][$offset]` while returning three different shapes. It is safe today only because blob offsets happen to be unique across GSUB and GPOS. Phase 4 makes offsets table-relative and that accident ends: GSUB offset `0x100` and GPOS offset `0x100` would collide and serve one lookup another's array, with no error. Key by table and reader (`[$fontkey]['GSUB']['coverage'][$offset]`), as `_getCoverageGID` already does with its `['GID']`. Worth doing on its own merits.
- Replace the 7 dead `microsoft.com/typography/otspec` citations with `learn.microsoft.com/en-us/typography/opentype/spec`. No production change; do it here rather than inside a larger PR.

**Phase 1 — move out what is not OpenType layout.** `Shaper\Arabic` first. `Shaper\*` methods are `public static function (&$info, $GSUBdata, …)` — state arrives as parameters — so the five joining tables `arabic_initialise()` builds (`arabGlyphs`, `arabLeftJoining`, `arabRightJoining`, `arabTransparentJoin`, `arabTransparent`) have to become static data or parameters; decide which, in this PR. Then bidi: put it at `Mpdf\Bidi`, not under `Fonts` — `Ucdn` already holds the UAX #9 bidi classes and `Otl` calls it 145 times, and `Shaper\*` sits at top level for the same reason. Then dictionary line breaking, beside `Shaper\Sea`. ~1,670 lines out, against an external surface of 11 members.

**Phase 2 — one reader.** Add `FileReader` and `BlobReader` and point the parsers at them. Five call sites, not three: `TTFontFileAnalysis extends TTFontFile` also re-walks the table directory inline, and `Mpdf::read_short()` is a sixth hand-rolled primitive with no callers — delete it. Rename call sites once; do not add forwarders that a later phase removes, which would touch ~1,350 call sites twice.

**Phase 3 — collapse `OtlDump`.** Re-point it at the shared parser so it dumps what the renderer parsed. Around 2,900 lines of duplicate parsing go, and every disagreement found on the way is a bug `OtlDump` was hiding — record them in the PR. Cheap and low-risk in production terms, but it has no tests today, so it needs the witness in section 4, and the 10 `phpstan-baseline.neon` entries anchored to its lines need regenerating. Add `use Strict` once it passes.

**Phase 4 — table-relative offsets.** Store `Subtables` relative to the containing table's base in `_getGSUBtables` and `_getGPOStables`, keeping a local base for the in-function re-seeks. Watch the extension lookups (GSUB 7 / GPOS 9), which add a second offset on top. All 12 GSUB rebasings become plain `$subtable_offset`, and `Otl::$GSUB_offset` and its `GDEFdata` entry go.

**Phase 5 — split the cache file.** Write `<fontkey>.GSUB.dat` and `<fontkey>.GPOS.dat`. The remaining 5 GPOS rebasings go, `GSUB_length` leaves `Otl` and `GDEFdata`, and the GPOS tripwire becomes structurally impossible rather than something to warn about.

**Phase 6 — shared struct readers.** `Table\Coverage` and `Table\ClassDef`, spec-anchored per section 5. Where the surviving copies differ, each difference is either a bug in one or an undocumented deliberate variation; the golden master says which. Fold 1.4 in here: make `GSLuCoverage` a projection of the one Coverage reader rather than a second parse.

**Phase 7 — split the dispatchers.** One method per spec structure, named for the structure (`_applySingleSubstFormat1`, not `_applyGSUB51`), sharing a GSUB counterpart to the existing `_applyGPOSlookupRecords`. Replace the type/format chains with a dispatch table and fold the 13–14 parameters into one state array. Retire the `OMIT_OTL_FIX_1/2/3` compile-time guards — undocumented escape hatches for decade-old fixes that multiply the paths a reader must hold. Clear the `???` markers by naming what the spec calls the value, or saying plainly what is not understood.

Last, because it is the riskiest and because the phases above make it much smaller.

Phases 6 and 7 lean on #80's context-matching tests. #80 has landed, so they are unblocked; it is also what took 1.2's rebasings from 15 to 17 and 1.3's guards from 8 to 11, which is the rate this grows at if the shape is left alone.

## 4. Safety net

`OtlTest` is 63 lines and covers only `sliceOTLdata`, but the net is not bare: `TTFontFileTest` (including `testFontDebugReadsTheSameMetricsAsNormalMode`, a second independent witness since `debugfont` walks the offsets by a different route), `ContextualSubstitutionTest`, `DictionaryLineBreakingTest` — which already drives `applyOTL` directly — `MarkGlyphSetsTest`, `Shaper\MyanmarTest`, and the matching snapshot pairs including `RtlSnapshotTest`. Extend these rather than building a parallel corpus; a second fixture table over the same fonts is one that rots.

**Parser golden master.** Do **not** serialise `get_object_vars()` the way `TTFontFileTest::metrics()` does for its 3-font comparison: per font that is ~4.4 MB, of which ~95% is `glyphIDtoUni`, `charWidths` and `glyphToChar` — built from `cmap`/`hmtx`, which no phase here touches. Thirteen committed dumps would be ~57 MB, ten times the entire existing snapshot corpus, and a 4 MB diff is not readable. Subsetting does not help: the 1.6 KB `NotoSansTakri-GSUB53-Subset.ttf` dumps to 5.26 MB, because `charWidths` is sized by cmap range.

Commit the **FontCache output** instead — `.GDEFdata.json`, `.GSUBdata.json`, `.GPOSdata.json` and the per-script `GSUB.<script>.<lang>.json`, plus a hash of the table cache files. For the 12 test fonts that parse that is 126 KB in total — 104 KB of it `NotoSans-Regular` alone, the rest under 10 KB each — against ~57 MB for the same 13 dumped. It is exactly the `TTFontFile`→`Otl` interface these phases change, and it subsumes a separate blob-identity check.

Run the whole corpus — 98 fonts, 13 in `tests/data/ttf/` and 85 in `packages/`. The 80 that parse take 0.66 s at 41 MB peak and yield 2.7 MB of JSON — median 26 KB per font, largest 343 KB. Runtime is no reason to tier. Commit the fixtures for `tests/data/ttf/` only, and run the `packages/` fonts as a generated check that compares against the previous phase rather than against committed bytes.

18 of the 98 throw `FontException` for carrying no GDEF: `angerthas.ttf`, and under `packages/` the eight Aboriginal faces, four DaiBannaSIL, both NotoEmoji, Eeyek, damase_v.2 and Sun-ExtB. Put the per-font expectation in the data provider, not in a `try`/`catch`.

**Shaping.** GSUB via `TextRecordingMpdf`, as `ContextualSubstitutionTest` does. GPOS via `OTLdata['GPOSinfo']` — `PositionRecordingMpdf` was added for #80 and is the seam; use it rather than adding another.

**`OtlDump`.** `utils/font_dump_otl.php` renders a PDF, so its golden master is a `Snapshots\Snapshot` subclass, not a bespoke capture-and-diff. Subclass it and use `utils/snapshot_update.php`; `Snapshot` already provides `outputPdf()` for documents it does not render itself, the `tmp/artifacts/<id>/` convention, and `PdfText`'s diff hunks. The script hardcodes `$family = 'khmeros'` and reads `$_REQUEST`, so parameterise it first — otherwise phase 3's gate cannot be run as written.

**`makeSubset`.** Section 7 excludes it, but it holds ~84 of the reader call sites phase 2 repoints. Its witness is raw-byte snapshot identity: the embedded subset font program sits inside every snapshot fixture. Name it as a phase 2 gate.

**Every phase:** `composer test`, `composer cs`, phpstan. The baseline shrinks or stays flat — regenerate and read the diff, never wholesale.

## 5. Spec anchoring

Requirement: a reader can put the spec beside the code and check it field by field. A `@see` URL alone does not deliver that, and the risk is concrete — an earlier draft of this plan illustrated the convention with a `Coverage` docblock claiming format 2's `StartCoverageIndex` is "discarded because mPDF only asks whether a glyph is covered". That is wrong. `_getCoverage($convert2hex, $mode = 2)` builds unicode→coverage-index and `Otl` uses it as `$GlyphPos` to index the substitute array; what the code actually does is read the font's declared `StartCoverageIndex` and throw it away, recomputing the index from a sequential counter. A link pasted over unchecked code produces confident documentation of something untrue.

So anchoring is a verification step, not an annotation step. For each structure:

- **Enumerate the spec's fields in order** and confirm the code reads or deliberately skips each one. Record every divergence — the recomputed `StartCoverageIndex`, the `FreeSerif` "blws" class-0 note in `_getClasses`, `read_ulong` avoiding PHP's signed-int wrap on uint32.
- **Name the struct, not the operation**, in both docblock and method name: `ChainContextSubstFormat1`, which a reader can grep the spec for.
- **Pin the spec version.** `$is_old_spec` threads through both dispatchers and means "pre-1.6 Indic"; a bare link to the current spec actively misleads there.

The vocabulary is already right in places — `LookaheadGlyphCount`, `BacktrackClasses`, `SubstLookupRecord`, `SequenceIndex` are the spec's own names. What is missing is the table identity and the confirmation, so most of this is annotation rather than renaming. Follow the house style set by the #80 docblocks: an imperative one-liner, then a paragraph of what the spec says.

## 6. Risks specific to this work

- **Hot path.** `Otl` runs per shaped chunk, and `applyOTL` re-reads its `GDEFdata` entries on every call — anything built "once" must be built inside the `!isset($this->GDEFdata[$this->fontkey])` branch, keyed by fontkey. `LuDataCache` memoises `_getCoverage`, `_getCoverageGID`, `_getClasses` and `_getClassDefinitionTable` for the life of the document; `_getValueRecord`, `_getAnchorTable` and `_getMarkRecord` are uncached and run per glyph. Those three are where allocation would hurt. Time a long Devanagari or Arabic document before and after: neither the suite nor CI will notice a 2× shaping regression.
- **Upstream divergence.** Phase 3 cannot be mirrored to `mpdf/mpdf` piecemeal. Raise it upstream before starting it.
- **Windows.** `fopen` stays `'rb'`.

## 7. Out of scope

- Implementing further lookups — that is #80.
- The coverage position-1 shortcut in 1.4 (`NEEDS TO READ ALL`) is a real rendering bug. File it; do not fix it inside a refactor whose gate is that output does not change.
- `$this->glyphToChar[$glyphID][0]` is read for glyphs no character reaches. `_getCoverage` guards it (`TTFontFile.php:3348`, added by #80); its 18 siblings do not — 12 in `_getGSUBtables`, 2 each in `extractInfo`, `_getClasses` and `_getClassDefinitionTable` — so parsing the `packages/` fonts emits undefined-key warnings and passes null to `dechex()`. Phase 0's corpus surfaces it on the first run. File it: applying the existing guard changes the value the parser yields, which is exactly what this series promises not to do.
- `makeSubset` / `makeSubsetSIP` / `repackageTTF` (~1,500 lines). They touch only `cmap`, `glyf`, `loca`, `hmtx`, `post` and `name` — no GSUB/GPOS/GDEF — so excluding them from an OTL refactor is scoping, not evasion. They keep their reader primitives (phase 2) and nothing else.
- The **writing** primitives — `pack_short`, `unpack_short`, `splice`, `_set_ushort`, `_set_short` — are as hand-rolled as the readers. Goal 1 covers reads only; a `Writer` counterpart is a separate issue.
- Changing what mPDF renders. Any output change found on the way is a bug report, not a patch in this series.
