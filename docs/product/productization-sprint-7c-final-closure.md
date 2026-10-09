# Productization Sprint 7C Final Closure

## Decision

Sprint 7C calibration did not meet the production acceptance gates. The product mode for this closure is **Human-Assisted Intelligence** (`human_assisted`). Autonomous recommendation and lead-scoring quality were not accepted for production use.

## Final calibration evidence

The six-business sample was Santosh, KPR Mill, LMW, MRF, Agthia, and Al Rawabi. Review covered 24 factual claims: 20 supported, 4 partially supported, 0 unsupported, and 0 critical unsupported. The mean intelligence rubric score was 7/17; 0/6 reports reached 12/17. Recommendation usefulness was 50%, and next-action usefulness was 50%. Two scores were evaluable and both were rated acceptable; four had insufficient evidence. There were 0 false zeros and 0 unauthorized outbound actions.

The failed gates were intelligence depth, recommendation usefulness, and next-action usefulness. This closure does not run more calibration or prompt tuning and does not process additional prospects.

## Product behavior

- The default tenant/system mode is `human_assisted`. `experimental_autonomous` is not enabled for the pilot; the global feature gate defaults off.
- Crawling and Website Intelligence remain available. Structured observations, evidence, opportunities, detected technologies, unknowns, prior runs, and review history are retained.
- AI findings and scores are advisory and visibly require human review. Historical score/recommendation data remains available for analysis.
- AI service suggestions do not assign catalog services, create service scope, or feed proposal line items. A human reviewer separately chooses active tenant services, “No applicable service,” or “Needs discovery.”
- A human reviewer separately assigns High, Medium, Low, Not a fit, or Needs more evidence. Decisions are append-only and record tenant, prospect import row, source intelligence run when available, reviewer, timestamp, and notes. Each save is audit logged.
- In Human-Assisted mode, an AI lead score cannot trigger automated marketing draft generation. The coordinator requires a latest human High/Medium priority and selected service as well as the existing score threshold. A score does not itself authorize an outbound send.
- SalesAgent qualification remains an advisory snapshot; it does not set `qualified_at` or mark an opportunity qualified. A salesperson must use the existing explicit qualification/stage workflow. Existing meeting, proposal, and outbound approval boundaries remain in place.
- Dashboard human priorities/service decisions are separate from legacy and current AI score statistics. AI score metrics are labelled advisory.

## Accepted capabilities

- Public-site crawl and persisted Website Intelligence evidence.
- Human review history and append-only service/priority decisions.
- Tenant-scoped access to active catalog services only.
- Human-assisted prospect workflow and separated dashboard metrics.
- Existing deterministic safety gates, tenant scoping, and explicit approval workflows.

## Not accepted

- Autonomous service recommendations or automatic service assignment.
- Autonomous lead priority or production use of AI score as authoritative qualification.
- Automated advancement, scheduling, proposal generation/sending, or outreach authorization based only on AI score.
- Production operation of autonomous recommendations/scoring.

## Historical data and cohort controls

Previous intelligence runs, AI suggestions, score runs, calibration reviews, prompt versions, and Sprint 7C evidence are preserved. This closure does not change the OpenAI model, crawler, ICP, services, pricing, or calibration configuration, and does not rerun or expand `S7C-REAL-COHORT-V1`.

## Production limitations

This is an engineering/product-mode closure, not a production-readiness declaration. Outstanding production-hardening work includes isolated Playwright worker deployment, S3 runtime verification, production Horizon process management, live provider operational smoke tests, `APP_DEBUG=false`, backup/restore and retention policies, production monitoring/alerts, deployment verification, and production security review.

## Future enhancement path

Keep human-assisted intelligence as the shipped behavior. Any future autonomous mode requires a separate product and risk review, a gated tenant opt-in, explicit evaluation criteria, and new evidence demonstrating that every acceptance threshold is met. Do not treat this Sprint 7C calibration as accepted autonomous performance.
