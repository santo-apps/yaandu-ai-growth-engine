# Implementation Plan

## Phase 0: Architecture approval (completed)

The architecture set was reviewed before implementation was authorized. Deployment-specific privacy retention and external discovery-source approvals remain operational decisions.

## Phase 1: DEVELOPMENT COMPLETE / PRODUCTION HARDENING PENDING

1. **Repository bootstrap:** Laravel 12/PHP 8.3+ backend, Vue 3/TypeScript/Vite frontend, PostgreSQL, Redis/Horizon, environment configuration, coding conventions, and local development setup.
2. **Identity and tenancy:** tenant and membership schema, Sanctum authentication, tenant resolution, policies, scoped resources, audit foundation.
3. **Persistence:** migrations/models/factories for the approved schema, UUID strategy, tenant indexes, object-storage abstraction, retention metadata.
4. **Agent and AI contracts:** common agent lifecycle, run/event persistence, schema validation, queue dispatch, `AIProviderInterface`, three adapters, configurable `AIModelRouter`, safe fake implementations for local development.
5. **Crawler foundation:** URL/SSRF checks, robots and sitemap handling, bounded streaming body reads, page/depth/attempt/link/duration limits, distributed per-host serialization and rate checks, retries, same-domain restriction, and raw object storage are **IMPLEMENTED**. Playwright is disabled by default. Local fixture capture is functional; production browser isolation is **PENDING** deployment of a dedicated isolated worker.
6. **DiscoveryAgent:** bounded user-supplied seed intake, normalization, provenance, deduplication, and queued runs. An external discovery source adapter is intentionally not enabled; select and approve one before production-scale discovery.
7. **WebsiteIntelligenceAgent:** scan orchestration, page evidence handling, AI structured extraction, issue/technology/insight persistence, evidence provenance.
8. **LeadScoringAgent:** tenant scoring-rule configuration, deterministic evaluator, score history, explanations and coverage.
9. **API and dashboard:** listed Phase 1 API routes and nine views; tenant-aware company and intelligence workflows; agent run monitoring and editable approved AI configuration.
10. **Operational readiness:** Horizon queues, sanitized run summaries, tenant constraints, migrations, and local setup are **IMPLEMENTED**. Local Horizon is verified running against the configured Redis environment. Production process management, telemetry, backup/retention policy, browser-worker deployment, and cloud-storage runtime verification remain release work.

## Queue plan

Use Redis/Horizon with explicit queues: `discovery`, `crawl`, `intelligence`, `scoring`, and `default`. Crawl job timeout (360s), Horizon crawl supervisor timeout (390s), and Redis retry-after (420s) are aligned. Shared host locks and rate-limit rechecks serialize crawler requests across workers. Browser-worker network isolation is documented in a Cilium policy template but is **NOT DEPLOYED/NOT RUNTIME VERIFIED**. Persist runs before dispatch; jobs recheck tenant ownership and terminal state. Page bodies stay in object storage, not Redis payloads.

### Horizon process management

- **Local development:** Run `php artisan horizon` in a dedicated terminal when queue processing is needed. Check with `php artisan horizon:status`; stop gracefully with `php artisan horizon:terminate` (or Ctrl-C in the foreground terminal). The `queue:work --once` smoke check is separate and does not replace the long-running Horizon process. As checked for this closure, `horizon:status` reports **running** against the configured Redis environment.
- **Production:** Run Horizon as a continuously supervised process using the deployment's process manager (for example, systemd, Supervisor, or the container orchestrator). Start it only after Redis and the application are ready, restart it on failure, monitor process/queue health, and invoke `php artisan horizon:terminate` during deployment so the supervisor starts workers on the new release. A successful local status check does not verify production process management.

### Browser capture deployment gate

`CRAWLER_PLAYWRIGHT_ENABLED` remains **false** by default. Chromium must not run inside the normal Laravel worker in production. Browser capture may be enabled only after it is moved to a dedicated isolated worker with enforced network egress controls. `deploy/kubernetes/browser-worker-cilium-network-policy.yaml` is a deployment template only; its presence is not evidence that the policy or worker has been deployed or verified. Local Chromium fixture functionality is verified; production isolation is pending.

## Phase 1 verification baseline (2026-10-05)

**PHASE 1 DEVELOPMENT: COMPLETE.** The recorded development baseline is PHPUnit **67 tests / 333 assertions or higher**, PHP syntax checks passing, Vue TypeScript check and Vite production build passing, Laravel routes passing, and all 11 migrations applied. PostgreSQL 17 runtime connectivity and cross-tenant constraints are verified. Redis runtime is verified; `php artisan horizon:status` currently reports **running**. Safe crawler fixtures and tenant constraints are verified. Playwright is functional against local fixtures but remains disabled by default; production isolation is pending.

The PHPUnit suite intentionally uses fast in-memory SQLite. Preserve that suite. PostgreSQL coverage is a separate integration-test strategy: run an opt-in PostgreSQL suite against a dedicated disposable test database with `DB_CONNECTION=pgsql`, apply the test migrations there, and exercise PostgreSQL-specific constraints, foreign-key behavior, transactions, and tenant-scoped queries. Keep PostgreSQL integration setup and cleanup separate from the SQLite suite; never point destructive test setup at a shared, development, or production database. The current PostgreSQL baseline includes runtime/migration verification and rollback-only constraint checks; it does not claim the full PHPUnit suite runs on PostgreSQL.

S3 is **CONFIGURED / NOT RUNTIME VERIFIED**. The active local filesystem disk is `local`, and no S3-compatible runtime credentials were used. Live OpenAI, Anthropic, and Gemini provider calls are **NOT RUNTIME VERIFIED** absent explicit test credentials; adapters and fake-provider tests are sufficient for Phase 1 development closure. Neither status blocks local development closure.

## Phase 1 production hardening (pending)

**PHASE 1 PRODUCTION HARDENING: PENDING.** Complete and verify these deployment/release items before describing the system as production hardened:

- Isolated Playwright worker deployment and network-policy verification.
- S3-compatible storage runtime verification.
- Production Horizon process management and health monitoring.
- Live AI provider smoke tests using explicitly configured test credentials.
- Production `APP_DEBUG=false` verification.
- Backup and restore policy, with a restore exercise.
- Data retention policy and enforcement.
- Production monitoring and alerts.
- Deployment verification in the target environment.
- Production security review against the deployed configuration.

These are production-hardening gates, not Phase 1 development blockers. Phase 1 is not being described as production-ready.

## Phase 2A and Phase 2B development status

**Phase 2A Campaign + Outreach Foundation: COMPLETE.** Campaign lifecycle, sequence management, enrollment, suppression, outbound ledger, fake provider, delivery webhooks, and encrypted conversation capture remain owned by the deterministic campaign engine.

**Phase 2B AI Marketing + Follow-up Intelligence: DEVELOPMENT COMPLETE.** `MarketingAgent` creates encrypted, evidence-grounded drafts; only owner/admin approval can promote a selected campaign draft into an approved campaign template. `FollowUpAgent` is queued after inbound replies and stores one encrypted recommendation per inbound message. Confidence, high-risk intent, suppression, campaign/enrollment/delivery state, and sequence timing are handled by application policy. Recommendations are review-only; there is no autonomous send, negotiation, proposal, booking, or human handoff decision. Fake-provider coverage is verified; live AI-provider calls and production deployment hardening remain unverified.

Phase 2B added `MarketingAgent` and `FollowUpAgent` to the enabled registry. Phase 2C added the controlled `SalesAgent`; Phase 2E adds the controlled `ProposalAgent`. Autonomous negotiation/reply paths and deal closing remain deferred.

## Phase 2E — Proposal drafting and controlled handoff

**Phase 2E development implementation:** controlled proposal requests and structured requirements snapshots, tenant-catalogue service metadata, approved commercial inputs, deterministic cent-based line totals, a grounded `ProposalAgent`, immutable version snapshots, human-edited/approved scope, manager approval, deterministic PDF generation and authorized download, encrypted internal notes, and a policy-gated `READY_TO_SEND` handoff are implemented. Existing proposal tables/services and the shared agent/provider framework are reused. Proposal email delivery and public accept/reject endpoints are disabled; legacy queued delivery work is cancelled by the compatibility drain. See [phase-2e-proposals.md](phase-2e-proposals.md).

**Verification:** Phase 2E tests exercise the request → fake-agent draft → human scope/commercial review → approval → PDF → `READY_TO_SEND` path and negative security cases. PostgreSQL migrations through `2026_10_05_000024` are applied and status-verified. The local PDF renderer is verified with a safe fake-data fixture. Live AI provider calls and S3 runtime storage remain unverified. Production hardening and production deployment remain pending; Phase 2E is not described as production-ready.

## Phase 2F — Orchestrator and controlled autonomy

**Development implementation: PARTIAL.** Added a deterministic `B2B_ACQUISITION` workflow envelope, tenant-scoped workflow/event/approval/policy/usage tables, fixed action registry, stricter-of-system/mode/tenant policy evaluation, tenant workflow APIs and Vue workflow/approval/policy surfaces. `WorkflowTransitionMap` constrains known event/stage transitions; `ConfidencePolicy` provides shared deterministic checks to participating callers. `AcquisitionWorkflowCoordinator` resolves known events against workflow stop state and policy and can dispatch supported existing internal jobs with SQL idempotency keys, but it is not yet a durable consumer wired across domain event sources. Human handoff, campaign lifecycle, unsubscribe, hard bounce, complaint, suppression, enrollment stop, not-interested/wrong-contact, closed opportunity, and tenant suspension gates are bridged. First-touch and follow-up outbound workers require an executed matching human approval. Approval context exposes bounded safe business context without payload/ciphertext. Generic approvals revalidate immutable action snapshots and currently execute outbound/follow-up queueing and meeting-request creation; consequential actions remain human-only or without a reusable service executor. AI usage reservation and tenant call/token/spend guardrails are enforced at the shared router. Retryable agent failures store encrypted, hash-verified inputs and can requeue the same run within a bounded retry count. The requested complete fake journey test remains unimplemented.

**Verification:** Dedicated orchestration feature tests cover lifecycle, tenant boundaries, approvals, policy bounds, replay/loop limits, AI call budgets, agent linkage, safe retry input handling, terminal-event gates, coordinator action resolution/replay, and first-touch approval enforcement. Current local verification: PHPUnit 117 tests / 659 assertions passed; PostgreSQL migration status reports all 26 migrations through `2026_10_05_000026` applied; PHP syntax checks, 140-route registration, Vue TypeScript check, and Vite production build passed. Full cross-domain deterministic action dispatch and the requested end-to-end fake integration test are not implemented. Remaining Phase 2F gaps are enumerated in [phase-2f-orchestration.md](phase-2f-orchestration.md); this phase is not production-ready.

## Phase 2D — Meeting scheduling

**Phase 2D development implementation:** controlled, authenticated human-assisted scheduling is implemented using persisted request/offered-slot records, tenant rules, an allowlisted provider abstraction, and the fake provider. Booking requires an offered and selected slot and rechecks availability. Meeting creation feeds activity/audit/conversation records and may advance eligible opportunities to `MEETING_READY`. Dashboard metrics use tenant-scoped database counts. See [phase-2d-scheduling.md](phase-2d-scheduling.md).

**Verification:** 96 PHPUnit tests / 534 assertions pass; PHP syntax, Vue TypeScript compilation, Vite production build, and scheduling route registration pass. PostgreSQL migrations through `2026_10_05_000021` are applied and status-verified. The deterministic fake provider is covered by local tests. Google Calendar, Microsoft Outlook, Calendly-style integrations, real invites, and live event updates are not implemented or verified. Public slot selection and rescheduling are deferred. Production deployment/security hardening remains pending and Phase 2D is not represented as production-ready.

## Deferred

Do not add full autonomy, autonomous pricing or discounts, contract negotiation/acceptance, payment collection, invoice generation, unrestricted proposal sending, unrestricted meeting booking, arbitrary tools, dynamic agent-created tools, self-modifying prompts/policies, or WhatsApp/SMS/voice. Proposal drafting/handoff is controlled through the Phase 2E review boundary. Calendar booking remains human-assisted through Phase 2D. Phase 2F orchestration foundations are implemented partially; close the documented Phase 2F integration and stop-condition gaps before starting another phase.
# Phase 2C — Sales execution foundation

Status: **COMPLETE (development implementation and verification).** This does not claim production readiness or live AI-provider verification.

- Reuses the Phase 1 orchestrator/provider router, Phase 2B approved prompts/knowledge/evidence, conversation records, campaign stopper, and opportunity activity stream.
- Adds `SalesAgent`, bounded context, evidence-validated qualification, deterministic scoring/risk/stage policies, encrypted human-reviewed drafts, opportunity links, ownership transitions, tenant sales policy, and real pipeline metrics.
- Reply processing is chained FollowUpAgent → SalesAgent. AI does not send, negotiate, schedule, quote pricing/discount, or create proposals.
- Validation: PHPUnit 94 tests / 508 assertions; PHP syntax validation; frontend TypeScript check and Vite production build; Laravel route registration; PostgreSQL migrations through `2026_10_05_000018` applied and status verified.
