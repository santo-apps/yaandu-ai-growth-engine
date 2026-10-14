# Lead Discovery Phase 2A — Automatic Live Discovery

## Repository audit

Phase 1 is the repository baseline (`72f75c0 Add controlled lead discovery phase 1`). This phase reuses its tenant-scoped candidate and evidence tables, `DomainNormalizer`, `PublicWebsiteDocumentFetcher` through `LeadDiscoveryCandidateVerifier`, public business-contact extraction, deterministic `DiscoveryEligibilityService`, manual promotion path, audit log, Sanctum/RBAC and `lead-validation` queue. It does not create companies from provider results and does not add a score, CRM, contact store, sales review, or outreach flow.

New components are the typed search query/page contract, Brave Search API adapter, deterministic search taxonomy, source checkpoint records, serial discovery jobs, candidate fingerprinting, campaign start/progress endpoints and the campaign-first UI. An additive migration adds candidate fingerprints, run request/raw-result counts, and query checkpoints. The existing Phase 1 migration is unchanged.

## Concrete provider and eligibility

The first adapter is Brave Search API (`brave_search`), using the documented `POST https://api.search.brave.com/res/v1/web/search` endpoint, JSON body and `X-Subscription-Token` server-side authentication. The request uses the documented country, count, and offset parameters. The provider currently documents a maximum of 20 results per request and offsets 0–9; this implementation asks for 10 results and stops at the final documented offset. It observes HTTP auth, quota, rate-limit, timeout and malformed-response failures without logging query content, response content or credentials.

The standard Brave Search API terms prohibit storing/caching search results or creating a results database except transient storage required to operate the application. Since this workflow persists result provenance and evidence, **do not enable a standard plan that lacks explicit result-storage rights**. A tenant owner/admin must record the governing order form/plan reference and affirm that it expressly permits storing results for this workflow. The application key and global live-enable flag are separately required. This tenant attestation is an operational gate, not a legal determination by the application. The provider terms and current price are changeable; the operator must review the agreement before enabling the source. The standard plan page currently lists Search at $5 per 1,000 requests with $5 monthly credits and 50 requests/second, but the implementation is further capped locally to 30 requests/minute and campaign limits.

Source authorization and retention rights are distinct persisted states. `authorization_status` records tenant approval for the provider access method; `storage_rights_status` is `not_confirmed`, `confirmed`, or `not_required` for CSV. Brave execution requires `confirmed`, a non-empty agreement reference, the prior explicit operator confirmation, an authorized and enabled tenant source, the server credential, and `LEAD_DISCOVERY_LIVE_ENABLED=true`. A credential alone never changes authorization or storage status. The source-health API/UI reports credential, tenant authorization, storage rights, and provider health separately; a missing storage approval reports `STORAGE_RIGHTS_NOT_CONFIRMED`. CSV sources remain on their licensed-source authorization path and do not require Brave storage-rights approval.

Provider documentation reviewed:

- [Brave Search API request reference](https://api-dashboard.search.brave.com/api-reference/web/search/post)
- [Brave Search API authentication](https://api-dashboard.search.brave.com/documentation/guides/authentication)
- [Brave Search API terms](https://api-dashboard.search.brave.com/documentation/resources/terms-of-service)
- [Brave Search API pricing](https://api-dashboard.search.brave.com/documentation/pricing)

The API offers country-targeted search and supports ISO alpha-2 codes, including India (`IN`). This confirms the API parameter, not business-result recall or production suitability in India. Geographic retrieval quality remains unvalidated until an expressly authorized, bounded smoke test is separately approved.

## Runtime configuration

```dotenv
LEAD_DISCOVERY_LIVE_ENABLED=false
BRAVE_SEARCH_API_KEY=
LEAD_DISCOVERY_DEFAULT_TARGET=25
LEAD_DISCOVERY_MAX_RAW_CANDIDATES=125
LEAD_DISCOVERY_MAX_SOURCE_REQUESTS=50
LEAD_DISCOVERY_MAX_RUNTIME_MINUTES=30
LEAD_DISCOVERY_MAX_FAILURES=10
LEAD_DISCOVERY_RATE_PER_MINUTE=30
LEAD_DISCOVERY_QUEUE=lead-discovery
```

Keep `LEAD_DISCOVERY_LIVE_ENABLED=false` until the credential is installed only in server secret configuration and provider storage rights have been reviewed and recorded. Never place the provider key in tenant source metadata or browser configuration. The HTTP call is server-to-server.

## Deterministic query buckets

The first taxonomy is `ecommerce_retail` and includes online retailer, ecommerce company, D2C, fashion, apparel, electronics, home decor, furniture, beauty, cosmetics and specialty retailer buckets. Campaign city, region and country refine query text deterministically. No LLM generates queries. Query text and key are preserved on checkpoints and candidate evidence for provenance; campaign geography and industry are **not** copied to candidate facts based solely on the query.

## Queue and bounds

`StartLeadDiscoveryCampaignJob` creates a run and one checkpoint per taxonomy bucket. `DiscoverLeadSourcePageJob` processes one provider page at a time on the dedicated `lead-discovery` queue. Worker concurrency defaults to one and is configurable from one to two via `LEAD_DISCOVERY_DISCOVERY_PROCESSES`; checkpoint claiming prevents concurrent duplicate page processing. The job is unique until processing starts so a page can safely queue its next cursor. Queue retries are capped at three with bounded backoff; provider retry/rate-limit behavior remains handled inside the job. Pages resume using the checkpointed numeric provider offset. Pause prevents further requests and leaves checkpoints resumable; resume re-dispatches unfinished source pages and candidate verification; cancel marks outstanding checkpoints stopped. Failed pages can be retried manually up to three attempts.

Hard provider-side bounds are the minimum of application configuration and any campaign request/raw/candidate/processing limits. Defaults are 25 eligible businesses, 125 raw results, 50 source requests, 30 minutes, 10 source failures and 30 requests/minute. Provider results can overlap pages, so fingerprints deduplicate by normalized domain across a tenant. Campaign targets refer to eligible verified businesses; raw results do not count as verified leads. There is no percentage progress estimate because the index result universe is unknown.

Each result is stored only after authorization gates pass and is represented as a staging candidate. Candidate domain normalization rejects unsafe URL forms; verification goes through the existing public-site fetcher, which retains its DNS/private-IP, SSRF, redirect, timeout, response-size, HTTPS, and robots rules. Search titles/snippets do not establish official identity. Public contact extraction happens only in the existing verifier after safe website fetch and only for supported public business contacts. Eligibility uses the existing deterministic evaluator. Promotion remains a separate human action. No automated scoring, qualification, service selection, Human Sales Review, outreach draft, campaign enrollment or message send is performed.

## API/UI

Added authenticated tenant-scoped source health and campaign start/retry endpoints. Source setup is owner/admin-only; start, pause, resume and cancel require an active manager-or-higher tenant role. Source IDs, campaigns, runs, candidates and checkpoints are queried with the active tenant. The default Lead Discovery workspace presents live source status, campaign creation, Start Discovery, checkpoint/progress counts, and the existing candidate workbench. CSV remains available under Manual/advanced import.

## Verification status

Implementation status must be reported from commands actually executed in the current checkout. Mocked adapter tests do not mean the provider is live-verified. No live provider request is part of automated tests or this implementation task. Real-provider credential presence is reported only as a boolean. Do not run a live query without an explicit operator authorization.
