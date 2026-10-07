# Productization Sprint 3: Open-Web and Location-Based Discovery

## Sprint 2 integration seam

Sprint 2 remains the discovery pipeline. `DiscoveryController` creates tenant-scoped `DiscoverySearch` and `DiscoveryRun` records and queues the existing `DiscoveryAgent`. The agent requests a `DiscoverySourceInterface` from `DiscoverySourceRegistry`, aggregates source rows, and passes each row to `DiscoveryCandidateService`. That service is still the only place that normalizes domains and records candidate identity. Only candidates with a normalized website and a new tenant/domain identity enter `VerifyDiscoveryCandidateJob`; that job retains the existing URL policy, SSRF checks, robots handling, bounded HTTP verification, crawl, intelligence, score, and human review flow.

Sprint 3 adds a bounded query planner, configured location resolver, `OpenStreetMapDiscoverySource`, `OverpassClient`, a generic web-search adapter boundary, source aggregation, candidate source-provenance records, deterministic eligibility filtering, and transactional AI-analysis reservations. It does not create a parallel promotion or outreach pipeline. Candidates without a listed website remain in `discovery_candidates` as `website_not_found` with `verification_state=not_required`; they are never queued for URL verification or crawling.

## Query planning and location resolution

`DiscoveryQueryPlanner` converts city, country, category, keywords, and candidate limit into a small structured plan. OSM tag choices come from `config/discovery.php`, not the agent. `LocationResolverInterface` is currently bound to `ConfiguredLocationResolver`, which has coarse, configured bounds for Dubai, Abu Dhabi, Sharjah, Coimbatore, and Salem. Unsupported locations fail before any external request. Radius is clamped, and each configured bounding box is checked against the configured maximum area. The resolver interface can later be backed by a permitted, cached open geocoder without changing `DiscoveryAgent` or the candidate pipeline.

Current business-category mappings include furniture, home decor, retail, interior design, jewellery, electronics, fashion/clothing, textile/fabric, office, hospitality, healthcare, and a general business fallback. Each new mapping uses the corresponding OSM `shop` tag. Query limits, tags per category, run query count, response count, bounding-box area, timeouts, retry count, delay, and cache TTL are configurable.

## OSM source and Overpass limits

`OpenStreetMapDiscoverySource` implements `DiscoverySourceInterface`. It creates bounded node/way/relation tag queries from the structured plan. `OverpassClient` is the only application component that sends Overpass HTTP requests. The endpoint is HTTPS-only and its host must appear in `DISCOVERY_OVERPASS_ALLOWED_HOSTS`; users cannot supply an endpoint. The adapter uses a product User-Agent, serializes misses through a cache lock, caches identical public query results, limits returned elements and response bytes, applies a timeout, and retries a small number of 429/5xx failures. Errors are recorded in operational source health and agent events; a source error does not erase already persisted candidate data.

The public Overpass service is a limited shared resource, not production-scale search infrastructure. Keep the small defaults and avoid broad or repeated searches. A future production implementation should load regularly updated regional OSM PBF extracts into PostgreSQL/PostGIS or an equivalent local index and implement the same `DiscoverySourceInterface` as `LocalOSMDiscoverySource`. No downstream pipeline or Prospect UI redesign should be needed.

## Candidate identity and provenance

`DiscoveryCandidateService` remains the single normalization and tenant-aware identity boundary. Same-run results with the same normalized domain from separate providers, or duplicate OSM objects, converge on one candidate. Each observation is recorded in `discovery_candidate_sources`, scoped by tenant, candidate, and run. Provenance retains source key/reference, source timestamp when provided, discovery timestamp, source query, OSM type/ID, coordinates, public tags/contact tags, and category/location context. Website strings from OSM or a future search source remain untrusted and must pass the existing `DomainNormalizer`, URL policy, DNS/IP protections, and robots checks.

Website-less businesses retain their name, location, category, and public OSM source metadata. They receive no invented domain and no false website failure. OSM phone/email tags remain attributable to the OSM source record; they are not converted into verified website-extracted contacts. Website contact extraction after verification remains the existing evidence-based extractor.

## Web search policy boundary

`SearchEngineInterface`, `SearchQuery`, `SearchResultCollection`, and `WebSearchDiscoverySource` provide an adapter seam. `DeterministicSearchEngine` is a no-network test adapter. No production search engine is registered or required. Before adding one, document and review its access method, automated-query permission, robots/policy rules, rate limits, response format, and blocking behavior. Do not use CAPTCHA or anti-bot circumvention. Search snippets are unverified evidence and their domains must pass the existing verification pipeline.

## Cheap filter and AI budget

`CheapCandidateFilter` considers deterministic website signals after safe verification: reachable site, HTTPS, mobile viewport, page title, recommendation evidence, and response time. It stores eligibility and an explanation before any intelligence job is queued. `DiscoveryAnalysisBudget` locks the tenant-scoped run, checks `max_ai_analyses`, increments the reservation in the run budget, and marks the candidate reservation in the same transaction. Retries for a reserved candidate do not allocate another slot. Candidates that exceed the run cap display `Analysis budget reached` and are not marked as failed. A human-initiated analysis request still passes the same deterministic filter and reservation check.

## Source diagnostics

`GET /api/v1/discovery/source-health` is limited to active tenant owners/admins. It reports enabled/configured state, last success/failure, and rate-limit status for OSM, plus the intentionally pending state of production web search. Detailed per-run source errors are written to `agent_events`; candidate source provenance is tenant-scoped. The source-health cache is operational telemetry, not authoritative audit history.

## Attribution and license

Show OpenStreetMap attribution wherever OSM-derived candidates or details are shown: **© OpenStreetMap contributors**. Link to the [OpenStreetMap copyright page](https://www.openstreetmap.org/copyright) and make the Open Database License (ODbL) terms clear. Preserve OSM source identity and tags through candidate review and promotion. OSM tile servers are not used; if a map is added later, follow the [OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/) and do not prefetch tiles.

The public Nominatim service is not used in this implementation. Its [usage policy](https://operations.osmfoundation.org/policies/nominatim/) restricts public hosted usage and disallows systematic POI collection. Public Overpass availability and rate limits are finite and dynamic; use the [Overpass API guidance](https://wiki.openstreetmap.org/wiki/Overpass_API) and move production workloads to a local regional index.

## Security and reliability

- OSM values, search snippets, and website content are untrusted source data.
- Only server-configured HTTPS Overpass hosts are callable; endpoints and QL are never user-controlled.
- Category input selects a configured tag mapping; bounds, radius, query count, response count, time, retries, and bytes are capped.
- Website candidates use unchanged Sprint 2 URL normalization, SSRF defenses, robots checks, and bounded crawl behavior.
- Tenant scope is retained on candidates, runs, provenance, companies, and source diagnostics authorization.
- No discovery run promotes a company or starts a campaign/outbound message. Human candidate review remains the promotion boundary.
- No paid lead-data or search API is a dependency.

## Live acceptance status

Record the bounded live acceptance below only after executing it with a real public endpoint. Keep the search small (for example, Dubai/furniture, limit 20), inspect at least 10 records where available, and never promote or contact the resulting businesses as part of acceptance.

| Check | Result |
|---|---|
| Query and timestamp | Dubai / furniture, candidate limit 20; 2026-10-07 02:45 UTC |
| OSM elements returned | 19 |
| Businesses with websites | 3 |
| Website-less businesses | 16 |
| Duplicate domains observed | 0 across the 3 website-bearing records; no live candidates were persisted, so no live persistence merge was exercised |
| Website verification successes / failures | 0 / 3 checked; one connection refused, one cross-domain redirect rejected by URL policy, one robots-denied |
| Sample identity, category, and location quality | All 3 website-bearing records had named businesses and the requested furniture category; their city tags were Dubai (including one Arabic spelling). Sample: Misto Vareni Kitchens, Roche Bobois, Al Zahi Decor. Identity/category look plausible from source tags, but the low website count limited independent website confirmation. |
| Sample domain reachability and contact provenance | 3/3 website URLs passed through the existing domain/URL policy and robots check. None reached a verified homepage in this run. Phone/email tags remain source-attributed OSM metadata; no contact was asserted verified. |
| Promotion / outreach performed | No |

## Expanded live acceptance and final closure

The original Dubai/furniture result above is preserved as the first acceptance attempt. A second, controlled sample used ten small, sequential queries through the configured Overpass client on 2026-10-07. Each requested at most 20 businesses; configured cache, request pacing, timeouts, and retry ceilings remained in force. Unsupported categories and locations were not used, and the public search adapter stayed disabled.

### Query set and aggregate sample

| Location | Category | Businesses returned | Website-bearing records |
|---|---:|---:|---:|
| Dubai | Furniture | 19 | 3 |
| Dubai | Electronics | 19 | 1 |
| Dubai | Interior design | 20 | 0 |
| Dubai | Fashion/clothing | 18 | 0 |
| Coimbatore | Furniture | 6 | 0 |
| Coimbatore | Textile/fabric | 0 | 0 |
| Salem | Jewellery | 7 | 0 |
| Dubai | Retail | 18 | 4 |
| Abu Dhabi | Furniture | 0 | 0 |
| Sharjah | Furniture | 19 | 0 |
| **Total** |  | **126** | **8** |

The aggregate contained 118 businesses without a website tag and 8 website-bearing observations across 6 unique normalized domains. The same Spinneys domain appeared in three OSM objects; two repeat observations were merged, giving a 25% duplicate-observation rate within the 8 website-bearing records (2/126, or 1.6%, across all returned records). These results are a bounded OSM sample, not a representative estimate of OSM coverage.

### Website-bearing quality review

The table reports one row per unique normalized domain. Public OSM contact fields are counted as present/absent only; their values are not reproduced and are not treated as website-verified contacts.

| Business | Location | OSM category | OSM object | Website | Normalized domain | Outcome | OSM contact tags? | Analysis eligible? | Notes |
|---|---|---|---|---|---|---|---:|---:|---|
| Misto Vareni Kitchens | Dubai (OSM city label in Arabic), UAE | Furniture | node/4153882189 | `http://mistovareni.com/` | `mistovareni.com` | CONNECTION_FAILED | Yes | No | Connection refused during safe verification. |
| Roche Bobois | Dubai, UAE | Furniture | node/4356487191 | `roche-bobois.com` | `roche-bobois.com` | UNSAFE_REDIRECT | No | No | Initial domain normalized; cross-domain redirect rejected. |
| Al Zahi Decor | Dubai, UAE | Furniture | node/4548414371 | `https://alzahidecor.com/` | `alzahidecor.com` | ROBOTS_DENIED | Yes | No | Existing robots rules prevented verification. |
| Merlin Digital | Dubai, UAE | Electronics | node/4271162696 | `https://merlin-digital.com/` | `merlin-digital.com` | VERIFIED | Yes | Yes | Completed analysis, score, and pre-promotion review state. |
| Spinneys | Dubai, UAE | Supermarket (from OSM tags) | node/621564163, node/718465847, node/1013709397 | `https://www.spinneys.com` | `spinneys.com` | UNSAFE_REDIRECT | Yes | No | Three OSM observations merged to one domain candidate; one verification attempt. |
| Wolfi's Bike Shop | Dubai, UAE | Bicycle (from OSM tags) | node/916305759 | `https://wolfis.ae/` | `wolfis.ae` | VERIFIED | Yes | Yes | Completed analysis, score, and pre-promotion review state. |

All six initial website values were normalized to the domains shown before verification. The two blocked redirect cases are stored by the existing verification job as `VERIFICATION_FAILED`; the safe logs identify the precise reason as `Cross-domain redirects are not allowed.` The reporting classification above separates those policy blocks from the connection failure and robots denial.

### Redirect policy review

`UrlPolicy::fetch` validates every redirect destination through the full public-HTTP URL and DNS/IP boundary, then requires the destination hostname to equal the original hostname. It intentionally rejects every cross-host redirect, including same-registrable-domain changes such as `example.com` to `www.example.com`; it does not make a registrable-domain exception. HTTPS-to-HTTP downgrade is also rejected. The two live cross-domain redirects were therefore correctly blocked. No redirect-policy change was made.

### Persistence, merge, analysis, and outreach

The three selected Dubai result sets (retail, furniture, electronics; limit 20 each) were created through the authenticated discovery API in a fresh isolated local acceptance tenant and processed by the existing Redis `DiscoveryAgent` and verification jobs. The 56 OSM source observations became 54 candidate records: two same-domain repeats merged, while each source observation retained its provenance. Of those candidate records, 6 had websites and 48 remained `website_not_found` with `verification_state=not_required`; website-less rows were not sent to verification, crawl, or AI analysis.

The normal pipeline created two pre-promotion companies for the verified domains. Both remain `status=discovery_candidate`; neither was promoted. Two candidates passed the cheap filter and reserved one AI analysis each in their own run (run maximum 25); both completed Website Intelligence with provider `deterministic`, model `local-acceptance-v1`, then received a Lead Scoring result and reached `reviewable`. No live paid AI provider was called. The deterministic website analysis supplied no evidence-backed opportunity signals for these live pages, so the completed lead scores were 0/100. This proves execution and review-state behavior, not that these two sites are sales-qualified.

A controlled supplied-domain observation for `wolfis.ae` was then passed through `DiscoveryAgent` in the same run as its OSM result. The result remained one candidate and one company identity with both `openstreetmap` (`node:916305759`) and `supplied_seed` (`input-row-1`) provenance records. The initial and final acceptance-tenant counts were all zero for campaign recipients, outbound messages, and `SEND_OUTREACH` approvals. No promotion, campaign enrollment, or contact occurred.

### Candidate drawer and responsive recheck

The location form, results list, and candidate detail drawer were rechecked at 1440, 1280, 768, and 390 CSS pixels. The 768px drawer was usable, evidence and source provenance remained readable, actions stayed accessible, and no page-level horizontal overflow was observed. The 390px layout remains functional but compact; this is not a mobile redesign acceptance.

### Final Sprint 3 acceptance

The expanded sample produced 126 real OSM business records, 8 website-bearing observations, 6 unique domains, 2 safely verified live websites, and successful live persistence, provenance merge, deterministic analysis/scoring, and review-state evidence. The preferred target of 3 verified sites was not reached; four unique sites were safely blocked or unavailable (one connection failure, one robots denial, two cross-domain redirects). Those are external site/policy outcomes, not application defects. The deterministic fixture test separately confirms the AI analysis ceiling, including a second eligible candidate being marked `budget_reached` when a test run's cap is one. Production web search remains disabled. This acceptance is sufficient to close Sprint 3 without relaxing URL, redirect, robots, crawl, human-review, or no-outreach controls; it does not constitute production deployment or representative market coverage.

Derived metrics for the expanded sample: website availability was 8/126 (6.3%); successful verification was 2/8 website-bearing observations (25.0%); duplicate website-bearing observations were 2/8 (25.0%), or 2/126 (1.6%) of all returned businesses. Analysis eligibility, analysis completion, scoring, and reviewability were each 2. No candidate was auto-promoted or contacted.

### Final verification record

- `ProductizedOpenWebDiscoveryTest`, `ProductizedProspectDiscoveryTest`, `UrlPolicyTest`, `PhaseOneRemediationTest` (tenant isolation and database constraints), `ProductizedSalesJourneyTest`, and `AcquisitionWorkflowEndToEndTest`: **29 tests / 438 assertions passed**.
- Full PHPUnit suite: **147 tests / 1,151 assertions passed**.
- `npm run typecheck` and `npm run build`: passed after the final drawer metadata spacing adjustment.
- PHP syntax sweep: passed. `git diff --check`: passed.
- Browser acceptance: the drawer metadata label and provenance render on separate lines; at 768 CSS pixels the drawer remains usable, details remain readable, and `document.documentElement.scrollWidth` equals the viewport width. Responsive layout checks at 1440, 1280, 768, and 390 CSS pixels reported no page-level horizontal overflow. The 390px view is compact and may require vertical scrolling, which is acceptable for this sprint.
- A visual defect discovered during review concatenated the source timestamp and source identifier. The candidate drawer now displays source metadata as a separate block with spacing. No backend behavior changed.
- Remaining P0/P1 application defects identified by this acceptance: **none**. Production scaling/deployment gaps remain: public Overpass is rate-limited and unsuitable as a high-volume production index; production web search is intentionally disabled; production deployment, capacity, and ongoing provider/policy review remain separate work.
