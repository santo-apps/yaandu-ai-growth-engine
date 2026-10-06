# Productization Acceptance Gate

**Assessment date:** 2026-10-06
**Decision:** **C. PRODUCT UX NOT ACCEPTED**
**Scope:** Internal salesperson journey through proposal readiness, using only local fictional data and fake providers. This is not a production-readiness decision.

## Method and fixture state

Walked the local Vue/Laravel application as the fictional owner/manager user for the `Sprint 1B-2 Visual QA (TEST FIXTURE)` tenant. Used Acme Test Outfitters and Riley Sample. Added a reserved `.fixture.test` address because the UI has no contact-method authoring control. Its value is encrypted and marked unverified; it is not a real mailbox. The marketing copy was the existing deterministic fixture; no AI generation or live provider call was made.

The proposal was already approved and had a generated PDF at the start of this gate. I used the proposal UI to mark version 2 **Ready to Send** and verified its explicit notice that approval does not send or share the proposal. No proposal delivery was created.

For the fake-outbound check, I enabled the tenant’s fake-only configuration, approved the marketing draft, added its approved template to the campaign sequence, enrolled the fictional contact, and started the campaign. The approval and enrollment API actions returned a workflow-transition error after their records had been persisted. The campaign execution job then failed before creating the human send approval. The outbound ledger contains a `fake` message in `queued` status with no `sent_at`; the fake provider was not called. I paused the campaign and disabled fake outbound again. No external email was sent.

The focused end-to-end feature test was run: `AcquisitionWorkflowEndToEndTest` passed, 2 tests / 81 assertions. It verifies the fake-provider workflow at the service/API level with a clean test database. It does not remove the UI failure found in the pre-existing, already-advanced visual fixture.

## Acceptance journey

| Step | Result | Findings |
|---|---|---|
| Home | Partial | Shows prospect, qualification, opportunity, and meeting counts with useful navigation. For the fixture, the action center says “next action not recorded”; it does not surface proposal readiness or pending approvals as a next task. |
| Prospects | Pass | Search/filter list shows the fictional company, qualified status, score 82/100, and named contact. The row opens Prospect 360. |
| Prospect 360 | Partial | One view shows company, source, score contributions, website finding, contact, opportunity, and proposal. It links to Inbox and Pipeline. It shows no conversation/outreach activity or meeting; the outreach tab says no conversation is linked even though a campaign enrollment and queued message exist. |
| Outreach / draft review | Partial | Campaign context, approved message, subject/body, personalization, website evidence, knowledge, and TEST MODE are understandable. Deterministic copy is labeled as fixture text, not AI-generated. |
| Marketing approval | Fail | The UI returned `Workflow cannot transition from PROPOSAL to OUTREACH_PREPARATION.` The draft nevertheless became approved and an approved campaign template was created. The UI initially continued to show draft state until refreshed. |
| Fake outbound | Blocked | Campaign enrollment also persisted despite a workflow-transition error. The campaign became active, but `ExecuteCampaignStep` failed before creating a `SEND_OUTREACH` approval. Ledger status is `queued`, provider `fake`, `sent_at` null. Campaign was paused; fake outbound was disabled again. |
| Inbox / reply | Blocked | No conversation was created because the fake outbound message did not pass the human send-approval stage. Inbox is empty. No inbound reply or intent classification was processed. |
| Qualification | Partial | Prospect score and configured contribution evidence are visible. The pipeline fixture does not expose the need/authority/budget/timeline/fit breakdown, and there is no reply-driven qualification in this run. No second scoring algorithm was found in the frontend; it renders server-provided scores. |
| Pipeline / opportunity | Partial | Company, Proposal Ready stage, qualification label, proposal count, and recent proposal activity are shown. The list displays ₹0 while detail says “Not estimated”; no next action is recorded and detailed qualification is unavailable. |
| Meeting | Blocked | Meeting workspace clearly says TEST MODE and no external invite is sent. There is no conversation or scheduling request to continue from, so no fake slot or booking could be exercised. Calendar configuration remains fake and disabled. |
| Proposal | Pass | Version 2 has the correct prospect/opportunity/contact, qualification, scope, deliverables, exclusions, timeline, terms, and internal notes. Approved catalog pricing and human override are distinguished; currency and discount are explicit. |
| Proposal approval / readiness | Pass with caveat | Proposal was already approved at entry. I marked it Ready to Send through the UI. Status and opportunity stage updated to Ready to Send / Proposal Ready. The UI explicitly says this does not send or share the proposal. No delivery was made. |
| Approval Center | Partial | It correctly showed the pending proposal review before it was completed and showed no pending approvals afterward. The failed send path never produced the expected human send-approval card. |

### Cross-workspace navigation

The seven primary salesperson destinations are Home, Prospects, Outreach, Inbox, Pipeline, Meetings, and Proposals. Prospects links into Prospect 360; the prospect and proposal views link into Pipeline; Prospect 360 links into Inbox, Meetings, and Pipeline. Management, Setup, and Operations are separate groups and are hidden for non-manager roles in the frontend. The owner/manager role was exercised; a separate salesperson account was not available to verify its rendered navigation.

The main dead end is the workflow transition failure after draft approval/enrollment. Since the fake send-approval card is never created, there is no route to Inbox, reply, qualification, or meeting in this fixture run. Prospect 360 also does not surface the queued outbound ledger entry.

## Business language and human control

| Finding | Classification |
|---|---|
| `Workflow cannot transition from PROPOSAL to OUTREACH_PREPARATION` is displayed directly in the salesperson workspace. | **SHOULD FIX** — internal stage keys expose implementation detail and do not tell the user how to recover. |
| Campaign template title includes an opaque short identifier, e.g. `Approved AI draft f0b72282 v1`. | **ACCEPTABLE TECHNICAL DETAIL** for a manager-only template list; it is not a raw full UUID or prominent sales content. |
| Provider label `fake` appears in Setup/campaign activity. | **ACCEPTABLE TECHNICAL DETAIL** because TEST MODE and no-external-send language are also explicit. |
| Proposal review retains “Draft version 2 is saved for human review” after status becomes Ready to Send. | **SHOULD FIX** — this is stale state wording. |
| Proposal approval note says approval does not send/share and defines Ready to Send as a later controlled step. | **PASS** — consequential-action boundary is clear. |

Overall policy intent is clear in the UI: humans review outreach and proposals; the fake campaign and calendar visibly declare test behavior. The transition errors undermine that contract because persisted changes are reported as failed and the send-approval stage is skipped. No proposal was sent. No live AI, email, calendar, or S3 provider was called.

## Provider truthfulness

| Integration | Observed state |
|---|---|
| Outbound email | **TEST / FAKE**. Enabled only during this local check, then disabled. The ledger message is queued and unsent; no fake provider call completed. |
| Calendar | **TEST / FAKE**. Fake scheduling configured, disabled; no booking or invite was created. |
| AI | **Configured, not runtime verified**. No live provider call was made. |
| S3 | **Configured, not runtime verified**. No S3 credentials or runtime check were used. |

## Defect register

| ID | Screen | Problem | Severity | Blocks internal pilot? | Recommended fix | Estimated effort |
|---|---|---|---|---|---|---|
| G-01 | Outreach approval, Campaign enrollment/execution, Approval Center | Workflow-stage transition errors happen after business records are committed. Marketing approval and recipient enrollment persisted while the UI reported failure; campaign execution failed before the human send approval was created. This leaves a queued unsent message and an inconsistent user-facing result. | **P1** | **Yes** for reliable end-to-end operation | Make workflow-event recording and the triggering business mutation recoverable and idempotent together; define legal transitions for resumed/already-advanced workflows; ensure the UI reports the committed state and presents a safe recovery path. | 2–4 days, including integration coverage |
| G-02 | Home / Pipeline | The next action is “not recorded”; no clear task is offered for this opportunity. | **P2** | No | Surface a concrete owner/action or an explicit “no next action set” control where the salesperson works. | 1–2 days |
| G-03 | Pipeline list | Missing value is formatted as ₹0 in the list but “Not estimated” in detail. | **P2** | No | Use the same explicit missing-value label in both views. | 0.5–1 day |
| G-04 | Proposal Review | “Draft version 2 is saved for human review” remains visible after status changes to Approved/Ready to Send. | **P2** | No | Make the status message depend on proposal/version state. | 0.5 day |
| G-05 | Prospect 360 | Queued outbound/campaign enrollment is not included in the prospect activity summary; the screen says no conversation is linked. | **P2** | No, but obscures delivery progress | Include an honest queued/unsent activity state, clearly distinguished from a sent message. | 1–2 days |
| G-06 | Pipeline qualification | This fixture has no displayable need/authority/budget/timeline/fit detail, so users see “Detailed qualification information is not available.” | **P2** | No | Validate the production qualification payload shape and provide a clear missing-dimensions state. | 1 day if payload mapping; 2–3 days if API contract change |

No P0 defect was found. G-01 is nevertheless a pilot blocker because the core review/send path does not produce a trustworthy result in the attempted UI journey.

## Acceptance score

| Area | Score |
|---|---:|
| Navigation / Information Architecture | 8/10 |
| Home / Actionability | 6/10 |
| Prospect Management | 8/10 |
| Prospect 360 | 7/10 |
| Outreach | 5/10 |
| Inbox / Conversation | 3/10 |
| Pipeline / Opportunity | 6/10 |
| Meetings | 5/10 |
| Proposals | 8/10 |
| Approval / Human Control | 6/10 |
| **Total** | **62/100** |

Separate assessments:

- **Salesperson usability:** 6/10 — strong prospect and proposal context, but no actionable next step and the outreach path returns an internal workflow error.
- **Sales Manager usability:** 6/10 — review screens and test boundaries are clear; partial commits and failed workflow advancement are not.
- **Admin Setup Readiness:** 7/10 — fake/unverified provider states are represented truthfully, but outbound/scheduling remain disabled and live integrations are unverified.
- **Technical Operations Separation:** 8/10 — seven sales destinations are separated from manager/setup/operations groups. The workflow-stage exception leaks technical keys into sales UI.

## Internal pilot decision

**C. PRODUCT UX NOT ACCEPTED**

The surface areas are coherent individually, but the required outreach approval → fake send approval → inbox journey cannot complete reliably from this UI run. The existing service-level end-to-end test passes, but the visible action returned failure after committing state and the campaign job failed before human send approval. Resolve G-01 and rerun the acceptance journey before an internal pilot.

## Recommended next sprint

**E. Another UX remediation sprint.** Prioritize G-01 first, then align action-center next steps, proposal status wording, missing-value rendering, and queued-message context. Genuine prospect discovery or live provider integration would expand the system before its current human-controlled workflow reports outcomes reliably.

## Verification notes

- **End-to-end feature test:** 2 passed / 81 assertions (`AcquisitionWorkflowEndToEndTest`). The focused backend journey passes with fake services and a clean workflow fixture.
- **Actual UI fake outbound:** not sent. One `fake` outbound ledger entry remains `queued` with `sent_at` null; no `SEND_OUTREACH` pending approval exists. Campaign is paused and fake outbound is disabled after the check.
- **Reply, qualification-from-reply, and meeting booking:** not exercised because the send-approval stage was not reached and no conversation was created.
- **Proposal readiness:** UI showed `Ready to Send`; no proposal delivery was created.
- **Application code:** unchanged during this acceptance task. Only this report was added; the temporary Vite proxy configuration was outside the repository.

---

## RE-ACCEPTANCE RESULT — Sprint 1C (2026-10-06)

**Verdict: C. PRODUCT UX NOT ACCEPTED**
**Score: 62/100 — unchanged pending full re-acceptance.**

The original failure history above is retained as observed evidence. Sprint 1C reproduced the workflow defect with an HTTP Feature test before changing application code. The request returned 422 because `marketing_approved` targeted `OUTREACH_PREPARATION` while the workflow was already at `PROPOSAL`; the approval had committed first.

The fix now records a valid, older business event without regressing the current workflow stage, while still rejecting unreachable forward transitions. Marketing approval and workflow event recording share a transaction. A targeted failure-injection test proves a workflow error rolls the draft/template/audit mutation back. The human send-approval boundary is unchanged. The repeated-approval request is rejected and creates no duplicate template.

Automated results available at this re-acceptance point:

- Red-before-fix HTTP regression: reproduced `Workflow cannot transition from PROPOSAL to OUTREACH_PREPARATION.`
- Marketing approval Feature tests: 11 tests / 80 assertions passed.
- Phase 2F integration journey: 2 tests / 85 assertions passed. Marketing approval in this test now traverses the Vue-facing HTTP endpoint; subsequent async/domain steps retain their existing deterministic test execution.
- Full PHPUnit suite: 125 tests / 780 assertions passed.
- TypeScript, Vite production build, PHP syntax validation, route registration (165 routes), and `git diff --check` passed.

The visual fixture password and active tenant membership were verified read-only. A fresh local Laravel/Vite stack, using a process-only Sanctum stateful-domain addition for its temporary Vite port, successfully signed in and rendered Home. The only available fixture is still in the prior advanced state, with a paused campaign and disabled outbound configuration. I did not mutate that data or claim the requested campaign-to-proposal UI journey passed. The requested complete HTTP positive journey, stop journey, and UI re-acceptance have not been completed, so this gate remains C and the score is not increased based on tests alone. No real email, calendar, AI, or S3 provider was called.

**Remaining blocker:** complete and verify a separate clean deterministic HTTP/UI journey for approval → fake send authorization → fake send/reply → qualification → opportunity → fake meeting → proposal approval/readiness; then run the full test suite and final verification commands. Until then Sprint 1C is partial and the product UX is not accepted.

---

## Deterministic browser enablement and final attempt (2026-10-06)

The earlier “no runtime fake-AI adapter” limitation is resolved. The local-only `DeterministicAIProvider` uses the existing provider interface and router, and a dedicated reset command seeds a new fictional Northstar tenant before outreach. `AI_DETERMINISTIC_ENABLED=true` is required and registration fails outside `local`/`testing`. A local acceptance panel names DeterministicAIProvider, FakeOutboundMessagingProvider, FakeSchedulingProvider, the queue driver, and the no-external-action boundary. The reset command is `php artisan product:acceptance-reset`; local browser credentials are `sprint-1c-acceptance@example.test` / `Local-Acceptance-2026!`.

The local run used PostgreSQL, Redis, and Horizon (`php artisan horizon:status`: running; fixture queue: `redis`). The app ran on port 8001 and Vite used an untracked temporary proxy config from `/tmp`; Sanctum included the Vite origin as a process environment value. A local marketing draft was approved through the UI, then a zero-delay approved template step was added, the fictional contact enrolled, and the campaign activated through the campaign UI. Horizon processed the normal campaign jobs and created a human `SEND_OUTREACH` approval. The Approval Center displayed the fake/test outbound consequence and contact context. The test-only status shows fake providers and no external email or calendar action.

The attempt stopped at the send approval click. The browser-control click timed out before any request/response was observed. A new tab still showed the approval in the Approval Center; the dedicated tenant's outbound message remained `queued` on provider `fake`, with no confirmed sent receipt. The approval was not counted as approved. No fake delivery/reply was simulated; Inbox, FollowUpAgent, SalesAgent, qualification, Pipeline, meeting booking, proposal generation/approval/READY_TO_SEND, unsubscribe journey, and responsive inspection were not reached through the browser. No proposal delivery was created. The 62/100 historical score is retained because the full UI journey did not succeed; no fresh UX score or acceptance verdict improvement is claimed.

During the attempt, bodyless campaign-step deletion returned HTTP 419 (`CSRF token mismatch`) because the shared campaign request helper did not send the XSRF header without a request body. The helper now attaches XSRF to every state-changing method. A second local label issue was corrected: an outbound-only conversation thread is no longer described as an inbound reply. The dedicated reset was rerun after each fix. The browser flow subsequently reached campaign activation and send approval, but it remains incomplete as described above.

---

## Sprint 1C final acceptance completion attempt (2026-10-06)

**Verdict: C. PRODUCT UX NOT ACCEPTED**
**Score: 62/100 — unchanged; full UI journey remains unproven.**

The stale acceptance tenant was not used or modified. A new resettable deterministic pre-outreach fixture was added to `tests/Feature/ProductizedSalesJourneyTest.php`. It creates a distinct tenant, campaign, prospect, evidence, contact method, and fake providers per test run.

### Verified HTTP and deterministic job-handler journey

Positive chain: marketing draft approval → campaign step/enrollment/activation → send approval → fake outbound provider accepted and HTTP status webhook `sent` → signed inbound reply webhook → Inbox conversation read → FollowUpAgent → SalesAgent qualification with evidence → opportunity visible through sales API → fake availability/meeting booking → proposal generation/edit/commercials/approval/document → `ready_to_send`. Proposal delivery count remained zero. Replay assertions verified no duplicate enrollment/message/opportunity/meeting/proposal version and no additional fake send.

Separate unsubscribe chain: two-step campaign → first fake send → signed inbound HTTP `UNSUBSCRIBE` → FollowUpAgent → suppression + unsubscribed enrollment + cancelled workflow → attempt to run the next campaign step. No second outbound record or provider send, opportunity, meeting, or proposal resulted.

Failure consistency: injected workflow-event failure during the HTTP marketing approval call left the draft unapproved and created neither campaign template nor approval audit row.

`ProductizedSalesJourneyTest`: 3 tests / 139 assertions passed. `AcquisitionWorkflowEndToEndTest`: 2 tests / 85 assertions passed. The tests issue the UI-facing API calls and directly drain queued job handlers with deterministic fake services; they do not prove a separately supervised live queue worker journey.

### Browser journey status: BLOCKED

The local dashboard was opened and authenticated. The only configured PostgreSQL tenant is the existing Sprint 1B-2 acceptance tenant, already at proposal stage; it was left untouched. No OpenAI, Anthropic, or Gemini credentials are configured, and there is no runtime fake-AI adapter. Therefore a fresh persistent pre-outreach fixture cannot be advanced through FollowUpAgent/SalesAgent/ProposalAgent from the actual browser UI in this environment without adding test-only runtime support. The browser did not execute the requested full UI journey through `READY_TO_SEND`; HTTP test success is not counted as UI acceptance.

Final automated verification after the two-step unsubscribe assertions: 128 PHPUnit tests / 919 assertions passed; `AcquisitionWorkflowEndToEndTest` 2 / 85 and `ProductizedSalesJourneyTest` 3 / 139 passed; all PHP files under `app`, `tests`, `routes`, and `database` passed syntax validation; 165 routes registered; TypeScript and Vite production build passed. These results close automated HTTP journey coverage but do not close the browser UI gate. Do not raise the UX score or mark Sprint 1C complete until the actual browser journey passes with a fresh fixture.

## SEND approval trace update (2026-10-06)

`OrchestrationWorkspace.vue` binds the approval button to `decide(row, 'approve')`; HIGH/CRITICAL approval first opens `window.confirm`, then sends `POST /api/v1/automation/approvals/{id}/approve` with `{}` and tenant/XSRF headers. The browser's click command timed out before returning a network trace, but the same authenticated tenant then showed zero pending approvals; a read-only DB query confirmed the fixture's approval is `EXECUTED` and the review timestamp is present. Therefore the handler did reach the backend. Exact HTTP status/body were not captured. The timeout is attributable to browser-control interaction with the native confirmation; it is not evidence of a backend failure. No application-code change was retained; a trial in-page confirmation was reverted.

Horizon status is running. The fixture's fake outbound record is `accepted`, provider `fake`, provider reference present, `attempt_count=1`, with accepted/sent timestamps; campaign activity contains one `message_accepted` and one `message_sent`. The record status remains `accepted`, not `sent`. The “Confirm fake delivery” control performs a fake outbound status webhook; it was not used because the task allows event simulation only for the fictional prospect reply. No inbound reply was simulated and no downstream product journey was attempted. Current fixture: inbound=0, opportunities=0, meetings=0, proposals=0, proposal_deliveries=0. This is not full UI acceptance, so the prior 62/100 remains historical and no new score is assigned.

Final checks: 3/139 ProductizedSalesJourneyTest, 2/85 AcquisitionWorkflowEndToEndTest, 133/959 full PHPUnit; 227 PHP files linted; 168 routes; TypeScript/build pass; `git diff --check` pass for tracked files. Git root is correct and origin is expected, but `main` has no ref and `git ls-files` is empty: all source plus `.gitignore` and build artifacts are untracked. The current `.gitignore` does not cover all generated outputs. The root cause of the empty index cannot be distinguished from a never-committed project vs lost/unpopulated index. No Git state was changed. No live provider calls were made.

## Final resume: outbound activity defect (2026-10-06)

The local fake outbound lifecycle is `queued → accepted → sent → delivered`. `FakeOutboundMessagingProvider` returns `accepted`; `MessageEventProcessor` advances that state when a signed provider status event is processed. The existing UI control was confirmed to route through the local fixture helper and normal signed `OutboundWebhookController` / `MessageEventProcessor` boundary.

After one UI confirmation, the fake outbound row is `sent`, provider `fake`, reference present, attempt count 1; one `sent` provider event is stored. But campaign activity now has two `message_sent` rows for that same message. `SendOutboundMessage` emits `message_sent` on the provider's `accepted` result and also emits `message_accepted`; the later actual `sent` webhook emits a second `message_sent` under another idempotency key. This is an actual state/activity inconsistency and duplicate campaign timeline activity. Stop-on-first-defect was applied; no inbound event or downstream workflow was run, and no code was changed.

Persisted stop state: campaign active; recipient completed; acquisition workflow `WAITING_EXTERNAL / OUTREACH`; inbound 0; opportunities 0; meetings 0; proposals 0; proposal deliveries 0. Inbox, agents, reply qualification, Pipeline, scheduling and proposal UI remain unverified. Responsive checks and new score were not performed; 62/100 remains historical only. No live providers were called.

The actual outer UI helper request status was not captured by browser network instrumentation; successful UI state and persisted changes confirm processing. No tests were rerun because application code was unchanged; the last verified baseline remains 133 tests / 959 assertions, including the two acceptance feature suites (3/139 and 2/85), with TypeScript/build, PHP syntax, and route checks passing.

Git diagnosis, without mutation: expected `origin` exists but has no advertised refs (`git ls-remote --heads origin` and `git ls-remote origin HEAD` were empty); `git remote show origin` reports unknown HEAD; local log and reflog are empty. Classification **B: remote exists but repository has no project history**. Local `main` is unborn and all project files are untracked. No Git state was modified.

## Outbound activity consistency remediation (2026-10-06)

Canonical lifecycle: queued is recorded when the outbound message is created; provider acceptance records only `message_accepted`; a signed provider `sent` transition records `message_sent`; a signed delivery confirmation records `message_delivered`. Acceptance still advances/schedules the campaign sequence in `SendOutboundMessage`; no progression consumer depends on the premature `message_sent`. Workflow stage/status mapping consumes `message_sent`, so it now observes only the actual signed sent transition. Webhook replay keys for persisted provider events remain provider-event-specific, while campaign semantic activities are stable per outbound message and event type.

Root cause was `SendOutboundMessage` recording both accepted and sent against the same accepted provider result, while `MessageEventProcessor` correctly recorded sent again under a separate provider-event key. The send job now records acceptance only and leaves `sent_at` empty until sent confirmation. Signed event processing now records sent/delivered activity and workflow events once and synchronizes the conversation message delivery status. No historical event rows were rewritten.

Automated verification: focused lifecycle regression **1 test / 25 assertions**; `ProductizedSalesJourneyTest` **3 / 143**, with acceptance and sent activity counts asserted across the signed sent webhook; `AcquisitionWorkflowEndToEndTest` **2 / 85**; full PHPUnit **134 / 988**. TypeScript and production build passed. PHP syntax validation passed across application, routes, tests, database, bootstrap, and config PHP files. Campaign progression after provider acceptance remains verified.

The browser journey is resumed from a clean dedicated fixture after the automated checks. Final browser traversal, test-mode side-effect checks, product UX score, and verdict will be appended only after direct UI verification; prior partial/historical reports above remain as history.

## Browser acceptance resume — meeting request UI blocker (2026-10-06)

Playwright exercised Home → Prospects → Prospect 360 → Outreach → marketing draft approval → campaign setup/enrollment/activation → human SEND approval → fake provider acceptance → normal signed fake `sent` webhook → fictional interested reply through the local inbound boundary → Inbox. FollowUpAgent and SalesAgent completed on the local queue; the Inbox showed evidence-backed qualification of 65/100 (`QUALIFIED`) and the opportunity appeared in Pipeline.

A human `REQUEST_MEETING` approval was created by the coordinator and approved in Approval Center. The UI reported success. `GET /api/v1/conversations/01a1107c-ceff-71d2-8bf9-da892e1ae4d3` returned HTTP 200 and exposed the persisted scheduling request `8a4694c6-6e88-4b8f-9647-d4d5d28ae3d6` with status `REQUESTED`, timezone `Asia/Kolkata`; conversation intent remained `INTERESTED`. There is no booked meeting.

The next UI step is blocked: Inbox renders no meeting planner (`.meeting-planner` count 0) because `ConversationWorkspace` displays it only when the conversation intent equals `meeting_request`. Approval already created a valid `REQUESTED` scheduling record, but there is no visible control for fake availability or booking while intent is `INTERESTED`. The approval action succeeded; no availability HTTP request was attempted because the action is absent. This is a newly established UI product defect. Stop-on-first-defect applies; do not infer successful meeting or proposal acceptance from HTTP feature tests.

Persisted stop state from a read-only query limited to this fixture: outbound `{sent: 1}`; campaign activities `{message_accepted: 1, message_sent: 1}`; provider events `{sent: 1}`; FollowUpAgent succeeded 1, SalesAgent succeeded 3; opportunity 1; send and meeting-request approvals `EXECUTED`; scheduling request `REQUESTED`; meetings 0; proposals 0; proposal deliveries 0. Deterministic/fake providers only. No live provider was called. Sprint 1C remains **PARTIALLY COMPLETE**; the historical 62/100 remains historical and no new score is assigned. Next action: first fix the local deterministic classification response schema mismatch, then make an approved pending scheduling request actionable in Inbox; add focused regression coverage for both and resume from the current request without resetting this fixture unless it becomes unusable.

Before stopping, the existing Inbox `Analyze latest conversation` action was tried as a possible route to scheduling. Captured `POST /api/v1/conversations/01a1107c-ceff-71d2-8bf9-da892e1ae4d3/classify` returned **503** with `{"message":"Conversation analysis is temporarily unavailable."}`. The UI showed its retry error, intent remained `INTERESTED`, and the meeting planner remained absent. Source/schema comparison suggests `DeterministicAIProvider` does not return the required `summary`, `reason`, and `risk` fields for `ConversationIntentClassifier`; this is a likely cause, not an observed log trace. No more workflow actions were taken. This second failure prevents attributing the issue solely to planner conditional visibility.

## Latest Sprint 1C browser resume — booking validation blocker (2026-10-06)

The classifier root cause was subsequently confirmed as `RuntimeException: Structured output at $ is missing required field [summary]`; the deterministic provider was corrected to satisfy the classifier schema. Inbox now displays a persisted actionable scheduling request when the intent is `INTERESTED`. Focused automated tests pass: **12 tests / 202 assertions** across classifier, conversation workflow, and product journey feature tests.

Playwright on the preserved fixture verified Inbox classify HTTP 200, planner visibility, and fake availability. Slot selection returned HTTP 200. Booking stopped the browser journey with **HTTP 422** at `/api/v1/scheduling-requests/8a4694c6-6e88-4b8f-9647-d4d5d28ae3d6/book`: `The selected time no longer matches current scheduling rules. Refresh availability and select another time.` The browser displayed `Oct 7, 2026, 3:30 PM · Asia/Kolkata`, while selection/persistence returned `2026-10-07 10:00:00+05:30`, or 04:30 UTC. The persisted slot fails the configured UTC workday start of 09:00. This identifies a slot instant mismatch across availability response and PostgreSQL persistence/validation; fix and verify the timestamp round-trip against PostgreSQL before resuming.

At stop: request `SELECTED`, selected offer 1, meetings 0, proposals 0, proposal deliveries 0, outbound messages 1. Proposal UI and later journey steps were not attempted. No live AI, outbound, calendar, or proposal delivery providers were called. No acceptance score was recalculated; historical score 62/100 remains only a prior score. Sprint 1C remains **PARTIALLY COMPLETE**. Resume on this fixture without resetting it after the scheduling defect is fixed.

## Final Sprint 1C acceptance result — 2026-10-06

The classifier, meeting planner, PostgreSQL slot timestamp mismatch, and proposal zero-discount UI/validation mismatch were corrected narrowly. The deterministic proposal fixture was also adjusted to honor an exact requested service match among approved tenant services. Earlier findings and the historical score remain above for audit history; this section records the completed continuation and current decision.

| Acceptance step | Result | Evidence |
|---|---|---|
| Classification | Pass | Real error was missing required `summary` in deterministic structured output; local Inbox classify returned 200 after fix. Malformed responses still fail schema validation. |
| Meeting planner | Pass | Existing `REQUESTED` scheduling request remained actionable with `INTERESTED` intent; API detail includes request ID/status/timezone/duration. |
| Availability and slot selection | Pass | Fake provider returned date/time/timezone slots; selected slot API returned 200. Explicit offset persistence preserved the exact instant in PostgreSQL. |
| Fake booking | Pass | Booking API returned 201. One `SCHEDULED` meeting is tied to the same request, conversation, opportunity, contact and selected slot; provider is `fake`. |
| Meetings workspace | Pass | Prospect/context links, Oct 7 2026 3:30–4:00 PM Asia/Kolkata, scheduled status, and TEST MODE/no external invite are visible. |
| Proposal generation/review | Pass | Proposal was generated and regenerated through the UI; v2 matches the requested approved ecommerce service. Commercials use approved catalog pricing: INR 120,000, no discount. |
| Human approval / READY_TO_SEND | Pass | UI persisted human approval, generated a local PDF, then marked proposal `ready_to_send`; opportunity stage advanced to `PROPOSAL_READY`. |
| External delivery boundary | Pass | PostgreSQL proposal delivery count is 0; no proposal sent/shared, and outbound message count remains the existing single fake outreach. |

Final PostgreSQL state for the preserved tenant: scheduling request `BOOKED`; meeting 1; proposal v2 `ready_to_send`; proposal delivery 0; outbound messages 1. The selected slot and meeting starts are the same instant (`2026-10-07 15:30:00+05:30`). No database fixture reset occurred.

Final automated results: `ProductizedSalesJourneyTest` 3 tests / 151 assertions; `AcquisitionWorkflowEndToEndTest` 2 / 85; PHPUnit 137 / 1,012; TypeScript passed; Vite production build passed; PHP syntax validation passed for 250 files. Responsive inspection covered Inbox, Meetings, and Proposal Review at 1440, 1280, 768, and 390 CSS pixels; body/document widths equaled viewport widths throughout. The active planner was visually verified at desktop before booking; subsequent Inbox displays the booked meeting history. Mobile uses a compact two-row nav and vertically stacked proposal controls.

Current acceptance score: Navigation/IA 8/10; Home 7/10; Prospect Management 8/10; Prospect 360 8/10; Outreach 8/10; Inbox 9/10; Pipeline 8/10; Meetings 9/10; Proposals 8/10; Approval/Human Control 9/10. **Total 82/100.** The remaining non-blocking issue is stale proposal copy (“Draft version 2 is saved for human review”) after approval/readiness. No P0 defect was found in the completed journey. Current verdict: **B. PRODUCT UX ACCEPTED WITH NON-BLOCKING FIXES**.

No live AI, outbound email, calendar, proposal delivery, or S3 provider was called. Git state was not modified. Sprint 1C acceptance is complete for the local deterministic/fake-provider journey; this does not claim production readiness or live-provider verification.
