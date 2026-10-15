# Lead Discovery Phase 2B — Open Sources

## Status and safety boundary

Phase 2B adds multiple source adapters to the existing tenant-scoped Lead Discovery staging workflow. It does not create another CRM or sales system. Candidates remain separate from `companies` until a person promotes them. Website verification reuses the existing safe verification job. There is no automatic sales decision, outreach draft, campaign recipient, or outbound send.

`LEAD_DISCOVERY_LIVE_ENABLED` remains `false` by default. A live adapter cannot run unless the global switch, tenant source enablement, owner/admin source authorization, the applicable storage-rights basis, and adapter-specific configuration all pass. OSM, Wikidata, and GLEIF use their documented reusable licenses as the storage-rights basis and retain required attribution; India OGD/MCA requires resource-specific terms review. Storage permission is never inferred from credential presence. Brave is conditional and omitted from the default source plan; its result storage requires an explicit tenant agreement reference and approval. Google Maps is documented in the capability catalog as prohibited for bulk persistent discovery and is not a selectable adapter.

> Publicly visible does not automatically mean lawful for automated persistent lead harvesting.

## Providers

| Provider | Access and data | Reuse/attribution | Geographic and operational limits |
| --- | --- | --- | --- |
| OpenStreetMap / Overpass | Bounded Overpass QL requests for named business features. Retains OSM object type/ID, mapped tag evidence, and official object URL. | ODbL; attribution `© OpenStreetMap contributors`; share-alike is recorded. Operators must confirm the applicable persistent-use basis before enabling a tenant source. | Global data, but this implementation queries only small configured city bounding boxes. Default adapter request rate is 1/minute, max area is 2 degrees per side, request timeout is bounded, and page count is capped. It is not world coverage. |
| Wikidata Query Service | Country-partitioned SPARQL with a small LIMIT, bounded result pages, explicit User-Agent, and selected organization data (Q-id, label, official site, country, industry/city where available). | CC0 1.0; Q-id and Wikidata entity URL are retained. | Country filters accept the complete local ISO catalog; unfiltered campaigns use a bounded rotating country window. One request/minute; query timeout and maximum pages are bounded. WDQS may be slow or unavailable; the job records source errors and does not fall back to guessed identities. |
| India OGD / MCA | Official data.gov.in resource API for Company Master Data. Maps CIN, legal name, status/category/state and original fields into provenance. | The catalog/resource terms and attribution apply. The tenant must record the applicable permission/license and confirm retention. Government attribution is retained. | India only. Requires a server API key and official resource ID. This implementation uses a bounded paged API mode; streaming ZIP/CSV ingestion is deferred. Records with no website remain legal-entity candidates and are not assigned guessed domains. |
| GLEIF | Official GLEIF JSON:API for bounded LEI record pages; maps LEI, legal name, registration state, and registered address. | CC0 1.0; LEI and GLEIF record URL are retained. | Country-partitioned for the configured country catalog; one request/minute and at most five pages per query. Golden Copy snapshot/delta ingestion is deferred. |
| Licensed CSV | Existing operator-supplied licensed/manual import workflow. | License and source reference are recorded with row evidence. | Tenant-controlled; manual import row and byte limits remain in force. It is not a live provider. |
| Brave Search | Existing search adapter retained as conditional. | Persistent result storage is blocked unless an applicable agreement explicitly allows it and an owner/admin records the agreement and confirms rights. | Credential alone does not authorize execution. The default planner does not select Brave automatically. |

Provider access policies are operational controls, not legal advice. Source onboarding still requires an owner/admin to review the actual terms for the resource and intended use. The source adapters fail closed when those controls are absent.

## Source capability and governance

Adapters expose source classes (`OPEN_PERSISTABLE`, `LICENSED_PERSISTABLE`, `CONDITIONAL`, or `PROHIBITED_FOR_BULK_STORAGE`) and capability metadata for query modes, filters, geographic coverage, fields, attribution, license, and rate limits. Source catalog, industry, and country endpoints expose that metadata to the UI. Credential state, required configuration, tenant authorization, storage rights, and runtime health are shown separately.

Google Maps/Places is not implemented as a bulk adapter. The catalog entry is explicitly non-selectable and reports the restriction. No Maps UI scraping or alternate access method is used.

## Planning and bounded geography

Industry and country are optional. A null industry plans supported industry batches only for sources that can filter that industry; sources without an industry filter use one bounded organization query rather than repeating the same dataset for each category. A null country expands only through the configured country catalog. MCA is restricted to India. Brave requires an explicit source selection and a country partition.

Country input accepts the complete local ISO 3166-1 alpha-2 catalog (249 assigned codes), not just countries with current OSM boxes or Wikidata mappings. A null country uses a deterministically rotated, bounded country window per campaign for global providers; MCA still plans only India. Industry suggestions are not a whitelist: the exact user-entered industry is preserved, known labels receive a normalized taxonomy key, and custom labels receive a null key and a bounded source-compatible query plan. Sources that cannot apply a filter use bounded general discovery or are skipped individually. OSM uses only configured small-area city boxes (currently representative cities in India, UAE, US, UK, Canada, Australia, Singapore, Germany, France, and New Zealand). A country/city without a matching safe OSM box yields no OSM task, while other compatible providers may continue. This is a bounded coverage sample, not a country support restriction or comprehensive coverage claim. `max_partitions` and `max_provider_requests` are bounded by application configuration and campaign limits. A campaign-wide database-locked request reservation prevents concurrent source jobs from exceeding the provider request budget. Provider-specific throttles and request timeouts remain conservative; rate limits, CAPTCHA, or access controls are never evaded.

Campaign progress includes per-source runs and partition status plus request, raw-record, candidate, duplicate, eligible, and error totals. A source failure is isolated and recorded; other eligible source tasks may continue within the campaign-wide limits.

## Identity resolution and field selection

Candidate rows and candidate evidence are reused; a parallel source-record CRM table was not added. Evidence retains tenant, campaign, run, source, source-record identifier/type, source URL, retrieval time, license/attribution, rights state, and field values.

Strong deterministic matches are normalized domain or explicit registration identifier, LEI, Wikidata Q-id, or OSM object ID. Strong matches attach new source evidence to one tenant candidate. Exact normalized name plus country plus city is only a `possible_match`; it creates a separate review candidate and does not merge. Similar names without all medium-match fields remain separate. Every lookup is tenant-scoped.

Canonical displayed values are reselected from retained evidence with deterministic field-specific precedence (government/LEI legal identity before Wikidata and OSM; OSM preferred for location fields where appropriate). Conflicting source evidence remains available. Website values are never guessed; only an explicit safe source URL is eligible for existing website verification.

Industry tags are retained as `source_industry` evidence. The candidate's normalized industry is populated only when it maps to the configured industry taxonomy; unknown source values remain evidence and do not become invented normalized categories.

## API and UI

Added tenant-scoped endpoints:

- `GET /api/v1/lead-discovery/source-catalog`
- `GET /api/v1/lead-discovery/industries`
- `GET /api/v1/lead-discovery/countries`

Campaign creation accepts optional industry/country, region, city, and keywords. Industry suggestions are free-text assisted, not a closed select. The API validates country values against the complete checked-in ISO catalog. Start Discovery validates the selected sources server-side and dispatches planned partitions into the existing `lead-discovery` queue. CSV remains in the advanced manual-import flow. The UI offers “Any industry” and “Any country,” custom industry entry, source checkboxes filtered by capability/geography and safe OSM partition availability, a provider rights catalog, per-provider progress cards, and manual promotion controls.

## Database and queues

One additive migration makes campaign industry/country nullable and adds partition metadata to existing checkpoints (`partition_key`, country, region, city, bounding box, and industry key). Existing candidates and evidence store source records; no duplicate source-record table is introduced. Existing tenant foreign keys and queue names are reused. Production worker concurrency remains conservative (default one discovery worker). No sales or outreach queue is added.

## Tests and verification boundary

All provider adapter tests use mocked HTTP responses. The phase tests cover OSM query/mapping/attribution, Wikidata SPARQL and mapping, MCA and GLEIF mapping, capability metadata, optional filters, country compatibility, bounded plans, source-record staging, strong and medium identity cases, tenant scoping, manual promotion boundaries, and no-outreach invariants. No test calls Overpass, Wikidata, data.gov.in, GLEIF, or Brave live.

Live provider access, production legal review, a real resource terms review, data yield, source recall, production rate behavior, and production storage retention have not been verified by this implementation task.

## Known limitations and deferred work

- OSM geographic boxes cover selected cities only; no global/country-wide inventory claim is made.
- Wikidata queries cover the configured country list and broad organization class; query latency and completeness are unverified.
- MCA has bounded official API pagination only. The official downloadable resource streaming path, checksums, temporary-file cleanup, and national corpus capacity are deferred.
- GLEIF uses paged API reads only. Golden Copy snapshot and delta-sync ingestion are deferred.
- The normalized industry taxonomy cannot infer a source label that does not map to a configured category; raw category evidence is retained.
- Strong identity resolution is deterministic and conservative; medium matches require review. Field precedence is deterministic, not a claim that one source is always factually correct.
- Provider yield, recall, and unique-business counts have not been measured.

## Recommended rollout (not executed)

1. OSM only, target 5; review source authorization/attribution and source-specific yield.
2. OSM + Wikidata, target 25 after reviewing bounded-query health and candidate duplication.
3. Add MCA and GLEIF for a 50-lead sample after resource-specific rights/configuration review.
4. Expand to 100–250 only after reconciliation and operations review.
5. Do not begin with a 5,000-lead campaign.

## Official references

- [OpenStreetMap copyright and ODbL attribution](https://www.openstreetmap.org/copyright)
- [Overpass API usage notes](https://wiki.openstreetmap.org/wiki/Overpass_API)
- [Wikidata data access and Query Service guidance](https://www.wikidata.org/wiki/Help:Data_access)
- [Wikidata Query Service query limits](https://www.wikidata.org/wiki/Wikidata:SPARQL_query_service/query_limits)
- [India OGD Company Master Data catalog](https://www.data.gov.in/catalog/company-master-data)
- [GLEIF LEI data access and use](https://www.gleif.org/en/lei-data/access-and-use-lei-data)
- [Brave Search API terms](https://api-dashboard.search.brave.com/documentation/resources/terms-of-service)
