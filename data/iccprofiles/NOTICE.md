# Bundled ICC profiles — third-party notices

mPDF ships the ICC profiles in this directory as **verbatim, unmodified** data
files. Each is covered by its own licence (below), independently of mPDF's own
licence — they are aggregated data assets, not part of mPDF's source code, and
are redistributed here under the terms their publishers grant. Do not alter these
files; ship them byte-for-byte.

To use a different output condition (e.g. your house print profile), set the
`ICCProfile` config key to the path of your own `.icc` file — it overrides the
bundled default and nothing here is embedded.

---

## sRGB_IEC61966-2-1.icc

- **Colour space:** RGB (used as the PDF/A and default output-intent profile).
- **Publisher / copyright:** International Color Consortium.
- **Embedded copyright string:** `Copyright International Color Consortium, 2009`.
- **Terms:** Distributed by the ICC as a freely redistributable reference
  profile; may be copied, embedded and redistributed without restriction.
- **Source:** <https://www.color.org/srgbprofiles.xalter>

## SWOP2006_Coated3v2.icc

- **Colour space:** CMYK, device class `prtr` (printer), ICC v2.
  Used as the embedded `/DestOutputProfile` output intent (and transparency-group
  blending colour space) for **PDF/X-4** documents that do not supply their own
  `ICCProfile`. Represents U.S. web-coated (SWOP) publication printing, 2006,
  grade 3 coated.
- **Publisher:** IDEAlliance, with permission of X-Rite, Inc.
- **Copyright:** X-Rite, Inc.
- **Licence (verbatim, as embedded in the profile's `cprt` tag and published in
  the ICC Profile Registry):**

  > Copyright X-Rite, Inc.. This profile is made available by IDEAlliance, with
  > permission of X-Rite, Inc., and may be used, embedded, exchanged, and shared
  > without restriction. It may not be altered, or sold without written
  > permission of IDEAlliance.

- **Source:** <https://registry.color.org/profile-registry/SWOP2006_Coated3v2>
- **MD5:** `7fbad4c0ae1cb7195c34bfc20e623437`

  This profile is freely redistributable and embeddable under the terms above.
  It is **not** an open-source/free-software licence (redistribution is
  permitted, but modification and sale of the profile itself are not without
  IDEAlliance's written permission), so it is bundled unmodified as a separate
  data file and is not covered by mPDF's own licence.
