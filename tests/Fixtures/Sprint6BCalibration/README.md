# Sprint 6 calibration benchmark

Version: `S6-CALIBRATION-V1`

The benchmark inputs and ground-truth labels are separate files. A resolver
replay must read only `blind-input.json`; do not open or load
`ground-truth.json` in the resolver process. Inputs contain only the business
name, city, country, category, and benchmark ID. Labels are for post-run
scoring only.

## Frozen data hashes

SHA-256 (`blind-input.json`):
`86cabe9ed3f10c118516b101d7505fe8c2dc33a461ee7f28a3129cfc2c507295`

SHA-256 (`ground-truth.json`):
`959b420c640afd005934b711998258518035e6456ad31e834a61b602fabc01f7`

These hashes identify the data snapshot before any resolver replay. If either
file changes, the benchmark version must be incremented and both replays
restarted.

## Curation and evidence

Businesses were selected from public institutional, government, mall, and
business-directory references, then their official domains were corroborated
against current official location or business pages. Search result rank was
not used as evidence. The benchmark includes 10 India and 10 UAE businesses.
All 20 labels were assigned HIGH confidence; each contains two cited evidence
URLs. The second signal is not always independent of the business itself (for
example, an official store locator); see the per-record rationale before using
this set as a final external benchmark.

## Leakage checks

Leakage checks are post-freeze only. Local PostgreSQL records were checked in
`web_index_documents`, `website_resolution_candidates`,
`discovery_candidates`, and `companies`; no exact domain overlaps were found.
Repository test fixtures were searched; no pre-existing fixture overlap was
found. A complete OSM website/contact:website and Wikidata leakage audit is
still pending because public endpoint access was unavailable during this
construction run. Do not classify the set as leakage-free until those checks
are completed.

## Scope

This is a positive-only calibration set. It contains no negative controls and
does not measure precision against a representative production population.
It should be used only for the requested fixed-input source comparison, with
unavailable replays and source failures reported explicitly.
