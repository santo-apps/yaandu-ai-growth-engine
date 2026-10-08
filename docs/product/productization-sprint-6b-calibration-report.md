# Sprint 6B calibration report

**Benchmark:** `S6-CALIBRATION-V1`
**Status:** Dataset constructed; replay completed; calibration gate failed.
**Replay date:** 2026-10-07
**Application code:** unchanged during this task. No commit or push was made.

## Frozen data

The blind input and labels are separated under
[`tests/Fixtures/Sprint6BCalibration`](../../tests/Fixtures/Sprint6BCalibration/README.md).
Only `blind-input.json` was read by the replay scripts; ground truth was loaded
after replay for scoring.

| File | SHA-256 |
|---|---|
| `blind-input.json` | `86cabe9ed3f10c118516b101d7505fe8c2dc33a461ee7f28a3129cfc2c507295` |
| `ground-truth.json` | `959b420c640afd005934b711998258518035e6456ad31e834a61b602fabc01f7` |

## Dataset construction

There are 20 accepted positives: 10 India and 10 UAE. The set covers 9
normalized sectors: healthcare 4; retail 3; fashion/textile retail 3;
furniture/interiors 3; hospitality 2; perfumery 2; education 1; electronics 1;
manufacturing 1. Every label is marked HIGH and has two evidence URLs. This is
not equivalent to two independent domain-explicit sources for every record:
some second signals are the official business site/locator. The per-record
rationale is preserved in the labels. No rejected-business ledger was
maintained during curation.

Businesses: Chumbak; Apollo Hospitals; Mount Road Sangam; Kumaraguru College
of Technology; PSR Silks; Pothys; The Chennai Silks; Oorjaa; The Purple
Turtles; Goodgudi Retail; Al Hallab; Magrudy's; Sharaf DG; Royal Furniture;
Ajmal Perfumes; Emirates Pride Perfumes; Aster DM Healthcare; NMC Healthcare;
Mediclinic Middle East; Emirates Rawabi Company.

Evidence was collected from public government/institutional records, official
mall and business-location pages, recognized business listings, and current
official business pages. Search-result position was not treated as evidence.
No domain, website, email, phone, or address appears in blind input.

## Leakage audit

After the label hash was fixed, exact-domain queries against the local
PostgreSQL database found no matches in `web_index_documents`,
`website_resolution_candidates`, `discovery_candidates`, or `companies`.
Existing repository fixtures also had no exact-domain matches.

The full external OSM `website`/`contact:website` and Wikidata leakage audit
could not be completed. Public Wikidata SPARQL access timed out. An
OSM-derived Mapcarta result associates `apollohospitals.com` with an Apollo
hospital, so Apollo is a known possible OSM overlap; it is a different city
facility, and a direct OSM-tag result was not retrieved. The remaining 19 are
free of exact overlap in checked local sources, but are not confirmed
`NO_EXISTING_DOMAIN_EVIDENCE` until direct OSM and Wikidata checks complete.
Therefore final clean-vs-existing cohort sizes are unverified. All 20 had zero
retrieval candidates, so measured recall is zero in any possible cohort.

## Replay protocol and limitations

Sprint 5 was replayed using the committed source path at `HEAD`:
`c646becc1c1a4b50c8e0005dc7854b28e14fc424`. The unchanged Sprint 5
OSM-tag, linked Wikidata, and local-index sources received only the blind
identity fields; no OSM/Wikidata provenance was supplied.

Sprint 6 ran the current candidate-discovery service at the same base commit
plus the pre-existing uncommitted Sprint 6 working-tree implementation. It
used a temporary tenant and records inside a PostgreSQL transaction that was
rolled back. It ran each input once, left the discovery configuration at its
current values, and did not dispatch candidate verification or assignment.
The source returned a partial state for all records. In the inspected
per-record attempt, local index, OSM-linked evidence, Wikidata-linked
evidence, and public business email completed with zero results. The
`wikidata_open_search` source recorded `query_unavailable` followed by
`query_budget_exhausted`; the same two failure codes appeared in each of the
20 records. The provider returned no entities or domains.

This is a service-level source replay, not an HTTP/API journey or website
verification replay. Latency includes source lookup and persistence at
service level; do not compare it to endpoint or browser latency. Candidate
discovery does not assign a domain. Incorrect automatic assignments are
reported as zero observed; automatic verification/assignment was not run.
The benchmark is positive-only, locally assembled, small, and sector-skewed.

## Results

The p95 uses nearest-rank percentile. “Correct domain recall” is the number of
businesses with the official domain in the returned candidate list.

| Metric | Sprint 5 | Sprint 6 |
|---|---:|---:|
| Candidate-bearing | 0/20 | 0/20 |
| Correct-domain recall | 0/20 | 0/20 |
| Top-1 | 0/20 | 0/20 |
| Top-3 | 0/20 | 0/20 |
| Top-5 | 0/20 | 0/20 |
| No candidate | 20 | 20 |
| Incorrect suggestions | 0 | 0 |
| Incorrect automatic assignments observed | 0* | 0* |
| Average candidates/business | 0.00 | 0.00 |
| Median latency | 4 ms | 683 ms |
| p95 latency | 8 ms | 1,134 ms |
| Source failures | 0 | 40 failure codes (2 per business) |

\*The replay did not execute domain verification or automatic assignment.

Sprint 6 did not materially improve Sprint 5 on this benchmark and produced
no correct domains absent from existing evidence. The calibrated result is
**POOR** (0 candidate-bearing businesses), with the further caveat that the
new open-search source failed for all 20 identities.

Correct candidate source contribution: local index 0; OSM 0; Wikidata-linked
0; Wikidata open search/discovery 0; genuinely new correct domains 0.

## Recommendation

Do not expand this benchmark to 50 yet. Improve and operationally validate the
retrieval source that failed, complete the OSM/Wikidata leakage audit, then
rerun this exact frozen blind input and compare against the saved baseline.

## Sprint 6E closure note

The frozen application replay remains **0/20 candidate-bearing** and **0/20
correct official domains**. The standalone SearXNG PoC result of 1/5 does not
validate the application retrieval workflow; it experienced substantial
upstream CAPTCHA blocking. Wikidata did not yield enough successful business
retrieval to establish recall. The external OSM/Wikidata leakage audit remains
incomplete, so this benchmark must not be called leakage-free. The fixtures
remain frozen at the hashes recorded in `tests/Fixtures/Sprint6BCalibration/README.md`.

Sprint 6E closes the engineering implementation after final regression only.
Product discovery acceptance was not achieved, the retrieval provider remains
unresolved, and the focused-index proposal remains design-only with acquisition
yield unmeasured. See `productization-sprint-6-candidate-domain-discovery.md`
for the final engineering baseline, safety defaults, and limitations.
