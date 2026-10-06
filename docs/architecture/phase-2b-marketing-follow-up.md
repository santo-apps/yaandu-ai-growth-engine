# Phase 2B — AI Marketing and Follow-up Intelligence

## Boundary

Phase 2B adds intelligence to the Phase 2A campaign and messaging foundation. `MarketingAgent` creates personalized, evidence-grounded drafts. `FollowUpAgent` classifies a reply or no-reply state and creates a review recommendation. Neither agent can send, schedule, suppress, negotiate, book, create a proposal, or execute a recommendation.

The deterministic campaign engine remains authoritative for enrollment, campaign status, suppression, delivery state, send limits, send windows, and timing. Approving a marketing draft can create an approved `campaign_templates` record. It does not activate a campaign, add a sequence step, enroll a contact, or send a message. Approving a follow-up draft records human review for manual use only. Unsubscribe and other provider events remain handled by Phase 2A deterministic paths.

## Agent data flow

```text
Tenant-scoped company, contact, website intelligence, lead evidence,
score, campaign context, approved tenant knowledge
                         │
                         ▼
                  MarketingAgent
                         │
                         ▼
                 Versioned draft (DRAFT)
                         │
                 Human approve/reject
                         │
            approved campaign template (optional)

Conversation + campaign/enrollment/delivery state + evidence
                         │
                         ▼
                   FollowUpAgent
                         │
                         ▼
       NO_ACTION / DRAFT_FOLLOW_UP / STOP_SEQUENCE /
                  REQUEST_HUMAN_REVIEW
                         │
              Human review or takeover
```

Each agent implements `AgentInterface`, is registered with the existing `AgentOrchestrator`, and uses `AIModelRouter`. The agents expose no tools. They receive IDs in their input and construct bounded context using tenant-scoped database queries; no arbitrary database access or provider credential is exposed to model context.

Inbound replies enqueue `ProcessFollowUpReply` on the existing `conversations` queue. The job deduplicates by the newest inbound message, retries through Laravel's bounded queue policy, and stores a recommendation without changing conversation or campaign state. Provider unsubscribe/suppression processing remains synchronous and deterministic before this job is queued. An owner/admin can also request analysis through the tenant-scoped API.

## Grounding and prompt handling

The prompt repository selects only the tenant's active, approved prompt version for the matching agent. The system policy is always composed with that prompt. Website text, evidence, and conversation messages are passed as structured untrusted data and cannot change system instructions. Approved tenant marketing knowledge is stored in `tenant_marketing_knowledge`; only approved records enter generation context. Knowledge types are services, capabilities, value propositions, proof points, case studies, and CTAs.

Marketing output must have known evidence or knowledge references. A reference not included in the bounded context fails generation safely. The model may return confidence and draft text but does not choose message permissions. Follow-up model output contains intent, confidence, a short reason, draft text, and references. Application policy computes action, delay, and human-review requirement from fixed allowed actions, configured confidence, high-risk intent rules, deterministic delivery/enrollment/campaign/timing/suppression state, and deterministic unsubscribe state.

## Persisted review and provenance

- `prompt_templates`: tenant, agent key, version, system instruction, template, schema version, approval and active status.
- `tenant_marketing_knowledge`: tenant-approved claims and proof content.
- `marketing_drafts`: encrypted subject/message, immutable version history, evidence references, agent run, provider/model/task, prompt ID/version, correlation ID, idempotency key, and reviewer state.
- `follow_up_recommendations`: encrypted draft message, conversation and source-inbound-message scope, action/intent, confidence, references, delay suggestion, provider/model/task, prompt ID/version, correlation ID, idempotency key, and reviewer state.
- `agent_runs` / `agent_events` and `audit_logs`: execution lifecycle and safe review events.

Regeneration supersedes the earlier unapproved draft and creates a new version. Idempotency keys return an existing draft/recommendation or permit a failed agent run to retry without duplicating the durable record. Provider errors are returned as safe messages; raw provider errors and prospect text are not copied into audit metadata.

## API

All routes are under authenticated `/api/v1` routes with `ResolveTenant` membership validation. Generation, prompt/knowledge administration, review, and takeover require an active tenant owner or admin.

- `GET/POST /marketing-drafts`; `POST /marketing-drafts/{id}/regenerate|approve|reject`
- `GET /follow-up-recommendations`; `POST /conversations/{conversation}/follow-up-analysis`
- `POST /follow-up-recommendations/{id}/approve|reject|takeover`
- `GET/POST /marketing-configuration/prompts`; `POST /marketing-configuration/prompts/{id}/approve`
- `GET/POST /marketing-configuration/knowledge`; `POST /marketing-configuration/knowledge/{id}/approve`

Generation and analysis require an `Idempotency-Key` header. Takeover assigns the conversation to the acting user and terminalizes its active campaign enrollment through the existing campaign message stopper. No route in this module dispatches an outbound message.

## Operational limits

The UI requires an owner/admin to configure and approve an agent prompt before the corresponding agent can run. Tenant knowledge is optional; without it, generation may use only known prospect evidence and generic copy. Live provider behavior is not considered verified by fake-provider tests. Phase 2B does not enable live outbound email or implement autonomous follow-up sending.
