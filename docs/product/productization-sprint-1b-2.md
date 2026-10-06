# Productization Sprint 1B-2

## Scope delivered

This sprint productizes the existing outreach, proposal, approval, setup, and automation capabilities. It does not add external discovery, live email/calendar integrations, autonomous sending, or new orchestration architecture.

## UX and navigation

- Preserved the seven salesperson destinations: Home, Prospects, Outreach, Inbox, Pipeline, Meetings, and Proposals.
- Renamed the manager route to **Approval Center** and grouped administrator navigation under Management, Setup, and Operations.
- Added a manager-only Setup readiness page with explicit Organization and Team backend gaps, ICP/scoring, Services & Knowledge, Pricing, AI routing, messaging, calendar, and storage/integration states.
- Setup routes to the existing ICP, AI configuration, and service catalog workflows. The knowledge shortcut opens the existing approved knowledge/prompt editor in Outreach.
- Outreach now has Campaigns, Drafts / Review, and Activity sections. Campaign activity uses supported event labels and omits raw event payloads and identifiers.
- Campaign lists show supported enrollment/sequence counts and last update. Audience criteria render as readable filters instead of JSON. Fake outbound mode is shown prominently. Manager-only campaign controls are hidden from salesperson users.
- Message draft review shows prospect/contact/campaign context, evidence, confidence, warnings, and distinguishes AI wording from recorded human edits. Edit is available only to active owner/admin users and only while status remains draft. Edits stay encrypted and are audited. Approve still creates an approved template where applicable; it does not send.
- Proposal screens format currency using `en-IN`, translate lifecycle statuses, link to Prospect 360 and Pipeline, and explain when generated content is not yet available. The Setup service catalog can be opened independently of the proposal builder.
- The Approval Center groups pending workflow actions and adds proposals in review/input states with a direct route into proposal review. Technical target/workflow IDs are behind Technical details. Proposal review remains a human-controlled step and does not send.
- Automation policy choices use business descriptions for autonomy mode, policy state, risk, impact, and approval authority while retaining the existing policy values and validation.
- Added narrow-stack layouts for Setup, approval details, and campaign sequence controls. The 390px inspection showed no document-level horizontal overflow.

## Backend changes

- Campaign listing now includes `steps_count` alongside the existing enrollment count.
- Added a tenant-scoped, manager-authorized `PATCH /marketing-drafts/{id}` for subject/body edits on draft-state records. Fields remain encrypted. An audit row records who edited the message. Concurrent or post-approval edits return conflict.
- Added `proposal_generation` to the AI configuration task allow-list, matching the existing task registry.
- Campaign and marketing manager checks now require active tenant membership.
- Focused feature tests cover proposal-generation configuration, encrypted draft editing/audit, rejection of non-manager edits, and the existing campaign/proposal/approval workflows.

## Provider and environment status

- Messaging: **TEST MODE** using the existing fake provider. No external email is sent.
- Calendar: **TEST MODE** using the existing fake scheduler. No external calendar is connected.
- AI: task routes are displayed as configured/not tested. No live provider call was made in the browser pass.
- Storage: displayed as configured/not runtime verified. The Setup screen does not infer S3 availability.
- Organization profile editing and team invitations are explicitly marked **BACKEND FEATURE REQUIRED**.

## Known limitations

- There is no organization-profile or membership invitation/admin API, so those areas are status-only.
- No deterministic AI draft or generated proposal version existed in the local visual tenant. Live provider calls were intentionally not used. Empty draft state and proposal-without-content state are implemented; proposal document preview remains download-only through the existing authenticated endpoint.
- Proposal workflow approval is exposed as a direct proposal review route; workflow approvals remain separate persisted approval records. The UI does not fabricate workflow approval records for proposal statuses.
- The local developer configuration uses PostgreSQL, but direct CLI access to its configured `127.0.0.1:5432` endpoint was blocked in this execution environment. Browser-backed local application requests and test-suite SQLite runs were available.

## Visual acceptance

Reviewed the local app at 1440, 1280, 768, and 390 pixels for Outreach, Setup, and Proposals. Checked document `scrollWidth` at each viewport; no page-level horizontal overflow was found. The administrator session exposed the secondary navigation, and the salesperson session exposed only the seven primary destinations. Outreach was checked with a deterministic local campaign draft and activity event. The 390px view stacks setup forms and keeps proposal actions in the scroll flow.

Approval Center was inspected with a pending proposal review item and no workflow approvals. The non-empty campaign, marketing-draft, and generated-proposal detail states could not be visually exercised without introducing synthetic AI content or making a live provider call. They remain covered by component/API behavior and backend tests, but their visual acceptance remains limited to available deterministic states.

## Verification record

- `php artisan test --compact`: **PASS — 123 tests, 762 assertions**.
- PHP syntax checks across `app`, `routes`, `database`, and `tests`: **PASS**.
- `php artisan route:list --json`: **PASS — 165 registered routes**.
- `npm run typecheck`: **PASS**.
- `npm run build`: **PASS — Vite production bundle generated**.
- Lint: **NOT CONFIGURED** (`package.json` has no lint script).
- Local browser refresh: **PASS** — Home rendered tenant metrics, action center, upcoming meeting with Asia/Kolkata timezone, and prospect navigation without the earlier paginator response error.
- Visual document-width checks: **PASS** — Outreach campaign detail, Drafts / Review empty state, Proposals, and Approval Center at 1440, 1280, 768, and 390 pixels had no document-level horizontal overflow.
- Approval Center showed a pending proposal review item with readable company/title/status/value, a direct proposal link, and an empty workflow-approval state. Campaign detail showed TEST MODE and the local draft campaign; no send, enrollment, or activation action was taken.
- PostgreSQL CLI connectivity: **BLOCKED** in this execution environment (`127.0.0.1:5432` connection denied by sandbox). The separate SQLite-backed PHPUnit suite passed; this does not establish PostgreSQL runtime verification.
- Live AI providers, email, calendar, and S3: **NOT RUNTIME VERIFIED**. No external provider call or delivery was made.
- Generated marketing draft and generated proposal preview: **VISUAL ACCEPTANCE LIMITED** because no deterministic generated records existed in the local tenant and live AI calls were out of scope. Their empty states were inspected; automated tests cover the relevant backend workflow behavior.

### Visual status by area

| Area | Visual status | Notes |
| --- | --- | --- |
| Application shell / role navigation | PASS | Owner sees separate Management, Setup, Operations groups; seller retains seven primary destinations. |
| Home | PASS | Refreshed after pagination response fix; tenant context, next actions, prospect, and TEST MODE meeting context render. |
| Outreach / campaign detail | PASS for available state | Deterministic local draft, TEST MODE, readable activity; all four widths have no document overflow. |
| Drafts / Review | PARTIAL | Empty state and layout inspected. Generated message detail was unavailable without synthetic content or live AI. |
| Proposals | PARTIAL | Existing proposal review state and responsive layout inspected. No generated proposal version was available for preview-state visual validation. |
| Approval Center | PASS for available state | Proposal-review card and empty workflow approvals; all four widths have no document overflow. |
| Setup & readiness | PASS | Inspected at all four widths; profile/team gaps and configured-but-unverified integrations are explicit. |
| Inbox, Pipeline, Meetings, Prospect 360 | PARTIAL | Existing deterministic workspace screens were inspected in the preceding visual pass; generated message/proposal detail is unavailable as noted above. No additional changes were made to these screens in this pass. |
