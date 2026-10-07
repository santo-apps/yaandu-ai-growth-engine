# Productization Sprint 2: Prospect Discovery and Intake

## Scope and current flow

Sprint 2 adds a review-first candidate intake layer to the existing discovery seam. Existing `POST /api/v1/discovery-runs` remains the supplied-seed registration path. The `DiscoveryAgent` now resolves a registered `DiscoverySourceInterface` adapter, normalizes and deduplicates its records with `DiscoveryCandidateService`, and queues candidate verification. Website analysis and scoring continue through the existing `ScanWebsiteJob`, `WebsiteIntelligenceAgent`, and `LeadScoringAgent`; this feature does not implement another crawler or intelligence engine.

The active source adapters are `SuppliedSeedDiscoverySource`, `CsvDomainDiscoverySource`, and `DeterministicDiscoverySource`. CSV/domain upload is the production-shaped genuine intake path: an authorized user supplies business domains in CSV, reviews a read-only preview, and explicitly confirms import. There is no third-party search API or directory connector enabled in this sprint. Deterministic results are clearly labeled fictional, and their adapter fails closed outside local/testing or unless explicitly enabled.

## Candidate lifecycle and promotion

Candidates are persisted independently from active prospects with a discovery run and search. Source references make repeated source rows idempotent within a run; canonical normalized domains deduplicate against tenant companies and prior candidates. Invalid domains are retained as rejected candidates with a reason; existing companies are linked to their existing record. New candidates pass bounded HTTP verification before pre-promotion analysis.

The lifecycle is `DISCOVERED → VERIFIED → ANALYZED → SCORED → REVIEWABLE → ACCEPTED`. To reuse the existing `WebsiteIntelligenceAgent` and `LeadScoringAgent`, a verified new candidate receives a controlled provisional Company (`status=discovery_candidate`), website, and scan/evidence before promotion. Provisional companies are excluded from normal prospect and sales workflows. Intelligence and configured scoring rules execute before human review; incomplete or failed analysis blocks acceptance. Acceptance changes the existing provisional Company to active `new` status transactionally, retaining score, intelligence, website evidence, contacts, and audit context. It is idempotent and does not recompute the score. Human acceptance never enrolls a campaign recipient or sends a message. Prospect 360 is the existing destination, not a duplicate detail surface.

## Domain identity, safety, and deduplication

`DomainNormalizer` removes protocol, `www`, path, query, fragment, case, and trailing-slash differences and rejects unsupported schemes, embedded credentials, malformed hosts, localhost and literal non-public addresses. Internationalized domain handling follows the installed PHP runtime's IDN support. Candidate verification reuses the crawler `UrlPolicy`, public-address resolver, redirect revalidation, robots rules, and shared host rate/locking policy; it does not create a discovery-specific weaker network boundary. DNS, timeout, robots, HTTP, and unsafe destination failures are persisted per candidate so one failure does not terminate the run.

Verification uses bounded HTTP first and captures factual first-page signals (status, canonical URL, title, description, viewport, HTTPS, and response time). It reads robots policy before fetching candidate content. Pre-promotion website analysis reuses the existing bounded crawler and Website Intelligence agent for genuine sources; deterministic acceptance uses fictional local fixture HTML and makes no network request. Playwright remains disabled by default; `max_browser_renders_per_run` defaults to zero.

## Contacts and evidence

Public company email, phone, and public social/company profile links are extracted from crawled public pages only. Contact methods preserve source URL, observed time, extraction method and confidence in existing provenance fields; email/phone classification distinguishes generic business, sales, support, business phone, and social profile values. Values continue to use existing encrypted storage. No personal address is inferred and no contact is fabricated. Website findings and recommendation evidence use existing persisted intelligence/evidence structures; the initial service match is deterministic and only emitted when fetched page evidence supports its rule.

## Persistence, tenancy, budgets, and jobs

The two migrations add `discovery_searches`, `discovery_runs`, and `discovery_candidates`, plus contact-method classification. UUID identities, tenant-leading indexes, composite tenant foreign keys, and a tenant/run/source-reference uniqueness constraint protect relationships and replay. Every API query/action scopes by active tenant and cross-tenant feature tests assert 404 behavior.

DiscoveryAgent runs use the existing `agent_runs` and `agent_events` records. Candidate verification uses the `crawl` queue and unique tenant/candidate jobs. Accepted-candidate scans use the existing crawl-to-intelligence-to-scoring chain. Configurable server-side limits cover candidates, CSV rows, verifications, pages per domain, browser renders, AI analyses, and runtime. Request handlers do not synchronously crawl an imported list. Repeating a search run with the same idempotency key returns its existing run; repeating source references does not create duplicate candidates.

Audit events record search creation, CSV confirmation, candidate acceptance/rejection, and bulk review. Preview does not persist prospects or candidates. API and UI intentionally contain no bulk-send action.

## User workflow

Prospects > Find Prospects provides a local fictional-source search, recent runs/candidate table, candidate detail drawer, review actions, and CSV preview/confirmation. The fictional source is marked TEST MODE; genuine company domains enter through confirmed CSV. Search criteria include location, industry, company size, keywords, website signals, service interests, and a bounded candidate count. Candidate filters cover location, industry, status, contact presence, service, and score. Tables use contained horizontal scrolling at narrow widths and the drawer adapts to mobile. Candidate detail shows verification, intelligence, score components, findings and evidence, provenance, and review readiness before acceptance.

## Verification strategy and results

Fast feature tests run against SQLite through the standard PHPUnit suite. Product-level tests exercise deterministic source orchestration, candidate normalization/deduplication, unsafe/unreachable sites, explicit acceptance, Prospect API visibility, no campaign enrollment/outbound records, CSV preview and confirmation, and cross-tenant denial. Separate PostgreSQL integration checks remain necessary to verify database-specific composite constraints against a running PostgreSQL instance.

Sprint 2 verification should also run `ProductizedSalesJourneyTest`, `AcquisitionWorkflowEndToEndTest`, the full PHPUnit suite, frontend typecheck/build, PHP syntax validation, route registration, and `git diff --check`. Runtime PostgreSQL migration and browser acceptance require the local services and authenticated tenant session; do not infer those from SQLite feature tests.

## Acceptance history and final remediation

The initial Sprint 2 acceptance attempt was partial: deterministic source selection was disabled in the local server environment, browser file attachment hit an automation permission limitation, and analysis/scoring occurred after promotion. Preserve that result as historical context. The remediation enables the source only through explicit local process flags and completes scoring/intelligence before human acceptance through the existing agents and hidden provisional company status.

For local browser acceptance, set `APP_ENV=local DISCOVERY_ALLOW_DETERMINISTIC=true AI_DETERMINISTIC_ENABLED=true AI_REASONING_PROVIDER=deterministic AI_REASONING_MODEL=local-acceptance-v1` on both the Laravel server and queue worker process. `.env.example` keeps flags false. Both the environment check and explicit enablement are required; there is no production fallback. Fixtures include strong and weak fictional businesses, an existing-company duplicate, unsafe and unreachable sites, and contact/no-contact cases.

The initial failed browser attempt remains historical evidence. Its candidate record stored only the generic `VERIFICATION_FAILED` code and safe summary, and its catch log did not contain a candidate/correlation identifier. Therefore the original candidate's exact thrown exception cannot be reconstructed with certainty. Controlled replay of the same deterministic verification path with its local enablement flag omitted reproduced `LogicException` from `DeterministicDiscoverySource::__construct` at `app/Discovery/DeterministicDiscoverySource.php:12` (`Deterministic discovery is available only in explicitly enabled local/test environments.`); the contemporaneous worker log also records that exact deterministic-source exception, but it was not correlated to the old candidate. The reproduced failure occurs before network access. Do not overstate this as proof that the historical candidate threw that exception.

`php artisan queue:failed` was inspected: the current database contains two unrelated older failures (`CoordinateAcquisitionWorkflowEvent` on `workflow` and `ExecuteCampaignStep` on `campaigns`) and no discovery verification job. The verification job catches `Throwable`, maps it to a safe candidate-level failure, logs internally, and returns; the controlled replay therefore classifies as a caught exception persisted on the candidate (case B), not a verification job that escaped to `failed_jobs`. The old candidate's record alone does not prove its exact thrown exception or retry count. The existing SQLite tests missed the deployment mismatch because they invoke the verification job in-process and enable deterministic mode in the test environment; they did not cross the PostgreSQL + Redis serialization/worker configuration boundary.

The narrow remediation now logs candidate/run/tenant IDs, correlation ID, safe domain, stable failure code, exception class, basename and line, bounded sanitized trace, and scrubbed message. Technical verification failures use `verification_failed` rather than the human-review `rejected` state. Bulk retry/reverify reports queued versus processed counts and returns 202 when queue work remains; the UI says checks were queued and polls `analyzing` as well as other active states. Reverify resets only actionable failed candidates and enqueues a unique verification job. Deterministic local source use still requires explicit local process flags; `.env.example` remains disabled by default. A missing Northstar deterministic fixture was added so that duplicate-company behavior is exercised without falling through to an external request. Run finalization now excludes terminal existing-company and duplicate candidates from pending work; the PostgreSQL/Redis regression protects this state transition.

Fresh local browser acceptance (2026-10-06) used the isolated PostgreSQL tenant `Sprint 2 Discovery Acceptance`, not a reset or mutation of the Sprint 1 tenant. The app was opened at 1440×900 and used the visibly labeled deterministic test mode. A fresh search with an eight-candidate cap exercised Noura Market, Cedar & Loom, Offline Atelier, unsafe loopback, duplicate domain, and existing-company duplicate paths. Noura reached verified → analyzed → scored (35/100) → reviewable, was explicitly accepted, and opened its existing Prospect 360 view. Its public business contact and source provenance appeared in Contacts; website findings and score were visible in the candidate review. Northstar was added to this isolated tenant to make duplicate handling deterministic. The search reached 6 found, 2 duplicates, 1 invalid, 3 verified, 2 analyzed, 2 scored, 1 accepted, 1 rejected, and 1 intentional unreachable failure. Noura was the contact-found candidate and Cedar the no-contact weaker candidate (0/100); Cedar's actual WebsiteIntelligenceAgent and LeadScoringAgent runs succeeded, then the UI showed it as `Reviewable`. The original run was stuck in `analyzing` solely because its terminal existing-company duplicate was treated as pending; after the narrow finalization correction and UI requeue, PostgreSQL reported that run `completed` with those final counters. Northstar linked to its one tenant company rather than creating a second one.

CSV browser acceptance used a synthetic local file containing `https://example.com`, a same-file domain duplicate, an existing Northstar domain, and `http://127.0.0.1/admin`. Read-only preview displayed 1 valid, 2 duplicates, 1 invalid, across 4 rows, and explicitly stated preview creates no prospects. Confirmation reported 4 rows entered review and sent no outreach. The genuine CSV row then passed HTTP verification (200), received pre-promotion Website Intelligence and a 0/100 score, and visibly reached `Ready for human review`; it was not promoted. PostgreSQL inspection confirmed that candidate remains `verification_state=verified`, `lifecycle_status=reviewable`, and linked to its provisional company. In the acceptance tenant, persisted counts were 0 campaign recipients, 0 outbound messages, and 0 `SEND_OUTREACH` approvals.

Responsive browser checks covered Find Prospects, discovery results, and CSV preview at approximately 1440, 1280, 768, and 390 CSS pixels. The candidate details drawer was checked at 1440, 1280, and 390; its 768px tablet state was not verified. Before the narrow-width correction, 768px caused document/body width 1111px from discovery filters and candidate controls. A scoped responsive rule in `ProspectsWorkspace.vue` now constrains those controls; after the fix, document/body width matched each viewport at 1440, 1280, 768, and 390. At 390 the candidate table uses a contained horizontal scroll area. No company/outreach behavior changed. Mobile table columns still require contained horizontal scrolling and are not a mobile redesign.

An opt-in `PostgresRedisDiscoveryVerificationTest` now exercises the HTTP-authenticated discovery request, Laravel Redis serialization, PostgreSQL candidate persistence, Redis verification and analysis/scoring workers, and final reviewable state. It also inserts a terminal existing-company duplicate into the run and asserts that after analysis/scoring completes the run reaches `completed` instead of remaining stuck in `analyzing`. It is run with `phpunit.pg-integration.xml`, `YAANDU_POSTGRES_REDIS_INTEGRATION=true`, and a dedicated Redis prefix/database so shared queues are not purged or consumed. Final PostgreSQL/Redis result: **1 test, 19 assertions passed**. The scoped worker ran Laravel's serialized jobs directly; Horizon was stopped to avoid consuming unrelated campaign/workflow queues, and `php artisan horizon:status` therefore reported inactive. The current `failed_jobs` table contains only the two unrelated campaign/workflow failures above. A separate SQLite feature regression reproduced the disabled local deterministic flag, asserted structured exception diagnostics and `verification_failed`, then used the HTTP reverify action and asserted a queued response and successful verification without duplicate company creation.

Final fast verification on 2026-10-06: `ProductizedProspectDiscoveryTest` 5 tests/83 assertions; `UrlPolicyTest` 5/16; `TenantRolePayloadTest` 1/3; `ProductizedSalesJourneyTest` 3/151; `AcquisitionWorkflowEndToEndTest` 2/85; full PHPUnit 144/1,108. `npm run typecheck`, `npm run build`, PHP syntax validation (`app`, `routes`, migrations, tests), `php artisan route:list --except-vendor --json`, and `git diff --check` passed. The full suite includes the regression additions; the dedicated PostgreSQL/Redis suite requires elevated local service access and passed separately.

The historical exception-to-candidate correlation remains unavailable, so that historical failure cannot be attributed conclusively to the reproduced deterministic-source exception. The 768px candidate drawer check also remains outstanding. Current PostgreSQL/Redis and fresh UI flows pass with consistent local flags, and the acceptance pipeline, CSV, explicit acceptance, Prospect 360, no-outreach invariant, and the other responsive checks pass. No third-party discovery connector was added.

## Remaining production gaps

- No approved external search-engine or business-directory connector is configured; genuine intake is user-provided CSV/domain import.
- Historical exception-to-candidate correlation was never captured by the old logging path; fresh controlled reproduction and structured logging cover future occurrences.
- Local Horizon was inactive during final verification; the isolated queue worker was restricted to `crawl`. Production Horizon process management remains outside this acceptance.
- Playwright remains disabled by default and needs a separately isolated production worker before enablement.
- Production provider, retention, monitoring, review, and deployment hardening remain governed by the existing production-hardening checklist.

## Sprint 2 final closure

**PRODUCTIZATION SPRINT 2 COMPLETE**

Functional discovery and intake acceptance is closed based on the PostgreSQL-backed browser acceptance and automated verification recorded above. The earlier partial results remain preserved as history; the missing historical candidate correlation is closed as historical observability debt, not an active Sprint 2 P0.

P0 defects: **0**.

Confirmed product behavior:

- Discovery does not automatically send outreach.
- CSV import does not automatically send outreach.
- Candidate acceptance does not automatically enroll a campaign.
- Pre-promotion Website Intelligence and lead scoring are operational.
- Accepted candidates open in Prospect 360 with discovery results preserved.
- Sprint 1 sales workflow regression remains green: `ProductizedSalesJourneyTest` (3 tests / 151 assertions) and `AcquisitionWorkflowEndToEndTest` (2 tests / 85 assertions) passed.

Residual non-blocking items:

1. Candidate drawer at exactly 768px has not been visually verified.
2. The original failed candidate cannot be retroactively correlated to its exact exception because the earlier logs did not include candidate/correlation identifiers. Controlled replay and structured diagnostics are documented above.
3. Horizon was inactive during final verification. The PostgreSQL + Redis queued integration used a worker restricted to the `crawl` queue; production Horizon/process supervision remains to be established.
4. External search/directory discovery integration is not implemented. Current production-shaped intake supports supplied seed/domain and CSV/domain bulk intake; the source abstraction is ready for a future external provider.

Discovery, CSV import, and candidate acceptance all remain separate from campaign enrollment and sending. External discovery integration and production process supervision are follow-on capability/readiness work; they do not invalidate Sprint 2 pipeline acceptance.
