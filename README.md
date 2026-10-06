# Yaandu AI Growth Engine

Laravel 12 API and Vue 3/TypeScript dashboard for tenant-scoped company discovery, website intelligence, and lead scoring. The architecture and security boundaries are documented under [`docs/architecture`](docs/architecture/).

## Local development

Requirements: PHP 8.3+, Composer, Node.js 20+, PostgreSQL, Redis, and the PHP PostgreSQL/Redis extensions. Install the Playwright Chromium runtime on each worker host with `npx playwright install chromium`.

1. Copy `.env.example` to `.env`, set PostgreSQL/Redis values and at least one AI provider key, then generate the Laravel key with `php artisan key:generate`. Set `FILESYSTEM_DISK=s3` and configure private bucket credentials for production. Keep `APP_DEBUG=false` in deployed environments.
2. Install dependencies with `composer install` and `npm install`.
3. Run database migrations with `php artisan migrate`.
4. Start the API with `php artisan serve`; start the Vue app with `npm run dev`.
5. Run asynchronous agents with `php artisan horizon`.

Users and tenant memberships are provisioned by the deployment's identity process. The dashboard signs in through a stateful Sanctum session and requires an active tenant membership selected with `X-Tenant-ID`. Company candidates can be added through the Companies view; a website scan can then be submitted through `POST /api/v1/websites/{website}/scan`. Discovery accepts a bounded set of company seeds at `POST /api/v1/discovery-runs`; no third-party discovery source adapter is enabled by default. Contact values are encrypted with `APP_KEY` and use a keyed fingerprint for deduplication, so key rotation requires an explicit re-encryption operation.

The Sales Execution Engine includes campaigns, queued sequence sends, a signed fake inbound reply webhook, queued intent analysis, encrypted AI reply drafts with human edit/approval, human handoff, fake scheduling, proposal/pricing workflows, and an approval-gated decision ledger. `fake` is the only enabled outbound and scheduling adapter. Campaign sending and scheduling are disabled per tenant by default. Enable them only in local/test use until production providers, sender verification, webhook secrets, and deployment safeguards are configured. Proposal emails use the fake provider in this environment; signed customer approval links are enabled. Proposal PDFs, external inbound reply/calendar/email providers, and autonomous action execution are not implemented.

## Verification

Run backend tests with `php artisan test` and build/type-check the dashboard with `npm run build`. Other useful checks are `php artisan route:list`, `php artisan migrate --force`, `php artisan proposals:expire`, and `node --check resources/playwright/capture.mjs`.
