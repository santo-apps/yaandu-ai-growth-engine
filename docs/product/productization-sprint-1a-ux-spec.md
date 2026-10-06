# Yaandu AI Growth Engine
## Productization Sprint 1A — Sales UX Architecture & Screen Specification

**Document status:** Product / UX specification. This is not an implementation plan for backend redesign. Existing domain services, API contracts, tenant boundaries, approvals, and deterministic execution remain authoritative. Unsupported capabilities are labeled explicitly.

**Evidence basis:** Repository inspection of `src/App.vue`, the five existing Vue workspaces, `routes/api.php`, Phase 2 controllers/services, `.env.example`, queue/provider configuration, tests, and architecture documents. The product audit found 164 registered routes and a verified fake-provider journey; this specification does not claim live integrations or deployment verification.

## 1. Executive UX Direction

Build the interface around the salesperson’s work queue and prospect lifecycle, not backend modules. Keep the existing Laravel APIs and services as the system of record. Reuse `CampaignWorkspace`, `MarketingWorkspace`, `ConversationWorkspace`, `ProposalWorkspace`, and `OrchestrationWorkspace` capabilities behind task-oriented pages; reorganize and simplify their presentation rather than replacing the underlying workflow.

The product’s central object should be the **Prospect**: a company with its public website evidence, score, contacts, outreach, conversation, opportunity, meetings, proposal, and next action. **Prospect 360** is the consistent detail view, with Opportunity as a linked sales record rather than a second disconnected company page.

Use plain labels for business users. Show AI output as a recommendation with cited evidence and a human decision boundary. Show fake/test/live integration state wherever an action could send, book, or contact an external party. Do not put agent IDs, model IDs, raw JSON, or queue state in normal sales workflows.

Preserve the product rule: **Agents recommend. Policies authorize. Deterministic services execute. Humans control consequential actions.** The UI must not imply that an action executed merely because an AI draft exists, an approval is recorded, a meeting slot is displayed, or a proposal reaches `READY_TO_SEND`. Keep tenant authorization, suppression, sending windows, immutable approval snapshots, and commercial controls in their existing backend services.

### Capability boundary

- **AVAILABLE NOW:** user-supplied prospect seeds (one at a time in the UI; bounded candidate-array API), manual company records, website scans/intelligence, deterministic lead scoring, contact provenance, campaign/sequence configuration, AI drafts, human review/approval, fake outbound/inbound, conversation analysis/handoff, qualification, fake scheduling, proposal drafting/commercial review/PDF/approval/ready-to-send, and workflow/approval APIs.
- **PRODUCTIZATION SPRINT 1B (UI over existing APIs):** sales-first shell/dashboard, prospect list and 360, actionable inbox, pipeline, meetings, approval center, simplified proposal review, setup states, and role-aware navigation where backend authorization already supports it.
- **REQUIRES PRODUCTIZATION SPRINT 2 (backend/integration):** true third-party prospect discovery, CSV/bulk import and review ledger, robust assignment/notifications, live email/calendar adapters, provider health checks, and operational health integrations. The UI must not simulate these as if available.

## 2. User Personas

| Persona | Main jobs | Primary permissions/experience |
|---|---|---|
| Sales Executive | Review prospects, contact prospects, handle replies, qualify, manage own opportunities, schedule, prepare proposals | Sales workspaces only; assigned-record access as enforced by APIs; no model/queue internals |
| Sales Manager | Prioritize team work, review approvals, reassign/coach, inspect pipeline, set sales policy | Sales workspaces plus team-level approvals/pipeline/automation where current backend allows |
| Owner / Admin | Configure tenant, services/pricing, approved knowledge, AI task routing, messaging/scheduling policy, user provisioning coordination | Setup and tenant policy screens; sensitive writes remain role-gated |
| Operations / Technical Admin | Diagnose provider/queue/agent failures and deployments | Separate Operations area, restricted and explicitly technical |

The repository currently has tenant roles such as `owner`, `admin`, and default `member`; a dedicated `sales_manager`/`salesperson` role model and team administration UI are not present. UX role names must map to actual authorization before being exposed as a promise.

## 3. Proposed Information Architecture

### Sales navigation — 7 items

1. **Home** (current Dashboard)
2. **Prospects** (merge Companies, Company Details, Website Intelligence, Contacts, Lead Scores)
3. **Outreach** (Campaigns plus the sales-facing part of Marketing Intelligence)
4. **Inbox** (Conversation Inbox)
5. **Pipeline** (new first-class UI over opportunity APIs)
6. **Meetings** (new first-class UI over meeting APIs)
7. **Proposals**

### Management

- **Approvals** (business-language approval center)
- **Automation** (manager/admin only; explain modes and actions in business language)

### Administration

- **Setup** (guided configuration checklist and integration states)
- **Integrations** (AI, email, calendar, storage health/configuration; secrets remain out of browser)
- **ICP & Scoring**
- **Services & Pricing**
- **Team** (only when provisioning/role API is available; otherwise show managed externally with a clear explanation)

### Operations

- **System Health** (queue/provider/failed job and agent health; requires new operations data/API)
- **Agent Runs** and **Workflow Timeline** live under System Health / advanced diagnostics.

Keep seven primary sales items. Do not expose Company Details, Website Intelligence, Contacts, Lead Scores, Agent Runs, Workflows, AI Configuration, and policy internals as peer top-level destinations. Deep links should retain company/opportunity context.

## 4. Application Shell

- **Sidebar:** role-aware groups: Sales, Management, Administration, Operations. Default Sales Executive sees only the seven sales destinations. Collapse Administration/Operations; never show technical areas as a peer to Inbox.
- **Top bar:** tenant switcher (only active memberships), global prospect/company search, contextual breadcrumb, notification bell with actionable counts, and profile menu (account, tenant, sign out). Display tenant name prominently to reduce cross-workspace mistakes.
- **Global search:** search companies, contacts, conversations, and opportunities within the selected tenant. This requires a cross-domain search API; until then use clearly labeled prospect-only search.
- **Quick Action (`+ New`):** Add Prospect (available), Start Discovery (seed entry available; clearly marked limited), Create Campaign (available), Schedule Meeting (available from a conversation/opportunity), Create Proposal (available from an opportunity). Import Prospects must be disabled/marked “Coming in Sprint 2” until import exists. Do not promise arbitrary bulk outreach.
- **Breadcrumbs:** Home → Prospects → Company → Opportunity / Proposal, preserving context and back navigation.
- **Page header:** one business goal statement, one primary action, contextual secondary actions; do not repeat the page name in eyebrow + title + card title.

## 5. Dashboard Specification

### Action Center (priority order)

1. **Replies needing attention** — unread/oldest first; company, contact, short message, intent, owner, age; action: Open reply.
2. **Approvals waiting** — action in plain language, who is affected, risk, and what approval does; action: Review.
3. **Hot prospects** — high score plus contact availability and no active outreach; action: Review prospect / Prepare outreach.
4. **Opportunities due for follow-up** — next-action date, owner, stage; action: Open opportunity.
5. **Upcoming meetings** — local date/time plus timezone, company/contact, owner; action: Open meeting.
6. **Proposals to review** — status, total/currency when present, version; action: Review proposal.

Prioritize overdue/high-risk human work before new prospecting. Hide empty categories or show concise empty states with a relevant action.

### Sales Snapshot

Six compact, clickable counts: Prospects; high-potential prospects; active conversations; open opportunities; upcoming meetings; proposals awaiting review. Define time range and count semantics in the UI. Avoid “active agent runs” as a sales KPI.

### Recent Activity

Only business events: reply received, prospect shortlisted/ignored, outreach approved/sent, opportunity stage/value changed, meeting booked/cancelled, proposal approved/ready. Show actor, timestamp, company, and a link to the affected record. Agent/job events remain in Operations.

### States

- **Empty:** explain that no sales work is waiting; offer Add Prospect or Review Prospects.
- **Loading:** stable skeletons preserving layout; do not display zero counts as if loaded.
- **Error:** keep last successful data, identify the affected section, Retry; do not fail the whole dashboard for one widget.
- **Delayed:** show “Updates may be delayed” and last refreshed time if the API/queue status supports it. Queue health itself requires operations data.

## 6. Prospects Specification

### Prospect List

Use a searchable, filterable table on desktop and stacked cards on narrow screens. Default columns: **Company**, **Score**, **Primary contact**, **Website health**, **Outreach/conversation status**, **Last activity**, **Next action**. Industry/location can be shown as muted secondary text under company rather than extra wide columns. Opportunity level/status should be a compact badge only when one exists.

Keep row density moderate: one primary identity line, one metadata line, status badges, and an explicit Open action. Do not show every field (source, timestamps, IDs, serialized score) in the row.

### Filters and search

Search company/domain/contact; filters for score band, industry, location, website health, contact available, outreach state, conversation state, and opportunity stage. Preserve filters when opening/returning from Prospect 360. Existing company API search/pagination can be reused, but Vue currently does not expose search or pagination. Composite filters may require a small API change.

### Bulk selection and actions

- **Available after UI work:** select prospects and shortlist/ignore if backed by a persisted status.
- **Needs backend contract:** shortlist/ignore state and audit trail are not currently visible as prospect actions; do not simulate using company status without agreement.
- **Bulk outreach:** only proceed after each selected prospect has eligible contact, no suppression, campaign/sequence compatibility, and a preview of recipients/messages. Existing campaign enrollment APIs are candidate reuse; bulk UX and partial-failure reporting need implementation review.

Do not add a bulk “send” action. Bulk action creates/reviews drafts and still respects individual recipient approvals and send-time policy.

## 7. Discovery / Import Specification

Entry page has three cards with honest status:

| Entry mode | Status | Contract |
|---|---|---|
| Add manually | AVAILABLE NOW | Name, public website, industry, location, optional description; validate URL and show duplicate match before save |
| Add seed candidates | AVAILABLE NOW, limited | Current DiscoveryAgent registers user-supplied candidates. UI currently submits one candidate. Explain that it does not search the web for companies |
| AI Discovery | REQUIRES PRODUCTIZATION SPRINT 2 | Only launch after a compliant discovery source adapter exists. Planned fields: industry, location, company characteristics, website criteria, technology criteria, target count |
| CSV Import | REQUIRES PRODUCTIZATION SPRINT 2 | Upload preview, column mapping, validation, duplicate review, row-level errors, confirm import; no current import API |

### Future discovery job review (Sprint 2)

Show job owner, source/provider, submitted criteria, progress/results counts, and provenance. Results table supports duplicate, accept, reject, shortlist, and open Prospect 360. Never imply “AI found” means verified. Show source and observation time per candidate. Design states: queued, searching, review ready, completed with no matches, partial errors, failed/cancelled. Job progress/record-level review requires backend state not currently available.

## 8. Prospect 360 Specification

### Header

Company name/domain; lead-score badge and potential band; prospect status; primary contact and confidence/verification; assigned owner; next action and due date. Primary buttons depend on state: Review intelligence, Add contact, Prepare outreach, Open conversation, Create opportunity, Schedule meeting, Prepare proposal. Hide actions that are not currently eligible and explain why on demand.

### Information hierarchy

Use an Overview landing page plus a small secondary tab set: **Overview**, **Intelligence**, **Outreach & Conversation**, **Opportunity**, **Meetings & Proposals**, **Activity**. Contacts are a prominent section/card on Overview and a filtered subsection, not a detached prospect-management destination. On narrow screens, tabs become a labeled select or scrollable accessible tab row.

### Overview answers

- Who are they? Company, industry, location, website, source.
- Why a prospect? Score, fit factors, evidence and missing data.
- What may Yaandu improve? Evidence-backed website findings mapped to an approved service/capability; if no approved mapping exists, say “Potential opportunity — review evidence,” not a claim.
- Who should we contact? Public contacts, role, provenance, confidence, verification.
- What has happened? Outreach, replies, opportunity, meeting, proposal summary.
- What next? One prioritized action from deterministic business state; AI suggestions are labeled recommendations.

## 9. Website Intelligence Presentation

Render each finding as an **Evidence Card**:

1. Finding title in plain language.
2. **Business impact** — only a cautious interpretation supported by the captured evidence; mark inference as potential.
3. **Yaandu opportunity** — link to a tenant-approved service/capability where a mapping exists; otherwise leave unassigned.
4. Severity and confidence as secondary signals, with a tooltip explaining what the values mean.
5. Evidence: screenshot crop/full screenshot, page title/source URL, observed excerpt, capture time. Provide text alternative and source link.

Screenshots are a visual aid, not proof on their own. Label desktop/mobile viewport and scan date; show unavailable/capture-failed state. Keep raw technical identifiers, HTTP metadata, and scan error codes behind “Technical details” for authorized operators. Never fabricate business impact or screenshots.

## 10. Lead Score Presentation

Use a score badge plus text band: **82 — High potential**. Explain band thresholds and version date. “Why this score?” lists human labels with sign and points, such as `+ Website improvement opportunity`, `+ ICP industry match`, `? CRM usage unknown`. Each supported factor links to its evidence; missing information is not treated as a negative fact unless scoring rules explicitly say so.

Recommended next action should be one of: **Review**, **Find a contact**, **Prepare outreach**, **Keep researching**, or **Ignore**. It should be deterministic from score/contact/suppression/workflow state and clearly distinguish AI recommendation from executable action. Raw JSON belongs in Operations, not the score view.

## 11. Contacts UX

Inside Prospect 360, show name, role, public business email/phone, source page, observed date, extraction method, confidence, verification state, and selected/preferred state. Badges:

- **Verified:** only when a defined verification process actually confirmed it.
- **Publicly listed:** found on an identified public business page, not independently verified.
- **Low confidence:** extraction confidence below a documented threshold.
- **Unknown:** no value available; never infer/fill it.

The current UI shows source, time, extraction method, and confidence but not the method verification status in a salesperson-friendly way. Contact values are intentionally decrypted for authorized tenant API use; do not display ciphertext, hashes, or internal storage fields. Contact selection for outreach should require explicit selection and a suppression/eligibility check before message generation/enrollment.

## 12. Outreach Specification

Recast `CampaignWorkspace` and the sales-facing portions of `MarketingWorkspace` as one Outreach area with:

- **Campaigns:** state, audience count, owner, schedule, performance/event counts, test/live provider label.
- **Campaign detail:** Audience, Sequence, Messages, Performance. Show sequence order and delays as a timeline, not backend step rows.
- **Create outreach flow:** select prospects → choose/create campaign → choose contact per prospect (or review proposed contact) → generate personalization → review recipient/message pairs → approve → send/queue.

Before approval show **who**, **what**, **why personalized**, **when**, and **approval required**. A blocked recipient should have a plain reason: suppressed, no eligible contact, outside sending window, campaign paused, limit reached, or missing approval. Only display a reason the backend actually provides; don’t infer one from a generic error.

Current campaign APIs cover templates, sequence steps, enrollment, pause/resume, messaging configuration, and outbound ledger. Current UI workspaces need consolidation. External email performance/delivery data remains dependent on a live provider/webhook adapter.

## 13. AI Message Review

Review one recipient/message pair at a time, with a batch navigation rail if reviewing many. The content panel shows:

- Prospect and selected contact, including public source/confidence.
- Subject and editable message body.
- **Personalization rationale** in business language.
- Evidence used, with direct source/page and excerpt.
- Approved Yaandu claims used, distinguished from prospect facts.
- Warnings: unsupported claim, missing contact confidence, suppression, unresolved review, provider/test status.
- Labels for **AI draft**, **Human edited**, and **Approved**; approval applies to a defined version/snapshot.

Actions: **Edit**, **Regenerate**, **Approve**, **Reject**. Regeneration creates a new version and must not erase the reviewed draft. No send on “Approve” unless the explicit send step is separately explained and authorized. Existing MarketingWorkspace provides evidence and draft actions; campaign template approval is a different state and must not be confused with recipient-level send approval.

## 14. Sales Inbox Specification

### List

Default sort: attention required, then oldest unread/oldest waiting. Show unread marker, contact, company, latest message excerpt/time, intent, qualification band, opportunity stage, owner, next action, age. Fit in one row on desktop; collapse metadata in cards on mobile.

Filters: Needs Attention, Interested, Meeting Request, Pricing Request, Proposal Request, Human Handoff, Not Interested, Unread, owner, age. **Not Interested/Unsubscribe** must remain visible for audit but never appear as outreach candidates.

The current Inbox only toggles all conversations vs handoff queue. Intent/unread filters and true unread state require API support.

### Detail

One continuous message thread; header with company/contact, owner, status, intent/confidence; then concise AI summary and recommended next action. Keep evidence citations expandable and linked. Qualification, opportunity, meeting, and proposal summaries appear in side panel or mobile sections. Reply draft supports edit/reject/approve, with explicit “Approval queues reply” wording and clear provider TEST/LIVE label. Keep model/provider and agent-run details out of normal view.

## 15. Qualification UX

Show Need, Authority, Budget, Timeline, Fit as five dimension rows/cards. Each shows status, plain meaning, supporting evidence/source, last updated, and missing information. Meanings:

- **UNKNOWN:** not established in the conversation/evidence.
- **WEAK:** limited or indirect evidence.
- **MODERATE:** some specific supporting evidence, still needs confirmation.
- **STRONG:** clear, direct evidence; not a guarantee of purchase.

Display Qualification Score and Level with the scoring rubric and a next step (ask a question, confirm decision role/budget, schedule discovery, or mark not qualified). A human may correct a dimension, but must supply a reason/evidence where relevant; preserve actor, previous value, new value, source, and timestamp in the existing activity/audit model. API accepts manual dimensions/evidence, but current inbox does not expose this as a usable editor.

## 16. Pipeline Specification

Make Pipeline a first-class view. Start with **List** as default for accessibility and precise filtering; offer **Kanban** as an alternate once assignment and stage updates are clear. Do not implement drag/drop in Sprint 1B: a stage change has business meaning and should use a deliberate action with confirmation/undo guidance. Forecast view is future work; current opportunity values are optional estimates, not committed forecasts.

Use actual stages from `SalesPolicy`: New, Engaged, Discovery, Qualified, Meeting Ready, Proposal Ready, Closed, Not Qualified. Cards/rows show company, contact, qualification, estimated value/currency, owner, last activity, next action, meeting, proposal status. Filters: owner, stage, value/currency, age, next action, qualification.

Opening a card opens Opportunity Detail, not a generic company record. Existing `/api/v1/opportunities`, `/api/v1/sales-pipeline`, stage/value/qualification, activities, meeting, and proposal APIs are reusable. List filtering/assignment/next-action fields may need API changes.

## 17. Opportunity Detail

Consolidate company/contact, stage/value/owner, qualification and evidence, conversation summary, activities, meeting, proposal, and next action. Header actions are stage-eligible: update stage, schedule, prepare proposal, close/not qualify. Value is clearly marked **estimate** until contractually confirmed; edits are audited and currency remains explicit. Timeline contains meaningful business events, not raw workflow event JSON. Provide links to Prospect 360 and full conversation without losing opportunity context.

## 18. Meetings Specification

Dedicated Meetings workspace with tabs: **Upcoming**, **Requested**, **Completed**, **Cancelled**. Rows show company/contact, owner, local date/time and timezone, status, opportunity, and preparation/next action. Open a meeting detail for conversation context, agenda/prep notes, reschedule/cancel, and completion/no-show.

Use the existing scheduling workflow/provider router from Inbox, Prospect, or Opportunity. All entry points open the same meeting composer/request and persisted scheduling request. Display both the selected timezone and the organizer’s timezone when different. Current provider is fake; UI must display **TEST — no calendar invite sent**. Real calendar availability/invites/event sync are Sprint 2 external integration work.

## 19. Proposals Specification

Proposal List filters by status, owner, company, and opportunity. Show client, opportunity, version, currency/total when set, last updated, and next action. Builder uses a guided section rail: **Client Understanding**, **Objectives**, **Solution & Scope**, **Deliverables**, **Timeline**, **Commercials**, **Terms**, **Internal Notes**.

Content provenance labels: AI-generated, human-edited, approved source. Commercial provenance: service catalog price, authorized human override, discount, final total. Preserve current backend authority: catalog pricing/policies determine permitted commercial inputs; role-gated approval and existing controls remain unchanged. Internal notes must be visibly internal and excluded from client PDF.

The current workspace implements the core form/actions but is dense. Replace technical provider/model/version emphasis with a compact “Draft generated” provenance panel. Do not imply proposal sending exists; `READY_TO_SEND` means internally approved/ready for a separate delivery capability.

## 20. Approval Center

Approval card title must be a sentence: “Send first outreach email to Jane at ABC Ltd.” or “Approve proposal for ABC Ltd — ₹X.” Include:

- What action and exact reviewed content/version.
- Who/company is affected.
- Why approval is required and evidence/summary.
- What happens after approval; explicitly state if the next step is queued or still requires another action.
- External impact indicator (contacts outside Yaandu / internal-only).
- Reversibility: “Cannot be unsent” or the actual cancellation window, only where known.

Actions: Approve, Reject, Request Changes (if lifecycle supports it; otherwise map to reject with reason and explain), open record. Put raw action key, workflow, target ID, correlation/agent information in expandable **Technical details** for authorized operators. Existing Approval Inbox has helpful risk/evidence fields, but exposes technical metadata and does not consistently explain effect/reversibility.

## 21. Automation UX

Manager/Admin-only page. Keep backend semantics authoritative and explain them without promising capabilities beyond actual policy:

- **Manual:** AI can prepare analysis/drafts; consequential action waits for a human. Confirm exact semantics against current action registry before display.
- **Assisted:** AI supports workflow and requests review at configured gates; human controls consequential steps.
- **Controlled:** only system-allowlisted, tenant-permitted low-risk internal actions may proceed automatically; external/consequential actions still obey approval/human-only policy.

For each user-facing setting show scope, effective policy, reason, consequences, and reset/default. Hide raw side-effect identifiers/enum selectors under advanced details. No FULL_AUTONOMY toggle. “Save” must show which workflows are affected. Existing policy engine is the authority; do not change it in the UX sprint.

## 22. Setup / Onboarding

First-run checklist with Required/Optional, owner, Configured/Missing, Test/Fake/Live state, last checked, and next step:

| Setup step | Requirement/status intent |
|---|---|
| Company profile | Required for personalization; tenant profile API/UI gap |
| Team and roles | Required operationally; current provisioning is external |
| Services and pricing | Required before proposals; existing Proposal workspace APIs |
| Approved knowledge | Required for grounded marketing/proposals; existing Marketing workspace |
| ICP and scoring | Recommended; existing scoring rules UI |
| AI provider | Required for live AI; current task config UI, credentials stay server-side; connection test missing |
| Messaging | Required for live outreach; current fake-only provider configuration |
| Calendar | Optional until scheduling; fake-only provider currently |
| Automation policy | Required to explain controls; existing manager/admin policy API |
| Storage and browser capture | Admin/operations; local disk now, S3 unverified, Playwright isolation pending |

Show separate badges **TEST/FAKE**, **CONFIGURED — NOT TESTED**, **LIVE — VERIFIED**, **DISABLED**. Only show “verified” after an actual server-side health check. Never ask users to paste credentials into a browser form.

## 23. Role-Based Navigation

| Role | Primary visible areas |
|---|---|
| Salesperson | Home, Prospects, Outreach, Inbox, Pipeline, Meetings, Proposals |
| Sales Manager | Salesperson areas plus Approvals, Automation, Team (when supported) |
| Owner/Admin | Sales plus Setup, Integrations, ICP, Services & Pricing, Team, Approvals, Automation |
| Operations/Admin | Restricted System Health, Agent Runs, Workflow diagnostics, integration health |

Current backend role values and per-record authorization must be mapped before enabling these menus. Hiding a menu is not authorization; APIs remain the security boundary. Do not expose write controls to a role whose API call will return 403. Team management is a future backend/UI gap.

## 24. Status Vocabulary

Keep backend enums unchanged. Render business language in the UI, with raw value in operator details only.

| Backend state/example | User-facing label | Treatment |
|---|---|---|
| `draft` | Draft | Neutral gray |
| `queued` / `RUNNING` | In progress | Blue; include expected next update if available |
| `WAITING_APPROVAL` | Needs your approval | Amber action badge |
| `WAITING_EXTERNAL` | Waiting for reply / provider update | Muted blue; choose based on actual workflow context |
| `HUMAN_HANDOFF` / human review | Needs a person | Amber/high visibility |
| `QUALIFIED` | Qualified | Green, with rubric/evidence |
| `MEETING_READY` | Meeting ready | Blue/green |
| `PROPOSAL_READY` | Proposal ready for review | Blue |
| `READY_TO_SEND` | Approved — ready to send | Green, clearly state no send occurred |
| `FAILED` | Could not complete | Red with safe next action |
| `PAUSED` | Paused | Neutral/amber with actor/reason if known |
| `CANCELLED` / `NOT_QUALIFIED` | Stopped / Not a fit | Neutral; never conflate with technical failure |
| suppressed/unsubscribed | Do not contact | Strong warning, no send action |

“Waiting for Reply” should be used only when the state is actually waiting for a prospect reply, not for any external event. Avoid mapping generic workflow status without domain context.

## 25. Design System

Use existing Vue 3/Vite and current styles; do not introduce a new framework. Define shared tokens for color, spacing, type, radii, focus, elevation, and status semantics. Current CSS is largely global/component-local; Sprint 1B should centralize tokens/components gradually.

- **Page Header:** title, one-sentence purpose, one primary action.
- **Metric Card:** clickable only when it has a meaningful destination; label, value, period/definition.
- **Action Card:** priority, why it needs attention, owner/time, one next action.
- **Data Table:** sortable headers, pagination, sticky identity column on desktop, responsive card conversion.
- **Status Badge:** text plus color/icon; never color-only.
- **Score Badge:** score + band + accessible label; do not imply certainty.
- **Timeline:** human events, actor, timestamp, linked record; expand evidence/technical details.
- **Evidence Card:** claim/finding, source, excerpt/screenshot, time, confidence, provenance.
- **AI Insight:** “AI suggestion” label, evidence, confidence and accept/edit/dismiss action.
- **Approval Card:** business-language consequence, external indicator, reviewed version, decision/reason.
- **Empty State:** what is empty, why, primary next action.
- **Error State:** safe explanation, retry/recovery, correlation details only for operators.
- **Loading State:** skeleton or inline progress; prevent duplicate submissions.
- **Drawer/Modal:** use for focused short decisions only; proposal editing remains a page, not a giant modal.
- **Tabs:** small number, keyboard accessible, preserve selected tab in navigation state.
- **Forms:** grouped by task, inline validation, distinguish required/optional and unknown data.
- **Actions:** one primary; secondary quiet; destructive action labeled and confirmed with consequence.

Raise default text size from the current very small card/table styles to readable product defaults; validate contrast and keyboard focus.

## 26. Responsive Strategy

- **Desktop (≥1200px):** persistent sidebar; table + detail pane where useful; Inbox can use list/thread/evidence three-column layout.
- **Tablet (768–1199px):** collapsible navigation; two-column detail where space allows; evidence as a drawer/stack; tables prioritize company/contact/status/actions.
- **Mobile (<768px):** no horizontal page minimum; bottom/slide-in nav; cards for prospects/pipeline/meetings; Inbox list and conversation are separate navigable views; proposal reviewer uses section accordions and sticky decision footer; dialogs become full-screen sheets.

Priority validation: Dashboard, Prospect List/360, Inbox, Pipeline, Proposal Review. Complex admin/operations can remain desktop-first but must not overflow or hide save actions. Current `body { min-width: 800px }` prevents a genuine mobile layout; remove that constraint during implementation and test real viewport sizes.

## 27. Empty / Error / Waiting States

| State | Message/action |
|---|---|
| No prospects | “Your prospect list is empty.” Add manually or enter supplied seed candidates; clearly mark AI discovery/import as unavailable until delivered |
| No contacts | “No public contact found on scanned pages.” Review pages or add a verified public contact; do not invent details |
| No outreach | “No outreach has been created.” Select eligible prospects to start a draft |
| No replies | “No prospect replies yet.” Show last inbound sync/provider state only if available |
| No opportunities/meetings/proposals | Explain lifecycle prerequisite and link to the relevant eligible record/action |
| No approvals | “You’re up to date.” |
| AI processing / scan running | Progress state, company, started time, refresh/poll; no run UUID in sales copy |
| No contact found | Offer review/add path; never auto-send to a generic address without explicit selection |
| AI provider unavailable | Preserve input/draft; “AI is temporarily unavailable. Retry or continue manually.” Operator diagnostics separately |
| Email provider unavailable | “Nothing was sent.” Keep approval/message state and offer retry after admin check |
| Calendar unavailable | Preserve request; “No meeting was booked.” Offer retry or manual coordination |
| Approval required | State exactly who must approve and what happens next |
| Send blocked / suppressed | Say “Do not contact” for suppression; show no send CTA; for other reasons give the policy reason |
| Queue delayed | Only show if queue telemetry exists; do not invent an ETA |
| Proposal generation failed | Keep requirements and prior version; safe retry action and no duplicate version unless regenerated |

## 28. Notification Strategy

Notify on new reply requiring action, approval requested/expiring, high-potential prospect requiring review, meeting booked/changed/cancelled, proposal ready for review, and workflow requiring human intervention. Group repeated events by prospect and suppress routine agent/job start/completion noise. Notification click opens the exact contextual record. Provide unread/mark-read and user preferences once notification persistence/API exists. Queue/agent telemetry belongs in Operations, not sales notifications.

## 29. End-to-End User Flow

| Step | Screen | Primary action | Result / next screen |
|---|---|---|---|
| 1 Start discovery/import | Prospects → Add prospects | Choose manual, seed, AI Discovery, or CSV; unavailable modes explicitly labeled | Current seed/manual record appears; future source/import job goes to result review |
| 2 Receive results | Discovery Results (Sprint 2) | Review source/provenance/duplicate and accept/reject | Accepted records enter Prospect List; current manual/seed path opens Prospect 360 |
| 3 Review scores | Prospects | Filter high potential; open factor breakdown | Prospect 360 |
| 4 Shortlist five | Prospect List | Select and Shortlist | Shortlist filter/list updates; requires persisted shortlist status |
| 5 Open prospect | Prospect 360 | Review Overview and next action | Evidence, contact, history consolidated |
| 6 Select contacts | Prospect 360 → Contacts | Select preferred eligible public contact | Contact selection persists and is checked for suppression |
| 7 Add to outreach | Outreach | Select five prospects, campaign, sequence | Recipient/message review; bulk enrollment support required |
| 8 Review personalization | Message Review | Edit/regenerate, inspect evidence and approved claims | Reviewed version saved |
| 9 Request/receive approval | Approval Center | Manager approves/rejects with reason | Approved snapshot moves to explicit send step |
| 10 Send | Outreach | Confirm recipients, schedule/window, and send | Real provider required; show per-recipient status |
| 11 Receive reply | Inbox | Open new/unread reply | Conversation detail with business context |
| 12 Review qualification | Inbox → Qualification | Inspect dimensions/evidence; correct if authorized | Qualification saved with audit; recommend next step |
| 13 Convert/manage opportunity | Pipeline → Opportunity Detail | Create/open opportunity; set stage/owner/next action | Activity and pipeline update |
| 14 Schedule meeting | Opportunity or Inbox | Choose owner/timezone/slot and confirm | Meetings workspace displays booking; live calendar required for actual invitation |
| 15 Prepare proposal | Opportunity → Proposal | Capture requirements and generate draft | Proposal Builder |
| 16 Review commercials | Proposal Builder | Review catalog price, discount, total, terms | Save reviewed commercial snapshot |
| 17 Obtain approval | Approval Center / Proposal Review | Approve exact version | Approved version and audit record |
| 18 Reach ready state | Proposal Detail | Generate/review PDF and mark ready | `READY_TO_SEND`; UI states proposal has not been delivered |

## 30. Screen Inventory

| Screen | Persona / purpose | Main information & primary action | Reuse / new work |
|---|---|---|---|
| Home | Sales; focus today | Action Center, snapshot, recent business activity; open next item | Reuse Dashboard API and Orchestration summary selectively; substantial UI/API aggregation |
| Prospects | Sales; find/prioritize | Search, filters, score/contact/status; shortlist/open | Merge App Companies, Lead Scores, Contacts; pagination API exists, shortlist/filter API gaps |
| Discovery & Import | Sales; add candidates | Manual/seed now; source/import future; submit/review | Reuse DiscoveryAgent/API for seeds; AI source/CSV backend new |
| Prospect 360 | Sales; understand one company | Overview, intelligence, contacts, outreach, conversation, opportunity, meetings/proposals, activity | Reuse CompanyController, Marketing, Conversation, Sales, Scheduling, Proposal data; aggregation/deep-link UI work |
| Outreach | Sales; prepare/send | Campaign list/detail/audience/sequence/messages/performance | Reuse CampaignWorkspace, MarketingWorkspace, campaign/draft/outbound APIs; unify UI |
| Message Review | Sales/Manager; review exact content | Recipient, draft, evidence, approved claims, warning; approve/edit/reject | Reuse marketing draft APIs; improve review/approval semantics and context |
| Inbox | Sales; handle replies | Attention list, filters, thread, qualification, opportunity, next action | Reuse ConversationWorkspace/ConversationController/SalesController; unread/filter/assignment API gaps |
| Pipeline | Sales/Manager; manage deals | List/Kanban, filters, stage/value/owner/next action | Reuse Opportunity/Sales APIs; assignment and next action fields likely backend gap |
| Opportunity Detail | Sales; advance deal | Qualification, value, owner, activity, meeting/proposal; set next action | Reuse SalesController, SchedulingController, ProposalController; coherent page is new UI |
| Meetings | Sales; manage time commitments | Requested/upcoming/completed/cancelled, timezone, owner, prep | Reuse SchedulingController; new UI; live calendar integration separate |
| Proposals | Sales/Manager; prepare/review | Proposal list, builder, review, versions, commercial breakdown, PDF | Reuse ProposalWorkspace/API; redesign and reviewer mode |
| Approval Center | Manager; decide safely | Plain-language action/consequence/evidence; approve/reject | Reuse OrchestrationWorkspace/ApprovalService; enrich business context and remove IDs |
| Automation | Manager/Admin; set limits | Explain modes/effective policies; save policy | Reuse OrchestrationWorkspace/policy APIs; simplify labels |
| Setup | Owner/Admin; configure tenant | Checklist, required steps, fake/live/test state | Existing config APIs reused; profile/team/health gaps |
| Integrations | Owner/Admin; manage providers | AI/email/calendar/storage state, test status | Reuse config APIs; health checks and real integrations missing |
| System Health | Operations; diagnose failures | Queue, failed jobs, agents, provider health, alerts | AgentRun/Workflow APIs partial; queue/health/monitor APIs new |

## 31. Current → Target Mapping

| Current page/component | Target treatment |
|---|---|
| Dashboard | **REDESIGN** as Home/Action Center; retain useful snapshot metrics |
| Companies | **MERGE / REDESIGN** into Prospects and Prospect List |
| Company Details | **MERGE** into Prospect 360; retire from primary navigation |
| Website Intelligence | **MOVE** into Prospect 360 Intelligence; technical detail expandable |
| Contacts | **MOVE** into Prospect 360; remove from primary navigation |
| Lead Scores | **MOVE** score breakdown into Prospect List/360; remove from primary navigation |
| CampaignWorkspace | **KEEP capability / REDESIGN** inside Outreach |
| MarketingWorkspace | **MERGE** sales draft/review into Outreach; move prompt/knowledge administration to Setup |
| ConversationWorkspace | **KEEP capability / REDESIGN** as Inbox; move scheduling config out to Setup |
| ProposalWorkspace | **KEEP capability / REDESIGN** as Proposals with reviewer mode |
| Orchestration summary | **MOVE TO OPERATIONS**; dashboard retains only actionable approval/handoff counts |
| Workflows | **MOVE TO OPERATIONS** as advanced workflow diagnostics |
| Approval Inbox | **KEEP / REDESIGN** into Approval Center with business language |
| AI Automation Policies | **MOVE TO MANAGEMENT** as Automation; manager/admin only |
| Agent Runs | **MOVE TO OPERATIONS**; remove from sales navigation |
| AI Configuration | **MOVE TO ADMINISTRATION** under Integrations; remove from sales navigation |
| ICP Configuration | **MOVE / RENAME** to Setup → ICP & Scoring |
| Missing Pipeline / Meetings | **ADD UI** over existing opportunity/meeting APIs |

No backend functionality is recommended for deletion.

## 32. Backend Gap Register

| Gap | Category | Current evidence / Sprint 1B handling |
|---|---|---|
| Genuine external prospect discovery | **EXTERNAL INTEGRATION REQUIRED** | DiscoveryAgent accepts seeds only; choose source and compliance policy before implementation |
| CSV/bulk prospect intake and row review | **BACKEND FEATURE REQUIRED** | Candidate API accepts arrays but no import job/row outcomes/durable review contract; one-candidate UI today |
| Prospect pagination/search | **UI ONLY** for current company API | API already paginates/searches; Vue must expose controls. Combined multi-factor filters may need a small API change |
| Shortlist/ignore state | **SMALL API CHANGE** | No explicit user action/state visible for shortlist/ignore; preserve audit/history |
| Assignment/team users | **BACKEND FEATURE REQUIRED** | Assigned-record access exists, but no team/invite/assignment management workflow surfaced |
| Cross-domain Prospect 360 | **UI ONLY / SMALL API CHANGE** | Domain endpoints exist; compose frontend requests first; add a tenant-scoped aggregate endpoint only if measured need warrants it |
| Read/unread and inbox filters | **BACKEND FEATURE REQUIRED** | Current conversation API/UI does not expose a complete unread/action-filter model |
| In-product notifications | **BACKEND FEATURE REQUIRED** | No notification center/event persistence/mark-read API found |
| Real outbound email/inbound mailbox | **EXTERNAL INTEGRATION REQUIRED** | Fake adapters only enabled; need real adapter, secret/webhook/delivery configuration and sender verification |
| Real calendar | **EXTERNAL INTEGRATION REQUIRED** | FakeSchedulingProvider only; use same SchedulingWorkflow contract |
| Queue/provider health and live integration test | **BACKEND FEATURE REQUIRED** | Horizon config exists; no health endpoint/queue metrics for product UI |
| Proposal-generation task override | **SMALL API CHANGE** | Config defines `proposal_generation`, controller task allowlist omits it |
| Business-language approval effect/reversibility | **UI ONLY** where context exists; **SMALL API CHANGE** for missing effect metadata | Existing approvals have action/risk/context, but UI exposes target IDs and lacks consequence/reversibility explanation |
| Ownership/pipeline next action | **BACKEND FEATURE REQUIRED** | Opportunity stages/values/activities exist; owner/next-action filtering/update contract is incomplete for team pipeline UX |
| Setup health/test states | **BACKEND FEATURE REQUIRED** | Configuration forms exist, but no reliable live-vs-configured health-check contract |

## 33. Implementation Priority

Sprint 1B should establish a thin but coherent vertical sales experience, with each step staying on existing backend services. Do not block basic shell/prospect views on external integrations, but label unavailable actions precisely.

| Order | Work | Effort | Dependencies | Risk |
|---|---|---:|---|---|
| 1 | Application shell, role-aware navigation, status vocabulary, responsive foundations | M | Map existing roles and route guards | Medium: hidden UI must not replace server authorization |
| 2 | Dashboard Action Center over current data | M | Existing APIs; identify missing unread/next-action data | Medium: avoid placeholder counts |
| 3 | Prospects list/search/pagination/score/contact status | M | Existing Company API; shortlist/ignore contract decision | Medium: status semantics |
| 4 | Prospect 360 overview and intelligence/evidence presentation | L | Cross-domain API composition; approved service mapping decision | Medium: do not overstate AI inference |
| 5 | Inbox attention view and business-language detail | L | Conversation filter/unread/assignment API decisions | High: cannot imply unread/assignment exists |
| 6 | Pipeline and Opportunity Detail | L | Opportunity owner/next-action API contract | Medium: stage changes require audit/confirmation |
| 7 | Meetings workspace | M | Existing scheduling API | Medium: visibly fake until real adapter |
| 8 | Outreach and message review | L | Existing campaigns/drafts/approvals; future provider state contract | High: avoid mistaken live-send expectation |
| 9 | Proposal list, builder and review mode | M/L | Existing proposal lifecycle | Medium: protect commercial authority/version snapshots |
| 10 | Approval Center | M | Existing approval context; consequence/effect metadata review | High: approval must describe actual effect |
| 11 | Setup/Admin and Operations separation | L | Configuration health, roles, queue/API status contracts | Medium/high: do not label configured providers live |

## 34. Productization Sprint 1B Acceptance Criteria

- Salesperson primary navigation has seven items or fewer: Home, Prospects, Outreach, Inbox, Pipeline, Meetings, Proposals.
- Normal sales navigation contains no Agent Runs, Workflow IDs, prompt/model configuration, raw JSON, or queue terminology.
- Prospect List supports search, API pagination, readable score/status, and opens a contextual Prospect 360.
- Prospect 360 presents company, evidence-backed intelligence, score rationale, public contacts/provenance, outreach/conversation, opportunity, meetings/proposals summaries, and one clear next action without fabricating missing facts.
- Discovery UI distinguishes manual/seed candidates (available) from genuine AI discovery and CSV import (unavailable until Sprint 2); no false search/progress claims.
- Inbox supports action-oriented sorting and, once backend state is available, unread/intent/handoff filters; conversation actions show their real effect and TEST/LIVE status.
- Pipeline and Meetings are dedicated workspaces reusing current APIs; no drag/drop or real booking/send implication without the necessary policy/provider.
- Approval Center states action, affected person/company, reason, external effect, next step, and reversibility only when known; technical details are role-gated.
- Proposal review distinguishes AI draft/human edits/approved content and catalog price/discount/final total; `READY_TO_SEND` clearly does not mean sent.
- Every actionable page has tested loading, empty, error, waiting, and permission-denied states; screen readers/keyboard users can operate tables, tabs, drawers, and decisions.
- Responsive behavior is usable at 1440px, 1024px, 768px, and 390px without global horizontal page scrolling.
- No API/backend authorization is weakened for UI convenience; changes that require data not currently available are tracked in the backend gap register.
- Sprint 1B includes persona-based task testing for prospect review, reply handling, approval, opportunity stage update, meeting, and proposal review before acceptance.
