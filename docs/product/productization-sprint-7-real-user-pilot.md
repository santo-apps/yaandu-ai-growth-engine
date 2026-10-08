# Productization Sprint 7 — Real-User Pilot

## Acceptance status

**SPRINT 7 ENGINEERING COMPLETE — REAL-PROSPECT PILOT PENDING**

The Sprint 7 implementation and simulated browser acceptance are complete. The local Vue UI journey used a deterministic fictional `.fixture.test` business and completed the sales workflow through an approved proposal in `READY_TO_SEND`. The persisted workflow and dashboard counts reconciled.

This is not evidence of a real-user or live-customer pilot. No real prospect data, live AI provider, live outbound delivery, live calendar, or production environment was exercised. Fake outbound and scheduling providers were used; no proposal delivery was attempted. Production readiness is not claimed.

### Accepted

- CSV intake, row validation, duplicate handling, idempotent import processing, and import provenance.
- Simulated website intelligence and lead scoring for explicitly fictional fixture records.
- Marketing drafts and human approvals.
- Fake outbound and fake inbound replies.
- Qualification enforcement, including correct rejection of Business 03 at 40/100 and acceptance of Business 01 at 65/100 against the unchanged threshold of 60.
- Fake meeting booking and proposal generation, PDF generation, human proposal approval, and `READY_TO_SEND` state.
- Dashboard reconciliation, including a conversion denominator that cannot produce a rate above 100% from the tested workflow counts.

### Not accepted or verified

- A supervised real-prospect pilot or genuine prospect discovery coverage.
- Live AI-provider operation, live outbound delivery, or live calendar integration.
- Production deployment, production queue isolation, backups/retention, monitoring/security review, or S3 runtime operation.

## Reused product modules

Sprint 7 builds on the existing tenant-scoped company and website models, contact methods, website scan/intelligence services, lead scoring, marketing drafts and approvals, campaign execution, fake outbound and inbound providers, inbox classification, sales qualification, opportunities, fake meeting scheduling, proposals, approval controls, and Prospect 360 workspace. It does not replace those modules or change their provider architecture.

## New pilot implementation

- Added tenant-scoped pilot cohorts and prospect import batches/rows in `2026_10_12_000001_add_pilot_imports_and_cohorts`.
- Added a bounded CSV preview and import service. It validates required business fields, ISO country codes, contact value formats, safe website URLs, upload size, row limits, file duplicates, and tenant duplicates. Invalid and duplicate rows remain visible with row-level reasons.
- Added explicit manager-only cohort creation, tenant-scoped batch and dashboard endpoints, confirmation/retry endpoints, and a spreadsheet-safe error report.
- Confirmation queues only valid, nonduplicate rows. The `intake` queue is now served by the configured Horizon supervisors. The job is tenant-scoped and replay-safe; imported website identity remains unverified.
- Imported contact methods are encrypted using the existing contact-value service. The import row stores its payload encrypted, with source and import evidence kept separately.
- Prospect 360 now exposes safe import provenance: batch/file/row, source, original submitted URL, submitting user where available, receipt/processing timestamps, and cohort. The UI explicitly marks an imported website as unverified.
- Added a cohort pilot dashboard with funnel counts, rates with named denominators, processing duration, fake/sandbox indicators, and KPI definitions.
- Added a manager-only, read-only operational dashboard. It shows Redis/Horizon state, installation-wide queue backlog and failed-job counts, tenant-scoped workflow failure metrics, and safe recent workflow event labels. It does not expose job payloads, provider errors, credentials, or message content, and offers no retry or send controls.
- Added a local/testing-only crawler fixture for explicitly marked `.fixture.test` hosts. It returns static synthetic website content without network access. Screens and records identify it as simulated and state that the findings are not live measurements.
- Added a local-only fixture command gated by both `APP_ENV=local/testing` and `PILOT_ALLOW_SIMULATED_FIXTURES=true`.

## Import and provenance schema

`pilot_cohorts` belongs to a tenant. `prospect_import_batches` belongs to a tenant and optionally a cohort. `prospect_import_rows` belongs to a tenant and batch, and may link to a company once imported. Composite tenant foreign keys protect the cohort, batch, and company associations. The batch records file name/hash, creator, idempotency key, confirmation time, state, and aggregate counts. Each row records the row number, encrypted submitted payload, business name and submitted URL for review, normalized domain, source, validation/deduplication/import states, errors, linked company, and processing timestamps.

Email and phone values are stored through the existing encrypted `contact_methods` mechanism and are never included in the public company import-provenance response. Website values supplied by a user are treated as untrusted input, validated against private/reserved destinations, stored as unverified, and are not proof of company identity.

## Workflow and human controls

1. An active tenant member previews a bounded CSV upload.
2. The preview reports valid, invalid, and duplicate rows before any company records are created.
3. An authorized tenant manager creates/activates the pilot cohort. A user confirms the import using an idempotency key.
4. Valid, nonduplicate rows are queued on `intake`; jobs create tenant-scoped company records and optional encrypted contact methods.
5. Website verification, scanning, scoring, marketing approval, send approval, and all subsequent sales actions remain separate existing workflow steps with their existing controls.
6. The dashboard reports cohort activity and makes simulated/fake activity visible.

CSV confirmation does not trigger website crawling, AI inference, campaign enrollment, outbound messages, meetings, or proposals. Automated outreach remains zero unless a salesperson separately performs the existing approval-gated workflow. The fixture command itself creates no outbound messages, meetings, or proposals.

## Verification performed

Final regression results are recorded in **Sprint 7A qualification path, queue isolation, and final acceptance** below. Commands must be rerun for this closure commit; the final report records their observed results. The PostgreSQL/Redis integration suite runs separately from the fast SQLite PHPUnit suite.

## KPI definitions

The dashboard returns explicit numerator/denominator definitions. In particular:

- Import success rate = imported rows / valid, nonduplicate rows.
- Duplicate rate = duplicate rows / all submitted rows.
- Website analysis completion = completed/partial scans / scans created.
- Scoring coverage = distinct scored companies / imported companies.
- Reply rate = distinct cohort companies with an inbound message / distinct cohort companies with a sent outbound message.
- Qualification rate = distinct companies with a qualified opportunity / distinct replied companies.
- Opportunity conversion = distinct cohort companies with an opportunity / distinct cohort companies with an inbound reply.
- Meeting booking rate = opportunities with a scheduled meeting / opportunities.
- Proposal generation rate = opportunities with a proposal / opportunities.
- Failed job rate = failed valid/new import rows / valid/new rows reaching a completed or failed terminal state.

A zero denominator returns `null`, not zero. Dashboard values are operational cohort measurements, not product effectiveness claims or service-level guarantees.

## Local simulated fixture

`php artisan product:pilot-seed-simulated` is available only when both local/testing environment and `PILOT_ALLOW_SIMULATED_FIXTURES=true` are set. It resets only its specifically marked `sprint-7-simulated-pilot` tenant, then imports 25 explicitly labeled fictional companies using `.fixture.test` domains across India and UAE. It also creates one duplicate and two invalid rows. Three sample contacts are synthetic. Fixture sign-in is `sprint-7-pilot@example.test`; each reset generates a random local-only password, displayed once by the command, and no static password is stored in source. The fixture is not a source of actual prospects and must never be deployed with fixture mode enabled.

## Acceptance evidence and limitations

| Area | Evidence | Status |
| --- | --- | --- |
| Cohort setup and CSV intake | Feature tests and local UI preview | Implemented and tested |
| Row validation, duplicate handling, tenant scope | Feature tests | Implemented and tested |
| Import job idempotency and encrypted contact values | Feature tests and PostgreSQL/Redis integration suite | Implemented and verified locally |
| Queue deployment wiring | `intake` added to Horizon supervisors | Configured and locally verified; production Horizon process not verified |
| KPI reporting | Feature assertions and UI dashboard | Implemented; reported values require real operating data |
| Imported prospect provenance | API response and Prospect 360 UI | Added; focused feature test passes |
| Full simulated HTTP sales journey | Deterministic test includes imported CSV record and existing fake-provider journey | Proven in automated test |
| UI import → website analysis → lead score | Demonstrated with a local simulated prospect | Passed in browser; not real website evidence |
| Full simulated UI sales journey | Browser-visible steps from marketing through approved proposal `READY_TO_SEND` | Passed, simulated only |
| Supervised real-user pilot | No real user/customer cohort exercised | Outstanding acceptance item |
| Live outbound, calendar, and AI services | Not exercised; fake/sandbox paths only | Not accepted as live capability |
| Real prospect discovery | Depends on separately unvalidated Sprint 6 retrieval capability | Not claimed or accepted |

## Operational limitations and production gaps

- Operational dashboard counts for Redis queues and Laravel failed jobs are installation-wide; other displayed failure summaries and events are tenant-scoped. Horizon status is observed from the local Redis runtime and does not prove production worker deployment.
- The local reset fixture and browser journey use synthetic businesses and `.fixture.test` domains; they do not measure real market or website outcomes.
- No cohort of 50–100 real known-domain prospects has been legitimately sourced, imported, and processed. Stage C remains unexecuted; no prospect or contact data was fabricated to fill it.
- A supervised real-user pilot remains outstanding; this acceptance was simulated.
- The full browser journey evidence exists locally at `/tmp/yaandu-sprint-7a-evidence/` (screenshots and JSON measurements) and is intentionally excluded from source control because it is generated browser evidence, not source. Key artifacts include `marketing-current.png`, `inbox-1440.png`, `pipeline-1440.png`, `meetings-1440.png`, `proposals-1440.png`, and `results.json`.
- Local Horizon and integration behavior are not proof of production queue isolation or target-environment process management.
- Exercise import throughput, queue backlog, worker restart, failed-row retry, and retention behavior under representative pilot load.
- Complete production secrets, `APP_DEBUG=false`, backup/restore, retention, monitoring/alerts, production security review, and deployment verification.
- Deploy isolated Playwright capture infrastructure before enabling browser capture in production.
- Verify S3-compatible storage and live AI providers only when explicit credentials and a controlled smoke-test environment are available.
- Use approved real business data only after the organization confirms its source, permission, retention, suppression, and outreach policies.

No claim is made here that Sprint 6 open-web retrieval has useful coverage, that the imported domains are the official websites, or that the platform is production-ready.

## Final verification results

The closure run results and source-control status are added as part of this Sprint 7 closure. The PG/Redis suite is run with Horizon intentionally stopped; separate local dashboard evidence demonstrated Horizon and the `intake` queue worker running. No Stage C real-data claim is made.

## Sprint 7A blocker-resolution UI continuation — 2026-10-08

This section supersedes the earlier statement that the browser journey stopped at marketing draft generation. It records the actual continuation; it does not change the overall **PRODUCTIZATION SPRINT 7 PARTIALLY COMPLETE** status.

- **Marketing draft root cause:** the pilot tenant lacked an active approved `MarketingAgent` prompt and its own `content_generation` override. The attempted run returned the generic safe `AGENT_EXECUTION_FAILED` response; tenant configuration and agent prerequisites were inspected, then deterministic content configuration and an approved prompt were saved through the local UI. The deterministic adapter was also changed to use the selected company identity and available evidence instead of fixture-specific names and unsupported claims. The MarketingAgent draft was generated, edited, and approved in the UI. Approval did not send it.
- **Campaign and sandbox send:** a fresh immediate-step campaign used that approved template. Human send approval was completed in the UI; the fake provider ledger recorded the message as sent. The local acceptance helper visibly identified deterministic/fake providers; the signed fake status webhook recorded delivery. No external email was sent.
- **Inbox and reply:** the fictional contact's fake inbound response was recorded through the signed fake webhook and appeared in the Inbox UI. A confirmed import defect had left encrypted imported contact methods without `value_hash`, so inbound matching acknowledged but dropped the reply. The import job now persists the existing canonical contact fingerprint, and an idempotent migration backfills older imported methods. The pilot tenant migration was applied. The retry produced an inbound event and message in the conversation.
- **Follow-up analysis:** its first queued run failed because this tenant lacked an active approved `FollowUpAgent` prompt. A bounded prompt was created and approved through the UI; the Inbox “Analyze latest conversation” action then succeeded and visibly classified the fictional reply as interested, with a human-review recommendation. No follow-up message was approved or sent.
- **Sales qualification:** the UI's “Analyze sales reply” action initially failed because the tenant also lacked an active approved `SalesAgent` prompt. The existing prompt API accepted that agent key, but the prompt-management selector exposed only MarketingAgent and FollowUpAgent. The selector now exposes the already-supported SalesAgent and ProposalAgent options. Both prompts were configured/activated through the UI. A retried SalesAgent analysis succeeded and persisted `INTERESTED`, medium risk, and **40/100 DEVELOPING** qualification. Unknown fit, budget, and authority stayed unknown; the UI asked who else is involved and whether a budget is allocated.
- **Qualification gate and stop point:** the UI “Mark qualified” action was exercised and rejected with `Qualification evidence must meet the configured threshold.` The tenant's configured qualification threshold is 60; the actual score is 40, and the fixture company has no verified website evidence (`.fixture.test`, unverified). This is the intended policy gate, not a defect that should be bypassed. The run stopped here. A `NEW` opportunity record was created by sales analysis, but it was not falsely marked qualified. Meeting and proposal UI steps were not attempted. There is therefore no end-to-end UI journey through opportunity qualification, meeting, or proposal, and no Stage B acceptance.
- **Dashboard reconciliation:** the first Home dashboard refresh showed one cohort “qualified lead” although this prospect remained `NEW` at 40/100 and the global 70+ score card showed zero. Inspection found the cohort query counted any non-null qualification JSON snapshot, including unqualified opportunities. The KPI and funnel now count `qualified_at` only, and a regression verifies an under-threshold `NEW` opportunity does not count as qualified while remaining counted as an opportunity. The Home headline uses a separate stated 70+ lead-score threshold.
- **Overall safety:** activity used only clearly marked fictional `.fixture.test` records, local deterministic AI, the fake outbound provider, and the fake scheduling provider. No live provider request, real prospect outcome, actual calendar booking, or real email delivery is claimed.

### Continuation acceptance matrix

| Step | Browser/UI evidence | Result |
| --- | --- | --- |
| Marketing draft → edit → approve | Marketing workspace; approved draft remains unsent | Passed |
| Campaign → human send approval → fake sent | Campaign and approval UI; fake provider ledger says `sent` | Passed, simulated only |
| Fake reply → Inbox | Local acceptance panel and Inbox timeline show inbound fixture response | Passed, simulated only |
| FollowUpAgent classification | Inbox analysis shows interested and review-required recommendation | Passed after tenant prompt setup |
| SalesAgent qualification | Inbox shows `INTERESTED`, medium risk, `40/100 DEVELOPING` | Analysis passed; qualified-stage transition correctly blocked below threshold 60 |
| Qualified opportunity → meeting → proposal | Not reached after qualification gate | Not demonstrated |

The browser journey is incomplete. Continue only with genuine, policy-eligible evidence for the fictional pilot record or an acceptance fixture whose approved evidence supports the configured threshold; do not lower the qualification threshold or manufacture evidence to make this report pass. Stage B remains **NOT ACCEPTED**.

### Continuation regression results

- Focused marketing, CSV import, sales journey, outbound, campaign lifecycle, and tenant-role tests: **43 passed / 506 assertions**.
- Full PHPUnit suite: **223 passed / 1,648 assertions**.
- PostgreSQL/Redis integration: run with explicit `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, and `YAANDU_POSTGRES_REDIS_INTEGRATION=true`, bypassing the SQLite-only `phpunit.xml` defaults. **2 passed / 1 failed (51 assertions)** on two clean-Horizon attempts. The remaining failure is `test_authenticated_discovery_run_is_serialized_to_redis_and_verified_in_postgres`, where one delayed `crawl` job remains at the final queue-empty assertion. Other integration tests passed. The delayed job was not deleted; local Horizon was restored and the queue subsequently drained.
- TypeScript check: **passed**. Vite production build: **passed**. Composer validation: **passed**. PHP syntax: **344 files passed**. `git diff --check`: **passed**.
- Horizon after integration: **running**. Queue depths were then zero for `crawl`, `discovery`, `candidate-discovery`, `intake`, and `conversations`.
- No lint script is configured in `package.json`; no separate frontend lint was run.

## Sprint 7A qualification path, queue isolation, and final acceptance — 2026-10-08

This is the current final simulated-acceptance record and supersedes earlier statements that the UI path stopped at the 40/100 prospect or that proposal workflow had not been exercised. It does not accept real-prospect Stage C or claim production readiness.

### Preserved negative case

- **SIMULATED · Business 03** remains at **40/100**, below its configured threshold of **60**. Its fit evidence remains unknown, and the lead context panel reports no website evidence.
- The actual Inbox UI “Mark qualified” action was attempted. The server rejected it with `Qualification evidence must meet the configured threshold.` The opportunity remained `NEW`; no meeting or proposal was added by the rejection.
- Regression coverage now verifies the 40/100 API transition returns HTTP 409, leaves `qualified_at` null and stage `NEW`, and creates no meeting or proposal. The pipeline/dashboard continue to show it as an unqualified opportunity.

### Separate qualifying simulated journey

The independently simulated prospect is **SIMULATED · Business 01**, using only fictional `.fixture.test` website and contact data. Website findings are labeled simulated and are not live measurements. No real domain, real contact, or fabricated real-world measurement was used.

SalesAgent grounded the qualification in the inbound request and simulated website evidence:

| Dimension | Assessment | Points |
| --- | --- | ---: |
| Need | Strong, from the inbound modernization request | 25 |
| Fit | Strong, from the simulated website evidence | 25 |
| Timeline | Strong, from “next week” in the inbound message | 15 |
| Budget | Unknown | 0 |
| Authority | Unknown | 0 |
| **Total** | **Configured threshold: 60** | **65/100** |

The independently displayed lead-score model remains **35/100**; that is a different score from SalesAgent qualification. The threshold was not lowered and no qualification field was directly edited.

### Browser journey results

Every step below was performed through the local Vue UI using the same simulated Business 01 record. Browser-visible state and persisted UI summaries were observed; no API or database mutation substituted for UI actions.

| Stage | Result and evidence |
| --- | --- |
| Prospect intake | Existing fictional simulated pilot record selected in Prospects. |
| Website intelligence | UI scan completed using local fixture content; visible label `SIMULATED FIXTURE · NO LIVE MEASUREMENTS`; findings were older storefront layout and difficult mobile navigation. |
| Lead scoring | UI scoring completed; 35/100, distinct from sales qualification. |
| Marketing | Deterministic MarketingAgent draft created, edited, then human-approved in Outreach. |
| Outbound | Campaign step and send were explicitly approved; fake outbound ledger showed `sent`. No external email was sent. |
| Reply and Inbox | Local acceptance control submitted a fictional inbound response through the fake webhook. Inbox showed the sent and received messages in the correct Business 01 conversation. |
| Follow-up and sales | UI analysis classified the explicit request as `MEETING REQUEST`; SalesAgent showed 65/100, with budget and authority unknown. The human approved the meeting recommendation. |
| Opportunity | UI transition marked Business 01 qualified; the accepted meeting advanced it to `MEETING READY`. |
| Meeting | Fake provider availability was offered in `Asia/Kolkata`; the salesperson selected a slot. UI recorded a scheduled fake meeting for **2026-10-09 10:00 Asia/Kolkata**. No calendar invite was sent. |
| Proposal | Requirements snapshot and a human-approved INR 150,000 service catalog entry were created in the UI. Draft v1 exposed an unrelated company-name contamination and was not approved. After the fix, v2 used `SIMULATED · Business 01`, catalog scope/pricing was saved, a human approved it, PDF generation succeeded, and UI state reached **Ready to send**. |
| Proposal delivery | Not attempted. Proposal-delivery records remain zero. `Ready to send` is preparation state, not a send. |

The initial deterministic run also classified “Can we book a meeting next week?” as generic interest. The deterministic provider now recognizes explicit meeting requests while keeping approval required. The meeting planner had compared intent casing exactly; its UI condition now handles the `MEETING_REQUEST` value case-insensitively. The resulting UI showed the meeting planner and required explicit human approval and slot selection.

Proposal inspection found the deterministic proposal provider hardcoded “Northstar Retail Systems” in every proposal. It now uses the company name supplied in prospect context, with a regression proving no Northstar content leaks into an Aster Medical fixture proposal. The existing incorrect v1 remained unapproved; regenerated v2 was reviewed and approved.

### Dashboard reconciliation and no-automation evidence

After the journey, the Pilot Operations dashboard reported **28 imported**, **2 sent through fake provider**, **2 replies**, **1 qualified lead**, **2 opportunities**, **1 meeting**, and **1 proposal**. These are tenant/cohort operational counts, including the preserved unqualified Business 03 opportunity; they are not commercial outcomes. The ordinary Home score card still says **0 leads with lead score ≥70**, because Business 01’s separate lead score is 35 and the negative prospect has no qualifying lead-score record.

The opportunity-conversion rate previously used qualified companies as denominator while including unqualified open opportunities as numerator, which could produce 200%. It now measures cohort companies with opportunities divided by cohort companies with inbound replies. A regression with two replied companies, two opportunities, and one qualified company verifies the rate is 100%, consistent with those displayed counts.

Safety observed in this browser run:

- Only the send with an explicit human approval was submitted to the fake provider; no unapproved or live outbound send was attempted.
- Fake outbound provider was shown in the local acceptance controls; the fake calendar was visibly identified as test mode and “no invite sent.”
- Proposal delivery remained unattempted and the delivery ledger remained empty.
- Business 03’s meeting recommendation remained pending; it was not approved, and its qualification rejection produced no new meeting/proposal.

### Tenant readiness

The owner-only tenant readiness endpoint and Setup UI report required AI task/provider routing, approved prompts for MarketingAgent, FollowUpAgent, SalesAgent, and ProposalAgent, fake outbound/scheduling availability, and queue/Horizon readiness. Missing prerequisites are returned as names/status only; provider secrets are not exposed and no prompts/providers are silently activated. `PilotReadinessTest` verifies manager authorization, active membership, cross-tenant denial, and absence of secret material.

### Delayed crawl job and Redis isolation

The earlier integration failure was a delayed `crawl` job created during the serialized discovery test. The test configured Redis and then asserted that a shared queue was globally empty. That namespace could also contain delayed work. During the first local browser attempt, a Horizon worker without `PILOT_ALLOW_SIMULATED_FIXTURES=true` tried to resolve a fictional `.fixture.test` robots URL; this is not permitted as a real external crawl. The deterministic fixture configuration is now explicit in the integration test. The integration harness also uses a fresh random Redis prefix for every test and purges only the named Redis connection before it is first used; it does not flush Redis or delete unrelated work. Horizon is stopped during this opt-in integration suite.

With the corrected configuration and per-test namespace, the previously failing discovery case passed twice consecutively (**19 assertions each**), then the complete PostgreSQL/Redis integration file passed (**3 tests / 54 assertions**). The evidence supports that the prior failure was caused by unsafe shared-queue test assumptions and fixture-mode mismatch; it does not prove production queue isolation.

### Final regression baseline

Closure regression rerun on **2026-10-08**:

- Focused Sprint 7 acceptance and safety suite: **27 tests / 391 assertions passed**.
- Full PHPUnit: **226 tests / 1,677 assertions passed**.
- PostgreSQL/Redis integration: **3 tests / 54 assertions passed** against local PostgreSQL and Redis, with Horizon stopped for the test and restored afterward.
- TypeScript: `npm run typecheck` **PASS**.
- Vite production build: `npm run build` **PASS**.
- PHP syntax sweep (`app`, `routes`, `database`, `tests`): **345 files passed**.
- Composer validation: `composer validate --no-check-publish` **PASS**.
- Pilot route registration: **11 routes registered**; every route is inside Sanctum authentication and tenant resolution.
- Horizon local health: **running** after integration test completion.
- `git diff --check`: **PASS** after documentation update.
- Frontend lint: **NOT CONFIGURED** in `package.json`.

### Sprint 7A conclusion

**Simulated UI acceptance passed** for the below-threshold rejection and the separate 65/100 qualified path through human-reviewed proposal `READY_TO_SEND`. Human approval boundaries held, the meeting used the fake provider, no proposal was delivered, and the dashboard counts reflect the persisted fake workflow. Local screenshots and measurements are under `/tmp/yaandu-sprint-7a-evidence/`; examples include `marketing-current.png`, `inbox-1440.png`, `pipeline-1440.png`, `meetings-1440.png`, `proposals-1440.png`, and `results.json`. They are generated local browser evidence and intentionally excluded from source control. Do not interpret the simulated UI pass as a supervised real-user pilot, Stage C real-prospect acceptance, genuine discovery coverage, live provider validation, or production readiness.

The application remains **not production-ready**. Production process management, isolated Playwright deployment, live provider smoke tests, production monitoring/alerts, retention, backup/restore, security review, deployment verification, and S3 runtime verification remain separate hardening work.
