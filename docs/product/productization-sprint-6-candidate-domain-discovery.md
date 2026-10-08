# Productization Sprint 6 — Open-Web Candidate Domain Discovery

## Pre-implementation repository audit

Branch: `codex/sprint-6-open-web-domain-discovery`, created from updated `main` at `c646becc1c1a4b50c8e0005dc7854b28e14fc424` after a fast-forward pull. The Sprint 5 merge is present. This audit was completed before application-code changes.

### Existing architecture to reuse

| Concern | Existing implementation | Sprint 6 use |
| --- | --- | --- |
| Business discovery | `App\\Agents\\DiscoveryAgent`, `DiscoverySourceInterface`, `DiscoverySourceAggregator`, `DiscoverySourceRegistry`, `DiscoveryCandidateService`, `DiscoveryQueryPlanner` | Continue to create known business identities and preserve their source data. Candidate-domain discovery is a later, explicitly requested step for website-less rows. |
| Search seam | `SearchEngineInterface`, `SearchQuery`, `SearchResultCollection`, `WebSearchDiscoverySource` | Keep provider search separate from domain-candidate policy. The current deterministic web-search binding is not used as a live source. |
| Existing candidate inputs | `OSMWebsiteEvidenceSource`, `WikidataWebsiteResolutionSource`, `LocalWebIndexResolutionSource` | Reuse explicit OSM website tags, linked Wikidata P856 evidence, and local-index identity matches as distinct evidence. Do not turn Common Crawl into name search. |
| Candidate verification and identity | `ResolveWebsiteCandidateJob`, `UrlPolicy`, `RobotsRules`, `WebsiteIdentityPageExtractor`, `BusinessWebsiteIdentityMatcher`, `DirectoryDomainClassifier`, `WebsiteResolutionSourceRegistry` | Send evidence-backed URLs through the existing safe fetch, same-domain/redirect, robots and identity-match path. Do not add a verifier or second identity score. |
| Persistence | `website_resolutions`, `website_resolution_attempts`, `website_resolution_candidates`, `website_resolution_evidence` | Reuse tenant-scoped resolution runs, attempts, candidate rows and match evidence. Add only the raw discovery-result and separate discovery-rank fields needed to avoid conflating raw search results, candidate ordering, and identity confidence. Composite tenant foreign keys already protect these tables. |
| Human review | `DiscoveryController` website-resolution endpoints and the existing resolution UI in `ProspectsWorkspace.vue` | Extend the current review flow to show discovery evidence and candidate rank while preserving confirm/reject/unresolved semantics. |
| Budgets and queue | Existing website-resolution request/run caps, unique `ResolveWebsiteCandidateJob`, Redis/Horizon, and the dedicated `web-index` queue | Add bounded query/result/verification/bulk budgets and use a distinct candidate-discovery queue; never use outreach/workflow queues. |
| Tenant/security | Sanctum + `ResolveTenant`, tenant-scoped queries, composite tenant FKs, `UrlPolicy` with public DNS pinning, and no AI in website resolution | Keep every query, source result, candidate, and action tenant-scoped. Search result text is untrusted and discovery remains deterministic. |

### Gaps found

1. Current `WebsiteResolutionSourceInterface::find()` returns URL candidates directly; it does not model query planning, raw result classification, per-result provenance, discovery rank, or source-health/budget details as a distinct stage.
2. `SearchEngineInterface` is bound to `DeterministicSearchEngine` in every environment. We did not find a permitted general web-search adapter that should be required for product use.
3. Existing local index and linked Wikidata sources are useful but are not a general identity-to-domain search. Common Crawl only enriches already-known domains.
4. Existing candidate `score` and `confidence_band` are identity-match confidence. Reusing those for search result rank would conflate discovery with proof; the Sprint 6 schema/UI must keep the values separate.
5. The resolution job already runs source lookup, safe verification, identity scoring, policy and persistence. The new discovery layer must remain a separately testable service invoked before the existing verification/matching handoff, rather than duplicating these controls.
6. The UI has website-less discovery candidates and resolution review, but no explicit bounded Find Website/Find Websites product action or candidate-discovery operations metrics.
7. The repository contains no 50-business blind ground-truth dataset. Sprint 5 documentation records aggregate 50-business metrics (2 candidate-bearing, 1 correct of 2 known ground truth, 0 incorrect, 48 no candidate), not the underlying identity list or independent source citations. A new benchmark must therefore document provenance and must never feed its ground-truth domains to discovery.

### Sprint 6 target flow

`Known Business Identity → bounded Query Planner → pluggable Candidate Domain Discovery Sources → raw result persistence/classification → safe URL/domain normalization → evidence-preserving deduplication → explainable discovery ordering → existing URL verification → existing website identity matcher/confidence → existing auto-resolution policy or human review`.

Discovery rank is a source-ordering signal only. Existing identity confidence is retained separately. Directory/social results remain raw evidence; only an explicitly linked external business website may be extracted as a separate URL candidate. No guessed domains are generated. The current `WEBSITE_RESOLUTION_AUTO_RESOLVE_HIGH_CONFIDENCE=false` default remains unchanged.

Candidate-domain discovery is available as an explicit action, capped per business and per bulk request; it does not run automatically on every OSM row. Source failure is recorded and isolated so successful sources can still produce a `PARTIALLY_COMPLETED` run. An empty successful result is `NO_CANDIDATES`, distinct from technical failure.

## Implemented architecture

- `CandidateDomainDiscoverySourceInterface` returns query provenance, bounded raw results, source metadata and failures. It is separate from safe URL verification and `BusinessWebsiteIdentityMatcher`.
- The pipeline reuses explicit OSM website evidence, linked Wikidata claims, local web-index identity evidence, public business-email domain evidence, and Wikidata Action API entity search. Common Crawl remains supplemental only after a known domain exists.
- `CandidateDomainQueryPlanner` uses known name, location, category, region, phone and alternate names, capped at five queries. It does not derive domains from names.
- Discovery rank and `discovery_score` are separate from existing identity `score`/`confidence_band`. Search rank is only a bounded ordering signal; entity-name search alone is neutral identity evidence.
- Raw results are persisted separately in tenant-scoped `website_resolution_search_results` with composite tenant foreign keys. A directory/social page is retained as evidence; only a separately explicit linked target can enter candidate ranking.
- Candidate URLs are normalized by the existing `DomainNormalizer`, tracking parameters are removed, unsafe schemes/credentials/IP literals are rejected, and candidate verification is handed to existing `ResolveWebsiteCandidateJob` with DNS/IP, robots, redirect and identity-matching controls.
- Manual prospects without websites can request discovery from Prospect 360. The request creates a tenant-linked identity candidate and an idempotent job on `candidate-discovery`; it has no outreach side effects.
- Candidate-discovery metrics are available in manager-only Corpus Operations: tenant-scoped runs, state counts, source calls/failures, raw result and candidate totals, failure codes, cache observations, and median/p95 latency over the latest 50 runs.
- Deterministic local/testing fixtures support positive, multiple, no-result, timeout, malformed URL, directory, social, duplicate-domain and conflicting-identity cases. Fixture URLs are supplied data and are never generated from business names.

### Production-shaped open source

The production-shaped source uses Wikidata's documented HTTPS Action API (`wbsearchentities` and entity `P856` claims). It is one bounded open evidence source, not a general web search engine or a coverage guarantee. The adapter sends an identified User-Agent and `maxlag`, caches responses, uses a source-specific rate budget, applies connect/total timeouts and response-size caps, and fails closed on HTTP/API errors. Wikimedia documents client identification and recommends caching; WDQS documentation describes throttling and requires a descriptive User-Agent. References: [API Etiquette](https://www.mediawiki.org/wiki/API:Etiquette/en), [Wikimedia API access policy](https://www.mediawiki.org/wiki/Wikimedia_APIs/Access_policy), [Wikidata Query Service limits](https://www.mediawiki.org/wiki/Wikidata_Query_Service/User_Manual/eu).

### Default budgets

| Budget | Default |
| --- | ---: |
| Queries per business | 5 |
| Source calls per business | 5 |
| Raw results per source | 10 |
| Candidate domains per business | 10 |
| Verification candidates per business | 5 |
| Businesses per bulk request | 25 |
| Discovery wall time | 45 seconds |
| Wikidata Action API requests | 30/minute |
| Wikidata cache | 7 days |

All caps are environment-configurable and hard-capped in application configuration. Discovery is an explicit action; no automatic OSM expansion is enabled.

### Result states and side effects

`PENDING`, `SEARCHING`, `CANDIDATES_FOUND`, `VERIFYING`, `COMPLETED`, `PARTIALLY_COMPLETED`, `NO_CANDIDATES`, and `FAILED` are represented in resolution/attempt records. Successful empty results remain distinct from technical failure. A failed source does not discard successful evidence. Auto-resolution remains off by default. Candidate discovery does not create campaign recipients, outbound messages, send approvals, meetings or proposals.

## Acceptance evidence

Sprint status: **PARTIALLY COMPLETE**. Architecture correctness and regression gates pass, but the mandatory independent blind benchmark is unavailable and has not been replayed. This is not a production-readiness claim.

### Controlled benchmark and baseline

### Existing Sprint 5 benchmark artifact


The old artifact `/private/tmp/yaandu-sprint5-coverage-sample.json` contains 50 real OSM identity inputs, stratified across five locations and retail/healthcare categories: 25 India and 25 UAE. Only two explicit OSM website tags were retained as ground truth. Sprint 5 measured 2 candidate-bearing / 50, 1 correct domain among the 2 known labels, 0 incorrect assignments, and 48 no-result. It is not the required controlled dataset: 48 independent official-domain labels and 10 negative controls are missing. No qualifying benchmark was executed for Sprint 6, so Sprint 6 candidate recall, Top-1/3/5, false candidate rate, no-candidate rate, average candidates/business, and comparable baseline-vs-new-system results are **NOT MEASURED**. Incorrect automatic assignment evidence is 0 in the existing scoped tests and local integration, but this is not a 50-business benchmark result.

### Bounded live check


One real business identity (`Aster Medical Centre`, Dubai, UAE, healthcare) was sent to the live Wikidata Action API adapter with a two-query cap. Businesses attempted: 1; planned queries/provider calls: 2; raw results/candidate domains/verified domains: 0; cache hits: 0; source failures: 1 (`query_unavailable` after a public API HTTP 200 `maxlag` error body); elapsed latency: 1,355 ms. No independently established ground-truth domain was available for this run. This demonstrates safe failure reporting, not coverage. The earlier run that treated an HTTP 200 API error body as an empty result is excluded; the adapter now rejects API error bodies and uses a new cache version.

### PostgreSQL/Redis integration

The local integration run uses a unique Redis prefix, executes the candidate-discovery job through Redis, verifies raw directory evidence persisted in PostgreSQL, verifies replay idempotency, and asserts zero campaign recipients, outbound messages, meetings and proposals within its disposable tenant. Both opt-in integration tests passed: 2 tests / 34 assertions per run. The directory fixture deliberately has no external business-site link, so its URL remains evidence and does not become a candidate.

Sprint 6A stability follow-up: five consecutive runs were completed with distinct Redis prefixes. All five passed, each with 2 tests / 34 assertions. The earlier raw-result count assertion failure was not reproduced. Its historical root cause remains **UNKNOWN**: the original failed-run environment, queue/cache state, and row-level artifacts were not captured, so this evidence cannot distinguish test-data leakage, timing, cache, idempotency, assertion, or environmental causes. Do not treat reruns as proof of the original cause.

### Browser and responsive acceptance

The local app was opened in Chromium on its configured `127.0.0.1:5173` origin and signed into the existing local-only visual-QA manager tenant. Prospects, the website-less Prospect 360 overview, the Find Prospects / Runs & candidates empty state, and manager Corpus Operations candidate-discovery metrics were inspected at 1440, 1280, 768, and 390 CSS pixels. Each measured `document.documentElement.scrollWidth === innerWidth`; no page-level horizontal overflow was found. Operations metrics/cards reflow to one column on mobile and the ingestion runs table stays within its own scroll container. The seven primary sales links, manager-only Operations entry and active navigation state remained visible at desktop; the narrow shell collapsed the management sidebar while retaining primary sales navigation. The empty candidate list did not expose bulk selection/action controls during this visual pass.

The tenant had no website-less candidate result, so a populated candidate-review card/drawer, Confirm/Reject actions, and bulk selection with candidates could not be visually exercised. Their API semantics are covered by existing website-resolution/prospect-discovery feature tests and the dedicated discovery tests. A temporary manual prospect created to inspect Prospect 360 was removed after the browser check. No outreach or discovery job was triggered by visual testing.

### Final verification baseline

- Focused candidate-discovery feature tests: **13 tests / 82 assertions PASS**.
- Full PHPUnit: **212 tests / 1,514 assertions PASS**.
- PostgreSQL + Redis integration: **2 tests / 34 assertions PASS**.
- TypeScript: **PASS** (`npm run typecheck`).
- Vite production build: **PASS** (`npm run build`).
- PHP syntax sweep: **337 files PASS**.
- Composer validation: **PASS** (`composer validate --no-check-publish`).
- Candidate website-resolution routes: **8 registered** (company discovery GET/POST, bulk request, candidate request, resolution metrics/detail/retry/review); candidate-discovery Operations adds **1 route**.
- `git diff --check`: **PASS**.
- Browser responsive checks: no document-level overflow at the four specified widths on the inspected screens; populated candidate review was not available in the test tenant.
- Zero outreach in scoped discovery tests/integration: recipients, outbound messages, `SEND_OUTREACH` approval, meetings and proposals remained absent.

### Sprint 6A acceptance attempt (2026-10-07)

- The old `/private/tmp/yaandu-sprint5-coverage-sample.json` still contains 50 OSM identity inputs but only two explicit expected-domain labels, both copied from OSM website tags. Those labels are therefore exposed to an existing evidence source and are not independent blind ground truth. The file cannot serve as the requested independently labeled 50-positive benchmark; one label (`dubaihtc.com`) refers to a renamed medical-centre identity and requires adjudication before use.
- No frozen Sprint 6A dataset, content hash, 10-control set, blind replay, cohort metrics, or valid paired Sprint 5/Sprint 6 comparison was produced. No positive sample was padded with unsupported labels.
- A read-only inventory found 13 distinct domains in `web_index_documents`. The two old labels overlap explicit OSM website evidence by provenance; `aabsweets.com` additionally exists in the local web index, while `dubaihtc.com` does not. Because 48 labels are absent and these two labels are source-leaked, this is not a 50-business leakage audit and does not establish valid EXISTING_EVIDENCE / NO_EXISTING_DOMAIN_EVIDENCE cohort sizes. Other source/table overlap, including Wikidata and prior fixtures, remains unaudited for a complete label set.
- The local dashboard opened on `127.0.0.1:5173`, but its visible Home workspace showed zero prospects and a loading recent-prospects section. No populated candidate-review or bulk-selection fixture was created or visually accepted. This does not extend the earlier empty-state responsive inspection into populated-state acceptance.
- Post-check database totals were 2 campaign recipients, 2 outbound messages, 1 meeting, 2 proposals, and 1 `SEND_OUTREACH` workflow approval. These are pre-existing global totals of unknown provenance; no before snapshot was captured, so a system-wide zero-outreach delta is **NOT PROVEN**. The repeated integration test asserted zero consequential records only inside each disposable test tenant.
- Focused regression group: 70 tests / 677 assertions passed. Full PHPUnit: 212 tests / 1,514 assertions passed. PostgreSQL/Redis: five runs, each 2 tests / 34 assertions passed. TypeScript, Vite production build, Composer validation, PHP syntax (337 files), registration of 167 `api/` routes (including 8 website-discovery/review/operations routes), and `git diff --check` passed. There is no configured frontend lint script.

This attempt does not satisfy the Sprint 6A benchmark or populated UI acceptance gate. Sprint 6 remains **PARTIALLY COMPLETE**; no production coverage or correctness improvement is inferred from these verification results.

### Remaining acceptance work

- Build an independently sourced 50-business ground-truth set (25 India / 25 UAE) and at least 10 negative controls, with website labels stored separately from blind input.
- Replay identical identity-only inputs through Sprint 5 and Sprint 6; report baseline and new candidate recall, Top-1/3/5, false candidate rate, incorrect automatic assignment count, no-candidate rate, and per-business latency.
- Visually exercise populated website candidate review and bulk selection/actions using a resettable deterministic candidate fixture; current visual check covered the empty state only.
- Investigate the one initial non-reproducible PostgreSQL/Redis raw-result assertion failure.

The unresolved dataset and replay mean the Sprint 6 acceptance gate is not fully met. Do not describe Sprint 6 as complete or production-ready until the benchmark is available and executed, or the product owner explicitly revises that acceptance gate.

### Known issues and production limitations

- **Known P0:** none identified by the executed automated and local acceptance checks; this is not a comprehensive production security review.
- **P1 acceptance blocker:** independent 50-business ground truth, 10 negative controls, and paired Sprint 5/Sprint 6 replay are still missing.
- **P1 test-stability follow-up:** one initial PostgreSQL/Redis raw-result assertion failed and could not be reproduced in the isolated and final reruns; investigate before treating the integration test as stable under repetition.
- The Wikidata Action API returned a `maxlag` error during the bounded live run. No search-source SLA or coverage is established.
- Production source capacity, corpus growth, monitoring/alerts, retention and cost at scale remain out of scope for this sprint and are not verified here.

Sprint 6 is not accepted or production-ready until those evidence gaps are resolved or formally accepted as unavailable without misstating benchmark results.

## Sprint 6E — Engineering Closure and Baseline Preservation

**Engineering implementation: COMPLETE, subject to the final regression recorded here.**
**Product discovery acceptance: NOT ACHIEVED.**
**Retrieval provider: UNRESOLVED.**
**Production-ready: NO.**

### Acceptance outcome

- The frozen 20-business application replay found **0/20 candidate-bearing businesses** and **0/20 correct official domains**. It is a small, positive-only, locally assembled calibration set and does not establish production precision or coverage.
- The standalone SearXNG PoC found the correct official domain for **1/5** businesses. DuckDuckGo produced 13 CAPTCHA events and one upstream crash; unattended use is unsuitable. The prior runner also continued scheduled requests after the first CAPTCHA, a documented procedural deviation. No CAPTCHA bypass was attempted.
- The application’s Wikidata name-search replay found no candidate domains for the frozen 20 identities. The separate Sprint 6D API test produced seven empty business-entity searches and then HTTP 429 before the planned five-business sequence completed. Wikidata recall is therefore **not established**.
- Focused-index feasibility remains design-only. Domain-acquisition yield for India and UAE is unmeasured; no national coverage or cost claim is made.
- The external OSM/Wikidata benchmark leakage audit is incomplete. Do **not** describe `S6-CALIBRATION-V1` as leakage-free. Its frozen hashes and the leakage caveats are preserved in `tests/Fixtures/Sprint6BCalibration/README.md`.
- The Sprint 6D provider-selection research and its raw API responses are preserved outside this repository under `/private/tmp/yaandu-open-web-search-poc/sprint6d/`; no raw search-response artifacts or credentials are included in this source-control change.

### Operational safety

- The new general Wikidata name-search source is **disabled by default** (`CANDIDATE_DISCOVERY_WIKIDATA_ENABLED=false` unless explicitly overridden). This does not disable the existing, distinct linked-entity/P856 evidence lookup.
- SearXNG, DuckDuckGo, Mwmbl, Tavily, Brave, and other unvalidated retrieval services are not registered as application candidate sources. No search provider has been selected for production integration.
- Candidate discovery remains explicitly requested, tenant-scoped, queue-budgeted and idempotent. Candidate URLs remain suggestions and pass through existing URL/SSRF, robots, identity-verification and human-review controls. `WEBSITE_RESOLUTION_AUTO_RESOLVE_HIGH_CONFIDENCE` remains false by default.
- Discovery creates no campaign recipients, outbound messages, send approvals, meetings or proposals. PostgreSQL/Redis integration assertions verify this within their disposable test tenant; this is not a claim that global pre-existing database totals are zero.
- Source failures are isolated and safe failure codes/counters are persisted. Provider HTTP response bodies/status details are intentionally not persisted; this limits diagnosis of rate-limit and upstream failure causes and remains follow-up work.

### Final regression baseline — 2026-10-08

- Focused candidate discovery, website-resolution, prospect-discovery and URL-policy regressions: **44 tests / 301 assertions PASS**.
- Full PHPUnit suite: **212 tests / 1,514 assertions PASS**.
- PostgreSQL + Redis integration: **2 tests / 34 assertions PASS** against `yaandu_growth` and Redis.
- TypeScript: **PASS** (`npm run typecheck`).
- Vite production build: **PASS** (`npm run build`).
- PHP syntax: **363 / 363 files PASS** across `app`, `bootstrap`, `config`, `database`, `routes` and `tests`.
- Composer validation: **PASS** (`composer validate --no-check-publish`).
- `git diff --check`: **PASS**.
- The new general Wikidata source default was checked disabled; it must be explicitly enabled for any future source experiment.

### Remaining P0/P1 and production limitations

- **Known P0 defects: 0.** This is based on the implementation audit and stated regression suite, not a full production security review.
- **P1 product acceptance gap:** 0/20 candidate-bearing results; no validated retrieval provider and no demonstrated business-domain recall.
- **P1 benchmark integrity gap:** OSM/Wikidata leakage checks remain unresolved, and the positive-only fixture has no negative controls. No leakage-free claim is permitted.
- **P1 diagnostics gap:** safe source error categories are stored, but exact HTTP statuses/provider response details are not retained for root-cause analysis.
- **Production limitations:** provider commercial-use approval, operational quotas/SLA, India/UAE coverage, licensed corpus acquisition, source refresh yield, cost at scale, monitoring/alerting, and production deployment have not been established.
- One earlier PostgreSQL/Redis run had a raw-result assertion failure that did not reproduce in the subsequent isolated/final runs; its historical cause remains unknown.

Sprint 6 is closed as an engineering deliverable only. Product discovery acceptance remains unmet and retrieval remains unvalidated.
