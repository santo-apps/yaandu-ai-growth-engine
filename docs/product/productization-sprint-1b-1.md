# Productization Sprint 1B-1 — Core Sales Workspace

**Status:** Implemented against the existing Phase 1–2F APIs. Automated acceptance commands pass; responsive behavior is implemented in CSS but could not be visually checked in a browser in this environment.

## Delivered

- Replaced the business-facing primary navigation with **Home, Prospects, Outreach, Inbox, Pipeline, Meetings, and Proposals**. Existing technical screens remain available under manager-only Management, Setup, and Operations sections; company, contact, score, and intelligence screens are represented inside Prospect 360.
- Mapped actual tenant roles to the shell: `member` receives the seven sales destinations; `owner` and `admin` also receive secondary management, setup, and operations navigation. There is no `sales_manager` role in the current authorization model. Navigation is a convenience only; backend policies continue to authorize every operation.
- Added active membership role to `/tenants` and login tenant payloads. No access policy or membership semantics changed.
- Replaced the normal dashboard with an action center based on existing approvals, human-review conversations, qualified opportunities, proposals, and scheduled meetings. It does not show agent-run, workflow, or queue telemetry and does not claim unread state.
- Added a prospect directory with server-side company name search, API pagination, and available-page filters for industry, location, company status, lead score, and public contact presence. Add Prospect and limited supplied-seed registration reuse existing APIs. The screen explicitly says external discovery is not available.
- Added Prospect 360 with company profile, current score/rule contributions, evidence-backed website findings, optional stored screenshots, sourced contact methods, conversations, opportunity, meetings, and proposals. Technical issue evidence is available under a disclosure. No business impact or Yaandu capability is invented.
- Reorganized Campaigns and Marketing Intelligence under Outreach. Prompt/knowledge administration is shown only to owner/admin. Provider/model and prompt-version details were removed from normal draft summaries.
- Reused the existing conversation workflow as Inbox, connected contextual links to Prospect 360 and Pipeline, and disclosed that unread state is unavailable. The existing handoff queue remains available. No unread badge or “unread” filter is simulated.
- Added a list-first Pipeline over `/opportunities`, showing company, stage, available qualification, stored value, update time, and proposal count, with contextual links to Prospect 360, Inbox, Meetings, and Proposals. No drag-and-drop or new stage-write path was added.
- Added a Meetings workspace over `/meetings`, with upcoming/all/past filters, conversation-to-prospect context when present, and a visible **TEST MODE — no calendar invite sent** label.
- Added responsive layouts for the new sales workspaces and removed the effective global 800px body minimum.

## Reused components and files

- Existing `ConversationWorkspace`, `CampaignWorkspace`, `MarketingWorkspace`, `ProposalWorkspace`, and `OrchestrationWorkspace` remain the source of existing workflow actions.
- New pages: `HomeWorkspace.vue`, `ProspectsWorkspace.vue`, `Prospect360.vue`, `OutreachWorkspace.vue`, `PipelineWorkspace.vue`, and `MeetingsWorkspace.vue`.
- Shared request and display helpers: `src/salesApi.ts`.
- Shell and role context: `src/App.vue`.
- Small API addition: active membership `role` in `AuthController` tenant payloads.
- Regression coverage: `tests/Feature/TenantRolePayloadTest.php`.

## Behavior and API limitations

- The current roles are `owner`, `admin`, and `member`; a distinct Sales Manager role and team administration surface do not exist.
- Company search and pagination are API-backed. Industry, location, status, score, and contact filters operate on the loaded 25-company page and the first 25 score/contact records because those endpoints are paginated independently. Filter values can be incomplete across later pages. A server-side compound filter API is not part of this sprint.
- The prospect table uses available deterministic company status, latest score, and public contact presence. There is no consistent API field for assigned owner or next action, so the UI does not fabricate either.
- The current Inbox API does not expose unread/read state, latest message previews in the list endpoint, intent filters, or assignment administration. Inbox shows the available conversation summary/intent and handoff queue; unread state is explicitly not supported.
- Opportunity list data does not provide a contact display name, activity summary, or normalized next-action field. Pipeline displays the stored `updated_at` date and available qualification/value/proposal count; it does not label `updated_at` as last activity.
- The scheduling API exposes persisted bookings, but no tenant-scoped list endpoint for requested scheduling records. Meetings therefore lists bookings only. Requested/cancelled workflow states and external calendar actions are not simulated.
- Campaign/contact/prompt eligibility and suppression checks remain backend-controlled. There is no CSV import, true external prospect source, cross-domain search, bulk review ledger, or live provider integration in this sprint.
- A locally served visual browser pass could not be performed in this environment: the Vite dev server socket was denied with `listen EPERM`. TypeScript and production bundling provide the frontend build verification; this is not represented as browser E2E coverage.

## Role and test-provider indicators

- The role comes from the active tenant membership returned by the authenticated tenant API. Sales pages are visible to active members; secondary configuration and operations entries are visible only to owners/admins. APIs remain the security boundary.
- Supplied-seed discovery is labeled as registration of a known company, not web-wide discovery.
- Meetings identifies the currently supported calendar path as fake/test and states that no invitation was sent. Existing campaign execution settings already identify the local fake provider.

## Verification

Executed results:

| Verification | Result |
|---|---|
| `php artisan test --compact tests/Feature/AcquisitionWorkflowEndToEndTest.php` | PASS — 2 tests, 81 assertions |
| `php artisan test --compact` | PASS — 122 tests, 750 assertions |
| `php artisan route:list --json` | PASS — valid JSON, 164 routes |
| PHP syntax (`app`, `routes`, `database`, `tests`) | PASS — 219 files, no syntax errors |
| `npm run typecheck` | PASS |
| `npm run build` | PASS — Vite production bundle generated |
| `git diff --check` | PASS — exit 0 |
| Local visual viewport review | BLOCKED — Vite could not bind to `127.0.0.1` (`listen EPERM`) in the managed environment |
