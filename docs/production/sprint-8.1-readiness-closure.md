# Sprint 8.1 Controlled Pilot Readiness Closure

**Assessment date:** 2026-10-10
**Branch/revision assessed:** `codex/sprint-8-production-hardening-ui` / `484123ff3ca3df65e28cdcd1853af4009f735e19`
**Result:** NOT READY — production deployment evidence is unavailable.

This closure did not deploy the application, process prospects, call a provider for business data, enable autonomous mode, or send outbound messages. Local checks do not substitute for checks against the actual controlled-pilot environment.

## Readiness command

`php artisan production:readiness` was run against the configured local Laravel runtime with host access to local services. It reported:

| Gate | Result | Evidence |
|---|---|---|
| `APP_ENV` | FAIL | Runtime is not production. |
| `APP_DEBUG` | FAIL | Runtime/environment setting is not configured false, despite the production bootstrap fail-safe. |
| `APP_KEY` | PASS | Present; value was not displayed. |
| PostgreSQL | PASS | Local `yaandu_growth` connection succeeded. |
| Redis | PASS | Local Redis ping succeeded. |
| Horizon | PASS | Local `horizon:status` reported running. Production supervision was not verified. |
| OpenAI credential | PASS | Detected without displaying the value. |
| Tenant `website_reasoning` route / Human-Assisted mode | PASS | Rerun with the previously identified pilot tenant ID; explicit enabled OpenAI route and `human_assisted` mode were confirmed. |
| Playwright | PASS | Disabled in application workers. |
| Pilot processing limits | PASS | Configured limits satisfy the command's bounds. |
| Object storage | FAIL | Default disk is local; no durable production storage decision is verified. |
| Scheduler | PASS / WARN | Application schedule is registered; external heartbeat is unverified. |
| Public URL / HTTPS | FAIL | Local HTTP URL. |
| Secure session cookie | FAIL | Not configured for HTTPS deployment. |
| Trusted hosts / proxies | WARN | Edge deployment allow-list and proxy behavior are unverified. |
| Mail | FAIL | Log transport; no production notification channel. |
| Backups | FAIL | Production schedule/destination/verification are not configured. |
| Monitoring | FAIL | No deployed monitor/alert receiver is evidenced. |

The command correctly remains NOT READY. No readiness check was weakened and no environment assertion was changed to force a pass.

## Backup and restore exercise

A non-destructive local exercise used PostgreSQL 17.11 `pg_dump` custom format to back up `yaandu_growth` into a temporary private directory, restored it into a uniquely named isolated database, and compared schema inventory. The source and restored databases each had 84 public tables and 48 migration records. The isolated database and temporary backup were removed successfully.

This validates the local PostgreSQL backup/restore commands only. It does **not** establish an automated production schedule, backup destination, encryption at rest, retention, access controls, or production restore evidence. Production backup readiness remains blocked. The previously documented policy is a target expectation, not a deployed configuration.

## Regression results

- Full PHPUnit: **287 tests / 2,151 assertions passed**.
- PostgreSQL/Redis: **4 tests / 94 assertions passed** using `APP_ENV=testing` and PHPUnit `--process-isolation`. The same integration suite without process isolation had one failure in the deterministic candidate raw-result case; that single case passed when run alone, and all four passed with process isolation. No test or application code was changed to mask the result.
- Temporary Redis integration keys: **0** after cleanup; Horizon remained running.
- TypeScript, Vite production build, PHP syntax sweep, Composer validation, Composer audit, npm audit, pilot route inspection, and `git diff --check`: **PASS**.
- Synthetic Chromium UI acceptance: **PASS** for seven covered surfaces at 1440, 1280, 768, and 390px. It is not manual authenticated visual acceptance.

## Storage and browser-worker decisions

- **Storage decision — Option A, S3 required:** Production artifacts must use approved private S3-compatible object storage. No S3 credentials or endpoint were available, and no S3 probe was attempted. Local storage is not being counted as a production pass.
- **Playwright decision — Option B, disabled for initial pilot:** Keep production Playwright disabled. The existing HTTP-first crawler can supply Website Intelligence for pages whose normal safe HTTP response contains enough evidence. Do not include a business when required evidence depends on browser rendering; that path remains unavailable until a dedicated isolated worker is deployed and verified. No anti-bot restriction is bypassed.

## Process supervision, monitoring, deployment, and smoke test

Local Horizon is running, but no production systemd/Supervisor/container-orchestration configuration or restart-survival check is available. Production web, Horizon, and scheduler supervision therefore remain unverified. `/up` exists, but there is no configured production monitoring system or alert channel for HTTP, PostgreSQL, Redis, Horizon, failed jobs, queue backlog, provider failures, or crawl failures. No recipient/channel or end-to-end alert recovery was verified. The documented trigger thresholds and recovery procedures are design guidance only until connected to a real monitor and protected recipient/channel.

No production deployment target or deployment credentials were available. Consequently there is no deployment SHA/environment, production migration result, deployed frontend result, supervised Horizon restart, HTTPS login check, authenticated tenant smoke test, or production human-assisted workflow result. No real or synthetic smoke request was sent to a production environment.

## Frozen cohort artifact investigation

`docs/product/sprint-7c-real-cohort-v1.json` remains untracked and was not modified or staged. The file is 15,308 bytes and 273 LF-terminated lines. Its raw file SHA-256 is `ee38f173b00338abaa8b39c4e900d78d8d5daab503a821cf18dadc4eb1d9434d`; this is not the documented frozen-cohort checksum, which is defined over canonicalized record content rather than the whole JSON file.

The manifest contains 20 records, rows 2–21, with 20 unique company IDs and 20 unique domains. Required domain, country, industry, source, source URL, and provenance fields are present. Recomputing the documented canonical record serialization gives `bab0d32113e48985b30ea54eda68c93223761cd3329671e47cd28d82f751762b`, matching both the manifest's `manifest_sha256` and the previously recorded canonical checksum. Thus the record-content checksum discrepancy is resolved; the raw-file checksum is not expected to equal that value. The file itself is absent from Git history, so its byte-for-byte prior version and non-record metadata cannot be recovered from this repository. Do not use this cohort for new pilot intake; use a separately owner-approved dataset.

## Pilot intake and acceptance blockers

Prepare a separate manually approved dataset of 10–25 legitimate public businesses, each with a known public website and no required private/personal data. Do not process it until the deployed pilot environment and owner authorization are verified.

Manual visual acceptance in an authenticated browser remains pending. The local app is available in Chrome but signed out; a synthetic headless browser test is not a replacement for the requested manual screen review. Login and authenticated screens cannot be certified until an authorized pilot user signs in.

### Required before controlled pilot authorization

1. Deploy to the actual controlled-pilot environment with `APP_ENV=production` and `APP_DEBUG=false` configured directly.
2. Verify production PostgreSQL, Redis, explicit tenant route, Human-Assisted mode, HTTPS, secure cookies, trusted proxy/host policy, and supervised web/Horizon/scheduler processes.
3. Choose and verify production artifact storage; verify S3 if selected, or document and test approved durable private local storage on the deployment host.
4. Configure automated encrypted PostgreSQL backups, destination, retention, and a production isolated restore test.
5. Deploy monitoring and a protected alert channel, then exercise actionable alerts for availability, core dependencies, failed jobs, and provider auth/quota failures.
6. Run the deployment smoke test against the deployed environment with synthetic or separately authorized safe data and confirm zero outbound sends.
7. Complete manual authenticated visual review at desktop and tablet sizes.

Until these deployment-owned checks pass, status remains **SPRINT 8.1 NOT ACCEPTED — CONTROLLED USER PILOT BLOCKED**.
