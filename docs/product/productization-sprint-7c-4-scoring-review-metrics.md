# Sprint 7C.4 — Scoring Semantics, Review History, and Pilot Metrics

## Scope and safety

This change corrects scoring interpretation and reporting only. It does not alter the Sprint 7C provider, model, approved prompt, frozen cohort membership, score weights, or ICP thresholds. The only real-cohort action in this task was a deterministic `LeadScoringAgent` run for the already intelligence-processed Santosh Limited prospect (`01a11b36-4f6e-7378-9520-35deba5ec9f0`). No website crawl, Website Intelligence run, campaign draft, recipient, message, meeting, or proposal was created. The canonical frozen cohort fixture hash remains `bab0d32113e48985b30ea54eda68c93223761cd3329671e47cd28d82f751762b`.

## Root cause and corrected semantics

The tenant settings contained only `{"pilot":"sprint_7b_real_prospect"}`: there were no configured scoring ICP industries or locations. The previous evidence builder nevertheless marked `relevant_industry` as a confirmed absence whenever the company had an industry value. The evaluator treated that dimension as evaluable, awarding zero of its 10 possible points. The other nine dimensions were unknown and excluded from normalization, so the persisted result was 0/100 with 10% evidence coverage. This was a false negative caused by an empty configuration, not evidence that the company failed the ICP.

Scoring now uses four explicit dimension states:

| Status | Meaning | In score denominator | In evidence coverage numerator |
| --- | --- | --- | --- |
| `POSITIVE` | Evidence supports the dimension | Yes | Yes |
| `NEGATIVE` | Evidence contradicts configured criteria | Yes | Yes |
| `UNKNOWN` | Evidence is missing or inconclusive | No | No |
| `NOT_CONFIGURED` | No applicable tenant criterion exists | No | No |

Industry and geography are separate dimensions. An empty industry list makes industry `NOT_CONFIGURED`; a configured list with missing company industry makes it `UNKNOWN`; a configured match is `POSITIVE`; and an explicit mismatch is `NEGATIVE`. Geography is evaluated independently. A negative remains zero raw points while retaining its weight in the denominator. Unknown and unconfigured dimensions do not dilute fit normalization. The current minimum remains the existing evaluator rule: at least one positive or negative weighted dimension must be evaluable; otherwise score is `null` and status is `insufficient_evidence`. No threshold or score weight changed.

Each persisted component now contains the dimension, semantic status, raw points, possible points, evidence reference, human-readable reason, and denominator inclusion flag. Legacy `points`, `max_points`, `present`, and `evidence` keys remain for existing consumers during the transition.

## Santosh rescore

| Measure | Before | After |
| --- | ---: | ---: |
| Score | 0/100 | `null` |
| Evaluation status | `scored` | `insufficient_evidence` |
| Evidence coverage | 10% | 0% |
| Evaluable points | 10 | 0 |

The new null score is evidence-correct: the persisted website report does not produce a configured positive or negative scoring dimension. Both ICP geography and relevant industry are `NOT_CONFIGURED`; the eight other dimensions are `UNKNOWN`. No point has been awarded without evidence.

## Crawl failure diagnostics

New crawl failures store `failure_category`, `retryable` (`YES`, `NO`, or `UNKNOWN`), `safe_error_summary`, and `original_scan_id`. Retryability is descriptive and does not itself dispatch work. The failure classifier uses stored/observed exception evidence; retry policy remains bounded and separate. Historical scans with only generic `CRAWL_FAILED` have insufficient evidence for a subtype and remain `UNKNOWN` with retryability `UNKNOWN`. The migration backfilled the five existing failed scans this way and used each historical scan's own ID as its original attempt ID. No recrawl was performed.

## Review history and migration

`pilot_review_history` stores immutable review versions, tenant and import-row identity, reviewer, review timestamp, review type, applicable Website Intelligence run and lead score IDs, rubric and ratings, claim counts/details, and notes. Every review submission appends a version and updates the existing `prospect_import_rows` columns as the latest-review compatibility projection. `GET /api/v1/pilot/import-rows/{row}/reviews` returns tenant-scoped history in newest-first order.

Migration `2026_10_13_000006_add_pilot_review_history_and_scan_diagnostics` backfilled all 20 existing reviewed import rows from their currently persisted review projection. The migration preserves those projection values. A review overwritten before this migration cannot be reconstructed if its previous version was not stored; the historical superseded values are unavailable and were not invented. Rolling the migration back drops the new history table and diagnostics columns while leaving the current review projection intact.

## Dashboard semantics

When a cohort is selected, pilot metrics are scoped to that cohort's import batches and associated companies; the existing empty-filter mode intentionally aggregates all pilot imports for the active tenant. Crawl counts use the latest scan per prospect and distinguish completed, partial (including failed scans with saved pages), and failed without pages. Website Intelligence runs are counted separately using tenant-scoped scan inputs; a partial crawl is never counted as a generated intelligence report. Generated/failed figures count distinct prospects with at least one successful/failed Intelligence run respectively; a prospect can appear in both when an earlier attempt failed and a later attempt succeeded. Lead scoring counts only latest scores explicitly classified `scored`; `insufficient_evidence` is separate; legacy or unknown evaluation statuses are exposed as `score_unclassified` and are not represented as verified scoring. Human review is a separate metric. The workflow funnel no longer labels crawl evidence as "analyzed".

The live frozen-cohort dashboard now reports 20 imported prospects; latest crawl statuses of 15 completed, 2 partial, and 3 failed; 1 successful intelligence report and 20 prospects with a persisted failed Intelligence run; 0 currently classified scores, 1 insufficient-evidence score, 19 legacy-unclassified scores, and 20 human-reviewed prospects. The failed Intelligence count is historical run evidence and does not imply 20 successful reports. The 19 legacy scores remain untouched pending later cohort rescore authorization.

## Horizon provider configuration observation

The first real Website Intelligence run recorded three `PROVIDER_AUTH` attempts while the Horizon worker was using stale process configuration. After a graceful Horizon restart loaded the configured Laravel provider environment, the same synthetic provider path passed and the subsequent real intelligence execution succeeded. This was stale worker configuration, not a remaining provider authentication defect. Operators should gracefully reload/restart Horizon workers after changing provider credentials or configuration; verify `horizon:status` returns to running and perform a synthetic smoke check before cohort work.

## Verification record

The PostgreSQL migration and review backfill were applied successfully. The specifically authorized Santosh scoring-only run persisted `null / insufficient_evidence / 0%`, with no ICP false-negative component. Final focused scoring, ICP, review, dashboard, and crawl tests passed at `32 tests / 242 assertions`; the full PHPUnit suite passed at `255 tests / 1,920 assertions`; PostgreSQL/Redis integration passed at `3 tests / 87 assertions`; TypeScript and Vite production build passed; the PHP syntax sweep passed for 364 files; Composer validation, route inspection, and `git diff --check` passed.

Product-discovery acceptance remains governed by the frozen-cohort business and intelligence acceptance criteria. This engineering fix alone does not claim the full cohort is accepted.
