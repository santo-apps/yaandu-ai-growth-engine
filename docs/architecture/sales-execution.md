# Sales Execution Architecture

## Phase 2 delivery order

Phase 2 is delivered as verified increments. Campaign foundation, tenant-scoped APIs and dashboard, provider abstraction, queued sequence execution, conversation inbox/classification/handoff, fake scheduling, proposal foundation/dashboard, and an approval-gated decision ledger are implemented. Outbound execution and scheduling remain disabled until a tenant owner/admin explicitly enables their configurations.

## Campaign persistence mapping

The Phase 1 schema already contains `campaigns`, `campaign_steps`, `campaign_recipients`, `campaign_events`, and `suppression_lists`. Phase 2 extends those tables rather than duplicating them:

| Concept | Persistence |
|---|---|
| Campaign lifecycle/objective/targeting/timezone/windows/limits | `campaigns` |
| Audience criteria | `campaign_audiences` (one audience configuration per campaign) |
| Reusable or campaign-specific message content | `campaign_templates` |
| Ordered sequence and delay | `campaign_steps` |
| Lead/contact enrollment | `campaign_recipients` via `CampaignEnrollment` |
| Campaign lifecycle events | `campaign_events`, with sensitive content excluded from metadata |
| Outbound message content and delivery state | `outbound_messages`; subject/body are encrypted at rest and hidden from model serialization |
| Provider delivery callbacks | `outbound_message_events`, tenant scoped, signature checked, timestamp bounded, and replay safe |
| Tenant contact suppression/unsubscribe | `suppression_lists` with keyed identifier hashes |
| Tenant provider and sender/rate configuration | `tenant_messaging_configurations`; credentials are external secret references |

Campaign and enrollment states are backed by PHP enums and persisted as strings. Composite foreign keys bind campaign, audience, step, enrollment, event, company, contact, and contact-method relationships to the same tenant. Enrollment idempotency is enforced by tenant-scoped keys and a campaign/contact unique constraint.

## Outbound and automation safety

No HTTP request sends an email. The provider interface, fake provider, queued send path, persisted outbound message idempotency boundary, and signed provider event processing are implemented. The fake provider is the only enabled adapter; no real vendor credentials are needed or accepted by the dashboard. The send path rechecks campaign state, enrollment state, suppression, sending window, timezone, and tenant/campaign limits before send. A signed fake inbound webhook validates tenant, provider signature, event timestamp, and replay key; recognized replies are encrypted into the conversation, stop active sequences, and enqueue tenant-scoped conversation analysis. This is a development/test integration and is not connected to an external mailbox.

Conversation intent classification uses the existing tenant-aware AI router and a structured schema. Website evidence and conversation text are passed as untrusted data. Unknown evidence references are discarded. Confidence below the configured threshold, pricing requests, and legal, complaint, sensitive, or explicit human-request risks route to `human_review`. The inbox supports encrypted AI reply drafts, human editing, and approval-gated queued sends. Replies are sent only after a human takes ownership and approves; the current queue job is constrained to the fake provider. Accepted campaign emails and inbound replies create encrypted conversation timeline entries. Human takeover and resolution are tenant authorized and audited, and sequence jobs stop for contacts with human-owned conversations. Existing legacy `body` values are still readable for compatibility and should be migrated/retained under the deployment's data-retention policy.

Scheduling uses a tenant-configurable `SchedulingProviderInterface` with a fake adapter, timezone-aware availability, idempotent persisted bookings, rescheduling, cancellation, and audit events. Booking, rescheduling, and cancellation require explicit confirmation unless an owner/admin enables the autonomous policy. The fake adapter is the only implemented provider; external calendar integration is deferred.

## Proposal safety

Proposal line items snapshot prices from the tenant's service catalog. The tenant pricing policy limits discounts and sets currency and validity. Drafts must pass a pending-approval state and owner/admin approval before delivery. Proposal email is queued through the outbound provider abstraction; delivery content is encrypted, retryable, and idempotent. Signed response links require an explicit customer confirmation POST and move the opportunity to won only on acceptance. PDF generation and external provider delivery remain deferred.

## Autonomous decision boundary

Each conversation analysis records an `agent_decisions` row with tenant, conversation and company references, action, explanation, confidence, and validated evidence IDs. Safe no-op and human-handoff decisions can be recorded automatically. Send, follow-up, meeting, and proposal actions remain pending owner/admin approval. Approving a recommendation only records approval; this release has no autonomous execution tool for those actions.

## Rollout constraints

The fake messaging adapter is for tests/development and does not send email externally. Provider secrets currently resolve from the application key for deterministic local signing; production secret-manager and vendor webhook integrations are not implemented. Sender address verification is not performed. External send and calendar providers stay disabled until provider credentials, sender verification, webhook signing, unsubscribe behavior, rate limits, applicable legal review, retention, observability, backups, and production deployment controls are configured and reviewed.
