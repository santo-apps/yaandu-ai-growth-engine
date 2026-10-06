# Phase 2A — Campaign and Outreach Foundation

## Existing architecture reused

Phase 2A extends the Phase 1 campaign tables and existing `Campaign`, `CampaignStep`, `CampaignEnrollment`, `OutboundMessage`, `Conversation`, and `SuppressionEntry` models. Existing `CampaignLifecycle`, `CampaignTemplateRenderer`, `SendingWindowCalculator`, `SuppressionChecker`, queue jobs, provider router, fake provider, webhook processors, and Vue workspaces remain the domain boundaries. No production mail provider is configured; the fake adapter is the only enabled outbound provider.

The persisted campaign states remain lowercase (`draft`, `active`, `paused`, `completed`, `cancelled`) to preserve existing APIs. `active` represents a running campaign. Readiness is checked at start time from approved active steps and an enabled tenant sender configuration. Sequence templates are plain text; markup and unsupported placeholder expressions fail closed.

The established conversation workflow status vocabulary is preserved: `ai_active` is the open capture state, `human_active` is the human-owned state, and `resolved` is the closed state. `human_review` remains an explicit review state. These stored values are reused instead of introducing duplicate status synonyms.

## Execution and safety

Enrollment processing queues campaign-step work, which checks campaign/enrollment state and suppression before creating one encrypted outbound ledger row per enrollment/step. A separate outbound queue job rechecks campaign state, enrollment state, suppression and the tenant-timezone sending window before provider invocation. The provider receives the persisted idempotency key. Row locks serialize per-tenant and per-campaign hourly/daily limit checks; `outbound_messages.attempted_at` is the durable counting source, while Redis is not the only enforcement mechanism. Pausing defers queued work for a later resume; completion/cancellation cancel queued messages and stop pending/active enrollments.

Message subject/body remain encrypted at rest. API list responses omit ciphertext and address hashes; a tenant-scoped detail endpoint decrypts content for authenticated workspace users. Template rendering supports only `contact_name`, `contact_first_name`, `company_name`, `website`, and `sender_name`. Unsupported/malformed braces fail closed; templates are never evaluated as PHP or Blade. Header controls are stripped from substitutions and email subject validation rejects line breaks.

Provider webhooks are signature checked, timestamp bounded and deduplicated by provider event ID. Normalized delivery, deferral, soft bounce, hard bounce, complaint, and unsubscribe states are persisted. Hard bounce, complaint, and unsubscribe create tenant-scoped suppression entries and stop eligible enrollments; suppression records cannot be deleted or reactivated through the Phase 2A API. Signed inbound replies are encrypted into the conversation timeline, mark active enrollments replied, record campaign events, and stop automated sequence progression. The campaign engine never generates or sends an AI reply.

## Tenant consistency and audit

Composite database keys bind enrollments to same-tenant campaign, company, contact, and contact method rows. Outbound messages bind campaign, enrollment, and sequence step to the same tenant and campaign. Campaign events bind their campaign/enrollment consistently. A conversation linked to an enrollment has a same-tenant/same-campaign composite foreign key. API queries are tenant-scoped after active membership resolution.

Campaign create/start/pause/complete/cancel, manual enrollment, manual suppression, and enrollment stop actions write audit records where an authenticated actor exists. Lifecycle and delivery facts use idempotent `campaign_events` with optional enrollment/message correlation and safe metadata; message content, provider secrets, and email addresses do not go into event metadata.

## API and UI surfaces

Campaign APIs cover list/create/detail/update, start/pause/resume/complete/cancel, template approval, step list/create/update/delete/reorder, enrollment list/create/stop, and tenant messaging configuration. Campaigns persist separate name, description, and objective fields. Outreach APIs provide tenant-scoped outbound-message list/detail and suppression list/create. Existing conversation inbox/detail and inbound capture APIs are reused. The Vue Campaigns workspace now includes live campaign counters, step controls, enrollment stops, message ledger/detail, and suppression management. Existing ConversationWorkspace behavior is reused without extending AI sales behavior in this increment.

## Verification boundary

SQLite remains the fast PHPUnit default. PostgreSQL compatibility is checked independently with the local PostgreSQL migration and composite constraints. Automated tests cover tenant boundaries, lifecycle cancellation, sequence edit/reorder, manual suppression, encrypted message detail access, durable daily limits, duplicate job execution, signed/replayed bounce callbacks, complaint suppression, inbound reply capture, and template placeholder rejection. No live email provider, AI provider, S3 service, production worker deployment, or production environment is verified by this increment.
