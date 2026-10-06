# Phase 2E — Proposal Agent and Controlled Proposal Generation

Status: **development implementation**. This is not a production-readiness claim. Live AI calls and S3-compatible storage remain unverified.

## Reused foundation

Phase 2E extends the existing `proposals`, `proposal_items`, `tenant_services`, `tenant_pricing_policies`, `sales_opportunities`, `opportunity_activities`, approved tenant marketing knowledge, prompt templates, `AgentOrchestrator`, and `AIModelRouter`. It does not create a second proposal catalogue or AI router. The proposal lifecycle is human controlled; `SalesAgent` may identify a proposal request, while this API requires an authorized human to create it.

## Responsibilities and boundaries

- `ProposalAgent` implements `AgentInterface`, is registered in the existing orchestrator, and uses the configurable `proposal_generation` AI task. Its tool list is empty. It sees only bounded tenant-scoped prospect, requirement, meeting, evidence, approved service, and approved knowledge context.
- AI supplies narrative, objectives, recommended services/scope, assumptions, exclusions, and source IDs. The app rejects unknown tenant service IDs, evidence IDs, knowledge IDs, case-study IDs, unsupported deliverables, generated prices/discounts, and unsupported date/duration commitments.
- Application policy controls eligibility, qualification overrides, currencies, catalog lookups, discount limits, integer-cent totals, required human-approved scope, approval, expiry, document generation, and `READY_TO_SEND` transitions.
- All dynamic proposal content is rendered as escaped plain text by `ProposalPdfRenderer`; no HTML templates, remote assets, shell execution, or AI-generated binary documents are used.

## Data and version history

Migration `2026_10_05_000022_create_proposal_generation_foundation` adds structured tenant service metadata, proposal requirements, proposal versions, commercial fields on proposal lines, human price override attribution, and encrypted internal notes. Migration `2026_10_05_000023_add_proposal_internal_controls` adds the tenant-composite latest-version constraint; migration `2026_10_05_000024_add_proposal_document_failure_state` adds safe document-failure state. Every version snapshots requirements, AI content, approved scope, commercial terms, prompt/provider metadata, grounding references, and generated-document hash. The encrypted source snapshot captures the bounded context used by the agent and is hidden from API serialization. Regeneration creates a new immutable version; approved content is not editable.

`tenant_services` is the tenant-owned service and price catalogue. Commercial models are allow-listed: `FIXED_PRICE`, `TIME_AND_MATERIAL`, `MONTHLY_RETAINER`, `MILESTONE_BASED`, and `CUSTOM`. Catalogue price and currency are human maintained; optional human price overrides and discounts require an owner/admin and record reason, actor, and timestamp. Tax defaults to zero and no tax calculation is implied. Money is parsed and calculated in integer cents; percentage discounts use integer basis points. No currency conversion occurs.

## Lifecycle and API

The development lifecycle includes `draft`, `commercial_input_required`, `review_required`, `approved`, `ready_to_send`, `rejected`, and `superseded`. Older `sent`, `viewed`, `accepted`, and `expired` values remain for stored-record compatibility. Phase 2E creates no sent/accepted transition. A historical proposal delivery job is retained only as a safe queue drain and cancels queued work; it no longer sends. Public accept/reject endpoints and proposal delivery endpoints are removed.

Tenant-authenticated routes cover request/requirements snapshots, list/detail/version reads, generate/regenerate, human content and scope edits, commercial inputs, approve/reject/supersede, document generate/download, and ready-to-send. Pricing and service maintenance is owner/admin-only. Opportunity-assigned users may request/edit drafts and access the related document. All reads and mutations are tenant scoped; composite database FKs protect proposal, version, opportunity, company/contact, prompt, agent-run, and service relations. Source references are validated against company/opportunity context.

`READY_TO_SEND` requires human approval and a generated PDF. The existing sales-stage policy must allow the move to `PROPOSAL_READY`; no opportunity is closed/won automatically. Activities and audit rows record request, generation/regeneration, scope and commercial edits, approval/rejection/superseding, PDF generation, and readiness. Internal notes are encrypted at rest and are never passed to the agent or PDF renderer.

## Document storage

PDF artifacts use Laravel's `local` storage disk by default, under a tenant/proposal/version-scoped key. The API returns only version ID, checksum, generation time, and an authorized download URL. Downloads require Sanctum, active tenant membership, and opportunity assignment or manager privileges. PDF generation is deterministic for a given approved snapshot and uses a content hash in its key. S3 runtime verification is not a Phase 2E blocker and has not been claimed.

## Verification and known limits

The deterministic fake-provider feature tests cover request, generation, retry idempotency, commercial math, scope review, approval, PDF render/download, readiness, authorization, tenant boundaries, and hallucinated service/case-study/commercial output. A safe fixture PDF is generated in the test storage disk. Live OpenAI/Anthropic/Gemini calls have not been verified.

The native text PDF renderer intentionally uses standard Helvetica and transliterates unsupported glyphs to ASCII; DOCX is not enabled. Branding currently uses the tenant name only. Tax, multiple currencies per proposal, conversion, richer rate cards, multiple recurring line schedules, and external proposal sending remain deferred. `READY_TO_SEND` is a human handoff state; no “send” action exists. This phase does not generate contracts, signatures, invoices, payments, or closed deals. Production review, storage policy, backup/retention, and deployment verification remain separate release work.
