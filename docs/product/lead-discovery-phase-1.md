# Lead Discovery Phase 1

## Architecture and repository fit

This implementation adds a tenant-isolated candidate-staging module. The existing `companies` table remains authoritative after promotion. It reuses `companies`, `company_websites`, `contacts`, `contact_methods`, `DomainNormalizer`, `PublicWebsiteDocumentFetcher`, `UrlPolicy`, `RobotsRules`, `ScanWebsiteJob`, WebsiteIntelligenceAgent, LeadScoringAgent, Sanctum/ResolveTenant, and `audit_logs`.

The existing `discovery_*` feature is a different website/discovery pipeline. Its verification path can create hidden companies and run intelligence/scoring before human acceptance, so this campaign implementation does not route through that lifecycle. No changes were made to its existing contracts.

## Tables

The additive migration `2026_10_18_000003_create_lead_discovery_foundation.php` creates:

- `lead_discovery_sources`: tenant-owned adapter and authorization metadata. No credentials are stored in the configuration JSON.
- `lead_discovery_campaigns`: target audience, independent bounded limits, status, and a permanently disabled auto-promotion flag.
- `lead_discovery_runs`: per-import state, counts, idempotency key, and a hash-only CSV provenance record.
- `lead_discovery_candidates`: staging identity and validation/promotion state. A company foreign key is populated only after an existing-company match or human promotion.
- `lead_discovery_candidate_evidence`: field-level source, timestamp, confidence, safe source URL, and attribution.

The candidate indexes and foreign keys include tenant identity where supported. There is no global domain uniqueness constraint.

## Enabled source and lifecycle

The initial source registry exposes only `csv_upload`. Owner/admin source setup requires an authorization/license summary and permitted collection method. It is disabled by default in the UI. There is no external directory endpoint or crawler enabled by default; an unavailable approved source is reported as a configuration need, not replaced with invented data.

CSV rows are bounded by byte, row, campaign, and processing limits. Business names are required. Website values pass existing domain normalization. Unsafe/invalid URLs are not copied into candidate records. Candidate website verification uses the existing public-site fetcher, which enforces URL normalization, public DNS/IP checks, redirect validation, robots policy, timeouts, and response-size bounds. Only a bounded number of identity pages is fetched. Public contact extraction accepts generic same-domain business email aliases and public business phones; no people are guessed or imported from private profiles.

Candidate eligibility is deterministic: authorized source, website identity match, exact campaign industry and geography. Unmatched or incomplete evidence remains `needs_review`; duplicates are blocked from automatic promotion and may be manually reviewed for reuse of an existing tenant company. New companies are created only by the promotion endpoint after server-side eligibility and source-authorization checks. Promotion is idempotent and audit logged. It creates/reuses the existing company and website/contact records and queues the existing scan/intelligence/scoring job. It does not set a human sales decision, select a service, enroll a campaign, or send outreach.

## API and UI

Authenticated, active tenant routes under `/api/v1/lead-discovery` provide source configuration, campaign create/list/detail, CSV import, candidate list/detail, pause/resume/cancel, manual promotion, and rejection. Tenant identity is derived from `ResolveTenant`; client tenant IDs are not accepted in request bodies. Source configuration requires owner/admin. Campaign execution and promotion require owner/admin/manager. Reads remain tenant scoped.

The Lead Discovery workspace is available from Prospects. It contains source setup, the India E-commerce & Retail campaign form (5,000 eligible target), CSV upload, a candidate workbench, provenance detail, and manual promotion. The pilot implementation is manual; no recurring schedule or auto-promotion exists.

The `lead-validation` queue has a dedicated conservative Horizon supervisor, capped at two workers and defaulted to one. Post-promotion scans use the existing website/crawl and intelligence/scoring queues. The source uses zero AI calls; scoring occurs only in the existing post-promotion workflow.

## Runbook and current limits

1. Apply the additive migration through the normal reviewed deployment process.
2. In the owner/admin Lead Discovery workspace, register a CSV source only after the source license/authorization and retention terms have been reviewed. Leave it disabled until approved.
3. Create a campaign and upload a licensed CSV with `company_name` and `website`; optional columns include `industry`, `country_code`, `region`, `city`, `source_url`, `business_email`, `business_phone`, and `source_reference`.
4. Review verification, eligibility, duplicate matches, and evidence. Promote only eligible or verified existing-company matches after human review.
5. Monitor Horizon's `lead-validation`, existing `crawl`, `intelligence`, and `scoring` queues. Disable a source or pause/cancel the campaign to stop new validation work.

Default CSV import limit is 100 rows per upload; campaign candidate and processing caps are enforced cumulatively across repeated uploads, independent of the 5,000 eligible-company target. UTF-8 is required and ambiguous/malformed headers or rows are rejected. This is not a validated 5,000-company acquisition capability. Open-web/directories, third-party APIs, broad contact enrichment, cross-campaign name similarity, and large-scale source/cost controls remain deferred until compliant sources and production capacity are evaluated. No production migration or live source run was performed for this change.
