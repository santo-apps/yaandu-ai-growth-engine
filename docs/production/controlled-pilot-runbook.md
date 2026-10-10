# Controlled Human-Assisted Pilot Runbook

## Authorization and limits

- Audience: 2–5 named internal Yaandu sales/management users, each individually provisioned by the tenant owner. Do not share accounts or place credentials in this repository.
- Tenant: use only the owner-approved, active pilot tenant. Verify the tenant name and active membership before importing.
- Volume: manually reviewed public business records only; maximum 10–25 prospects per import batch. Start with 10. Do not bulk process a frozen cohort or expand volume without an owner review.
- Mode: Human-Assisted Intelligence. AI findings and score are advisory. Humans select a service/no service/needs discovery, set priority, choose next action, and approve any outbound action. No autonomous qualification, service assignment, or outreach.
- Browser: Playwright stays disabled until a dedicated isolated browser worker and egress policy have been deployed and verified.

## Before a session

1. Confirm the release SHA, `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, correct tenant, PostgreSQL/Redis connectivity, Horizon running, current backup, and no active incident.
2. Run `php artisan production:readiness`; record all PASS/FAIL/WARN results. Stop on any FAIL or unresolved P0/P1.
3. Confirm users and roles through the normal invitation workflow. Sales roles must not have owner/admin configuration permissions.
4. Confirm explicit tenant provider route, approved active prompt, service catalog and ICP versions. Confirm provider credential presence without displaying it.
5. Ensure fake/deterministic providers are disabled. Verify daily AI budgets, crawl caps, suppression rules and the no-autonomous-outbound policy.
6. Tell reviewers that public site text is untrusted, AI may be wrong, and no private/internal content should be entered or sent to an AI provider.

## Import and review

1. Prepare a CSV with public business name, official website, country, and source/provenance. Exclude private contact data unless collected from a permitted public business source and approved for this purpose.
2. Preview the import. Resolve invalid, duplicate, or ambiguous domains manually. Confirm the source and collection time. Keep a local record of who authorized the list.
3. Enqueue no more than the approved batch. Monitor the `intake` and `crawl` queues; stop adding work if backlog or failure alerts trigger.
4. Open each prospect's Prospect 360 workspace. Verify identity, domain, scan status, and source provenance before interpreting intelligence.
5. Review each finding against its evidence URL/excerpt. Mark supported, partial, unsupported, or unable to verify. Report unsupported factual claims; do not treat an AI suggestion as an observed fact.
6. A human selects one or more active tenant services, “No applicable service,” or “Needs discovery.” A human separately sets High, Medium, Low, Not a fit, or Needs more evidence. AI score is advisory and cannot qualify or disqualify.
7. Record next action and missing information in the existing review/decision notes. Preserve review history; do not overwrite prior decisions silently.

## Stop and escalation

Stop further crawl/AI work if identity is uncertain, evidence is missing, provider auth/quota failures repeat, queue backlog breaches alert limits, a cross-tenant record appears, unsafe/private URL is attempted, or any unauthorized outbound action occurs. Pause the affected tenant workflow and Horizon queue only under the on-call incident procedure; preserve correlation IDs and records. Do not delete evidence or retry blindly. Notify the security/engineering owner for security, tenant, privacy, or data-integrity incidents; notify the AI owner for systematic unsupported claims or provider failure; notify sales operations for incorrect service/priority decisions.

## Outreach and downstream actions

The pilot is advisory. A salesperson must explicitly choose and review the recipient, content, channel and timing. Follow existing suppression, policy, approval and unsubscribe workflows. Do not send real messages as a smoke test. Fake/test provider labels must remain visible. Any request for live outbound, calendar, or proposal delivery needs separate authorization and environment validation.

## Feedback and reporting

Use existing append-only human review and decision history: evidence accuracy, intelligence usefulness, selected service, human priority, missing information, reviewer notes and next action. At the end of the day report volume imported/processed/reviewed, queue and provider health, evidence issues, human decisions and any incidents. Keep public content transfers limited to the approved provider route and purpose. Do not export raw provider payloads or tenant data to personal tools.

## Incident/recovery

1. Stop new intake and AI dispatch for the affected tenant; leave outbound disabled.
2. Record release SHA, tenant identifier in the protected incident system, correlation IDs, timestamps, queue/job identifiers, and safe error categories. Never copy secrets, cookies, raw auth headers, or unnecessary page payloads into incident chat.
3. Preserve database/object artifacts and audit history. Do not run destructive cleanup or restore over production.
4. The on-call owner decides whether to roll back code/assets, apply a forward fix, restore to an isolated environment, or pause the pilot. Verify tenant boundaries, queue health, and synthetic-only workflows before resuming.
5. Resume only after the owner records acceptance of the fix and all readiness gates pass again.
