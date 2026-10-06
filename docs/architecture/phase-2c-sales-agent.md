# Phase 2C — AI Sales, Qualification, and Human Handoff

## Boundaries

`SalesAgent` is registered with the existing `AgentOrchestrator` and uses `AIModelRouter` task `sales_reasoning`. It analyzes bounded tenant-scoped conversation context, extracts evidence-backed qualification, and may create an encrypted human-review draft. It has no tools and no message-send, pricing, discount, calendar, proposal, shell, or arbitrary browsing capability. The provider output is data only: application policy owns permissions, transitions, scoring, and handoff.

Phase 2A campaign controls and Phase 2B marketing/follow-up flows remain in place. The inbound reply job runs `FollowUpAgent` first, then `SalesAgent`; both produce reviewable intelligence and neither sends. If either execution fails, the queue retry policy applies. A human-owned or resolved conversation cannot be analyzed automatically.

## Qualification and policies

Qualification dimensions are NEED, FIT, AUTHORITY, TIMELINE, and BUDGET with levels UNKNOWN, WEAK, MODERATE, and STRONG. Non-UNKNOWN levels require a supplied conversation/evidence reference; invented or cross-scope references fail. The deterministic `QualificationScorer` applies tenant-configurable weights (defaults 25/25/20/15/15), normalizes to 0–100, and classifies LOW, DEVELOPING, QUALIFIED, or HIGH_PRIORITY using tenant-configurable thresholds (defaults 40/60/80). The model never supplies the final score.

`SalesPolicy` validates stage transitions and maps intents to risk. Medium and high risk, low confidence, explicit person requests, sensitive legal/contract content, unsubscribe/decline intents, and missing case-study proof require handoff. The conversation's ownership state and status move to human review under application control. AI output cannot move an opportunity stage. Stage and qualification changes use authorized APIs and create opportunity activity records.

Pricing and discounts are never generated in Phase 2C. A pricing/discount intent produces no sales draft and requires human review even if approved pricing knowledge exists. Meeting and proposal requests also produce no draft. No approval action in this module dispatches a message. Approved case-study/proof claims require a valid approved knowledge reference.

## Data and APIs

- `tenant_sales_policies` stores tenant weights and thresholds.
- `sales_opportunities` is extended with tenant-constrained contact/conversation links, qualification score/level, source, and qualified/closed timestamps.
- `conversations` gains explicit ownership and sales stage fields plus an optional opportunity link.
- `sales_drafts` stores encrypted response text, evidence and approved-knowledge references, model/prompt/run provenance, idempotency, and review state.
- `sales_analyses` persists each structured analysis (including high-risk/no-draft outcomes) with its source inbound message and idempotency key, so retries return the same result without rerunning inference.
- `opportunity_activities` gains agent-run and correlation provenance and tenant-composite opportunity constraints.

The API includes sales analysis, sales draft listing/review/regeneration, opportunity detail and stage/value management, qualification update, handoff queue, return-to-AI assistance, pipeline counts, and sales policy configuration. High-value handoff may be configured by explicit minimum value and currency; estimates can only be entered by a tenant manager. All records are tenant-scoped; writes use assignment or manager checks, and policy changes require an owner/admin. Human takeover continues to stop campaign enrollment through the existing deterministic campaign stopper.

## Context and failure handling

The context builder caps history to 16 messages at 1,500 characters each, lead evidence to 12 items, and approved knowledge to 20 items. Prospect text is always untrusted. No cross-tenant evidence or unapproved knowledge enters the request. Agent runs and events retain provider/model, prompt version, and correlation provenance. The outbound draft is encrypted at rest. Failed runs are safe to retry with the same idempotency key; delivery is never part of analysis or approval.

## Explicitly deferred

No autonomous sending, negotiation, dynamic pricing/discounts, contract handling, proposal creation, calendar booking, WhatsApp, SMS, voice, or deal closing is included. Approved sales drafts are not delivered by this module. Production provider smoke tests and deployment hardening remain separate from fake-provider development tests.
