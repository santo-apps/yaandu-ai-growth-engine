# YAANDU-PILOT-ICP-V2 (Proposed)

**Status:** Draft for pilot-owner review. Not written to tenant settings and not active.

This proposal is based on Yaandu's stated commercial capabilities and target markets, not on the six real calibration prospects. It is a B2B commercial-fit framework, not an industry whitelist.

| Independent dimension | Evidence standard | Scoring/configuration rule |
|---|---|---|
| Geography | Verified operating location in India or UAE / relevant Middle East market | Geography only; one signal maps to `icp_fit`. Missing location is unknown. |
| Organization suitability | Evidence of operating B2B products, operations, service delivery, or workflows where custom digital services are commercially plausible | Context and scale must be evidenced. Industry membership alone does not satisfy this dimension. |
| Digital opportunity | A specific, observed, cited website or workflow gap | Existing features are not defects. Absence of evidence is unknown. |
| Service fit | A controlled Yaandu service key is connected to cited prospect evidence | Use the taxonomy in `sprint-7c-6-calibration-diagnosis.md`; a catalog listing alone is not prospect need evidence. |
| Commercial/contact readiness | Public company inquiry path or role-relevant business contact, with source and timestamp | Public business contact evidence only. Do not infer a decision-maker from a generic contact channel. |
| Industry context | Explicit company industry record | Optional independent context only. Do not duplicate it inside geography or organization suitability. |

Proposed Yaandu capabilities: Website modernization; E-commerce development/migration; Custom software; Mobile applications; AI agents; WhatsApp automation/Converiq; ERP/workflow automation; SEO; conversion-rate optimization; digital marketing; Cloud/DevOps. These are taxonomy examples from Yaandu's stated strategy; the active pilot tenant currently has no active service catalog rows. The owner must confirm actual sellable services/SKUs before recommendations bind to tenant service IDs.

## Numerical score coverage gate

Keep current scoring weights unchanged. Return `null / insufficient_evidence` until all are true:

- At least 30% of the configured weighted dimensions are supported by positive or negative evidence.
- At least three dimensions are evaluable.
- At least one digital/service-opportunity dimension is evaluable.

Thirty percent means evidence spans at least three of the current ten 10-point dimensions at their current weights. Requiring both three dimensions and a digital/service opportunity prevents geography or an industry match from becoming a standalone score. Confirmed negative evidence remains in the denominator; unknown and not-configured evidence remains out. The score normalizes only the evaluable configured points, while coverage stays visible as a separate measure. These are conservative pilot gates and require owner acceptance before tenant configuration is changed.
