# PDF/UA-1 Test Fixtures — mpdf-examples

HTML fixtures extracted from the [mpdf/mpdf-examples](https://github.com/mpdf/mpdf-examples)
repository for use in `VeraPdfConformanceTest` veraPDF conformance tests.

## Provenance

- **Source repository**: https://github.com/mpdf/mpdf-examples
- **Branch**: master
- **Commit SHA at time of extraction**: `4f1e09d1cfda5ddc1205ceed8a7821c1edb2e4b8` (initial 16 fixtures)
- **Date of extraction**: 2026-04-28 (initial), 2026-04-30 (round 2: examples 03, 09, 11, 18, 19, 20, 21, 23, 24, 38)

Each fixture corresponds to the `$html` variable passed to `WriteHTML()` in the upstream
example, with the following adaptations applied consistently:

1. `mode='c'` (core fonts) stripped — PDF/UA-1 requires embedded TrueType fonts.
2. `$mpdf->Output($filename)` calls stripped — tests use `Output(null, 'S')` via `getOutput()`.
3. External image paths (e.g. `assets/tiger.jpg`) replaced with 1x1 red-pixel PNG data URIs
   where no equivalent fixture exists under `tests/data/img/`. A comment in each affected
   fixture documents the substitution.
4. `<?php` bootstrap code stripped — fixtures are pure HTML.
5. `if (PHP_SAPI != 'cli')` guards and surrounding HTML boilerplate stripped.

## Fixture files

| Fixture | Source example | What it exercises |
|---|---|---|
| `example01_basic.html` | `example01_basic.php` | H1–H6, P, A (hyperlink), DIV, BLOCKQUOTE, ADDRESS, PRE, HR, OL, UL, DL, TABLE |
| `example03_backgrounds_and_borders.html` | `example03_backgrounds_and_borders.php` | Body-level background gradient, linear/radial gradients, rounded borders |
| `example09_forms.html` | `example09_forms.php` | Comprehensive AcroForm widgets (textarea, select, radio, checkbox, text, password, submit/button/reset) |
| `example11_overflow_auto.html` | `example11_overflow_auto.php` | Fixed-position block element with overflow:auto autofit |
| `example18_headers_method_4.html` | `example18_headers_method_4.php` | Method-4 header API via inline `<!--mpdf ... mpdf-->` HTML comments with `<htmlpageheader>`/`<sethtmlpageheader>` |
| `example19_page_sizes.html` | `example19_page_sizes.php` | Multiple page sizes within one document via `<pagebreak sheet-size="...">` |
| `example20_justify.html` | `example20_justify.php` | Text justification with mixed inline styles, justified table cells with explicit /Headers |
| `example21_hyphenation.html` | `example21_hyphenation.php` | Automatic hyphenation in 4-column layout (CSS hyphens: auto + SetColumns) |
| `example23_orientation.html` | `example23_orientation.php` | Mid-document orientation switch via `<pagebreak orientation="L">` / orientation="P" |
| `example24_orientation_2.html` | `example24_orientation_2.php` | Orientation switch with a landscape table (Table/THead/TR/TH/TD with /Headers) |
| `example38_dot_tab.html` | `example38_dot_tab.php` | `<dottab>` directive emits a row of leader dots between two text runs |
| `example04_images.html` | `example04_images.php` | Various image formats (all replaced with data URIs); opacity; rotation; alt text |
| `example05_tables.html` | `example05_tables.php` | Simple tables; THEAD/TFOOT/TH; cell backgrounds |
| `example06_tables_nested.html` | `example06_tables_nested.php` | Nested tables (Table-in-TD) |
| `example07_tables_borders.html` | `example07_tables_borders.php` | Collapsed/separate table borders |
| `example08_lists.html` | `example08_lists.php` | OL/UL with roman, decimal, alpha, disc markers; nested lists |
| `example10_floating_and_fixed_position_elements.html` | `example10_floating_and_fixed_position_elements.php` | Float and fixed-position rendering (artifact tagging) |
| `example12_paging_html.html` | `example12_paging_html.php` | HTML page headers/footers via `<htmlpageheader>`/`<setpageheader>` tags |
| `example14_page_numbers_ToC_Index_Bookmarks.html` | `example14_page_numbers_ToC_Index_Bookmarks.php` | ToC (`<tocpagebreak>`), page numbers, bookmarks |
| `example16_headers_method_2.html` | `example16_headers_method_2.php` | Method-2 header/footer (PHP API — SetHTMLHeader/SetHTMLFooter in test method) |
| `example22_columns.html` | `example22_columns.php` | Multi-column layout content (SetColumns() called in test method) |
| `example26_RTL.html` | `example26_RTL.php` | RTL text (Hebrew, Arabic, Farsi, Urdu); bidirectional; Unicode symbols |
| `example34_invoice_example.html` | `example34_invoice_example.php` | Real-world invoice table with colspan, totals rows |
| `example36_annotations_and_attached_files.html` | `example36_annotations_and_attached_files.php` | HTML `<annotation>` tags; span title2annots feature |
| `example39_PDFA_compliance.html` | `example39_PDFA_compliance.php` | PDFA+PDFUA coexistence (config flags set in test method) |
| `example64_protected_document.html` | `example64_protected_document.php` | Encrypted document (SetProtection() called in test method); stripped mode='c' |

## How to regenerate

To refresh a fixture from the upstream mpdf-examples master:

```sh
curl -fsSL https://raw.githubusercontent.com/mpdf/mpdf-examples/master/example01_basic.php \
  | php -r '
    preg_match("/\\\$html = ('"'"'.*?'"'"');/s", file_get_contents("php://stdin"), $m);
    $html = eval("return " . $m[1] . ";");
    file_put_contents("example01_basic.html", $html);
  '
```

Or simply re-fetch all 16 sources and re-apply the adaptation rules documented above.
Record the new commit SHA from:

```sh
curl -fsSL https://api.github.com/repos/mpdf/mpdf-examples/commits/master \
  | grep '"sha"' | head -1
```
