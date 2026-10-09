# Sprint 7C.6 — Intelligence Quality, ICP, and Recommendation Calibration

## Calibration guardrails

This is a six-business calibration set only: Santosh Limited, KPR Mill Limited, LMW Limited, MRF Limited, Agthia Group PJSC, and Al Rawabi Dairy Company. The frozen cohort checksum remains `bab0d32113e48985b30ea54eda68c93223761cd3329671e47cd28d82f751762b`. No other cohort business, failed scan, or outreach workflow is in scope.

The tenant route remains OpenAI / `gpt-4.1-mini` / `website_reasoning`; WebsiteIntelligenceAgent prompt v1 remains approved and active. The six prior outputs, review records, score records, and scan snapshots are the baseline and must not be overwritten.

| Business | Import row | Crawl snapshot | Prior intelligence run | Prior review | Prior score / score run |
|---|---|---|---|---|---|
| Santosh Limited | `41749d5c-81f0-475e-920c-6c044dd1f282` | `3dbcea0d-efb4-483c-b95d-7d4eee38083a` | `06f1329a-ac72-47cb-9565-4a587f728a95` | `dc91a9dc-d51d-43c3-90a5-1285044a3eb6` v1 | `ba35eb32-e184-4030-b1f3-f345f02d1cb3` / `09822486-f196-4a7d-a314-8622a5e2191f` |
| KPR Mill Limited | `24a11b49-df07-4025-9f9f-2a5cc38f1499` | `1b945905-7ada-4c58-b935-965f7cadc068` | `6f44203b-8d9c-4e92-931a-e4001ed0ac8a` | `322d4d38-a936-4155-8071-56ff6dd85261` v2 | `cf406362-2023-48ed-a9f9-f84a3cc16d72` / `609f67ee-7998-4ff8-9ce8-96a931a5da8f` |
| LMW Limited | `fcecebf3-4923-4a26-bb1b-8aecc5be5fff` | `aeb2730a-4759-4de1-bca9-2b4408c3280a` | `30721873-32f7-43d2-9bd0-ac5b27805b81` | `aea4bfc5-9755-4fdd-9461-f49b0406257d` v2 | `a626ab7a-0613-4d9c-a756-224a8c131da0` / `7fe6cdc8-b4a2-4f75-9682-9fc4d5b2829e` |
| MRF Limited | `251bd452-0553-4e0c-a3ae-3c062d5d9a0f` | `a34db2be-f66c-4b48-bda6-ec4c61535810` | `115abb26-f568-4f52-96aa-0c8582a63006` | `aeea6833-db68-470b-b0f3-3d981be3fef7` v2 | `a6097552-fed1-4a25-98e3-45c3fafc01bc` / `8bbcd156-bb21-4e91-901a-54c9755c0efb` |
| Agthia Group PJSC | `aa94716f-1721-45bf-90e9-0f8a6755d26a` | `a3431462-6769-4a27-83d8-0c3f8b177513` | `843de4e4-937b-4e63-bc57-1bd619bcfe2d` | `047556bf-de84-4e4b-ae56-82732620cb12` v2 | `07c57aad-29cf-42e5-ad1f-269a2a350cef` / `05b1b484-21c1-4dd2-b020-b5c8811edf3a` |
| Al Rawabi Dairy Company | `6ea5462c-fed6-4584-a1c5-34c9c0709d34` | `e667de7b-d1e7-4893-ac09-aa6b4985b43e` | `9541fd63-2840-422c-88d1-ea3c5c1fe7f5` | `a89c059e-6dec-4ff5-b4c1-fafbf97e94cd` v2 | `baef8c17-d0a9-4469-b0f7-ff84d8318f0b` / `b5d5dee1-9556-4280-865d-588f8147db3b` |

The scan snapshots above are completed. Santosh has one crawled page; the other five have ten each. The active tenant has no active `tenant_services` rows and no configured ICP industries or locations.

## Recommendation diagnosis from persisted evidence

| Business | Persisted outcome | Verified cause(s) | Unknown due to missing structured response |
|---|---|---|---|
| Santosh | No service recommendation or next action in human review; one crawl page, zero stored issue/technology rows. One logistics/supply-chain inference was recorded as an opportunity. | Limited crawl coverage (A) and the opportunity does not itself evidence a digital-service need (F). V1 never requires a next action or a catalog mapping (C). | Whether the provider proposed and normalization dropped a recommendation (D/E), or proposed none (B). The complete response was not stored. |
| KPR Mill | Three flattened service-recommendation insights were stored; review rated the recommendation weak. Ten pages, one issue, no detected technology. | Some recommendations extrapolated from manufacturing scale/expansion to automation, logistics, and ERP demand without evidence of an active need (F). No active service catalog or controlled mapping existed (C/G). | Exact per-recommendation structured strength and whether any model fields were lost during normalization (D/E). |
| LMW | Three flattened recommendation insights were stored, including duplicate website-audit suggestions and generic digital marketing. Review found the recruitment-page audit relevant but marketing need unsupported. Ten pages, two issues. | Recommendation was generated, but generic marketing expansion was not grounded in a demonstrated need (F). Duplicate flattening and absent service IDs/next-action contract are verified persistence/contract gaps (C/D). | Original model output is unavailable, so the source of the duplicate (model vs normalization) cannot be proven (E). |
| MRF | One digital-marketing recommendation was stored. Review found possible relevance from the distributor network, but no current digital gap; recorded site findings were features rather than defects. Ten pages, two issue rows, no technology rows. | Opportunity inference was broader than the website evidence of a current need (F); v1 has no controlled service taxonomy or strength/missing-information contract (C/G). | Whether any additional recommendation or next action was omitted by persistence (D/E). |
| Agthia | Two opportunity suggestions were described in the review as weak; six insight rows were persisted. Ten pages, no technology rows. One provider parse attempt failed, then a retry succeeded. | The stored review says suggestions did not show demand or connect to an active Yaandu service (F/G). V1 does not strongly constrain recommendation mapping or require discovery questions (C). | Full response is absent, so parser/normalization loss cannot be ruled in or out (D/E). |
| Al Rawabi | Five facts persisted; no recommendation or next action in the review. Ten pages, one issue row, no technology rows. | The review correctly left recommendation unassessed where no supported service need was available (F). V1 permits empty recommendations and does not ask for next actions (C). | Whether any recommendation was emitted then dropped (D/E) cannot be established without the original structured response. |

Across all six, crawling supplied business facts, but technical findings were sparse and service catalog evidence was absent. The agent persists only an output hash and flattened insight/evidence rows, not the complete structured response; therefore missing recommendations cannot be conclusively attributed to the model versus normalization/persistence. No evidence supports treating the prompt as the sole root cause.

## Rubric diagnosis

The latest historical rubric scores are Santosh 10/17, KPR 11/17, LMW 15/17, MRF 12/17, Agthia 9/17, and Al Rawabi 9/17. Five, not four, are at or below 12. The exact six-category breakdown was recorded only for Santosh: identity 2/2, website facts 3/3, technical observations 0/3, opportunity identification 2/3, service recommendations 0/3, evidence quality 3/3.

For the other five, review notes do not preserve a per-category point allocation, and full structured model responses are unavailable. Inventing category-level lost points would not be auditable. The evidence supports these causal findings only:

- **Crawler coverage:** all six have completed scans, but Santosh's one page is materially thinner than the other five's ten-page snapshots. Thin coverage limits technical findings and opportunities.
- **Prompt construction:** v1 separates facts and inferences and requires evidence IDs, but does not require discovery questions, next actions, controlled service identifiers, or recommendation strength.
- **Evidence packaging:** page text is capped to 6,000 characters per page and at 20 pages; the current snapshots were 1 or 10 pages. No observed evidence shows a packaging failure for the other five.
- **Response parser:** exact citation checking is strict; however, no full provider output exists to establish whether valid fields were filtered. This remains unproven.
- **Persistence:** confirmed loss of the complete output contract; only a hash and flattened rows survive. Recommendation field loss is possible but not proven per business.
- **Model response:** some persisted recommendations were generic or extrapolated; no conclusion can be made about content that did not persist.

## Implementation changes

- Add immutable `website_intelligence_results`, one normalized structured result per tenant/run, with scan, company, run, prompt version, model/provider, correlation, confidence, provider latency, execution duration, and validated evidence references. Do not store raw provider payloads or prompts.
- Link issue and technology findings to their producing agent run. New intelligence runs append findings; they do not delete prior scan findings. Scoring reads the latest successful intelligence run's findings.
- Persist provider latency on `ai_usage_records` when the adapter supplies it; retain existing token and estimated-cost behavior.
- Add a fixed service-key vocabulary. Active tenant catalog IDs are attached only when an active tenant SKU exactly matches a controlled key; the tenant's catalog currently has no active services.
- Prompt v1 remains untouched. V2 is implemented as an unapproved draft contract in code; it constrains service keys and requires evidence, strength, missing information, discovery question, and next action. It must be created through the owner/admin prompt workflow and reviewed before activation.
- Add score coverage gates: at least 30% of configured weighted points, at least three evaluable dimensions, and at least one evaluable digital/service-opportunity dimension. Weights are unchanged. Industry alone or geography alone cannot yield a score; unknown and unconfigured dimensions remain excluded.
- Add named dashboard metrics for latest intelligence success/failure and cumulative historical failures; retain legacy aliases. Review history API exposes latest and previous review records and their run IDs.

## Proposed YAANDU-PILOT-ICP-V2 (not activated)

This is an owner-review proposal based on Yaandu's stated commercial capabilities, not fitted to the six calibration businesses. India and UAE/Middle East are target geographies. The dimensions are independent:

1. **Geography:** a verified business operating location in India or UAE; map only to geography (`icp_fit`).
2. **Organization suitability:** an operating B2B organization whose evidenced products, operations, or customer workflows plausibly support custom digital-service engagement. Industry is context only and cannot establish this dimension by itself.
3. **Digital opportunity:** an observed, cited website or workflow gap. A feature's presence is not evidence of a defect.
4. **Service fit:** evidence supports one or more controlled Yaandu service keys. A service's existence in Yaandu's catalog does not establish the prospect's need.
5. **Commercial/contact readiness:** public business contact or inquiry route, verified only from crawl evidence. No private personal data is required.

Service vocabulary: Website modernization; E-commerce development/migration; Custom software; Mobile applications; AI agents; WhatsApp automation/Converiq; ERP/workflow automation; SEO; conversion-rate optimization; digital marketing; Cloud/DevOps. Industry should remain its own contextual criterion if the owner chooses to configure it; never count that same industry match again as geography or organization fit. The proposal is not tenant configuration and needs owner approval, a commercial industry/organization definition, and confirmation of which service SKUs are actually offered.

## Synthetic scoring cases

`Sprint7C6CalibrationTest` covers strong, weak, mixed, industry-only, opportunity with unknown industry, geography-only, strong service fit, no evidence, and conflicting evidence. Ordering must be weak < conflicting < mixed < strong. Industry-only, geography-only, and no-evidence cases remain null / insufficient evidence. The fixture uses existing weights and does not set any of the six real companies' tenant configuration.

## Calibration execution decision

Do not rerun the six reports in this change set. V2 is not approved, the service catalog is empty, and the proposed ICP is not approved or configured. Those are deliberate governance gates, not reasons to modify v1, seed fictitious offerings, or silently change tenant scoring configuration. After an owner approves a v2 prompt and ICP/service catalog settings, run only the six listed IDs, append review-history versions, and apply all stated quality acceptance targets before authorizing the remaining cohort.

## Sprint 7C.6 verification status (2026-10-09)

**Calibration acceptance: NOT ACCEPTED.** This implementation pass did not rerun intelligence or scoring for any of the six businesses. There are no revised reports, claim reviews, recommendation ratings, next-action ratings, or score-usefulness ratings to report. The six Sprint 7C.5 reports/reviews/scores remain the only evaluated baseline. Their six latest review records contain 52 claims: 35 supported, 14 partially supported, 0 unsupported, and 3 unable to verify. These historical figures do not measure a revised prompt or this implementation.

Historical calibration baseline only: rubric scores were 9/17 (2), 10/17 (1), 11/17 (1), 12/17 (1), and 15/17 (1), so 2/6 reached 12 or higher. Recommendation ratings were weak (2), possibly relevant (2), and unable to assess (2). Next-action ratings were useful (1), acceptable (1), and unavailable (4). All six score reviews were insufficient evidence; the linked results were null/insufficient for five and an older zero score for Santosh. Technology ratings were unknown (5) and not detected (1). These are the previous reviewer's outcomes, not Sprint 7C.6 acceptance results.

- Frozen manifest: 20 records; recomputed SHA-256 `bab0d32113e48985b30ea54eda68c93223761cd3329671e47cd28d82f751762b` (matches the canonical checksum).
- Provider route: OpenAI / `gpt-4.1-mini` / `website_reasoning`, enabled. Prompt v1 remains approved and active. Prompt v2 exists only as an inactive draft; it has not been approved or used for calibration.
- Database/runtime: PostgreSQL connected; Redis `PONG`; Horizon running.
- Cohort preservation: all six baseline intelligence run IDs remain present; zero versioned structured reports were created for the cohort in this pass. Campaign recipients and outbound messages remain zero.
- Focused Sprint 7C.6 tests: 35 passed, 295 assertions. Full PHPUnit: 258 passed, 1,973 assertions. TypeScript check and Vite production build passed. PHP syntax passed for 367 files. Composer validation and `git diff --check` passed. Routes registered and inspected.
- PostgreSQL/Redis integration: 4 tests passed, 94 assertions. The first run exposed a verified defect: execution duration was computed by subtracting timezone-ambiguous database timestamps, producing a ~5.5-hour value and a PostgreSQL integer conversion failure. Duration now uses a monotonic runtime clock; the focused persistence test guards against this regression, and the full integration rerun passed. No temporary `yaandu_s7_it_` keys remain. The live Horizon queue prefix was not touched.

The ICP V2 and service catalog are proposals requiring owner review and tenant configuration. The active tenant currently has no configured target industries or geographies and no active services. Do not run the six-business recalibration until those settings and any desired prompt v2 activation are approved through the normal governance flow. Do not process the other nine businesses or retry the five failed scans in this task.

**Recommendation: FIX AGAIN.** The implementation regression is resolved and technical verification passes. Complete owner review/configuration of the ICP and service catalog, and decide whether to approve prompt v2. Then rerun only the six calibration businesses and perform append-only human review against the stated acceptance targets. No revised quality evidence exists yet, so the frozen cohort remains blocked.

## Sprint 7C.7 owner-configuration stop (2026-10-09)

The authenticated local UI showed the active tenant `Sprint 7B Controlled Real Prospect Pilot`, signed in as `LOCAL TEST · Sprint 7C Pilot Owner`; a read-only membership check confirmed active owner membership for tenant `01a11b31-7161-701f-a78a-2700d054e533`. The route remains enabled OpenAI / `gpt-4.1-mini`. Prompt v1 remains approved/active; v2 remains draft/inactive.

Prompt v2's text was reviewed and includes grounded facts, technical findings, opportunities, controlled service keys, citations, recommendation strength, missing information, discovery questions, next actions, unknowns, confidence, and unsupported-claim restrictions. **Approval was withheld** because the runtime currently injects the full static 11-key taxonomy (`YaanduServiceTaxonomy::promptCatalog()`) rather than the tenant's approved offerings. With zero active tenant services, a model could return a recognized service recommendation that has no tenant catalog ID. This is not safe for the requested service-mapped calibration.

The service catalog contains no rows. The owner UI requires a unit price and currency to create a service; its price field defaults to `0.00`. No authorized service rates or commercial models were present, so no catalog entries were created and no zero-price placeholders were activated. The public Yaandu services page lists several actual capability families, but does not establish the pilot's approved sellable SKUs, scopes, or rates ([Yaandu Services](https://yaandu.com/services/)).

The proposed ICP remains inactive. Its five dimensions are not represented by the current tenant configuration UI/API, which only accepts industry strings, exact company-location labels, and keywords. The operator prompt does not name the pilot's owner-approved target markets or target industries. No geography, industry, keyword, or weight setting was changed; scoring weights remain unchanged. The synthetic scoring and evidence-coverage rules remain implemented and regression-tested, but tenant-specific scoring configuration is not ready.

Per the Sprint 7C.7 stop condition, no prompt was approved, no service/ICP configuration was changed, and calibration stopped before any provider call. No intelligence, score, review, crawl, or outreach was created for any of the six businesses. The frozen cohort and baseline remain blocked pending an owner-confirmed service/SKU/rate list, approved target markets and optional industry criteria, and a correction to ensure only active tenant services are supplied to the model.

## Sprint 7C.8 configuration foundation

### Previous service recommendation path

Before this change, `WebsiteIntelligenceAgent::execute()` loaded `WebsiteIntelligencePilotPromptV2`, appended `YaanduServiceTaxonomy::promptCatalog()` (all 11 canonical keys), and asked the model for `service_key`. Normalization checked only that the key existed in the global taxonomy, then separately looked up an active tenant SKU; the recommendation was still retained when that lookup returned null. Consequently the static vocabulary was presented as the tenant's offerings, and recommendation persistence could have a null `tenant_service_id`. `ProposalController` service CRUD stores tenant rows, but it was not the source of the Website Intelligence prompt catalog.

### Service boundary now enforced

`TenantServiceCatalog::recommendationServices(tenantId)` is now the source for recommendation-capable offerings. It returns only rows for that tenant which are active, have `approved_by`, are within their effective dates, and have a canonical service SKU. The global `YaanduServiceTaxonomy` remains vocabulary for key validation only; its former all-services prompt-catalog helper has been removed.

For v2, prompt construction passes only each eligible tenant row's `service_key`, display `name`, and bounded `description`; it never passes price/cost data. The structured-output schema is constrained to eligible keys. An empty catalog constrains the recommendation array to zero items and supplies `[]`, not the global taxonomy. Normalization requires an exact match in the current tenant catalog and persists the matched tenant service ID; unsupported/inactive/unapproved keys are dropped. V1 has no service-key mapping contract and therefore cannot persist service recommendations.

The authenticated intelligence-readiness endpoint now reports catalog existence/counts and prompt/service-key compatibility, and cannot return ready without recommendation-capable tenant service rows, a compatible active v2 prompt, and an active versioned tenant ICP. An empty catalog remains safely not-ready.

### Versioned tenant ICP

`tenant_icp_configurations` stores tenant-scoped version numbers with explicit JSON dimensions, draft/active/inactive/superseded status, author, activation actor/time, and timestamps. Owner/admin-only `/api/v1/icp-configurations` endpoints create/update drafts, activate a draft, and deactivate the active version. Activation/deactivation lock the tenant row and append an `audit_logs` event in the same transaction. Activation validates required explicit geography, target industries, organization suitability, evidence-driven digital-opportunity categories, active approved service keys, and public-contact evidence criteria; it supersedes the previous active version.

The Setup → ICP Configuration screen now edits this versioned contract separately from scoring weights. Active version geography and industries feed the existing geography-only `icp_fit` and distinct `relevant_industry` scoring dimensions. Existing explicit legacy scoring settings remain a compatibility fallback until a versioned ICP is activated; they do not satisfy intelligence readiness. No scoring weights or cohort records were changed.

### Owner decisions remain outstanding

The pilot tenant still has zero service rows and no approved prices or commercial terms. The services interface requires a real price/rate model; none was supplied, so no service was created and no zero-price placeholder was used. The tenant has no active ICP version. The UI/API and data model can now represent the five dimensions, but the owner must supply actual target countries/locations, target industries, suitable organization types and commercial-viability criteria, selected evidence-based digital opportunities, public contact-readiness criteria, actual service keys, and their price/rate/currency/unit terms before activation.

Prompt v2 remains an inactive draft and was not approved. No provider calls, crawl, intelligence, scoring, review, sales record, or outreach were triggered for any frozen-cohort business. The 20-business checksum remains the canonical `bab0d32113e48985b30ea54eda68c93223761cd3329671e47cd28d82f751762b`; the six historical intelligence runs and review/score history remain the baseline.
