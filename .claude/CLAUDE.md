# CLAUDE.md

## Core Principles

### Skills-First Workflow

**EVERY user request follows this sequence:**

Request → Load Skills → Gather Context → Execute

Skills contain critical workflows and protocols not in base context.
Loading them first prevents missing key instructions.

### Planning

When creating plans, always save them to: ./claude/plans/YYYY-MM-DD-<topic>.md
Never use the default plan location. Use the ./claude/plans/ directory.

### Memory

Save project memory to ./claude/memory/YYYY-MM-DD-<topic>.md
Never use the default memory location. Use the ./claude/memory directory.
When beginning a new session, view all memories in ./claude/memory.

### Context Management Strategy

**Central AI should conserve context to extend pre-compaction capacity**:

- Delegate file explorations and low-lift tasks to sub-agents
- Reserve context for coordination, user communication, and strategic decisions
- For straightforward tasks with clear scope: skip heavy orchestration, execute directly

**Sub-agents should maximize context collection**:

- Sub-agent context windows are temporary
- After execution, unused capacity = wasted opportunity
- Instruct sub-agents to read all relevant files, load skills, and gather examples

### Routing Decision

**Direct Execution**:

- Simple/bounded task with clear scope
- Single-component changes
- Quick fixes and trivial modifications

**Sub-Agent Delegation**:

- Complex/multi-phase implementations
- Tasks requiring specialized domain expertise
- Work that benefits from isolated context

**Master Orchestrator**:

- Ambiguous requirements needing research
- Architectural decisions with wide impact
- Multi-day features requiring session management

### Operational Protocols

#### Agent Coordination

**Parallel** (REQUIRED when applicable):

- Multiple Task tool invocations in single message
- Independent tasks execute simultaneously
- Bash commands run in parallel

**Sequential** (ENFORCE for dependencies):

- Database → API → Frontend
- Research → Planning → Implementation
- Implementation → Testing → Security

#### Quality Self-Checks

Before finalizing code, verify:

- All inputs have validation
- Authentication/authorization checks exist
- All external calls have error handling
- Import paths verified against existing codebase examples

### Coding Best Practices

**Priority Order** (when trade-offs arise):
Correctness > Maintainability > Performance > Brevity

#### Task Complexity Assessment

Before starting, classify:

- **Trivial** (single file, obvious fix) → execute directly
- **Moderate** (2-5 files, clear scope) → brief planning then execute
- **Complex** (architectural impact, ambiguous requirements) → full research first

Match effort to complexity. Don't over-engineer trivial tasks or under-plan complex ones.

## Commands

All via composer scripts defined in `composer.json`:

- `composer test` — run PHPUnit (uses `phpunit.xml`; excludes the `snapshot` group by default).
- `composer coverage` — same plus text coverage summary.
- `composer cs` — PHP_CodeSniffer against `src`, `utils`, `tests` with the repo's `ruleset.xml` (PSR-2 with tabs).
- `composer cs:fix` — auto-fix code style violations.
- `vendor/bin/phpstan analyse` — PHPStan level 2 over `src` (baseline in `phpstan-baseline.neon`; add new violations there rather than disabling rules).

### Running a single test

- Single file: `vendor/bin/phpunit tests/Issues/Issue2186Test.php`
- Single method: `vendor/bin/phpunit --filter testSettingWatermarkFontWithObject tests/Issues/Issue2186Test.php`
- Snapshot tests (excluded by default) require `imagick`, `ghostscript`, and PNG ImageMagick policy enabled: `vendor/bin/phpunit --group=snapshot`. Failing snapshot diffs are written to `tmp/artifacts/`.

### CI matrix

`.github/workflows/tests.yml` runs the full suite on PHP 5.6 → 8.5 across Ubuntu and Windows. Any change must remain compatible across that range — no PHP 7+ syntax (typed properties, arrow functions, match, named args, nullsafe, etc.) in `src/`.

## Architecture

mPDF predates modern PHP DI frameworks. The shape is unusual and worth internalizing before making non-trivial changes.

### The `Mpdf\Mpdf` god-class

`src/Mpdf.php` is ~27,500 lines and is the public entry point. Public methods like `WriteHTML()`, `Output()`, `AddPage()`, `SetHTMLHeader()` live here. It holds hundreds of public/var properties that collaborators mutate directly — treat `Mpdf` itself as shared mutable state, not an encapsulated object.

Construction flow (`__construct`, `src/Mpdf.php:1050`):
1. Merge user `$config` over defaults from `Mpdf\Config\ConfigVariables` and `Mpdf\Config\FontVariables`. **Any new configuration option must be declared in one of those two classes** so it's recognized.
2. Instantiate `ServiceFactory` (optionally PSR-11-ish container aware via the second constructor arg) and assign the returned collaborators onto `$this` as public properties (`$this->cssManager`, `$this->imageProcessor`, `$this->writer`, etc.).

### `Mpdf\ServiceFactory` (`src/ServiceFactory.php`)

Hand-wired factory that constructs every collaborator and returns them as an array keyed by service id. When adding a new service, register it in both `getServices()` and `getServiceIds()`. The factory also picks `CurlHttpClient` vs `SocketHttpClient` based on availability and lets container overrides swap `httpClient`, `localContentLoader`, and `assetFetcher`.

### HTML → PDF pipeline

1. **CSS** (`src/Css/`) — `CssParser` + `CssMerger` build a cascade stored on `CssManager->CSS` (flat) and `CssManager->CSS_TABLE` (nested for table selectors). `NormalizeProperties` canonicalizes values; `DefaultCss` supplies the UA stylesheet.
2. **Parse** — `WriteHTML()` tokenizes HTML and dispatches each opening/closing tag through `Mpdf\Tag` (`src/Tag.php`), which delegates to per-tag classes in `src/Tag/` (`src/Tag/A.php`, `src/Tag/Table.php`, `src/Tag/Img.php`, …). To add HTML tag support, drop a class in `src/Tag/` and register dispatch in `Tag.php`.
3. **Layout & buffer** — `Mpdf` accumulates low-level PDF drawing ops into `$this->buffer` / page buffers. `SizeConverter`, `Gradient`, `Otl` (OpenType layout), `Hyphenator`, and `ImageProcessor` (`src/Image/ImageProcessor.php`, plus `Bmp`, `Svg`, `Wmf`) are invoked from tag handlers.
4. **Output** — `src/Writer/` turns in-memory state into PDF object streams. `ResourceWriter` orchestrates the specialized writers (`FontWriter`, `ImageWriter`, `BookmarkWriter`, `MetadataWriter`, `PageWriter`, `BackgroundWriter`, `ColorWriter`, `FormWriter`, `JavaScriptWriter`, `OptionalContentWriter`). `BaseWriter` is the byte-level emitter and handles `Protection` (encryption). PDF/A and PDF/X variants are controlled by `$PDFA` / `$PDFX` properties on `Mpdf` and branched throughout the writers.
5. **Fonts** — `src/Fonts/` handles TrueType parsing (`TTFontFile.php` at repo root), caching (`FontCache` → `tmp/mpdf/ttfontdata`), and metrics. Bundled font metadata lives in `ttfonts/`; `data/` holds Unicode tables, CJK data, and language helpers.

### `Strict` trait

`src/Strict.php` is mixed into `Mpdf` and many collaborators. It overrides `__call`, `__get`, `__set`, etc. to throw `MpdfException` on undeclared properties/methods. Consequence: you cannot silently add a new public property by assignment — declare it on the class (or inside `ConfigVariables`/`FontVariables`). Dynamic property access that works on plain PHP will throw here.

### FPDI import

`FpdiTrait` (`src/FpdiTrait.php`) layers PDF-import capability from `setasign/fpdi` onto `Mpdf`. Enabled when `enableImports` config is set.

## Tests

- `tests/bootstrap.php` loads the composer autoloader and instantiates `new Mpdf()` once so that mPDF-level constants are loaded before any test runs.
- Autoload (from `composer.json`): `Mpdf\` → `tests/Mpdf`, `Snapshots\` → `tests/Snapshots`, `Issues\` → `tests/Issues`.
- `tests/Mpdf/BaseMpdfTest.php` is the standard base class — extends Yoast's PHPUnit polyfill `TestCase` (so use `set_up`/`tear_down`, not `setUp`/`tearDown`). It creates `$this->mpdf` with `['mode' => 'c']` and calls `cleanup()` on teardown.
- `tests/Issues/IssueNNNNTest.php` — one file per GitHub issue; the class name must match the filename (e.g. `Issue2186Test`) and live in namespace `Issues`. When fixing a bug, add a regression test here keyed to the issue number.
- `tests/Snapshots/` — visual PDF diffing. Each test extends `Snapshots\Snapshot`, implements `getId()` + `generatePdf()`, and is compared against a reference PDF in `tests/data/snapshots/` via Imagick after Ghostscript rasterization. Marked `@group snapshot` so they're skipped unless explicitly run.

## Code style

- `ruleset.xml` is PSR-2 with **tabs** for indentation (width 4). `phpcbf` (via `composer cs:fix`) will keep files in line.
- `var $foo;` style property declarations are used throughout `Mpdf.php` for PHP 5.6 compatibility — don't "modernize" to `public $foo` or typed properties in that file or other files that need to run on 5.6.
- `src/functions.php` (prod) and `src/functions-dev.php` (dev-only) are loaded via composer `files` autoload — prefer putting new code in classes unless you're extending existing global helpers.

## Temp directory

Runtime artifacts (font cache, working files) go to the path in the `tempDir` config, defaulting to the bundled `tmp/` directory (composer `post-install-cmd` chmods it to 0777). mPDF sweeps old files out of this directory, so never point it at shared storage.

## Changelog discipline

Per `.github/CONTRIBUTING.md`, PRs must add a line to `CHANGELOG.md` and be based on the `development` branch. Commits should be small and atomic.
