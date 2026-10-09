# YAANDU-PILOT-ICP-V1

**Status:** conservative pilot definition; not activated on a tenant. Unknown remains unknown.

| Dimension | What qualifies | Evidence and scoring | Overlap decision |
|---|---|---|---|
| Geography fit | Operating business has a supported, tenant-verified India or UAE location | Company location field; existing `icp_fit` signal | Geography only contributes to `icp_fit`; it is not inferred from industry or website content. |
| Business type | Operating business in a segment Yaandu can plausibly serve | Explicit company industry, separately scored as `relevant_industry` | Industry is not repeated inside `icp_fit`. |
| Digital opportunity | A concrete website issue supported by a crawl page citation | Website issue rows, each evidence-linked | Do not award points for an unobserved weakness or absent page. |
| Service relevance | A recommendation explicitly connected to verified evidence and a Yaandu service | Human-reviewed service fit from cited observations/opportunities | A recommendation is not an observed fact and does not count again as generic business fit. |
| Evidence coverage | Enough evaluable evidence to make the score interpretable | `evidence_coverage` is reported separately from normalized score | Coverage is not a positive fit signal. |

Pilot scoring keeps the existing configurable rule weights. In particular, `icp_fit` is now geography-only, while `relevant_industry` is a separate explicit industry match. Missing evidence contributes neither positive nor negative points. Confirmed absence is zero only when the system can actually establish absence. If no dimensions are evaluable, the score is `null` and status is `insufficient_evidence`.

This definition is a reviewed proposal for the controlled pilot. Do not place it into tenant settings until the pilot owner approves the supported industry list, evidence standards, and service-fit mapping.
