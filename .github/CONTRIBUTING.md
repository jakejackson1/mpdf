Contributing
============

Issue tracker
-------------

The Issue tracker serves mainly as a place to report bugs and request new features.
Please do not abuse it as a general questions or troubleshooting location.

General troubleshooting
-------------

For these questions please use [Discussions](https://github.com/mpdf/mpdf/discussions). Add your enquiry
to appropriate category and as always, include a reproducible code example when applicable (see code example guidelines below).

You can also use the [mpdf tag](https://stackoverflow.com/questions/tagged/mpdf)
at [Stack Overflow](https://stackoverflow.com/)
as the StackOverflow user base is more likely to answer you in a timely manner.
When doing so, make sure you comply to StackOverflow question guidelines.

Bug reports
-------------

* Bug reports **MUST** contain a small example in php/html that reproduces the bug.
* The code example **MUST** be reproducible by copy&paste assuming composer dependencies are installed. That means:
    * No calling unrelated funcions,
    * an actual final HTML code has to be present, pasting a template file is not enough,
    * if the bug considers import or fonts, example source PDF/TTF/etc files have to be included.
* Failing to provide necessary information or not using the issue template will cause the issue to be closed until required information is provided.
* Please report one feature or one bug per issue.

Feature requests
-------------

Feature requests have to be labeled as such and have to include reasoning for the change in question.


Pull requests
-------------

Pull requests should be always based on the default [development](https://github.com/mpdf/mpdf/tree/development)
branch except for backports to older versions.

Guidelines:

* Use an aptly named feature branch for the Pull request.
* Only files and lines affecting the scope of the Pull request must be affected.
* Make small, *atomic* commits that keep the smallest possible related code changes together.
* Code must be accompanied by a unit test testing expected behaviour whenever possible.
* To be incorporated, the PR should contain a change in the CHANGELOG.md file describing itself

When updating a PR, do not create a new one, just `git push --force` to your former feature branch, the PR will
update itself.

Testing PDF/UA-1 conformance locally
-------------------------------------

The `@group verapdf` tests in `tests/Mpdf/Ua/VeraPdfConformanceTest.php` validate
mPDF's PDF/UA-1 output against the veraPDF conformance checker. They are excluded
from the default `composer test` run (so they are never a barrier to local development
of unrelated changes) and only run when `VERAPDF_BIN` is set.

**Install veraPDF locally:**

Follow the instructions at https://docs.verapdf.org/install/ to download and install
veraPDF (requires Java 17+). The typical macOS/Linux install places the CLI wrapper
at `~/verapdf/verapdf`.

**Run the conformance tests:**

```bash
VERAPDF_BIN=~/verapdf/verapdf vendor/bin/phpunit --group=verapdf
```

Without `VERAPDF_BIN` set, the entire `verapdf` group is skipped with an informative
message — no Java or veraPDF installation is required to run the standard test suite.

CI runs these tests via the dedicated `verapdf` job in `.github/workflows/tests.yml`
(single Ubuntu runner, PHP 8.3, Java 17 via Temurin, veraPDF 1.27.1) and the job
is a required gate before merging PDF/UA-1 changes.
