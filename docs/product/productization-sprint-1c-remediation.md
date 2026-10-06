# Productization Sprint 1C — Workflow Reliability Remediation

**Assessment date:** 2026-10-06
**Status:** Partial — approval consistency, deterministic HTTP workflow paths, unsubscribe stopping, replay, and rollback are covered; fresh-fixture browser journey remains blocked.

## Reproduced defect and call path

The Vue Marketing Workspace posts to `POST /api/v1/marketing-drafts/{id}/approve`. `MarketingDraftController::approve` invokes `MarketingDraftService::approve`, which changed the draft to `approved`, created the approved `CampaignTemplate`, and wrote the audit row in one transaction. After committing that transaction, it called `WorkflowService::recordCompanyEvent` with `marketing_approved`. `WorkflowTransitionMap` maps that event to `OUTREACH_PREPARATION`. If the company's active workflow was already at `PROPOSAL`, `WorkflowService::append` rejected the backwards transition. The API returned 422 after the business mutation had committed.

The new HTTP regression test reproduced the same response before the production fix: `Workflow cannot transition from PROPOSAL to OUTREACH_PREPARATION.` The UI failure was therefore caused by the mismatch between an older workflow event and the current workflow stage, combined with the transaction boundary around approval.

## Remediation

- Workflow events whose target stage is already an ancestor of the current stage are now appended for audit/idempotency without moving `current_stage` backwards. Events targeting a genuinely unreachable future stage still fail transition validation.
- Marketing approval, its approved campaign template, audit row, and workflow event now share the same database transaction. A workflow-recording failure rolls the approval back; the endpoint cannot return an error while leaving an approved draft behind.
- No human review or outbound send authorization was bypassed. Approval still creates a template only; sending remains behind the existing `SEND_OUTREACH` approval and fake-provider execution path.

## Tests added or updated

- `PhaseTwoBMarketingTest::test_marketing_approval_http_path_is_atomic_and_does_not_regress_a_later_workflow_stage` generates a draft through HTTP, sets up an already-advanced workflow fixture, approves through the same HTTP route used by Vue, verifies the stage remains at `PROPOSAL`, and verifies the event/template are recorded once. A repeated approval is rejected without creating another template.
- `PhaseTwoBMarketingTest::test_marketing_approval_rolls_back_if_workflow_recording_fails` injects a workflow-recording exception at the application boundary and verifies the draft remains `draft`, with no template or approval audit row.
- The retained `AcquisitionWorkflowEndToEndTest` now performs its marketing approval through the HTTP route; its downstream domain/job/provider checks remain as the existing integration test.

The failure was observed red before changing application code. After the fix, the focused HTTP regression passed (1 test / 9 assertions), the marketing feature file passed (11 tests / 80 assertions), and the Phase 2F integration test passed (2 tests / 85 assertions). The final full suite passed (125 tests / 780 assertions).

## Product journey acceptance run (2026-10-06)

`tests/Feature/ProductizedSalesJourneyTest.php` creates a fresh tenant/owner/company/campaign/contact fixture under `RefreshDatabase` for each test. It starts before outreach approval, writes only deterministic crawl fixtures, binds fake AI/scheduling/outbound providers, and does not require live provider credentials.

Positive path exercised the UI-facing HTTP endpoints for marketing approval, campaign step/enrollment/activation, Approval Center approval, inbound/outbound webhooks, inbox read, opportunity read, meeting availability/selection/booking, proposal creation/edit/commercials/approval/document/readiness. Real queued job handlers were invoked directly by the test to drain the deterministic queue. The path verified the `SEND_OUTREACH` approval, fake outbound status `sent`, inbound reply, FollowUpAgent, SalesAgent qualification/evidence, opportunity, fake meeting, ProposalAgent, and final `ready_to_send`; it verified no proposal delivery. Replay checks covered enrollment, sends, webhook events, follow-up processing, meeting booking, and workflow events without duplicate business records or extra fake sends.

The unsubscribe path sent one fake message in a two-step campaign, accepted an HTTP signed inbound `UNSUBSCRIBE`, ran FollowUpAgent, verified suppression, unsubscribed enrollment, and `CANCELLED` workflow, then ran the next campaign-step handler and proved no additional outbound message/provider send, opportunity, meeting, or proposal was created. A failure-injection HTTP approval test proves draft/template/audit writes roll back when workflow recording fails.

Focused results: `ProductizedSalesJourneyTest` 3 tests / 139 assertions; retained `AcquisitionWorkflowEndToEndTest` 2 tests / 85 assertions. The product journey verifies application HTTP endpoints, but queue dispatch/worker supervision is not driven by an external live worker in these tests.

## Remaining acceptance work

- **UI journey remains blocked.** The local dashboard was opened and signed in against the only configured tenant, but that tenant contains the old Sprint 1B-2 proposal-stage fixture. It was not used or modified. No OpenAI, Anthropic, or Gemini credentials are configured, and there is no runtime fake-AI adapter to drive a fresh fixture through follow-up/sales/proposal actions from the browser. Do not infer UI acceptance from the HTTP tests.
- Re-score product UX only after completing the requested browser journey with a new resettable pre-outreach fixture. Until then, keep the historical score at 62/100 and verdict C.
- Production delivery, live providers, and deployment acceptance are outside this Sprint 1C run.

## Verification snapshot

| Check | Observed result |
|---|---|
| Red HTTP regression | Failed before fix with `PROPOSAL` → `OUTREACH_PREPARATION` 422 |
| Marketing feature tests | 11 tests / 80 assertions passed after fix |
| Dedicated product journey test | 3 tests / 139 assertions passed |
| Phase 2F integration test | 2 tests / 85 assertions passed |
| Full PHPUnit suite | 128 tests / 919 assertions passed after the final unsubscribe assertions |
| TypeScript | Passed |
| Vite production build | Passed |
| Routes / PHP syntax | 165 routes registered; all PHP files under `app`, `tests`, `routes`, and `database` passed `php -l` |
| Browser | Dashboard opened and existing tenant signed in; only old proposal-stage fixture available; full UI journey blocked and not claimed |
| Live providers | None called |

## Final browser enablement attempt (2026-10-06)

Deterministic acceptance support is now wired through the existing `AIProviderInterface` and `AIModelRouter`. `DeterministicAIProvider` is registered only when `AI_DETERMINISTIC_ENABLED=true`; both registration and construction reject environments other than `local` and `testing`. It supports the grounded website, marketing, sales, and proposal tasks required by this journey, validates through the normal router schema validator, and returns recommendations/content only. No approval, send, booking, price authority, proposal approval, or readiness decision is returned by the provider.

`php artisan product:acceptance-reset` replaces only the marked `sprint-1c-local-acceptance` tenant and seeds a fictional Northstar prospect before marketing approval, with no enrollment, outbound message, opportunity, meeting, or proposal. The local-only authenticated `/api/v1/local-acceptance/*` endpoints expose fake-provider status, confirm a fake delivery through the ordinary outbound webhook processor, and inject only interested/unsubscribe replies through the ordinary inbound webhook processor. The dashboard shows a persistent `LOCAL ACCEPTANCE · TEST MODE` provider label. The marketing draft UI now recognizes the deterministic model as fixture text rather than live AI output.

The manual run used `APP_ENV=local AI_DETERMINISTIC_ENABLED=true`, PostgreSQL, Redis, and Horizon. `php artisan horizon:status` reported Horizon running. The fixture's queue setting was `redis`. Campaign activation and `ProcessCampaignEnrollment` / `ExecuteCampaignStep` were executed by Horizon: the campaign became active, the recipient enrolled, and the app created a `SEND_OUTREACH` approval. A temporary Vite proxy configuration in `/tmp` targeted Laravel on port 8001; the Laravel process included the Vite origin in `SANCTUM_STATEFUL_DOMAINS`. This was process-only local configuration.

The browser completed Home, prospect list/open, Prospect 360, Outreach, marketing draft review and approval, campaign step setup, contact enrollment, campaign activation, and opening the send approval. The approval screen showed the target prospect/contact, message preview, consequence, and explicit TEST MODE / fake-provider notice. Approving `SEND_OUTREACH` could not be confirmed: the browser interaction timed out at `Input.dispatchMouseEvent` before a request/response was observed; a fresh tab and PostgreSQL showed `workflow_approvals.status=PENDING`, `outbound_messages.status=queued`, provider `fake`, and no sent receipt. No simulated reply or downstream journey was run. This is a browser-control blocker; it is not reported as an application HTTP failure. Do not count the attempted click as an approval.

A separate UI correction was made after observation: the acceptance panel previously called any created conversation an inbound reply. It now reports an inbound reply only when `inbound_count > 0` and labels an outbound-only thread accurately. The campaign request helper now supplies XSRF on all state-changing methods, including bodyless `DELETE`; this was verified against the previously failing sequence-step delete (`DELETE /api/v1/campaigns/{campaign}/steps/{step}`, HTTP 419 before the fix). The fixture was reset after that defect, and the successful campaign setup uses a zero-delay step.

The fake acceptance guards/reset/provider tests pass (5 tests / 40 assertions). The requested browser journey remains incomplete past the pending human send approval; the unsubscribe UI path, fake delivery/reply UI controls, Inbox, qualification, Pipeline, fake meeting booking, proposal review/readiness, and responsive review were not completed. Preserve the prior score of 62/100 and verdict C; do not infer full product acceptance from API journey tests or this partial browser path. No live AI, email, calendar, or S3 integration was called.

## Sprint 1C SEND approval trace and resumed browser attempt (2026-10-06)

The Approval Center is `src/components/OrchestrationWorkspace.vue`. Its Approve button calls `decide(row, 'approve')`. For HIGH/CRITICAL approvals, the handler first invokes `window.confirm`; accepting then calls `POST /api/v1/automation/approvals/{id}/approve` with `{}`, credentials, `Accept`, tenant and XSRF headers. There is no form wrapper or second application-level confirmation step. The body is `{}`, so the helper includes JSON and `X-XSRF-TOKEN`.

The browser driver timed out while dispatching the original click, and did not provide a usable request/response trace. However, subsequent authenticated UI state showed no pending approvals and the read-only PostgreSQL record for the fixture showed `SEND_OUTREACH / EXECUTED` with `reviewed_at=2026-10-06 07:41:52+05:30`. This persisted transition proves the approval handler reached the backend despite the driver timeout. The exact HTTP status and response body were not captured and are not claimed here. The native confirmation explains the automation stall; no backend approval defect was found. A temporary in-page-confirmation experiment was reverted; no application code change was retained and no new frontend test framework was added.

Horizon was started for this resumed run and `php artisan horizon:status` reported running. For tenant `sprint-1c-local-acceptance`, PostgreSQL shows the one outbound record as `accepted` from provider `fake`, a provider reference is present, `attempt_count=1`, and `accepted_at`/`sent_at` are populated. The campaign has one `message_accepted` and one `message_sent` event. This is the fake provider's successful acceptance result, but the persisted message status has not advanced from `accepted` to `sent`. The UI offers “Confirm fake delivery”, which invokes the local fake-provider status-webhook simulator. It was not clicked because this acceptance task permits external-event simulation only for the fictional prospect reply and forbids manually marking the message sent. No reply was simulated. Current fixture counts: inbound 0, opportunities 0, meetings 0, proposals 0, proposal deliveries 0.

The downstream browser journey therefore stops before the sent-message prerequisite. Inbox analysis, FollowUpAgent/SalesAgent qualification, Pipeline, meeting booking, proposal review/approval/`READY_TO_SEND`, and responsive review were not performed. No updated UX score is assigned; the historical 62/100 remains a prior result only. No live AI, email, calendar, or S3 provider was called.

Final verification after reverting the experiment: `ProductizedSalesJourneyTest` 3 tests / 139 assertions; `AcquisitionWorkflowEndToEndTest` 2 / 85; full PHPUnit 133 tests / 959 assertions; PHP syntax 227 files; 168 routes; TypeScript and Vite production build passed; `git diff --check` passed for tracked files only.

Repository state: the top-level path is the project directory and `.git/config` points to the expected GitHub origin. `main` is an unborn branch (no local branch ref), and `git ls-files` reports zero tracked files; the complete project, `.gitignore`, generated `dist`, PHPUnit cache, TypeScript build info, and local database file appear untracked. The available evidence establishes an empty/unpopulated index at the correct root; it cannot distinguish a never-added initial checkout from an index/branch history loss. `.gitignore` is incomplete for generated outputs. No Git mutation or cleanup was performed. `git diff --check` does not inspect these untracked files.

## Outbound activity consistency remediation (2026-10-06)

Canonical lifecycle and consumers: `ExecuteCampaignStep` creates a queued outbound message and records `message_queued`; `SendOutboundMessage` calls the provider, persists its result, creates the conversation message, records `message_accepted`, and advances/schedules the next campaign step at provider acceptance. Sequence progression is an explicit send-job operation and does not consume `message_sent`. The erroneous acceptance-time `message_sent` was consumed by workflow status/stage mapping as an actual send and appeared in the campaign activity UI. Signed status webhooks are processed by `MessageEventProcessor`; its sent and delivered events now record their corresponding campaign activities and workflow events at the persisted transition.

The fix removes acceptance-time `message_sent`, records acceptance with the canonical `outbound:<message>:accepted` key, and leaves `sent_at` unset while provider state is only accepted. A signed `sent` transition records one `message_sent`; a signed `delivered` transition records one `message_delivered`. Campaign activity keys are stable per outbound message and semantic event, so a semantically duplicate event under a different provider event ID cannot create a second activity. Provider event IDs remain independently persisted for webhook replay tracking. The conversation message's delivery state and sent timestamp are updated along with provider state. No history cleanup or runtime rewrite of existing audit rows was performed.

Regression results: focused accepted/sent/delivered transition test **1 test / 25 assertions**; `ProductizedSalesJourneyTest` **3 / 143**; `AcquisitionWorkflowEndToEndTest` **2 / 85**; full PHPUnit **134 / 988**. `npm run typecheck`, `npm run build`, and PHP syntax validation under `app`, `routes`, `tests`, `database`, `bootstrap`, and `config` passed. Campaign sequence advancement after acceptance remains covered and passed.

The browser journey is resumed from the dedicated fixture only after these automated checks passed. Browser traversal and UX acceptance score remain pending until actual UI verification; HTTP coverage is not represented as browser acceptance.

## Browser acceptance resume — meeting request UI blocker (2026-10-06)

After resetting only the dedicated deterministic fixture, Playwright rendered the application and exercised Home → Prospects → Prospect 360 → Outreach → marketing draft review/approval → campaign step/enrollment/activation → Approval Center → `SEND_OUTREACH` approval (including the native confirmation) → fake-provider acceptance → signed fake `sent` status confirmation → fictional interested inbound reply → Inbox. The configured local Horizon worker processed the reply: API-visible agent runs for FollowUpAgent and SalesAgent succeeded, and the SalesAgent UI result showed a 65/100 `QUALIFIED` qualification with evidence. One opportunity appeared in Pipeline.

The coordinator then created a human `REQUEST_MEETING` approval. The approval was approved in the UI; the app showed “Approval approved.” The resulting authenticated `GET /api/v1/conversations/01a1107c-ceff-71d2-8bf9-da892e1ae4d3` returned HTTP 200 with intent `INTERESTED`, status `human_review`, opportunity `61e51b02-34f9-4bd2-84a6-3b6817daff0d`, and scheduling request `8a4694c6-6e88-4b8f-9647-d4d5d28ae3d6` in `REQUESTED` state and `Asia/Kolkata` timezone. No meeting is booked.

**Stop-on-first-new-product-defect:** Inbox renders no `.meeting-planner` (Playwright locator count 0), so there is no UI action to generate fake availability or book the already-approved `REQUESTED` scheduling record. The existing planner is gated on `selected.intent === 'meeting_request'`, but the actual persisted conversation intent is `INTERESTED`; the approved scheduling request does not make the planner visible. No availability endpoint was called because no control was presented. The approval request succeeded; this is a UI reachability defect, not an HTTP failure. Stop here as required; no backend code was changed for this newly discovered blocker.

At the stop, a read-only query limited to the dedicated tenant confirmed outbound statuses `{sent: 1}`; campaign events `{message_accepted: 1, message_sent: 1}`; signed provider events `{sent: 1}`; FollowUpAgent run 1 and SalesAgent runs 3; opportunity 1; approvals `{SEND_OUTREACH: EXECUTED, REQUEST_MEETING: EXECUTED}`; scheduling request `{REQUESTED: 1}`; meetings 0; proposals 0; proposal deliveries 0. All acceptance UI provider indicators named deterministic/fake providers. No live AI, email, or calendar provider was called. Browser journey is **PARTIALLY COMPLETE**; no new score is assigned, and the historical 62/100 is not represented as a current re-score.

As a final check of an existing alternate Inbox action, `Analyze latest conversation` was invoked once. The browser captured `POST /api/v1/conversations/01a1107c-ceff-71d2-8bf9-da892e1ae4d3/classify` returning **HTTP 503** with `{"message":"Conversation analysis is temporarily unavailable."}`; the UI displayed the same retry notice and `.meeting-planner` remained absent. Likely code cause from inspection: `DeterministicAIProvider`'s classification response omits the classifier schema's required `summary`, `reason`, and `risk` fields and uses differently named fields instead, resulting in structured-response rejection. This remains a diagnosis from code/schema comparison, not a logged exception trace. The current conversation/meeting request state did not progress. No further actions were taken.

## Classification and persisted meeting request remediation — acceptance stopped at booking (2026-10-06)

The classifier error was reproduced inside a rolled-back transaction: `JsonSchemaValidator` threw `RuntimeException: Structured output at $ is missing required field [summary]` through `AIModelRouter → ConversationIntentClassifier → ConversationAnalysisService`; the controller safely returned 503. `DeterministicAIProvider` now returns the classifier's strict schema shape for classification, without changing its separate SalesAgent contract. Inbox now displays a persisted actionable scheduling request (`REQUESTED`, `AWAITING_SELECTION`, `SELECTED`, or `FAILED`) regardless of the AI intent, and shows its status, timezone, and duration. API coverage verifies that `INTERESTED` conversation detail includes the approved `REQUESTED` request.

Focused verification: **12 tests / 202 assertions passed** across classifier unit, conversation workflow, and product journey feature tests. Coverage includes deterministic provider→classifier success, HTTP classification success, tenant scoping, malformed output rejection, and scheduling-request detail serialization.

On the existing, non-reset fixture, Playwright confirmed Inbox classification returned HTTP 200 and the planner rendered from the saved request. Fake availability rendered. `POST /api/v1/scheduling-requests/8a4694c6-6e88-4b8f-9647-d4d5d28ae3d6/select-slot` returned HTTP 200, then booking returned **HTTP 422**: `The selected time no longer matches current scheduling rules. Refresh availability and select another time.` This is the first new defect, so the browser journey stopped here. The UI offered `Oct 7, 2026, 3:30 PM · Asia/Kolkata`; the selected API/persisted slot was `2026-10-07 10:00:00+05:30` (04:30 UTC). Booking evaluates `owner_timezone=UTC` and working hours `09:00–17:00`, so the persisted instant is outside the configured workday. The API's offered instant and PostgreSQL persisted instant disagree. No booking implementation change was made; correct UTC/timestamptz persistence and a PostgreSQL regression are required before resuming.

Exact persisted state at stop: scheduling request `SELECTED`; one offered slot `SELECTED`; meeting bookings 0; proposals 0; proposal deliveries 0; outbound messages 1 (existing fake outbound). No proposal UI actions, further sends, or live provider calls occurred. Sprint 1C remains **PARTIALLY COMPLETE**; the historical 62/100 remains historical, with no new score. Resume from this fixture after fixing and PostgreSQL-verifying the slot instant round-trip; do not reset it.

## Final acceptance resume — completed through READY_TO_SEND (2026-10-06)

The previously documented stop conditions were fixed narrowly, preserving the current fixture and outbound activity semantics:

- Classification's expected schema is `intent`, `confidence`, `summary`, `reason`, `risk`, and `evidence_references`. The actual exception was a missing `summary` field, raised by `JsonSchemaValidator` through the router/classifier and mapped to generic HTTP 503. The deterministic provider now returns the required strict shape; classifier validation and safe controller output remain unchanged.
- Inbox now uses the serialized persisted scheduling request as authority and exposes the planner for actionable request states even while intent is `INTERESTED`.
- PostgreSQL timestamp bindings omitted offsets when Laravel formatted a UTC Carbon value, so the database interpreted UTC wall time in its session timezone and shifted the instant. Offered-slot lookup/insertion and booking persistence now use explicit offset-bearing timestamps. A regression compares API offered instants with persisted slot and meeting instants.
- Proposal review sent `discount_percent=0` while hiding the reason field. A malformed `required_if:discount_percent,>,0` rule treated zero as requiring a reason. The field is now nullable at validation and the explicit positive-discount guard remains authoritative. The feature test now sends explicit zero without a reason.
- The deterministic proposal fixture now selects an approved service that exactly matches the requested service before falling back to the first approved service. Its test verifies it chooses the requested ecommerce service.

Focused verification: **12 tests / 204 assertions** after classification and timestamp regressions; proposal workflow/provider/product-journey tests **19 / 254** before final confidence assertions. The final full suite below includes all latest changes and assertions.

The existing browser fixture was continued without reset. Classification succeeded in Inbox (HTTP 200). Persisted request `REQUESTED` rendered the planner, provider-derived fake availability displayed date/time/timezone and 30-minute duration, selection returned HTTP 200, and booking returned HTTP 201 from the fake provider. PostgreSQL then showed request `BOOKED`, one scheduled meeting tied to the same request/conversation/opportunity/contact, provider `fake`, timezone `Asia/Kolkata`, starts `2026-10-07 15:30:00+05:30`, and a booked slot at the identical instant. Meetings displayed the prospect, 3:30–4:00 PM Asia/Kolkata, scheduled status, Prospect 360/context links, and `TEST MODE` / no invite sent.

Proposal requirements were created in the UI for the same opportunity. The first deterministic draft selected an irrelevant first catalog service; the service-matching correction was applied and the same proposal regenerated to version 2 with `E-commerce modernization discovery` scope. The human reviewer saved that approved scope and its approved catalog price: INR 120,000, zero discount. Human approval returned HTTP 200; local PDF generation returned HTTP 201; the UI action reached `ready_to_send` with opportunity stage `PROPOSAL_READY`. PostgreSQL confirms proposal version count 2, proposal status `ready_to_send`, one meeting, one outbound message total, and **zero proposal deliveries**. The PDF was local only. No live AI, outbound, calendar, or proposal delivery provider was called.

Final test results: `ProductizedSalesJourneyTest` **3 / 151**; `AcquisitionWorkflowEndToEndTest` **2 / 85**; full PHPUnit **137 / 1,012**; `npm run typecheck` passed; `npm run build` passed; PHP syntax validation passed for **250 files**. Responsive browser checks at 1440, 1280, 768 and 390 CSS pixels covered Inbox, Meetings and Proposal Review; document/body widths matched each viewport at all sizes, with no horizontal page overflow. At 390 the shell becomes a compact two-row horizontal sales nav and long workspaces remain vertically scrollable. The scheduling planner itself was directly inspected at desktop before booking; after booking, Inbox correctly displays the meeting history rather than the actionable planner.

Fresh UX score (not inherited from the historical 62): Navigation/IA 8, Home 7, Prospect Management 8, Prospect 360 8, Outreach 8, Inbox 9, Pipeline 8, Meetings 9, Proposals 8, Approval/Human Control 9 = **82/100**. The proposal workspace still displays “Draft version 2 is saved for human review” after approval/readiness; this is a documented non-blocking status-copy issue. No P0 defects were found in the completed journey. Current decision: **B. PRODUCT UX ACCEPTED WITH NON-BLOCKING FIXES**. Historical incomplete and score results above remain intact as history.

**PRODUCTIZATION SPRINT 1C COMPLETE** — the requested browser journey reached `READY_TO_SEND` on the existing fixture, with no proposal delivery and no Git state changes.

## Final acceptance resume — fake delivery stopped at campaign event defect (2026-10-06)

### Lifecycle and confirmation

Inspection confirms the intended provider lifecycle is `queued → accepted → sent → delivered`: `FakeOutboundMessagingProvider::send()` returns `accepted`; a provider status event, signed and normalized through `OutboundWebhookController` and `MessageEventProcessor`, transitions `accepted` to `sent` and may later transition to `delivered`. The `Confirm fake delivery` button calls the local-only fixture helper, which builds a signed fake `sent` status event and passes it through that ordinary webhook boundary; it does not update the message directly.

The control was clicked once through the authenticated local UI. The UI then offered the interested/unsubscribe reply controls, and read-only PostgreSQL verified the outbound row changed `accepted → sent`; provider stayed `fake`, receipt stayed `fake-f6ab171c-7cc9-48cb-90f1-21b4850b671e`, `attempt_count=1`, and one `outbound_message_events` row of type `sent` was persisted. The local UI displayed no error. The browser tool did not provide a raw network trace or exact outer HTTP response status; the persisted event and row transition establish successful processing.

### Product defect found; stop-on-first-defect applied

`SendOutboundMessage` records a `message_sent` campaign event when the provider result is only `accepted`, with key `outbound:<message>:accepted`; it also records a separate `message_accepted` event. Later, the signed provider `sent` event is correctly processed and records another `message_sent` event with a different `provider:<hash>` key. After one send attempt, the campaign therefore contains **two `message_sent` rows for the same outbound message**, alongside `message_accepted`. Before confirmation, the message was `accepted` while campaign activity already said `message_sent`; after confirmation, the message is `sent` but the duplicated campaign activity remains. The verdict is **C — actual state/activity inconsistency**, not merely ambiguous wording: the same actual status transition creates a duplicate campaign activity item. The acceptance run stopped at this defect. No code was changed.

At stop: campaign `active`, recipient `completed`, message `sent` / fake / attempt count 1, acquisition workflow `WAITING_EXTERNAL` at `OUTREACH`; inbound messages 0, opportunities 0, meetings 0, proposals 0, proposal deliveries 0. No interested reply was simulated. Inbox inbound context, FollowUpAgent, SalesAgent, reply-based qualification, Pipeline, meeting booking, proposal review/approval/`READY_TO_SEND`, and responsive inspection were not attempted. Prior score 62/100 remains historical; no new score was assigned. No live provider was called.

### Git diagnosis

Read-only commands showed `origin` configured to the expected GitHub URL, but `git remote show origin` reports unknown HEAD, `git ls-remote --heads origin` and `git ls-remote origin HEAD` return no refs, and local `git log`/`git reflog` are empty. Classification: **B — remote exists but contains no project history**. The local branch `main` is unborn and the index tracks zero files. `git status --short` still shows the project files as untracked. No Git state was modified.

No application code changed, so no tests were rerun for this resume. The previously verified baseline remains the latest test run: 133 PHPUnit tests / 959 assertions; ProductizedSalesJourneyTest 3 / 139; AcquisitionWorkflowEndToEndTest 2 / 85; TypeScript and Vite build passed; 227 PHP files linted; 168 routes registered. This baseline does not cover the duplicate campaign-event behavior observed in this browser acceptance run.

## Current final outcome — supersedes prior partial statuses

The earlier Sprint 1C stop points remain as chronological history; current acceptance subsequently completed on the same preserved fixture. Classification's missing-summary root cause, persisted scheduling planner gate, PostgreSQL timestamp shift, and zero-discount validation/UI mismatch are fixed and regression-covered. Inbox classify returned 200; scheduling selection returned 200; fake booking returned 201; Meetings showed one correctly associated fake booking; the ecommerce proposal was human-approved, documented locally, and reached `ready_to_send`.

Current PostgreSQL counts: one meeting; one proposal at version 2 with status `ready_to_send`; zero proposal deliveries; one original fake outbound. Offered-slot and booking instants match exactly at `2026-10-07 15:30:00+05:30`. Verification: ProductizedSalesJourneyTest 3/151; AcquisitionWorkflowEndToEndTest 2/85; full PHPUnit 137/1,012; TypeScript/build passed; 250 PHP files linted. Responsive checks at 1440/1280/768/390 showed no horizontal page overflow; active planner responsive validation was desktop only. Current fresh UX score is **82/100**, verdict **B. PRODUCT UX ACCEPTED WITH NON-BLOCKING FIXES**; known non-blocking issue is stale “Draft version 2 is saved for human review” copy after approval/readiness. No P0 found. No live provider calls or Git changes occurred. **PRODUCTIZATION SPRINT 1C COMPLETE** for this local fake-provider acceptance only; production readiness is not claimed.
