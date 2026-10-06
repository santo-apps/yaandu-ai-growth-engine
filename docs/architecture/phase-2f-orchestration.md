# Phase 2F — Orchestration, Controlled Autonomy, and Approval Policies

## Implementation status

Phase 2F provides a deterministic workflow envelope around existing domain services. `AcquisitionWorkflowCoordinator` now resolves known events to application-owned candidate actions, checks tenant-scoped workflow stop state and policy, and uses idempotent database agent-run keys for supported internal dispatch. It remains an explicit service and is not yet wired as a durable consumer across every domain event. The existing `AgentOrchestrator` validates agent contracts and records `agent_runs` / `agent_events`; domain jobs continue to dispatch discovery, website scans, campaign steps, replies, sales analysis, scheduling, and proposal work. A complete cross-domain action consumer and the full acquisition-journey integration test are still missing. Phase 2F remains **partially complete**.

## Architecture

`B2B_ACQUISITION` is a persisted workflow envelope around existing domain services. Domain jobs and authenticated actions append idempotent workflow events using tenant-scoped IDs and correlation IDs. `WorkflowTransitionMap` defines allowed forward transitions and maps known domain events to stages; model output cannot select a stage or action. This map validates and records progress, but does not itself dispatch all subsequent agents/actions. Workflow controls (pause, resume, cancel, retry) are tenant scoped and require an active member; autonomy configuration and approval decisions require an owner/admin. Discovery completion creates one workflow per discovered company; other agent runs attach when their bounded input identifies a company/conversation and a workflow exists.

Stages: `DISCOVERY`, `WEBSITE_INTELLIGENCE`, `LEAD_SCORING`, `OUTREACH_PREPARATION`, `OUTREACH`, `REPLY_ANALYSIS`, `SALES_QUALIFICATION`, `OPPORTUNITY`, `MEETING`, `PROPOSAL`, `HUMAN_HANDOFF`, `COMPLETE`.

Workflow statuses: `PENDING`, `RUNNING`, `WAITING_APPROVAL`, `WAITING_EXTERNAL`, `PAUSED`, `COMPLETED`, `FAILED`, `CANCELLED`.

## Policy and action boundaries

The action registry is application code only. It declares side-effect/risk, permission, default decision, and idempotency. Modes are `MANUAL`, `ASSISTED`, `CONTROLLED`; `FULL_AUTONOMY` is unsupported. Tenant policy may only tighten a system default. Website analysis, scoring, draft generation, reply analysis, and qualification are internal actions. Outreach/follow-up sending requires approval; booking, proposal approval/sending, pricing, discounts, and deal closure remain human-only. Actions requested by model/prospect content are never dispatched directly.

First-touch and follow-up campaign messages now both create a `SEND_OUTREACH` / `SEND_FOLLOW_UP` approval against the queued message. `SendOutboundMessage` checks for an `EXECUTED` matching approval before provider access, so direct job dispatch alone cannot send.

`ConfidencePolicy` centralizes deterministic confidence, evidence-count, risk, intent, and qualification checks for callers that use it. It is not yet a universal gate around every legacy agent/controller entry point; existing follow-up behavior also applies its domain-specific policy.

Approval records bind tenant, workflow, action, target, risk, reason, immutable JSON payload snapshot/hash, requester, expiry, and reviewer. An approval decision alone does not call an external provider. Execution must go through a registered deterministic handler and revalidate current domain state. Deterministic approval executors exist for queued outbound/follow-up messages and meeting-request creation; message sending still uses the existing outbound job/provider boundary, and meeting booking is human controlled. Other actions remain non-executable through generic approval until a deterministic handler is implemented.

## Human handoff and stop conditions

Human ownership pauses workflow agent execution while allowing domain events to be recorded. Pricing, discount, legal, low-confidence, explicit human-request, and policy-required cases remain review/handoff states. Workflow stop reflection is bridged for unsubscribe, hard bounce, complaint, not-interested/wrong-contact outcomes, manual suppression, campaign cancellation, enrollment stop, closed opportunity, manual workflow cancellation, and suspended-tenant detection when an agent is attempted. These terminal gates prevent workflow resume; existing domain suppression and send gates remain authoritative. This does not establish that every possible external event source has been integrated.

## Recovery, replay, and loop protection

Workflow events have tenant-unique idempotency keys; transitions run transactionally. Workflows have a maximum step count, per-stage agent run cap, and retry cap. A breached limit pauses the workflow and emits a human-review event. Provider-unavailable and timeout failures are marked retryable; other agent failures are terminal. Retry reuses the same run ID and a hash-verified encrypted input snapshot, then dispatches the existing agent job after commit. Resume does not rerun completed stages and is blocked at the workflow step ceiling. Approval decisions are row-locked and replay-safe; payload-hash mismatch, expiry, or wrong-tenant target rejects execution.

## AI usage and budgets

AI provider responses already expose optional input/output tokens. Usage rows associate tenant, agent, task, provider/model, agent run, workflow, and timestamp. Provider-reported cost is not fabricated; estimated spend remains null until explicit model pricing metadata is configured. Tenant daily-call/token and monthly estimated-spend limits are optional. Budget enforcement reserves calls/tokens before provider use and pauses the linked workflow when configured caps are reached. A single call can overshoot token/spend limits when actual usage exceeds the reservation or pricing is not configured; subsequent work fails closed. Direct legacy agent endpoints are not yet centrally budget-enforced.

## Observability and security

Workflow timeline APIs combine workflow events and linked agent runs/events. Metadata is allowlisted, bounded, and excludes prompts, raw provider responses, message bodies, credentials, and unrestricted payloads. Failed run input is encrypted for bounded retry and excluded from model serialization. All resource queries include tenant scope; migration-level composite foreign keys bind workflow, company/contact/conversation/opportunity, event, approval, and usage rows to a single tenant. Approval and policy changes are audited.

## Remaining gaps and limitations

This is a deterministic orchestration foundation, not a generic BPM engine or production-ready autonomous sales system.

- There is no `AcquisitionWorkflowCoordinator`/event consumer that deterministically dispatches each permitted next agent or domain action across the complete lifecycle.
- The coordinator currently dispatches only existing internal-agent jobs and the controlled meeting-request approval path. The marketing-draft and proposal endpoints still combine authenticated request handling, agent execution, and domain persistence; there is no reusable service boundary the coordinator can call to create those records without duplicating controller business logic. Cross-domain triggers are not yet consistently wired into the coordinator.
- There is no executed end-to-end fake journey test covering discovery → website intelligence → lead score → marketing draft/review → campaign delivery → inbound reply → follow-up/sales qualification → opportunity → meeting → proposal review boundary. Component and alternative-path tests do not prove this journey.
- Campaign enrollment approval is not generically executable; first-message approval is represented by authorized campaign activation, while follow-up approval is enforced per outbound message.
- Approval context includes bounded, safe company/contact/workflow context, confidence/evidence references, and qualification details without exposing ciphertext or payload snapshots. Proposal approval remains a human boundary; proposal sending is not enabled.
- Workflow and domain event bridges exist for documented events, but the event map is not a complete dispatch graph and independently callable legacy services can still bypass some orchestration policy checks.
- Live provider spend/pricing are unverified. Production deployment, isolated browser worker, queue topology/process management, monitoring, storage verification, backups/retention, and live-provider smoke testing remain production-hardening work.
