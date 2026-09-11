mPDF (Gravity PDF fork)
=======================

Gravity PDF's fork of [mPDF](https://github.com/mpdf/mpdf), the PHP library that generates PDF files from UTF-8
encoded HTML.

Features and fixes are developed and tested here first, then submitted upstream to
[mpdf/mpdf](https://github.com/mpdf/mpdf) as pull requests. This fork is a staging area, not a separate library.

mPDF was written by Ian Back and is based on [FPDF](http://www.fpdf.org/) and
[HTML2FPDF](http://html2fpdf.sourceforge.net/) (see [CREDITS](CREDITS.txt)). It is released under the
[GNU GPL v2 licence](LICENSE.txt).

How this fork tracks upstream
-----------------------------

- `gravitypdf` is the default branch, kept current with upstream `development`.
- Work is done on `fix/*` and `feature/*` branches and merged into `gravitypdf` by pull request.
- A commit that fixes a problem reported upstream names it in the subject, for example
  `Keep a top caption with its table under use_kwt (mpdf/mpdf#1666)`.
- `origin` is `GravityPDF/mpdf`. Upstream is configured as the `upstream` remote.

Requirements
------------

PHP 5.6 to 8.5, with the `mbstring`, `gd` and `json` extensions. `zlib` compresses output and embedded fonts,
`bcmath` is needed for some barcode types, `xml` for SVG handling and charset conversion, and `imagick` for the
snapshot tests.

CI runs the test suite on every supported PHP version on both Linux and Windows, so code must stay PHP 5.6
compatible: no type declarations, no null coalescing, no arrow functions.

Installation
------------

The package name is still `mpdf/mpdf`, so add the repository and require the branch. `dev-gravitypdf` is aliased
to `8.x-dev` for constraint matching.

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/GravityPDF/mpdf" }
    ],
    "require": {
        "mpdf/mpdf": "dev-gravitypdf"
    }
}
```

Usage and configuration are the same as upstream; see the [online manual](https://mpdf.github.io/). Set your own
writable `tempDir`:

```php
$mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__ . '/tmp']);
$mpdf->WriteHTML('<h1>Hello world!</h1>');
$mpdf->Output();
```

mPDF deletes old temporary files from its temporary directory, so give it a directory of its own.

Development
-----------

```bash
composer install                        # font packages are symlinked in from packages/
composer test                           # the full PHPUnit suite
composer test -- --group=snapshot       # snapshot tests only
composer test -- --filter IndexOrderTest
composer snapshot:update <id>           # regenerate a snapshot fixture (or 'all')
composer cs                             # phpcs, PSR-2 with tabs
composer cs:fix
vendor/bin/phpstan --no-progress        # level 2 over src/; install phpstan/phpstan:^2.0 first
```

Tests are in three directories: `tests/Mpdf` for unit and behaviour tests, `tests/Issues` for one regression test
per upstream issue number, and `tests/Snapshots` for whole documents.

A snapshot test renders a document and compares it against a fixture in `tests/data/snapshots`: first the raw
bytes, then the PDF objects in a readable text form, and only if those differ are the pages rasterised with
Imagick and compared pixel by pixel. Snapshot documents use a fixed creation date, no version string and no
compression, so their output does not vary between machines. On failure the generated document, a diff of the
objects that changed, and an image of each changed page are written to `tmp/artifacts`.

If Imagick is not installed, a difference that only the rendered pages can settle is reported as skipped rather
than passed. Check the diff in `tmp/artifacts` before treating a skip as a pass.

Contributing
------------

- Branch from `gravitypdf` as `fix/<issue>-<slug>` or `feature/<issue>-<slug>`.
- Include a test. Layout changes usually need a snapshot test; crashes and notices usually need a test in
  `tests/Issues`.
- Keep commits small and atomic. Write the subject as a sentence describing the behaviour change, naming the
  upstream issue as `(mpdf/mpdf#NNNN)` where one applies.
- The coding standard is PSR-2 with tab indentation, enforced by `composer cs`.

Bugs in released mPDF should be reported [upstream](https://github.com/mpdf/mpdf/issues); see upstream's
[CONTRIBUTING.md](.github/CONTRIBUTING.md) for what a report needs. For general mPDF questions, use upstream
[Discussions](https://github.com/mpdf/mpdf/discussions) or the
[mpdf tag](https://stackoverflow.com/questions/tagged/mpdf) on Stack Overflow.
