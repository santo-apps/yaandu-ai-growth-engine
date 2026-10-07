# Productization Sprint 4 — Prospect Enrichment and Official Website Resolution

## Status

Sprint 4 is **PRODUCTIZATION SPRINT 4 COMPLETE**. Live blinded acceptance established capability correctness against the available five-domain corpus. The corpus-size target is now classified as a production coverage/scaling gap, not a Sprint 4 correctness blocker. Website resolution never enrolls a company in a campaign or sends a message.

## Placement in the discovery pipeline

Resolution is a bounded domain service between `discovery_candidates` and `VerifyDiscoveryCandidateJob`. Website-bearing candidates continue through the existing verifier unchanged. Website-less candidates can create a tenant-scoped resolution record and queue `ResolveWebsiteCandidateJob` on the existing `crawl` queue. The resolver proposes and explains candidate domains; it does not promote them to `discovery_candidates.original_url` until an explicitly permitted high-confidence policy or a human confirms one. Human confirmation re-enters the existing website verifier, pre-promotion scan, WebsiteIntelligenceAgent, LeadScoringAgent, and human-review lifecycle.

The existing `DomainNormalizer`, `UrlPolicy`, DNS pinning, public-address checks, response-size limits, redirect bounds, and `RobotsRules` remain authoritative. Same-registrable-domain redirects are allowed only after complete destination URL and DNS revalidation against the vendored Public Suffix List. Different registrable domains, HTTPS downgrades, and unsafe destinations remain rejected.

## Data model and tenant boundary

The Sprint 4 migration adds four UUID-backed, tenant-isolated records:

- `website_resolutions`: one immutable identity snapshot, state, outcome, score, failure information, idempotency key, and discovery run/candidate references.
- `website_resolution_candidates`: one row per normalized domain, candidate URL, source, candidate type, review status, confidence, and explainable match summary. A unique tenant/resolution/domain key prevents duplicate domains.
- `website_resolution_evidence`: append-only source and matcher evidence, polarity, points, provenance, observed time, and an idempotency key. Evidence for directories and rejected candidates is retained.
- `website_resolution_attempts`: bounded per-source attempt history, safe failure details, metrics, and start/finish times.

Composite foreign keys keep resolution/candidate/run relationships inside a tenant. API lookups and review actions filter by the resolved tenant context. Resolution snapshots retain only attributes actually present in candidate/source records; they are not refreshed during an attempt.

## States and outcomes

The persisted flow is `PENDING → SEARCHING → CANDIDATES_FOUND → VERIFYING → RESOLVED | AMBIGUOUS | UNRESOLVED | FAILED`. Intermediate states can be brief because one queued job owns the work. `FAILED` represents technical/source/job failures; `UNRESOLVED` means no trustworthy official site was established; `AMBIGUOUS` means multiple or insufficiently decisive plausible matches remain. Failed queued work is marked with a safe retryable failure code. No failed outcome is represented as an identity mismatch.

## Identity evidence and candidate sources

`WebsiteResolutionSourceInterface` supplies named sources through `WebsiteResolutionSourceRegistry`. The current sources are:

- `OSMWebsiteEvidenceSource`: preserves OSM `website`, `contact:website`, `brand:website`, and `operator:website` as distinct candidate types and weights; preserves brand/operator Wikidata, Wikidata, and Wikipedia references as identity evidence.
- `WikidataWebsiteResolutionSource`: uses explicitly linked OSM Wikidata entity IDs and official-website (P856) claims; it does not query arbitrary names or synthesize domains.
- `CommonCrawlResolutionSource`: bounded historical capture evidence for candidate domains already discovered from another source. It may corroborate that a domain has served HTML; it is not ownership proof.
- `LocalWebIndexResolutionSource`: searches a populated shared local index and returns URL-backed discovery candidates, search rank, matching fields, and traceable provenance. It never assigns an official URL.
- `DeterministicWebsiteResolutionSource`: available only in the test environment for repeatable acceptance fixtures.

### Common Crawl role and local index

Common Crawl's CDX index is organized around URL/host patterns. Its public index is not a full-text company-name search service. In this design, Common Crawl enriches already known domains/pages with historical capture evidence and is a future offline index-ingestion source. It does not receive arbitrary company-name lookup attempts. See [Common Crawl CDXJ index documentation](https://www.commoncrawl.org/cdxj-index), [the public index endpoints](https://index.commoncrawl.org/), and [Common Crawl FAQ](https://commoncrawl.org/faq).

The local index stores one bounded metadata document per canonical page in `web_index_documents`. It stores public identity fields, a small visible-text excerpt, contact values only when available from a source, structured identity data, outbound business links for directory pages, source provenance, timestamps, and a content hash; it does not store entire pages. It is a shared public-evidence corpus, not a tenant-owned prospect table. Tenant-specific resolutions/candidates/evidence remain in their existing tenant-scoped tables.

`LocalWebIndexSearchService` uses PostgreSQL `to_tsvector('simple', search_text)` / `to_tsquery` and a GIN index in production. SQLite uses bounded token `LIKE` checks for fast deterministic tests. It normalizes case, punctuation, whitespace, select legal suffixes, location aliases, and phone digits. Search ranking separately weighs name-token overlap, city, country, address overlap, phone, and category context; wrong-country results lose rank. Search rank is persisted in provenance details with zero resolution points. The existing `BusinessWebsiteIdentityMatcher` evaluates identity confidence independently using the fetched candidate page and specific corroborating evidence. Search rank alone cannot raise confidence, confirm, or attach a domain.

`WebIndexIngestionSourceInterface` bounds ingestion-source implementations. Sprint 4 provides `VerifiedDiscoveryIndexIngestionSource` and the explicit bounded `web-index:ingest --source=verified_discovery --limit=1..500` command. It uses only existing successfully verified OSM/Wikidata discovery candidates, so live fetches are not performed by this index bootstrap command and no security policy is bypassed. `web-index:status` reports document/domain counts, latest ingestion, failures, and source distribution. Content hashes make unchanged replays no-ops; canonical URL/domain uniqueness deduplicates documents; multiple useful pages can live under one domain, while `LocalWebIndexResolutionSource` aggregates them into one domain candidate.

Directory records can carry explicit outbound business links. A matching listing produces the linked target URL as the candidate and keeps directory URL/source reference as evidence. The directory host itself is never emitted as the business domain. A future direct-public-crawl ingestion source must call the existing DomainNormalizer, UrlPolicy, and RobotsRules before ingesting; this source has not been enabled. The scalable future path is a separately budgeted offline pipeline (including possible Common Crawl WARC metadata ingestion) → bounded metadata extraction → searchable local index. OpenSearch is not introduced until measured query volume justifies it. Interactive resolution never downloads WARC archives.

`CommonCrawlClient` centralizes collection selection, a single-flight cache lock, bounded query count, timeouts, limited retry/backoff, response byte cap, JSON-line parsing, domain/status/MIME validation, caching, and a product User-Agent. Common Crawl outage is isolated to the attempt and does not stop other candidates or the discovery run.

## Matching, confidence, and false-positive controls

`BusinessWebsiteIdentityMatcher` is deterministic and explainable. It compares normalized exact business/brand/operator names, public phone/email, location/address/country, structured Organization/LocalBusiness data, public `sameAs` links, email domain, and page indicators such as parked-domain text. Positive and contradictory signals are stored individually. Weights and HIGH/MEDIUM thresholds live in `config/website_resolution.php`; a name match alone is below the medium threshold. A location plus exact name can reach reviewable confidence; exact contact corroboration is stronger. Negative evidence reduces score, and scores are clamped to 0–100.

Directory and social hosts, directory-like URL paths, unsafe URLs, and parked pages are rejected or penalized. Social profiles are not treated as official company websites. Multiple distinct domains remain separate candidate rows. Low confidence is rejected; medium candidates remain review-only. Automatic confirmation defaults off and must be explicitly enabled by policy.

The deterministic source is a test fixture only. There is no domain permutation or business-name-to-domain guessing code. Every candidate must originate from an indexed canonical URL or explicit outbound business URL backed by a directory record. Candidate URLs are normalized using the existing `DomainNormalizer`, then fetched only through the existing public URL/SSRF/redirect/robots policy. Technical fetch failure keeps the candidate and source evidence, marks verification `failed`, leaves identity confidence distinct, and can be retried.

## Budgets, caching, queueing, and replay

Configuration bounds businesses per run/request, source lookups per business, domains per business, candidate page bytes, candidate pages/domain, overall duration, and concurrent jobs. Queue work is unique per tenant and resolution and is serialized through overlap middleware on the existing crawl queue. One candidate failure is converted to safe evidence/status rather than aborting a whole run. External source error summaries are sanitized and capped.

Resolution request idempotency keys are tenant-scoped. A repeated request returns the existing resolution, active in-flight attempts are not duplicated, source evidence and candidate domains have uniqueness keys, and human review preserves rejected evidence. Explicit retry starts a new bounded resolution record linked to the same candidate, retaining previous attempts for audit. Wikidata and Common Crawl responses use normalized TTL cache keys; repeated identical lookups do not repeatedly consume external requests.

## API and UI

Tenant-authenticated discovery endpoints expose single/bulk resolution, resolution detail, metrics, retry, and review actions. Bulk requests have a configured maximum and return an estimated bounded lookup count before asynchronous execution. Review actions (`confirm`, `reject`, `mark_unresolved`) write audit logs. Cross-tenant IDs resolve as 404.

The Prospects workspace shows an actionable website-less row, per-candidate status, a selected-candidate bulk action with budget estimate, and a detail drawer with candidate domains, match score, source/type, evidence, and human review controls. Technical attempt metadata stays secondary. Sprint 4 does not create campaign recipients, outbound messages, or `SEND_OUTREACH` approvals.

Metrics cover website-less candidates, attempts, candidate domains, resolved, ambiguous, unresolved, failed, verified after resolution, and analysis-eligible candidates. Index status additionally reports documents/domains, latest ingestion, failures, and source distribution; these are operational metrics rather than salesperson navigation.

## Acceptance and operational limits

Deterministic tests cover website-less OSM records without Wikidata against indexed documents: exact name/location/phone, name/location, wrong-country same-name, explicit directory link, comparable-domain ambiguity, and no match. They also verify human review, verified discovery → intelligence/scoring, idempotent ingestion, no guessed domains, no cross-tenant leakage, safe URL policy, technical failure semantics, and zero outreach. The existing URL policy/security tests remain part of the regression set. Live acceptance is limited to 10 website-less local records and must report attempted, discovered, resolved, ambiguous, unresolved, failed, and manually reviewed outcomes. A zero-result sample is reported as zero; no candidate is forced into a match.

Production-scale name-based website discovery is not supplied by Common Crawl's public CDX API. The local index is intentionally bounded and seeded from verified open discovery results. More coverage requires separately approved public/open ingestion sources and measured growth of PostgreSQL search volume. Live provider connectivity, production queue operation, and deployment are not implied by deterministic local tests.

### Local index remediation acceptance (2026-10-07)

- Seam audit: `LocalWebIndexResolutionSource::find()` was an empty stub, and `ResolveWebsiteCandidateJob` did not call it. There was no index schema, ingestion command, index search/ranking, directory-link handling, or index provenance to feed the existing candidate/identity pipeline.
- Schema: `web_index_documents` stores canonical URL, normalized domain, title/organization, short description/text excerpt, public location/address/phone/email values, bounded structured data and explicit directory business links, document type, source/reference/timestamps, content hash, normalized search fields, and searchable text. `web_index_ingestion_runs` records bounded run counters, source metrics, and failures. The index is a shared public-evidence corpus with no tenant data; resolution/evidence records remain tenant-scoped.
- PostgreSQL search: a GIN index over `to_tsvector('simple', search_text)` backs `to_tsquery`; the live PostgreSQL positive-control search found `https://femiclinic.com/` for the real `Femiclinic Medical center / Dubai / UAE` indexed identity at rank 100, with business-name, city, country, and category-context matching fields. `LocalWebIndexResolutionSource` returned its canonical URL, domain, OSM reference, and index document/content-hash provenance. Search rank contributed zero identity points. The existing `UrlPolicy` checked that domain's robots resource (HTTP 200) and homepage (HTTP 200); this is a positive control for the source/fetch pipeline, but this OSM identity itself already included a website and is not a website-less resolution acceptance.
- SQLite uses a bounded token `LIKE` implementation for the fast test suite. Identity normalization removes punctuation/whitespace variance and selected legal suffixes, normalizes UAE/UK/US aliases and phone digits, and requires at least two meaningful business-name tokens. Ranking is deterministic and separate from identity score; country conflicts are penalized, not hidden.
- Ingestion sources: `verified_discovery` imports only verified candidates with OSM/Wikidata source provenance and skips known social/directory URLs. `osm_public_websites` is an explicit, bounded local command source (maximum 50 accepted documents, four configured city/category queries, at most twice the requested page count checked, 300-second deadline). It reads only explicit OSM website references and fetches only the linked homepage through existing public URL/DNS/SSRF/redirect and robots controls. It does not crawl links or guess domains. Common Crawl remains known-domain historical enrichment/future offline ingestion, not company-name search.
- Bootstrap result: `php artisan web-index:ingest --source=verified_discovery --limit=50` inserted two existing verified public documents. The bounded OSM source successfully indexed three real pages, giving **5 live documents / 5 domains** total (2 verified-discovery, 3 OSM-page documents). A verified-source replay processed 2, inserted 0, updated 0, left 2 unchanged, and failed 0. This local database did not contain enough accessible verified public domains to reach the 20–50 target; no fixture or invented URL was added to the live corpus.
- OSM ingestion network diagnosis: a 10-document-bounded run checked 20 explicit website references. It recorded 3 DNS resolution failures before an HTTP fetch, 12 robots URL redirects rejected by the existing redirect policy, 1 other robots transport failure, and 4 homepage transport failures; there were no candidate HTTP status failures, TLS-classified failures, homepage redirects, or oversized/non-HTML responses in that run. One robots endpoint returned HTTP 403 in an earlier run and one site was disallowed by robots. These are per-host DNS/robots/transport outcomes, not identity rejections. A separate exact-policy check on `femiclinic.com` returned robots and page HTTP 200, so the local runtime was not globally unable to reach the public web. The previous three-company Wikidata sample was also checked through the same policy: Carrefour FR/COM, LG, and LuLu were stopped by cross-domain robots redirects; Carrefour BE robots returned HTTP 403; LG Korea robots had a transport failure. None reached candidate-page verification. No URL/SSRF/robots/redirect rule was relaxed, and no application identity failure was observed. The ingestion run stores stage/count metrics without raw exception strings or request content.
- Website-less live sample: after the 5-domain corpus was populated, **10 distinct** stored OSM business records with no OSM website/contact-website and no direct/brand/operator Wikidata reference were submitted through `WebsiteResolutionService` and the real `ResolveWebsiteCandidateJob` against PostgreSQL full-text search. Results: 0 local candidates, 0 verified candidates, 0 resolved, 0 ambiguous, **10 unresolved / `NO_CANDIDATES`**, 0 technical resolution failures. The cases were Food court, Carrefour, Ocean Coast Car Care, امارات, Cannondale, Marina Exotic Home Interiors, Yasir Car Services Centre, Mostafawi Carpets & Curtains, Al Khoory, and Hor Al Anz Electronics. An initial tenth selection was denied because its discovery run had reached the configured 25-business resolution budget; it was removed as a temporary duplicate-name acceptance record and replaced with the tenth from another run without changing limits.
- Cross-check: one bounded live OSM query returned 94 Dubai business records, 84 without an OSM website; none matched the local index. All 48 eligible website-less OSM records already stored in the local development database also returned no candidate. Therefore the live positive control proves local indexing/search/source provenance on real public data, but live website-less name-to-domain discovery has not yet been demonstrated. The current indexed corpus is too small and poorly overlapping with the available website-less set.
- Identity controls: deterministic acceptance covers strong exact business/location/phone; business-name plus location; same-name wrong-country rejection; directory listing link to a separate official-domain candidate; two comparable domains staying ambiguous; and no-match behavior. Directory host is never returned as the official candidate. No domain is constructed from a company name.
- No-outreach delta: live website-less attempts created only website-resolution, attempt, and evidence records. They did not create a campaign enrollment, outbound message, or `SEND_OUTREACH` approval. Deterministic confirmation re-enters existing verification, WebsiteIntelligenceAgent, LeadScoringAgent, and human review.
- Retry/error semantics: deterministic regression confirms page network failures preserve candidate/evidence, remain `FAILED / NETWORK_FAILURE`, and can be safely retried without identity rejection. URL policy/robots failure in index ingestion excludes a page from the index; it never marks an unrelated prospect as a mismatch.
- Responsive regression: the Sprint 4 Prospects Discovery/resolution UI was not changed by the local-index work. The existing responsive evidence remains 1440, 1280, 768, and 390 CSS px, with no document horizontal overflow; the resolution drawer uses viewport width and internal vertical scrolling at mobile width.
- Final verification: the website-resolution feature file passed **14 tests / 96 assertions** after the explicit source rank/provenance contract change. The named discovery/security/tenant/sales/acquisition regression set passed **33 tests / 477 assertions**. The final full PHPUnit suite passed **164 tests / 1,259 assertions**. TypeScript typecheck and Vite production build passed; PHP syntax passed for 317 files; `git diff --check` passed. PostgreSQL migrations and full-text search were runtime-verified. The frontend was unchanged by this remediation; the browser viewport checks above are the existing Sprint 4 visual evidence, not a new browser run in this remediation.
- Remaining acceptance limit: no live website-less OSM identity matched the available real index. Expansion of the public index corpus and another real overlapping website-less sample are required before Sprint 4 can close. No outreach was triggered.

### Local acceptance record (2026-10-07)

- PostgreSQL: the Sprint 4 migration applied successfully to the configured local PostgreSQL 17 database. The existing SQLite PHPUnit suite remains the fast default.
- Deterministic acceptance: `ProductizedWebsiteResolutionTest` covers manual review and return to verification/analysis/scoring, multiple-domain ambiguity, directory rejection, technical network failure versus ambiguity, repeated-attempt numbering, tenant isolation, idempotent replay, and no outbound side effects. `CommonCrawlClientTest` covers bounded parsing/cache and the brand-versus-direct Wikidata evidence weights.
- Historical pre-local-index sample: three website-less Dubai OSM records with explicit `brand:wikidata` references were attempted. Wikidata yielded six candidate domains total: Carrefour (`carrefour.fr`, `carrefour.be`, `carrefour.com`), LG (`lg.com`, `lge.co.kr`), and LuLu Hypermarket (`luluhypermarket.com`). All six were typed `brand_reference`, scored 30/100 LOW, and remained unassigned. Candidate checks were stopped during robots checks by cross-domain redirects, a 403 response, or a transport failure before homepage verification. Common Crawl lookups ran for all three businesses and returned zero historical capture candidates. No website was attached.
- Manual quality review: the three OSM references were brand-level identifiers, not branch-level proof. Independent public checks identify [Carrefour Group](https://www.carrefour.com/), [LG Global](https://www.lg.com/global/), and the [LuLu Hypermarket UAE site](https://gcc.luluhypermarket.com/en-ae/aboutus) as related brand sites. This supports keeping the OSM brand-reference candidates at low confidence; it does not establish that each domain is the official website for the particular Dubai feature. The checks were not used to override candidate-fetch failures or to promote a domain.
- UI: authenticated Prospect Discovery results and the resolution drawer were inspected using a temporary fictional fixture. At 1440, 1280, 768, and 390 CSS pixels, `documentElement.scrollWidth` equaled the viewport width. The 390px drawer filled the viewport width and scrolled vertically inside the drawer. Candidate evidence, conflicts, and Confirm/Reject/Mark Unresolved/Retry controls remained readable. The temporary candidate and its resolution records were deleted after the inspection.
- Regression: full PHPUnit **158 tests / 1,220 assertions passed**; the focused resolution, Common Crawl, Sprint 3 discovery/security, tenant, sales-journey, and acquisition end-to-end set passed **30 tests / 450 assertions**. TypeScript check and production build passed; PHP syntax sweep passed; `git diff --check` passed. Pint is installed as a development dependency but no project lint script/config is present. `vendor/bin/pint --test` fails on broad pre-existing compressed-style files as well as new files; no repo-wide autoformat was applied.
- The earlier three-company sample predates local index ingestion and the ten-business sample had no index overlap. Those historical counters are superseded by the final blinded acceptance below.

### Final remediation acceptance (2026-10-07)

#### Real corpus construction and limits

The bounded pipeline was run against configured Dubai, Sharjah, and Abu Dhabi OSM searches and replayed the existing verified-discovery source. No names or domains were fabricated, and no search-result pages or paid APIs were used.

| Source run | Businesses considered | Explicit website refs | Documents inserted | Results |
| --- | ---: | ---: | ---: | --- |
| Dubai OSM, `--limit=50` | 380 | 38 | 0 | 4 DNS failures; 18 robots redirects; 6 robots transport failures; 1 robots HTTP unavailable; 1 robots denial; 8 homepage transport failures. |
| Sharjah OSM, `--limit=50` | 375 | 19 | 0 | 5 DNS failures; 7 robots redirects; 2 robots transport failures; 1 robots HTTP unavailable; 4 homepage transport failures. |
| Abu Dhabi OSM, `--limit=50` | 0 | 0 | 0 | The configured Overpass result contained no matching businesses. |
| Verified discovery replay, `--limit=50` | 2 | n/a | 0 | Both existing documents were unchanged; no failures. |

The corpus remains **5 documents / 5 unique domains**: 3 `osm_public_websites`, 2 `verified_open_discovery`. The database contains the real indexed businesses Aster Medical Centre, Femiclinic Medical center, Sahara Medical Centre, Merlin Digital, and Wolfi's Bike Shop. The index has useful real name/title and location/category evidence; Femiclinic includes public phone/email and structured organization data, Sahara includes address, phone, location, title, and structured clinic data. Aster's indexed page explicitly says its account is suspended. Some records do not contain every identity field; those fields were not manufactured.

The live ingestion source reached 755 businesses in the Dubai and Sharjah runs and attempted 57 explicit OSM website references without successfully adding a page. Abu Dhabi returned zero. The existing five indexed records are therefore not a newly achieved 20–50-domain corpus. Public-site DNS, robots, redirect, and transport reachability remains the corpus construction bottleneck.

#### Blinded resolution method and outcomes

For five real indexed records, temporary website-less OSM candidate identities were constructed from business name, location, category, and available public contact data. Each input was asserted to contain no official URL, `website`/`contact:website`/`brand:website`/`operator:website`, known domain, or direct Wikidata website answer. The resolver received only that identity snapshot. The candidate URL and provenance were read from `web_index_documents`, then passed through normal URL, DNS/SSRF, robots, page-fetch, and identity checks. Temporary tenant records were deleted after each run.

| Business | Public corpus source | Domain hidden? | Index candidate | Rank | Identity score | Verification / result | Correct? |
| --- | --- | --- | --- | ---: | ---: | --- | --- |
| Aster Medical Centre | OSM node `2485941511` | Yes | `astermedicalcentre.com` | 100 | 5 LOW | Fetch succeeded, but the page says “Account Suspended”; rejected and left UNRESOLVED. | Yes |
| Femiclinic Medical center | OSM node `4101045398` | Yes | `femiclinic.com` | 100 | 50 MEDIUM | Public page/name and structured organization evidence verified; initially AMBIGUOUS, then confirmed via the authenticated human-review endpoint. | Yes |
| Merlin Digital | Verified open discovery, OSM node `4271162696` | Yes | `merlin-digital.com` | 95 | 50 MEDIUM | Candidate page verified; remained AMBIGUOUS pending human decision. | Yes |
| Sahara Medical Centre | OSM node `1357692409` | Yes | `saharamedicalcentre.ae` | 100 | 100 HIGH | Name + exact public phone + Sharjah location + structured clinic evidence. Authenticated human-review endpoint confirmed it; resolution became RESOLVED and website verification passed. | Yes |
| Wolfi's Bike Shop | Verified open discovery, OSM node `916305759` | Yes | `wolfis.ae` | 95 | 0 LOW | Candidate was found but safe verification failed due a transport error; resolution remained FAILED. | Yes |

The confirmed actions were executed by temporary local acceptance users to exercise the real authenticated API route; they are workflow verification, not a claim that a salesperson reviewed these businesses. Search rank did not contribute identity points. Across the five cases: 5 correct index candidates found, 4 public pages fetched and identity-evaluated, 3 initially AMBIGUOUS, 2 confirmed through the authenticated review route across separately repeated positive cases, 1 UNRESOLVED, 1 FAILED, and **0 incorrect domains assigned**. A candidate marked FAILED or rejected is not counted as verified identity.

Three additional real OSM identities were selected because their known public domains were absent from the index. Their website and Wikidata fields were stripped from resolver input and asserted absent. All three returned `UNRESOLVED / NO_CANDIDATES`: British Embassy (Dubai, hidden `gov.uk`), Al Maya Supermarket (Dubai, hidden `almaya.ae`), and Emarine (Dubai, hidden `emarine.ae`). No candidate domain was guessed.

No suitable independent real same-name business in a different city/country was present in the bounded corpus. The live wrong-location control was therefore not manufactured; the deterministic wrong-country test remains authoritative.

#### Redirect classification and policy

A 20-reference, non-following robots diagnostic revalidated each source and destination URL and resolved DNS before classification. A Public Suffix List utility was used for this diagnostic. Results: 7 apex-to-`www` same-registrable-domain redirects, 4 different-registrable-domain redirects, 3 non-redirect responses, and 6 transport failures. There were no `www`-to-apex, HTTP-to-HTTPS, other same-site subdomain, or other classified redirects in this sample. Cross-domain redirects remain denied.

Because same-site apex-to-`www` redirects were material, `UrlPolicy` now permits a hop only when source and destination have the same known registrable domain according to the vendored PSL, after the destination passes complete URL and DNS/IP validation. Each hop remains DNS-pinned, HTTP(S)-only, rate-limited, capped at three redirects, and prohibited from HTTPS-to-HTTP downgrade. Unknown/public-suffix-only hosts fail closed. The PSL snapshot is `2026-10-06_16-13-12_UTC` from the official Public Suffix List and is loaded locally; requests do not fetch policy data at runtime. The parser is `jeremykendall/php-domain-parser` 6.4.0.

The same bounded `--limit=10` run after this change checked 20 explicit references. Robots redirect rejections fell from the earlier 12 to 6; DNS failures were 3, robots transport failures 1, and homepage transport failures 10. No document was indexed. Thus canonical redirects improved robots-stage reachability in this sample but did not improve indexed-page count; the remaining transport failures still limit corpus growth.

Redirect regression coverage passes for apex→`www`, `www`→apex, HTTP→HTTPS, same-registrable subdomains, unrelated registrable domains, localhost, private IPv4, IPv6 loopback/private, metadata IP/host, loops, and redirect-depth overflow. The PSL test also distinguishes multi-label public suffixes and private suffixes such as `github.io`.

#### Downstream, zero outreach, and responsive smoke

The authenticated confirmation of the real Femiclinic candidate passed existing website verification and the cheap filter (`eligible_for_analysis=true`). With the explicit local deterministic AI provider enabled before application boot, `WebsiteIntelligenceAgent` and `LeadScoringAgent` completed; the resulting score was 0/100 under the tenant's available evidence/configuration. Live AI providers were not called. The strong Sahara website-resolution confirmation also passed website verification and the cheap filter, but its subsequent intelligence crawl stopped safely when a page redirected from HTTPS to HTTP; the downgrade was not relaxed. The Femiclinic case is the successful intelligence/scoring control.

Before/after counts in the isolated acceptance tenants were 0 campaign recipients, 0 outbound messages, and 0 `SEND_OUTREACH` approvals. No outreach side effect occurred.

The resolution drawer was inspected in the local browser at 1280×900 and 390×900. At 1280 it measured 520px wide, aligned to the viewport edge, and had no horizontal overflow. At 390 it filled the viewport and scrolled vertically; the page and drawer had no horizontal overflow and the heading/close control remained visible. Temporary browser tenant/user records were deleted afterward.

#### Acceptance status

The acceptance evidence in this section establishes correct candidate discovery, negative-control behavior, authenticated review, downstream processing, zero incorrect assignments, and safe canonical redirect handling. The completion decision and final baseline are recorded below.

## SPRINT 4 FINAL ACCEPTANCE

**PRODUCTIZATION SPRINT 4 COMPLETE**

Live blinded acceptance tested five real indexed business identities: Aster Medical Centre, Femiclinic Medical center, Merlin Digital, Sahara Medical Centre, and Wolfi’s Bike Shop. All five correct candidate domains were discovered and zero incorrect domains were assigned. Resolver inputs contained no website, known domain, known homepage, or direct Wikidata website answer; candidates originated from the local web index. Three additional real negative controls remained `UNRESOLVED / NO_CANDIDATES`, with no guessed domain. Femiclinic and Sahara were confirmed through the authenticated review endpoint. Downstream website verification, intelligence, and scoring were demonstrated. Outreach remained disabled throughout: campaign recipients delta = 0, outbound messages delta = 0, and `SEND_OUTREACH` approvals delta = 0.

**Capability correctness and corpus coverage are separate acceptance dimensions.** The local index currently contains five documents across five domains. The prior 20–50-domain target is a **PRODUCTION COVERAGE / SCALING GAP**, not a Sprint 4 correctness blocker: the accepted capability found the correct indexed candidates and preserved conservative outcomes when evidence was missing or insufficient. The limited corpus constrains recall and breadth of production discovery, but does not invalidate the demonstrated resolution behavior.

Same-registrable-domain canonical redirects are allowed only after complete revalidation, including apex ↔ `www` and safe HTTP → HTTPS redirects where applicable. The implementation uses the locally bundled Public Suffix List parser. Unrelated registrable domains, unsafe/private destinations, metadata endpoints, HTTPS downgrades, redirect loops, and redirect-depth overflow remain blocked.

### Final verification baseline

| Check | Result |
| --- | --- |
| Focused regression | **40 tests / 501 assertions — PASS** |
| Full PHPUnit suite | **171 tests / 1,283 assertions — PASS** |
| TypeScript | **PASS** |
| Vite production build | **PASS** |
| PHP syntax | **298 files — PASS** |
| Composer validation | **PASS** |
| Website-resolution routes | **4 registered** |
| `git diff --check` | **PASS** |
| P0 issues | **0** |
| Automatic outreach side effects | **0 recipients / 0 outbound messages / 0 send approvals** |

### Non-blocking production scaling work

1. The local web index contains only five domains.
2. Production corpus coverage needs systematic growth.
3. Public-source ingestion yield is low.
4. Common Crawl offline ingestion remains future work.
5. Corpus refresh, scheduling, and capacity remain production scaling work.
6. Resolution intentionally favors precision over recall.

These are production coverage and scaling items; they are not Sprint 4 completion blockers. No additional corpus expansion or resolution-policy relaxation is part of this closure.
