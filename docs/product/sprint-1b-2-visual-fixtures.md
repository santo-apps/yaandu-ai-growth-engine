# Sprint 1B-2 visual acceptance fixtures

The explicit `Database\Seeders\Sprint1B2VisualAcceptanceSeeder` creates one isolated fictional tenant in a local PostgreSQL database. It is never called by `DatabaseSeeder`, application boot, migrations, or deployment. It refuses to run unless `APP_ENV=local`.

## Create

```sh
php artisan db:seed --class='Database\Seeders\Sprint1B2VisualAcceptanceSeeder' --force
```

Sign in with the credentials printed by the seeder. The tenant is named **Sprint 1B-2 Visual QA (TEST FIXTURE)** and the company is **Acme Test Outfitters**, on the reserved `.fixture.test` domain. Contact Riley Sample and all website evidence are fictional.

Marketing and proposal text are returned by `Tests\Fakes\VisualAcceptanceAIProvider`, through the existing agent services and schema validation. The provider does not make network requests. Each persisted model is marked `visual-fixture-deterministic`; the review UI labels that copy as deterministic fixture text, not AI-generated. Tenant AI task configuration is not persisted. The campaign remains a draft, with no recipient enrollment or outbound ledger entries. Calendar configuration is fake and disabled. No meeting, message, email, or proposal delivery is created.

The proposal uses the existing proposal generation service, approved `TenantService`, and `DecimalMoney` arithmetic. Its commercial lines illustrate both approved catalog price and a recorded owner-approved price override, plus an owner-approved 5% discount. The proposal remains in human review. Two versions are retained for version-selector inspection.

## Remove

The fixture tenant and all tenant-owned fixture records are removed by the tenant foreign-key cascade. From the configured local PostgreSQL database:

```sql
-- First capture the local tenant UUID; its private proposal PDF is file-backed.
SELECT id FROM tenants WHERE slug = 'sprint-1b-2-visual-qa';
-- Remove only this fixture tenant's local private proposal files under
-- storage/app/private/proposals/<tenant-uuid>/, then:
DELETE FROM tenants WHERE slug = 'sprint-1b-2-visual-qa';
```

The seeder is idempotent and refuses to duplicate an existing fixture tenant. Remove the fixture before reseeding. Never run this deletion against a production database.

## Sprint 1B-2 visual acceptance record (2026-10-06)

The desktop browser rendered Marketing Draft Review, Proposal Review (version 2), Commercials, Approval Center, and Setup using this fictional tenant. The marketing copy is explicitly labeled deterministic fixture text, not AI-generated; TEST MODE and no-send language are visible. Proposal context, contact, qualification, recommended/approved scope, catalog and override provenance, INR totals, discount, terms, internal notes, versions, and the “approval does not send or share” notice are present. The Approval Center shows the proposal review card and says that proposal approval does not send it. Setup labels outbound/calendar as TEST MODE · fake, AI routing as configured but not tested, and private storage as configured but not runtime verified.

The reviewer approved version 2 only on this fictional local fixture after saving its scope. The proposal API moved it to Approved; this did not send or share anything. PDF generation succeeded and the UI exposed a version-specific Download PDF action. The browser automation did not surface a completed download event, so the actual downloaded file is not verified. S3 remains unverified.

The requested viewport override (1280, 768, and 390 px) did not change the in-app browser’s effective viewport from 1440 px. Those data-dependent states therefore have desktop visual inspection only; responsive acceptance remains pending. Do not infer the smaller viewport results from these screenshots.

The fixture campaign initially used a legacy `days` field while the workspace renders numeric `weekdays`; the fixture seeder now writes the workspace’s expected shape. The local row was corrected and the Campaigns tab renders the send window. A stale proposal error banner also remained after a later successful action; successful scope saves and review actions now clear that old error. No runtime Vue error was observed after reloading the corrected fixture.

Final regression commands for this acceptance:

```sh
php artisan test --compact tests/Feature/AcquisitionWorkflowEndToEndTest.php
php artisan test --compact
npm run typecheck
npm run build
git diff --check
php artisan route:list --json
```

Observed results: Phase 2F E2E 2 tests / 81 assertions passed; full PHPUnit 123 tests / 762 assertions passed; TypeScript check and Vite production build passed; `git diff --check` passed; 165 routes registered. The fixture makes no live provider calls.
