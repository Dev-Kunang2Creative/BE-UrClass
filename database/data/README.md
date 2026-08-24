# Reference data

## ptn-prodi-2026.csv

Daya tampung SNBT 2026 and applicant counts from 2025, per PTN study
programme. Semicolon-delimited.

- Columns: `NO;KODE_PTN;NAMA_PTN;KODE_PRODI;NAMA_PRODI;JENJANG;DAYA_TAMPUNG_2026;PEMINAT_2025;JENIS_PORTOFOLIO`
- 4,017 programmes across 76 PTN
- 3,840 rows carry both a quota and an applicant count, so keketatan
  (applicants per seat) is computable for those

Underlying figures are published by SNPMB (https://snpmb.id). This file was
taken from https://github.com/Afra4509/scrapping-data-ptn-2026, which scrapes
the SNPMB portal; retrieved 2026-08-24, upstream last updated 2026-03-20.

That repository carries no licence. The numbers themselves are public
government data, but if this ends up in a commercial release it is worth
confirming provenance, or re-scraping snpmb.id directly so the chain of
custody is your own.

Committed rather than fetched at seed time on purpose: seeding must not depend
on a third-party host being up. When that source went down mid-evaluation it
answered 503, and a seeder that reached out live would have failed with it.
