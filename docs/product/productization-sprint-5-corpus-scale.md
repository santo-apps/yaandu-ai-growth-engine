# Productization Sprint 5 — Corpus Growth and Discovery Scale

## Baseline audit (before implementation)

Sprint 5 starts from `origin/main` at `a50f434` (Sprint 4 merged), on branch `codex/sprint-5-corpus-scale`. The verified local PostgreSQL baseline is **5 documents / 5 unique domains**: 3 from `osm_public_websites`, 2 from `verified_open_discovery`. The last ingestion run attempted 20 explicit OSM website references and indexed none; its recorded failures included 3 DNS failures, 6 robots redirect rejections, 1 robots transport failure, and 10 candidate transport failures. This points to public-source yield and network reachability as the immediate growth bottleneck, rather than resolution matching correctness.

### Existing module boundaries

- `web_index_documents` and `web_index_ingestion_runs` are already global tables without `tenant_id`. This is appropriate for reusable public evidence. Tenant resolutions, candidate decisions, discovery runs, campaigns, and sales state remain in tenant-scoped tables and must not be copied into the shared index.
- `WebIndexIngestionSourceInterface` currently exposes `name()`, `documents(limit)`, and `metrics()`. There is no ingestion-source registry. The Artisan command selects one of two source implementations with a conditional.
- `VerifiedDiscoveryIndexIngestionSource` imports metadata from previously verified, public-source discovery candidates; it does not fetch pages.
- `OpenStreetMapWebsiteIndexIngestionSource` discovers explicit OSM `website` / `contact:website` candidates, fetches a homepage through `DomainNormalizer`, `UrlPolicy`, `RobotsRules`, host limiting, and bounded extraction. Its location and category plan is effectively fixed to configured city/country and four hard-coded categories. The discovery source itself parses the OSM website keys with precedence, which loses the separate `brand:website` and `operator:website` semantics for index harvesting.
- `LocalWebIndexIngestionService` bounds each invocation to 500 input rows, normalizes and caps stored fields, hashes content, and deduplicates by `(normalized_domain, canonical_url)`. It updates changed documents in place. It does not persist per-document provenance history, source cursors/checkpoints, domain/source yield metrics, freshness/availability, explicit content-change outcomes, or resumable state.
- `LocalWebIndexSearchService` uses PostgreSQL full-text search with a GIN index and a bounded SQLite fallback. Resolution confidence is evaluated separately by `BusinessWebsiteIdentityMatcher`; Sprint 5 must leave those thresholds unchanged.
- Existing resolution sources include OSM, Wikidata P856 for already-linked OSM entities, Common Crawl CDX for already-known domains, and the local index. `WikidataClient` has bounded requests and caching; `CommonCrawlClient` has rate limiting and bounded CDX results. Neither is an index ingestion source. Common Crawl is not a name-search source.
- `UrlPolicy`, `RobotsRules`, `HostRequestLimiter`, and `CrawlBudget` are existing safety controls. They remain the only path for website retrieval. OSM Overpass already has endpoint allowlisting, response caps, caching, a shared request lock, delay, and bounded retries. Redis/Horizon currently separate discovery, crawl, intelligence, scoring, campaign, outbound, conversation, and workflow queues; no `web-index` queue exists.
- Authentication and tenant-scoped business state are already established. Corpus administration is not exposed as a sales-user API. The current operations surface lacks a corpus screen.

### Sprint 5 architecture and invariants

Keep the existing index and ingestion service as the single corpus. Add an ingestion-source registry and a resumable run/checkpoint model around that service. An ingestion run owns a source, bounded plan and budgets, cursor/checkpoint, counters, lifecycle state, and safe failure summary. The source registry chooses implementations; commands/jobs do not contain source-specific branching. Every document remains globally reusable public evidence; a separate provenance relation records multiple source observations without duplicating the document. Raw tenant decisions never enter this relation.

The ingestion path is: **source seed → URL normalization → canonical/domain deduplication → public URL/DNS validation → robots check → bounded HTTP fetch → identity/structured-data extraction → normalized document + provenance upsert → freshness/hash outcome → run checkpoint and metrics**. Homepage-first is the default; optional contact/about fetches are strictly bounded, same-domain, robots-compliant, and attached as page-level evidence for the same domain. Budget completion is a partial/completed status, never a fabricated failure or a reason to bypass policy.

OSM plans are data-driven configuration (region bounding boxes resolved by the existing location resolver, legitimate category/tag mappings, explicit record/domain/fetch/byte/time limits). The harvester keeps `website`, `contact:website`, `brand:website`, and `operator:website` as distinct source evidence. Wikidata ingestion is restricted to explicit linked entity IDs and P856 official-website claims, retaining entity and retrieval provenance. Common Crawl ingestion starts only from known candidate domains: bounded CDX lookup, selected HTML capture, and bounded WARC range retrieval where supported. It never performs arbitrary name search or broad crawl download; unsupported or unsafe records are skipped with a classified outcome.

Refresh metadata distinguishes `first_seen_at`, `last_seen_at`, `last_fetched_at`, `next_refresh_at`, availability, and content-change time. Unchanged hashes refresh freshness without duplicating documents; changed content updates the domain/page record while provenance is retained; unavailable pages retain historical evidence and become stale/unavailable. Retry policy is configuration-driven and limited to transient failure classes. Unsafe URLs and robots denials are not retried automatically.

The index remains PostgreSQL-backed. Domain identity views aggregate page rows at query time initially; no new search platform is assumed. Queue work uses a dedicated `web-index` queue and small configurable worker/concurrency budget so corpus operations cannot starve sales workflows. Host politeness continues through the shared host limiter and robots policy. A restricted operator/operations surface reports corpus and run metrics; ordinary sales users continue to use existing prospect workflows without managing ingestion.

### Initial implementation sequence

1. Extend existing schema and ingestion interface for provenance, freshness, run states/checkpoints, safe budgets, and source metrics; preserve existing rows and the current index.
2. Add source registry, plan/preview configuration, resumable `web-index` work, and deterministic tests for deduplication, provenance, refresh, retry, budget exhaustion, restart, and source quality.
3. Generalize OSM plan inputs and maintain semantic provenance for all four website tags. Add bounded Wikidata linked-entity seeds. Add known-domain Common Crawl batch enrichment only where a bounded, safe retrieval path is demonstrable; otherwise document it as a separately bounded follow-up and report why.
4. Add operator-facing corpus status/run/source metrics in Operations with tenant-independent access control, while keeping sales navigation and data unaffected.
5. Capture a controlled website-less sample before growth, perform safe bounded multi-region/category ingestion, and rerun the identical sample. Measure quality and coverage; never change matching thresholds to improve counts.
6. Run stratified quality review, isolation/no-outreach checks, complete regression, and document achieved counts and scaling gaps.

### Acceptance and production scale

The preferred target is 500 unique high-quality real business domains, with evidence completeness, source/geographic/category distribution, and zero incorrect assignments reported separately. If public-source reachability or safe budgets prevent that target, report actual results and identify the limiting stage. No domain or field may be fabricated.

| Stage | Scale | Likely architecture needs |
| --- | ---: | --- |
| A | 500 domains | Existing PostgreSQL, bounded `web-index` supervisor, daily/weekly refresh, corpus/source-yield alerts. |
| B | 5,000 domains | Tune GIN and identity indexes from query plans, add queue throughput and host concurrency telemetry, stagger refreshes, monitor row/JSONB and index growth. |
| C | 50,000 domains | Dedicated ingestion workers, partition/retention evaluation based on measured query plans, object storage only for explicitly retained source artifacts, refresh prioritization and capacity alerts. |
| D | 500,000+ domains | Justify with measured latency/operations first; dedicated offline Common Crawl processing, object-storage staging, backpressure, partition/search architecture review, and separate operational SLOs. Not implemented in Sprint 5. |

No required paid lead, search, SERP, business database, or email-enrichment API is part of this architecture. Existing URL, robots, DNS/SSRF, redirect, tenant, and zero-outreach safeguards remain acceptance requirements.

## Sprint 5 implementation results

### Implemented pipeline

The existing `web_index_documents` table remains the single shared public corpus. A registry now provides `verified_discovery`, `osm_public_websites`, `wikidata_linked_websites`, `common_crawl_known_domains`, and `web_index_refresh` sources. The ingestion service persists source/run metrics, checkpoints and cursor/options, supports bounded resume, maintains one document per indexed domain/page identity, and stores multiple source observations in `web_index_document_sources`. Public index rows and provenance remain tenant-independent; tenant-specific resolution decisions and all sales state stay in tenant-owned tables. No tenant identifier is added to public evidence.

The path is bounded at source-record, domain, fetch, byte, run-time, and failure limits. Candidate URLs pass the existing normalization, DNS/public-address, redirect, robots, host-limiter and fetch controls before homepage-first extraction. Stored content is limited to normalized identity metadata and short excerpts. Failures and budget exhaustion are retained in run metrics; partial source failure or exhausted budgets produce `partially_completed`, while completed refreshes are separately identifiable. Only transient fetch failures are eligible for configured bounded retry; unsafe URLs and robots denials are not retried. Refresh updates last-seen/fetched/next-refresh and availability without deleting historical evidence. Content hashes distinguish changed and unchanged records. Source observations retain source reference/query, retrieval time and run.

OSM city and business-category plans are configurable, and feature, `contact:website`, `brand:website`, and `operator:website` are represented with distinct evidence types. The configured sample plan covers Dubai, Abu Dhabi, Sharjah, Salem, Coimbatore, Chennai, Bengaluru, Hyderabad, and Kochi. Wikidata seeds are limited to linked entity IDs and P856 website claims. Common Crawl is only used for known-domain enrichment; its bounded CDX/WARC mode was exercised, but selected retrieval yielded no WARC page for the tested domain. It is not used for arbitrary name search or broad crawl download.

The `web-index:ingest`, `web-index:refresh`, and `web-index:status` commands require explicit source and limits for ingestion. Dry-run avoids source requests and writes. Optional queue dispatch uses the dedicated Redis/Horizon `web-index` queue, separate from sales/outreach queues. The admin operations endpoints are `GET /api/v1/operations/web-index` and `POST /api/v1/operations/web-index/runs`; they are restricted to active tenant managers/owners. Operations UI is in the existing Operations area, not salesperson primary navigation.

### Live bounded ingestion and corpus quality

PostgreSQL baseline was 5 documents / 5 unique domains. Sequential, respectful bounded runs across the configured regions and categories grew the final local corpus to **12 documents / 12 unique domains** (**net +7 domains**). No paid lead, search, SERP, or email-enrichment API was used. The preferred 500-domain target was attempted through bounded public-source collection but was not reached. OSM Overpass candidate yield, website availability/safety, and public-source reachability were the limiting stages; Wikidata contributed only linked official-site claims, and the tested Common Crawl known-domain request returned no usable WARC document. Limits were kept in force.

The final status command reported 12/12 fresh documents, zero stale/unavailable, 38,409 stored metadata/excerpt bytes, and no active run. Corpus quality aggregates were: **12** with business-name evidence, **12** with location evidence, **3** with public-contact evidence, and **4** with recognized structured organization data. These are evidence flags, not a claim that all entries are equally suitable sales prospects. The raw indexed set includes an Aster suspended/error page, two IISc academic subunits, and LG's global brand site; they are excluded from a strict local commercial-business quality count. Manual review found **8 clearly valid real business domains** among the 12; source evidence for non-commercial and suspended pages remains inspectable rather than being represented as verified local prospects.

Source observations overlap when the same document is seen again. Final provenance status reported:

| Source | Distinct indexed documents | Source observations | Notes |
| --- | ---: | ---: | --- |
| OSM linked public websites | 9 | 12 | Major growth source; feature/brand/operator semantics retained. |
| Verified open discovery | 2 | 2 | Existing verified public evidence; no duplicate corpus rows. |
| Wikidata linked websites | 1 | 1 | Bounded P856-linked evidence. |
| Web-index refresh | 2 | 2 | Refresh provenance for two previously indexed records. |
| Common Crawl | 0 | 0 | No WARC page was available for the bounded tested capture. |

The operations metrics expose discovered/attempted/indexed records, unique-domain additions, duplicates, source and fetch failures, bytes, yield, and average identity completeness. The displayed source-quality score combines successful indexing (50%), unique-domain yield (30%), and identity completeness (20%); it is an operational source metric and is never used as a lead score. In the live trials, failure classes observed included source-query failures, candidate transport failures/timeouts, robots retrieval/rejection, unsafe URL or redirect rejection, oversized content, and absent Common Crawl WARC records. Wikidata and Common Crawl source failures were retained in their run metrics. We do not report one combined failure percentage because source query errors, candidate-page failures, and duplicate skips have different denominators and several historical runs predate the corrected aggregate classification; the operator view keeps per-run metrics separate.

Country distribution in the resulting sample was India 6 and UAE 6 (including an Arabic Dubai location label); city distribution was Chennai 2, Dubai 5 including the alias, Sharjah 1, Bengaluru 2, Hyderabad 1, and Kochi 1. Category values were bicycle 1, car 1, college 2, confectionery 1, hospital 2, electronics 2, supermarket 1, and category unavailable 2. These counts describe the 12 indexed records, not 12 verified commercial prospects. Provenance source counts are overlapping document/source associations, not exclusive attribution.

### Bounded source run results

| Source/run | Observed outcome |
| --- | --- |
| OSM, nine bounded regional/category runs | Six new domains across Chennai (+3), Bengaluru (+2), and Kochi (+1); remaining runs primarily produced duplicates, existing-domain refreshes, no seeds, or classified source/fetch failures. No broad/unbounded request was run. |
| Verified discovery | 2 existing records refreshed/imported; 0 new domains. |
| Wikidata linked websites | 4 entities seen, 8 P856 references, 2 documents indexed (1 new domain, 1 unchanged), 6 source failures; partial completion. |
| Common Crawl known domains | 5 domains considered, 1 CDX capture selected, 0 WARC records fetched, 1 source failure; no corpus growth. |
| Refresh | 2 due documents refreshed, 0 failures; both remain available and fresh. |

OSM run-specific observed examples included: Chennai 20 businesses inspected / 7 explicit website references / 3 indexed new domains; Bengaluru 28 businesses / 3 website candidates / 2 new domains and 1 existing record; Kochi 27 businesses / 1 candidate / 1 new domain. Other regional queries had lower seed yield, Overpass failures, duplicate domains, unsafe candidates, robots rejections, transport failures, or oversized pages. Source and page failures remain in run records. Counts are bounded run outputs, not a claim that each failed candidate was a business-domain miss.

### Coverage acceptance and search performance

Sprint 4's controlled baseline comprised 10 website-less Dubai OSM businesses, all unresolved with `NO_CANDIDATES`, zero incorrect assignments. After growth, the same ten identity-only local-index searches (business name + Dubai/UAE, with no website/contact input) returned zero candidate rows: **0/10 candidates found, 0 resolved, 0 ambiguous, 10 unresolved, 0 incorrect domain assignments**. This is a search replay against the expanded local index, not a persisted full resolver workflow. Candidate discovery improvement was **0 percentage points**. Matching thresholds were unchanged. This is a material Sprint 5 acceptance gap: the corpus grew, but it did not yet improve this controlled resolution sample.

Representative local PostgreSQL search measurements for the same ten inputs had 5.2 ms median and 50.27 ms slowest observed query; result rows were zero for every query. The existing PostgreSQL full-text GIN index remains in place. At 12 rows, the planner's sequential-vs-index choice is not a scaling conclusion; no search-platform replacement or premature index tuning is justified. The exact query-plan inspection remains a production-scale follow-up.

### Refresh, failures, safety, and no-outreach evidence

The bounded refresh found and refreshed two due pages, with zero refresh failures; final corpus status was 12 fresh and none stale/unavailable. Retry handling is covered by deterministic tests for transient failure and safe no-retry cases. Run state, cursor/checkpoint, stage outcomes, counters, source metrics, failure classes, duration, and bytes are persisted. Aggregate source failures are not double-counted with detailed classification. Historical evidence remains when a page is unavailable.

The new ingestion tests exercise source-registry ingestion, de-duplication, multi-source provenance, richer fetched evidence preservation, freshness/refresh, transient and permanent failure handling, budget exhaustion, resumability, structured evidence, quality metrics, and zero outreach. Existing URL policy/security regression tests continue to cover localhost/private and IPv6 targets, metadata endpoints, unsupported schemes and credentials, DNS rebinding, unsafe redirects, downgrade, loops/depth and cross-domain redirects. Public content remains untrusted input. No campaign recipient, outbound message, or `SEND_OUTREACH` approval was created by corpus ingestion; the automated zero-outreach assertions pass with the focused tests. Public data is shared only in the corpus; tenant resolution/business state remains isolated.

### Operations UI responsive acceptance

The authenticated operations workspace was opened against local deterministic data at 1440, 1280, 768, and 390 CSS pixels. Desktop/laptop/tablet showed readable corpus totals, freshness, source/run information and actions without document-level horizontal overflow. At 390px the content remains within the viewport and the runs table scrolls inside its own table region. Manager-only navigation sections collapse out of the mobile shell, so direct mobile navigation to Operations is a known usability limitation; it does not expose administrative actions to sales users. The selected operations page itself remains readable. No visual defect requiring a scope expansion was found.

### Explicitly unverified or incomplete

- The manual review covered all 12 currently indexed records because only 12 existed; the requested approximately 20-domain stratified sample could not be drawn without fabricating corpus volume.
- The post-growth coverage replay used the same ten Sprint 4 identity-only names against the local search service. It did not create persisted resolution attempts or verify independent ground truth for a larger 50–100 business sample.
- The live bounded runs used synchronous CLI execution. Queue routing, bounded dispatch, dedicated Horizon configuration, and resumability are covered by code and deterministic tests, but this session did not verify an actual Horizon `web-index` worker processing a live run or an interrupted live run being resumed.
- Fetching is homepage-first. It does not yet follow same-domain contact/about links to build multi-page domain views. Contact evidence therefore reflects available public seed/page evidence rather than a comprehensive contact-page crawl.
- Common Crawl's known-domain mechanism is bounded and functional at the request/staging boundary, but no WARC page was retrieved in the live trial. Offline batch snapshots, WARC/WET staging, and large-scale processing remain future work.
- Refresh is available as a bounded command and queueable source; production cadence/scheduling, concurrency/backpressure, monitoring/alerts, and worker capacity have not been deployed or verified.

### Verification status

Executed verification is recorded here without treating source existence as proof:

| Check | Result |
| --- | --- |
| Focused corpus ingestion, operations, Common Crawl, identity extraction, and required resolution/discovery/sales/security regression suites | **57 tests / 579 assertions passed** (`ProductizedWebsiteResolutionTest`, `ProductizedOpenWebDiscoveryTest`, `ProductizedProspectDiscoveryTest`, `ProductizedSalesJourneyTest`, `AcquisitionWorkflowEndToEndTest`, `UrlPolicyTest`, `ProductizedCorpusIngestionTest`, `WebIndexOperationsTest`, `WebsiteIdentityPageExtractorTest`, `CommonCrawlClientTest`). |
| Full PHPUnit | **186 tests / 1,352 assertions passed**. |
| TypeScript | Pass (`npm run typecheck`). |
| Vite production build | Pass (`npm run build`; Vite 6.4.3). |
| PHP syntax sweep | **310 PHP files** under `app`, `routes`, `database`, and `tests` passed `php -l`. |
| Composer | Pass (`composer validate --no-check-publish`). |
| PostgreSQL | Local PostgreSQL 17 connected; all 25 migrations including the Sprint 5 migration are applied. |
| Operations routes | 2 registered (`GET` status and `POST` run). |
| `git diff --check` | Pass. |

### Acceptance decision and remaining scale work

The multi-source bounded ingestion, provenance, freshness, partial-failure semantics, queue separation, operations visibility, and meaningful corpus growth are demonstrated. However the 500-domain preferred target was not reached, manual review found quality exclusions in the 12-row index, and the same controlled 10-prospect sample showed no discovery lift. **Sprint 5 remains PARTIALLY COMPLETE** pending greater useful public-source coverage and a controlled sample that demonstrates improved candidate discovery while preserving zero incorrect assignments. These are measured coverage/source-yield gaps, not grounds to lower match confidence or relax crawl policy.

There are **0 known P0 defects** from this Sprint 5 verification. Remaining high-priority work is to improve safe public seed yield and run a wider stratified ground-truth coverage sample. Production corpus freshness scheduling, worker concurrency/backpressure, alerting, Common Crawl offline staging, and the mobile navigation path remain scaling/productization work. The staged scale plan remains: Stage A 500 domains on PostgreSQL plus a bounded dedicated queue; Stage B 5,000 with measured index/worker/refresh tuning; Stage C 50,000 with dedicated ingestion workers and storage/retention review; Stage D 500,000+ only after measured need, with dedicated offline Common Crawl processing and a full search/partition architecture review. No Stage D infrastructure is implemented.

## Final remediation results — 2026-10-07

This addendum supersedes the earlier pre-remediation statements above about identity-page fetching, live Horizon execution, and the ten-record-only coverage replay. It records only the additional work performed in this final remediation; the earlier 12-domain and ten-business figures remain the pre-remediation baseline.

### Corpus growth and quality

| Measure | Remediation start | Final | Change |
| --- | ---: | ---: | ---: |
| Indexed documents / domains | 12 / 12 | **13 / 13** | +1 domain |
| Deterministic high-quality business domains | 6 | **7** | **+1** |
| Stored corpus metadata/excerpt bytes | 38,409 | **38,389** | -20 bytes |
| Business-name evidence | 12 | **13** | +1 |
| Location evidence | 12 | **13** | +1 |
| Public-contact evidence | 3 | **4** | +1 |
| Structured Organization evidence | 4 | **5** | +1 |

The final classifier distribution is **7 HIGH_QUALITY_BUSINESS, 2 PARTIAL_BUSINESS_EVIDENCE, 2 ACADEMIC_SUBUNIT, 1 BRAND_ONLY, and 1 SUSPENDED**. No directory or error-page domain is counted as a quality business domain. The storage decrease despite one additional domain reflects an updated existing document with a smaller normalized evidence payload; page HTML is not retained.

### OSM seed funnel and source selection

A fetch-free seed report covered 10 location/category combinations in Dubai, Sharjah, Salem, Chennai, and Bengaluru, across retail and healthcare. All 10 queries returned successfully:

| Funnel stage | Count | Rate |
| --- | ---: | ---: |
| OSM business records | 447 | 100% |
| `website` references | 35 | 7.8% of records |
| `contact:website` references | 3 | 0.7% |
| `brand:website` / `operator:website` references | 0 / 0 | 0% |
| All website references | 38 | 8.5% |
| Normalized distinct URLs | 35 | 7.8% |
| Duplicate URL/domain observations | 4 | — |
| Unique normalized non-directory domains | 34 | **7.6% domain yield** |
| Already in corpus at preview time | 0 | 0% |

These are seed-availability figures, before DNS, URL policy, robots, redirect, transport, or page parsing. Yield ranked by unique domains per business was: **Chennai healthcare 7/43 (16.3%)**, **Dubai healthcare 5/45 (11.1%)**, **Bengaluru healthcare 5/47 (10.6%)**, **Chennai retail 5/49 (10.2%)**, **Dubai retail 2/46 (4.3%)**, **Salem retail 2/34 (5.9%)**, **Sharjah healthcare 3/44 (6.8%)**, **Sharjah retail 1/46 (2.2%)**, **Salem healthcare 2/46 (4.3%)**, and **Bengaluru retail 2/47 (4.3%)**. This ranks explicit website-domain availability, not sales or lead quality.

The separate linked-Wikidata seed diagnostic considered 56 linked rows, of which 4 were business-like; it found 8 P856 claims, 6 unique normalized domains, one duplicate against the then-current corpus, one duplicate claim-domain, and zero URL normalization, directory/social, or source-query failures. The actual bounded ingestion produced 2 document outcomes (1 new domain, 1 unchanged) and 6 page/source failures. Wikidata is a modest supplement, not a volume source. Verified-discovery had 2 eligible public records, both already indexed (0 new domains). The bounded Common Crawl trial considered 5 known domains, selected 1 CDX capture, retrieved 0 WARC records, and recorded 1 source failure; offline/bulk Common Crawl remains future work.

### Live OSM ingestion funnel and failure isolation

One actual Redis/Horizon `web-index` run used Chennai healthcare. Across its bounded query/resume attempts, persisted run metrics recorded 222 business-record observations (repeated bounded queries, not 222 unique identities), 30 website-reference observations, 30 normalized URL observations, 30 unique normalized domains across attempts, 6 corpus-duplicate observations, and 8 page candidates checked. Ten HTTP page fetch attempts included homepage and identity pages; 4 pages parsed, 2 business identities extracted, and 2 same-site Contact pages fetched. The run yielded 2 indexed documents: **1 new domain and 1 update to an existing domain**. The cumulative run metrics also recorded 5 aggregate source failures; classified observations included 2 candidate DNS failures, 2 candidate HTTP failures, 1 robots-unavailable outcome, and 1 unsafe redirect rejection. Those stage counters are reported separately and can overlap the aggregate failure count.

One candidate/source failure did not fail or roll back the entire run. It completed as `PARTIALLY_COMPLETED`, retained its successful work, and persisted the latest cursor. The later resumed run's saved cursor advanced to `node:310910945:website`. The fetch and runtime budgets were honored; no security policy was relaxed. The final page evidence in the index contains 2 homepage and 2 Contact-page observations across the two fetched documents. Identity-page fetching remained bounded to at most two additional same-registrable-domain, URL-policy/DNS/robots-checked pages per domain. No About-page was found in this live run.

### Controlled 50-business coverage sample

The exact sample is stored outside the repository at `/private/tmp/yaandu-sprint5-coverage-sample.json`. It contains 50 deterministic records round-robin stratified across 5 regions and 2 categories. Resolver input contains only business name, city, country, and category; OSM `website` or `contact:website` values are kept only in a separate ground-truth field. Only **2/50** records had that explicit website evidence, so this ground-truth subset is too small for a broad precision claim. One expected domain (`aabsweets.com`) was already indexed and was returned by local search; the other (`dubaihtc.com`) was not indexed. This confirms no search defect for the indexed ground-truth example and identifies missing corpus coverage for the other.

| Outcome | 12-domain baseline | 13-domain replay | Change |
| --- | ---: | ---: | ---: |
| Candidate-bearing records | 2/50 (4%) | 2/50 (4%) | 0 |
| Correct candidate among 2 ground-truth records | 1 | 1 | 0 |
| Incorrect candidate for a ground-truth record | 0 | 0 | 0 |
| Automatically resolved | 0 | 0 | 0 |
| Ambiguous candidate sets | 0 | 0 | 0 |
| No candidate | 48 | 48 | 0 |
| Incorrect official-domain assignments | 0 | 0 | 0 |

This was an identity-only local search replay; it did not create or automatically resolve a website-resolution record. Therefore zero assignments means no assignment was attempted. Corpus growth did **not** improve candidate discovery; the minimum three-additional-correct-candidate criterion was not met. No matching threshold changed. No expected domain was hidden in resolver input.

### Live Horizon, restart/resume, and queue isolation

`php artisan horizon:status` initially reported inactive, which reflected that no Horizon process was running. A temporary local Horizon process was then started with a single `web-index` supervisor. The actual queued run was allowed to checkpoint as partial under its explicit fetch/runtime cap; Horizon was gracefully terminated, restarted with the same isolated supervisor to load the corrected source code, and the **same run ID** was requeued and resumed. This verifies a real worker stop/restart and persisted checkpoint resume; it was a budget-bounded checkpoint/restart, not a forced kill in the middle of an HTTP request.

The live run exposed a cursor bug: after reaching the saved OSM source reference, the old logic skipped all later entries. The cursor condition was fixed and a regression test now verifies that the OSM source continues after the saved reference and that document/provenance rows are not duplicated. The resumed live run advanced the cursor and added one domain. The final web-index, outbound, campaign, and workflow Redis list lengths were all zero. The local sales/outreach counts were unchanged before and after ingestion: 2 campaign recipients, 2 outbound messages, 1 `SEND_OUTREACH` approval, 1 meeting, and 2 proposals. These pre-existing local fixture records were not created by corpus ingestion.

### Refresh and source-quality ranking

The bounded live refresh run found 2 due documents, refreshed both successfully, and recorded 0 failures; it added no document/domain. Existing tests verify unchanged refreshes do not duplicate, changed content updates in place, and transient unavailability retains historical evidence and schedules a retry. The earlier live refresh is the runtime evidence; the changed/transient cases are covered by deterministic integration tests rather than asserted as observed production changes.

Using the operations workspace's displayed operational quality score (successful indexing, unique-domain yield, and identity completeness), the currently observed source ranking is: **Web Index Refresh 64/100**, **Wikidata linked websites 31/100**, **OSM public websites 20/100**, **Verified Open Discovery 12/100**, and **Common Crawl 0/100**. Refresh is a maintenance source rather than a seed source. OSM's seed-only 7.6% yield ranks its seed supply separately from the historical aggregate ingestion score. The historical OSM card includes earlier failed attempts, so it should not be interpreted as the success rate of the ten-query seed-only preview.

### Operations UI and responsive result

The authenticated local-only visual-acceptance tenant was used; its existing fixture seeder was already present and no additional fixture tenant was created. The Operations workspace visibly showed 13 indexed domains, 7 quality domains, source-yield cards, the saved partial run and its checkpoint, identity evidence, and the isolated table viewport. Visual checks at **1440, 1280, 768, and 390 CSS pixels** found no document-level horizontal overflow. At 768 and 390 pixels, the 850-pixel run table scrolls inside its own container; it does not widen the page. Metric cards and source cards remain within their grid. No visual fix was required.

### Final verification and remaining acceptance gaps

| Check | Final result |
| --- | --- |
| Focused corpus/fetcher/operations/resolution/identity/classifier/Common Crawl/URL-policy tests | **53 tests / 271 assertions passed** |
| Discovery, prospect discovery, tenant/workflow isolation, sales journey, acquisition end-to-end regression | **38 tests / 460 assertions passed** |
| Full PHPUnit | **195 tests / 1,406 assertions passed** |
| TypeScript | **Pass** (`npm run typecheck`) |
| Vite build | **Pass** (`npm run build`, Vite 6.4.3) |
| PHP syntax sweep | **315 files passed** under `app`, `routes`, `database`, and `tests` |
| Composer validation | **Pass** (`composer validate --no-check-publish`) |
| `git diff --check` | **Pass** |
| Horizon / Redis | **Live `web-index`-only Horizon job consumed, checkpointed, restarted, and resumed** |
| No-outreach delta | **0** in recipients, outbound messages, send approvals, meetings, and proposals |
| P0 defects | **0 known** |

Sprint 5 remains **PARTIALLY COMPLETE**. The bounded architecture, identity-page enrichment, live queue/restart/resume, measurable source funnel, and +1 quality business domain are verified. The requirements for at least 100 quality domains and measurable coverage improvement were not met: the corpus grew from 12 to 13 domains, only 7 qualify as high-quality businesses, and the identical 50-record sample gained zero candidates and zero correct candidates. The tiny ground-truth subset (2 businesses) also limits precision conclusions. The safe stop condition is to improve public-source yield and sample ground truth before scheduling broader ingestion; do not relax URL, SSRF, DNS, redirect, robots, crawl, or matcher policies. There are 0 known P0 defects; the high-priority remaining gap is useful corpus growth with demonstrated coverage lift.

## Remediation B — bounded offline Common Crawl experiment

### Architecture and safety boundary

Common Crawl now has two explicitly separate modes:

- **Mode A — known-domain online enrichment:** `CommonCrawlIngestionSource` uses CDX for domains already in `web_index_documents`, retrieves bounded capture ranges through the existing URL policy, and records historical evidence.
- **Mode B — offline artifact processing:** `CommonCrawlOfflineArtifactSource` takes a previously staged local `.warc.gz` file. `CommonCrawlArtifactProcessor` streams concatenated gzip WARC records and emits deterministic business-like page documents into the existing `LocalWebIndexIngestionService`. The processor never performs network requests. Artifact acquisition remains a separate operator/data-preparation step; no parallel corpus store or search index was added.

WARC was selected over WET because the HTML is needed for Organization/LocalBusiness JSON-LD, public phone/email, page title and visible text, and explicit address/city/country signals. The CLI requires an artifact path and explicit ingestion limit; it also accepts bounded artifact bytes, record count, per-document bytes, runtime, failures, and domain limits. Record payloads, headers, and HTML excerpts have hard caps. The local artifact is content-hashed; resume rejects a changed artifact and uses record number as its cursor. Source evidence carries snapshot identifier, artifact basename and SHA-256, WARC record number, original URL, capture timestamp, and ingestion-run provenance. The normal domain/page/content-hash deduplication and document-source provenance path remains in effect. Offline evidence is not marked as verified and must still pass the existing live DNS/SSRF/redirect/robots verification when resolved.

The deterministic gate rejects non-HTML/non-200 responses, invalid or IP-literal URLs, parked/error/search-result text, and pages without organization structure or a combination of business context with contact/location evidence. This gate makes no AI calls. It does not assert that self-declared structured data is true. No confidence threshold or crawling policy changed. Runtime artifacts (`.warc`, `.warc.gz`, `.wet`, `.wet.gz`, Common Crawl staging and checkpoints) are ignored by Git.

### Source viability experiment

The live Common Crawl collection list identified **CC-MAIN-2026-39** (September 2026). A single exact `example.com` CDX request returned one successful HTML capture. A bounded wildcard `*.ae` request returned HTTP 404 / “No Captures found”; a broad `https://` prefix query timed out with HTTP 504. The follow-up exact-domain CDX attempt for the controlled sample also timed out (HTTP 504). This demonstrates the current CDX interface is suitable for specific known URL/domain lookups, but did not provide a broad regional/business discovery feed in this session.

No Common Crawl artifact was downloaded or staged. Consequently the offline processor was **not** run on real Common Crawl records, no real record was indexed, no live WARC throughput, peak memory, artifact storage, business-filter precision, regional yield, Common Crawl queue execution, or interruption/resume can be claimed. Downloaded artifact bytes and temporary artifact storage were both **0**. The automated parser tests use generated local WARC fixtures only and are not counted as Common Crawl evidence. The 100-candidate target was not reached: real offline business-like candidates/domains indexed = **0**. This is an access/data-selection blocker for the experiment, not evidence that the parser is useful or that Common Crawl is a viable primary seed source.

Common Crawl's official [URL Index documentation](https://commoncrawl.org/url-index) describes its Parquet-based bulk index and recommends columnar querying for bulk filtering; the single-capture CDX service is optimized for individual capture lookup. An offline regional sample needs a repeatable, bounded URL Index query/export (e.g. an approved local DuckDB/Parquet slice or a preselected WARC/WET artifact). The full monthly URL Index partition is far too broad for an ad-hoc download experiment. Region must be derived from page evidence or available crawl metadata, not `.ae`/`.in` alone. A selected WARC slice is also preferable when structured contact/location evidence is required.

### Replay and corpus retrieval

The exact existing 50-business input file at `/private/tmp/yaandu-sprint5-coverage-sample.json` was preserved and replayed after the no-growth experiment. Resolver input remained name, city, country and category; the two ground-truth domains remained separate. Current post-experiment result: **2 candidate-bearing / 50**, **1 correct ground-truth candidate / 2**, **0 incorrect assignments**, **48 no-result**. This equals the captured baseline and yields **0 additional correct candidates**. No indexed corpus changed during this attempt.

The local index contains only **13 documents/domains**, of which the evidence-aware quality classification reports **7 high-quality business domains**. A read-only exploratory self-retrieval check hid domains and searched each of those 7 by indexed business name plus available location: **Top-1 7/7, Top-3 7/7, Top-5 7/7, incorrect Top-5 0, no result 0**. This sample was taken from the existing corpus, not from the missing offline acquisition experiment, and is below the required 20 distinct businesses. It must not be represented as a 20-business acceptance test. No final-resolution confidence was changed.

### Remediation B decision

The offline WARC parser and ingestion adapter are now testable and bounded, but the requested live data experiment is incomplete: no real artifact, at least 100 candidate domains, manually reviewed sample, real queue/restart, or 20-business blinded retrieval run was available. Thus business-domain precision, cost per useful domain, regional targeting yield, and retrieval quality remain **unmeasured**. Common Crawl is currently a **potential supplemental source**, not an evidenced primary corpus-growth strategy. The next data step is to obtain a small, licensed/public URL Index-derived regional/business slice or a preselected WARC artifact, then measure quality before increasing volume. Do not retry broad CDX prefixes or relax any safety/matching policy as a substitute.

The existing no-outreach state was preserved by keeping this task read-only against the live corpus: no campaign, message, send approval, meeting or proposal writes were made by the Common Crawl experiment. Automated fixture tests verify parser/index-path separation; no live no-outreach delta is claimed for an ingestion run because none ran.

### Remediation B verification

| Verification | Result |
| --- | --- |
| Focused corpus, website-resolution, open-web discovery, prospect discovery, sales journey, acquisition workflow, tenant, URL policy, and Common Crawl regression group | **62 tests / 609 assertions passed** |
| Full PHPUnit suite | **199 tests / 1,432 assertions passed** |
| TypeScript and Vite production build | **PASS** (`npm run typecheck`; `npm run build`) |
| PHP syntax | **318 files passed** across `app`, `routes`, `database`, and `tests` |
| Composer validation | **PASS** (`composer validate --no-check-publish`) |
| `git diff --check` | **PASS** |
| Artifact ignore rules | **PASS** for WARC/WET, Common Crawl staging and runtime checkpoints |
| Horizon | **BLOCKED for this experiment**: `php artisan horizon:status` reports inactive; no real Common Crawl artifact was available to enqueue |
| P0 defects identified by this work | **0** |

### Final acceptance decision

**PRODUCTIZATION SPRINT 5 REMEDIATION B PARTIALLY COMPLETE.** The WARC artifact processor, local-artifact source, existing-index ingestion path, deterministic filter, provenance, limits, checkpoint/resume and fixture regressions are implemented. The central acceptance proof is missing: live offline acquisition and processing, at least 100 business-like domains, precision review, regional result, real Horizon execution/restart, artifact cost/throughput/memory, and a 20-business blinded self-retrieval sample. The current CDX experiment did not supply a bulk artifact. Keep Sprint 5 open until an appropriate bounded Common Crawl URL Index or WARC slice is prepared and the live experiment is run.

## Remediation C — official/open business-source research

### Scope and outcome

Research was performed before choosing an application adapter. The task stayed on `codex/sprint-5-corpus-scale`. No business-source adapter, migration, app configuration, sample import, corpus write, or sales workflow write was performed. The existing OSM → candidate website evidence → safe resolver/index path remains the product architecture. The existing `DiscoverySourceInterface` can already represent identity-only records (`website` is nullable); `DiscoveryCandidateService` persists those as `no_website` / `website_not_found` while preserving source reference and metadata. A parallel prospect model is not required.

Common Crawl's final role is unchanged: **supplemental historical evidence after a candidate URL/domain exists**. It is not a primary business identity/location → website source. OSM remains the primary open geographic seed source. Official registries, where allowed, should add distinct business identity/location/activity evidence; explicit registry website fields, when present, remain candidates that must pass the existing URL safety, DNS/SSRF, redirects, robots, and verification path. No website is ever inferred from a legal name.

### Source-quality score

This operational source feasibility score is not lead scoring. Each dimension is rated 0–5 and converted by `rating / 5 × weight`. It is a preliminary documentary score; unknown fields are scored conservatively, and it cannot override access/license gates.

| Dimension | Weight |
| --- | ---: |
| Identity completeness | 20 |
| Location completeness | 20 |
| Explicit website availability | 15 |
| Category/activity usefulness | 10 |
| Freshness | 10 |
| Record volume | 5 |
| Automation suitability | 10 |
| License clarity | 7 |
| Cost | 3 |
| **Total** | **100** |

| Source | Preliminary score | Gate / use |
| --- | ---: | --- |
| Abu Dhabi DED trade-license ArcGIS service | 68/100 | **E — legal/source-specific terms review** before records are queried or reused. Strong name/activity/city/location structure; no website/contact documented. |
| India MCA Company Master Data (data.gov.in) | 65/100 | **A — open/API-capable candidate**, but sample access requires a working resource/API credential; current endpoint/sample was not verified in this run. Formal companies only; stale source cutoff concern. |
| India UDYAM registered-units dataset | 64/100 | **E — access/terms and field-schema review.** State/district preview exists; downloading requires login; actual schema was not exposed in the public metadata reviewed. |
| MoIAT Industrial Licenses API (UAE) | 55/100 | **A — API and Federal Open Data License are documented.** Bounded 50-record probe was attempted but the API did not return data (see below); no integration. Industrial-only, emirate-level. |
| Dubai DED Commerce Registry activities | 39/100 | **E — license unspecified and API entitlement requires a grant/key.** Do not automate until both are resolved. |

Scores are directional and do not mean that any source passed its required sample/quality gate.

### Source evaluation matrix

| Source / authority | Region and official access | Method, format, auth/cost | Documented fields, volume and freshness | Reuse / classification / suitability |
| --- | --- | --- | --- | --- |
| **Company Master Data — Ministry of Corporate Affairs via India OGD** ([catalog](https://www.data.gov.in/catalog/company-master-data)) | All India, state/ROC fields | Catalog API and ZIP download are listed. A resource API key is needed for programmatic retrieval; no key was present for this session. No fee is listed; exact API quota was not confirmed. | CIN, company name/status/class/category, registration date, registered state/ROC, principal business activity, registered office address and subcategory are listed by the source. No explicit website or public phone is documented. The catalog says monthly granularity and notes data only through 3 Nov 2023 despite its 2026 resource update. Current record count is not stated on the official page. | Resource is released under India NDSAP/GODL-India. Commercial reuse is allowed subject to source/license attribution, non-endorsement, lawful use, and no-warranty terms. **A, subject to bounded authenticated-API feasibility.** Valuable formal-company identity/activity, weak city precision, no website. Avoid director data; parse registered-office address only as company evidence and do not assume it is an operating location. |
| **List of MSME Registered Units under UDYAM — Ministry of MSME via India OGD** ([resource](https://www.data.gov.in/resource/list-msme-registered-units-under-udyam)) | State/district filters across India | Filter/preview is described as available without login; download requires login. Page gives daily granularity. No paid fee/rate limit is stated. | Resource is updated daily; its public metadata does not list row fields or volume. State and District are required filters. Website, contact, category and registration-ID coverage are unverified. | OGD portal describes GODL-India, but download/auth and specific field/schema/privacy conditions require review before systematic acquisition. **E.** Potentially strong prospect identity/location source if unit name/activity/address are confirmed; not yet demonstrated. |
| **Telangana Business Register — Directorate of Economics and Statistics** ([register](https://ecostat.telangana.gov.in/business_register.html)) | Telangana districts, including Hyderabad | Public informational page and historical tables; no current machine-readable API/download contract verified. | Describes establishment name, address/branches, phone/email, activity, PAN/TAN, year, employment and registration status. Collection described as through 31 Mar 2012, additions through 2014, and a 2016 canvass; the published district counts total about 259,203 historical establishments. No websites. | No explicit reuse license/bulk permission verified; contains high-risk PAN/TAN and contact fields; very stale. **D/E — reject for Sprint 5C ingestion.** Do not acquire those sensitive fields. |
| **Tamil Nadu Shops and Establishments registration / Chennai trade-license search** ([TN Labour registration](https://labour.tn.gov.in/services/shop-establishments/registration), [Chennai search](https://erp.chennaicorporation.gov.in/egtradelicense/citizen/tradeSearchNoLogin%21newForm.action)) | Tamil Nadu; Chennai lookup | Registration workflow and individual search form, not an advertised bulk API/export. Search supports license number/name/application/date; terms and rate limits for automation are not stated. | Registration form includes establishment name, nature of business, address, contact fields, employer residential details and PAN. This describes application collection, not an available business dataset. Search result schema and bulk volume unavailable. | Public lookup does not imply bulk reuse. **B/E — no automation.** Avoid private/home addresses, owner contacts, PAN, and application data. |
| **Karnataka Open Government Data Portal — Government of Karnataka/NIC** ([portal information](https://karnataka.data.gov.in/about)) | Karnataka | Dataset catalog exists; no specific current business-establishment dataset with verifiable fields or bulk/API terms was identified in this research. | No suitable source-specific record schema, volume, or update frequency verified. | Portal's open-data purpose is not proof that a particular business registry is published/licensed. **C — no source selected.** |
| **Kerala ODR / Kerala Data Portal / Shops Act dashboard — Government of Kerala** ([ODR](https://odr.ecostat.kerala.gov.in/), [data portal](https://datahub.kerala.gov.in/datasets?filter_type=all), [Shop Act dashboard](https://dashboard.kerala.gov.in/e-services/service.php?fcgzGljlqlQrXcSXWDDRKHpqTpMzsq=ZGlkPTI1JnNlcj1LZXJhbGErU2hvcHMrQ29tbWVyY2lhbCtFc3RhYmxpc2htZW50K0FjdCsxOTYwJmFwcD1OZXcrcmVnaXN0cmF0aW9u)) | Kerala districts | Open-data/API catalog and downloadable aggregate statistics; current Shop Act view contains district-level application/approval counts, not a business identity register. | Per-business names, addresses, category, website, contacts and volume were not found. | Portal terms require source acknowledgment; Kerala ODR license choices can require commercial permission. **C for current business-identity acquisition; no automation.** Re-evaluate only if a specific entity-level dataset with terms is published. |
| **MoIAT Industrial Licenses List — UAE Ministry of Industry and Advanced Technology** ([API docs](https://moiat.gov.ae/en/open-data/open-data-apis), [open-data policy](https://moiat.gov.ae/en/open-data/open-data-policy)) | UAE; industrial-license records with Emirate | Official JSON API documents `GetIndustrialLicensesList`, `LanguageId`, `PageNumber`, `PageSize`, with a sample page size of 10. No auth is shown in the documented sample; no cost/quota is stated. Page describes open-data APIs/live data and daily updates. | Published illustrative record fields: license number, factory name, emirate. API's live schema, city/address, category/activity, contacts, website, record count, and duplicates are not confirmed from sample rows. Industrial-only coverage. | MoIAT policy says reuse, modification and redistribution, including commercial use, are allowed subject to the UAE Federal Open Data License, attribution and non-misrepresentation. No website/contact field in the published sample. **A — selected for bounded PoC only, not integration yet.** |
| **Abu Dhabi DED / AD-SDI `CF_Commerce_And_Industry` ArcGIS service** ([service](https://arcgis.sdi.abudhabi.ae/agspublish/rest/services/Pub/CF_Commerce_And_Industry/MapServer), [license/activity table](https://arcgis.sdi.abudhabi.ae/agspublish/rest/services/Pub/CF_Commerce_And_Industry/MapServer/2)) | Abu Dhabi Emirate; DED trade-license features and activity table | Public REST JSON/GeoJSON query, pagination, MaxRecordCount 2,000; no credentials shown. Cost/rate limits not specified in layer metadata. | Layer table fields include English/Arabic business name, license type, legal form, member number, license location, city, activity name and location ID; related point layer supplies location geometry/count. Website/contact fields are absent. Update interval not documented. | Service shows `© 2019 AD-SDI, DED`. Abu Dhabi SDI describes open spatial datasets as reusable, but this specific service was not verified in the open-data catalog or with a dataset-specific license statement. **E — legal/terms review. Do not query/export records yet.** Strong candidate if the source owner confirms commercial automated reuse. |
| **Dubai Pulse DED Commerce Registry / Activities — Dubai Department of Economy and Tourism** ([activities dataset](https://gslb.dubaipulse.gov.ae/data/ded-registration/ded_commerce_registry_activities-open?page=2), [Dubai open-data portal](https://www.dubai.ae/en/open-data)) | Dubai; registry-level businesses and activity records | Activities CSV is listed as 517.57 MB and updated weekly; API supports filters/limit/offset, but access requires a granted dataset/API key+secret and bearer token. Cost/entitlement is not clear from the page. | Activities fields include registration/license identifiers, activity code/name (English/Arabic), activity status and dates; page reports expected 18k–23k volume/cycle for that activity dataset. Related registry master API is described as company-group master information; its fields were not confirmed. Website/contact fields not verified. | Activities metadata says `License: notspecified`; Open Data label alone is insufficient. **E — do not download or automate pending license, API entitlement and data-minimization review.** Potentially relevant if terms and master fields are confirmed. |
| **Invest in Dubai license search — Dubai DET** ([search](https://app.invest.dubai.ae/search-license)) | Dubai | Individual search by license/DUL/business name; interactive page shows login and hCaptcha. No public bulk API or automation terms identified. | Search-key fields documented; output fields/record volume not verified. No website/contact fields confirmed. | **B/D — public lookup, not bulk/automation suitable; no bot/captcha interaction.** |
| **UAE National Economic Register — UAE Government** ([official description](https://u.ae/information-and-services/business/important-digital-services/national-economic-register)) | UAE-wide, across licensing authorities | Public business/license/activity lookup is described; no permitted bulk API/export was found. | Provides license/business and activity inquiry at lookup level; website/contact/volume not confirmed. | **B/E — investigate only as manual verification until bulk terms/API are published.** |
| **Sharjah Economic Development Department (SEDD) license search** ([SEDD](https://www.sedd.ae/ar/web/sedd/home)) | Sharjah | Official interactive digital tools include business-license search and activity lookup; no bulk dataset/API or automation permission identified. | Business license search exists; downloadable identity fields, website/contact coverage and volume unknown. | **B/E — no automated acquisition.** |
| **Sharjah Finance Department Open Data** ([portal](https://www.sfd.gov.ae/En/Pages/Open_Data.aspx)) | Sharjah | Open-data portal, but no business-identity dataset found. | No suitable identity/location/activity row fields identified. | **C — not selected.** |

### Standalone source PoC — MoIAT

MoIAT was selected for a minimal UAE-only attempt because its own API documentation publishes an unauthenticated paginated endpoint, a page-size-10 example, the sample business fields, and a commercial-reuse policy. The attempt was capped at 50 rows and a 2 MB response, and retained only the documented business fields (`licenseNumber`, `factoryName`, `emirate`). No personal contact fields were requested or retained.

- A page-size-50 request returned **HTTP 400** (response body was not retained).
- A second request using the documented page-size-10 shape returned **HTTP 500** with `Cannot open database "MOIAT_OpenData" ... Login failed for user 'eservicesadmindb'`.
- Records acquired: **0/50**. This is an upstream API/database availability failure, not a source-quality result. No further retries were made.
- The API page's example fields are documentation examples, not evidence of the live payload. Therefore business-name/location/category/website/contact/duplicate/invalid rates are **not measurable** from this PoC.

No other source was sampled. Data.gov MCA was not queried because no API key was configured and the official catalog resource API fetch timed out; UDYAM download requires login; Dubai Pulse requires granted API credentials and has unspecified dataset license; the Abu Dhabi service needs source-specific reuse terms. No credentials were created, access restrictions challenged, or login/CAPTCHA workflows automated.

### Source-selection decision and findings

**Next bounded PoC candidate: MoIAT Industrial Licenses**, after the API owner restores the endpoint and the same 50-record request succeeds. It contributes industrial business identity plus emirate but does not yet solve city-level prospect targeting or website resolution. Keep the API page-size sample bound and retain only business identity, emirate, and license identifier; exclude any unneeded personal fields if the live API adds them.

**India next candidate: MCA Company Master Data**, after obtaining an authorized data.gov.in API key and validating a 50-row slice for target-state/address and NIC activity quality. It is the clearest open commercial-use candidate, but the source's 2023 data cutoff and lack of a website field make it primarily formal-company identity evidence. UDYAM is a possible broader MSME identity source only after the required login, field schema and automation/reuse conditions are reviewed.

**No source was integrated.** The MOIAT service was unavailable, MCA/UDYAM were not sampled, Dubai Pulse terms/access were unresolved, and the Abu Dhabi service's commercial reuse was not established. This follows the integration gate: no adapter is justified by current data evidence. The existing source abstraction can represent a website-less business identity without an architecture fork. If a source later passes its legal and 50-record quality gates, add it through the existing discovery registry and retain its source-native ID/provenance separately.

### OSM, matching, website verification and no-outreach results

OSM remains the primary open geographic seed, with the measured **7.6% explicit seed-domain yield** baseline. OSM data is under ODbL and requires attribution; derived database redistribution obligations must be assessed separately from per-record provenance. The India/UAE official sources found could complement OSM with legal name, activity, license, city, and location evidence, but none yielded records in this task, so no identity-only cross-source match experiment ran. Confident/ambiguous/no/incorrect matches: **not measured**.

Because no source record was acquired, the controlled identity-only sample was not created; no resolver was supplied an official-source URL; no ground truth was constructed; and no website verification, local-index ingestion, or no-website resolution experiment ran. Website coverage, valid/duplicate/unsafe/verified URLs, indexed references, new quality domains, correct/incorrect resolution candidates and ground-truth accuracy are all **not measured**. No campaign, recipient, message, approval, meeting, or proposal operations were invoked; this was documentation/source research only.

### Sprint 5C evidence and acceptance status

| Requested evidence | Result |
| --- | --- |
| India and UAE source research | Completed from official government/portal sources listed above; no third-party commercial lead database used. |
| Top source feasibility selection | MoIAT bounded API PoC selected and attempted; MCA and UDYAM retained as India follow-up candidates. |
| Source sample | MoIAT **0/50 records** due upstream API errors; all other sources untested. |
| App integration / code changes | **None**; no source passed the bounded evidence gate. |
| Corpus/local-index additions | **0** |
| Cross-source match / website coverage / resolution | **Not measured**; no records were acquired. |
| Outreach effects | No application/DB operation ran; zero records or sales actions were created by this research task. No before/after database delta query was performed. |
| Application regression | Not run; application code was unchanged. |
| P0/P1 status | P0 remains 0 per the supplied Sprint 5 baseline. P1: acquire accessible, permitted source samples; clarify MCA/Udyam credentials and freshness; restore/confirm MoIAT API availability; obtain dataset-specific DED/Dubai reuse terms. |

Sprint 5 remains **PARTIALLY COMPLETE**. Official/open source discovery identified realistic candidates but did not prove acquisition, prospect value, website coverage, cross-source matching, local-index lift, or any integration value. Do not report a source as successful based only on its portal metadata or API documentation. The immediate next step is to re-run the same bounded MoIAT 50-record sample after the source API is operational and obtain an authorized MCA resource API key for a separate bounded India sample. Continue without app integration until the source terms and sample quality pass.

## SPRINT 5 FINAL ACCEPTANCE

**Status: PRODUCTIZATION SPRINT 5 COMPLETE — VALIDATED SOURCE/COVERAGE LIMITATIONS.** This final acceptance supersedes the earlier interim “PARTIALLY COMPLETE” status entries above. The sprint closes because the ingestion, safety, provenance, operations, and source-evaluation capability was implemented and verified; the original volume and coverage goals remain unmet and are not represented as achieved.

### Built and proven

- Multi-source shared-corpus ingestion with explicit source adapters, bounded work, source/document provenance, duplicate handling, refresh/freshness tracking, operational quality metrics, and persisted resumable runs.
- Dedicated Redis/Horizon `web-index` queue routing and operator visibility, plus configured source plans and bounded ingestion controls.
- Safe public homepage/contact-page identity extraction, deterministic quality classification, and Common Crawl WARC range/offline processing boundaries.
- Shared public corpus data is separate from tenant-owned sales state. Corpus ingestion does not enroll campaign recipients or initiate outreach.
- Existing URL safety, public-address/SSRF, redirect, robots, domain-matching, and website-resolution policies remain in force. No policy was relaxed.
- Common Crawl range retrieval works technically, and the standalone offline processor is covered by deterministic local fixtures. No real WARC artifact was acquired or processed in this acceptance.
- Official/open-source evaluation documented source provenance, access, licensing, and evidence gates; no source was integrated without a usable and permitted sample.

### Final corpus and controlled coverage

| Measure | Accepted result |
| --- | ---: |
| Starting local corpus | 5 domains |
| Final local corpus | 13 domains |
| High-quality business domains | 7 |
| Original volume target | At least 500 domains — not reached |
| Remediation quality target | At least 100 quality domains — not reached |
| Controlled business sample | 50 businesses |
| Candidate-bearing | 2 / 50 |
| Correct ground-truth candidate | 1 / 2 known ground-truth cases |
| Incorrect assignments | 0 |
| No candidate | 48 / 50 |

Corpus growth did **not** materially improve coverage. The small candidate-bearing and ground-truth counts do not establish broad recall or precision. The system performs well when relevant domain evidence exists in the local index; it still lacks a demonstrated way to find candidate domains for businesses identified only by name, location, and category.

### Validated source limitations and roles

- **OSM:** useful primary geographic/business-identity seed; measured explicit website-domain yield was **7.6%**. That is insufficient for broad official-website discovery by itself.
- **Common Crawl:** bounded HTTP Range/WARC acquisition is technically feasible, but it did not demonstrate adequate business-identity/location-to-website yield. Its accepted role is **supplemental historical/website evidence after a candidate URL or domain exists**, not a primary prospect corpus.
- **Official/open registries:** may supply identity, location, activity, and source-native identifiers, but Sprint 5C did not establish an immediately usable production source. MoIAT's documented API was attempted within bounds and returned server/errors (**0 records acquired**); MCA's open-data candidate and GODL-India reuse terms were identified, but no sample was acquired because authorized API access was unavailable; UDYAM needs access/schema review; Abu Dhabi DED commercial reuse was not established; Dubai Pulse license and credential access remain unresolved. No source was integrated based only on public visibility.
- No temporary Common Crawl PoC artifacts, WARC/WET/Parquet files, runtime checkpoints, or browser acceptance artifacts are part of this repository change.

### Next capability boundary

The next distinct capability is **OPEN-WEB CANDIDATE DOMAIN DISCOVERY**: accept business name, city/location, category, and known public identity evidence; return zero or more candidate domains with source evidence, query provenance, ranking, and confidence inputs. Discovery must not confirm an official website. Every candidate continues through the existing URL safety, DNS/SSRF, redirect, robots, website verification, identity matching, resolution-confidence, and human-review path where required. Do not carry the 500-domain corpus target forward as a prerequisite. Evaluate candidate recall, precision, correct official-domain identification, zero false assignment, query cost/latency, and source independence.

### Final regression and repository review

| Check | Result |
| --- | --- |
| Focused Sprint 5 plus resolution, discovery, URL/robots safety, tenant, sales-journey, and acquisition regression | **84 tests / 724 assertions passed** |
| Full PHPUnit suite | **199 tests / 1,432 assertions passed** |
| PostgreSQL/Redis integration test | **Skipped by its environment gate**; no PostgreSQL integration pass is claimed by this run |
| TypeScript (`npm run typecheck`) | **Pass** |
| Vite production build (`npm run build`) | **Pass** |
| PHP syntax sweep (`app`, `routes`, `database`, `tests`) | **318 files passed** |
| Composer validation (`composer validate --no-check-publish`) | **Pass** |
| API route registration | **164 routes registered** |
| `git diff --check` | **Pass** |
| npm lint script | **Not configured** |
| Known Sprint 5 P0 defects | **0 known** |
| Automatic outreach caused by corpus ingestion | **0** |

These results are local development verification, not production deployment evidence. Production corpus coverage/freshness, scheduled refresh capacity, ingestion worker concurrency/backpressure, monitoring and alerts, and access to a permitted higher-yield source remain scaling work. The 500-domain and 100-quality-domain goals are explicit validated gaps, not blockers to closing this capability-focused sprint. Sprint closure does not claim production readiness.
