# Agent Architecture

## Phase 1 implementation status (2026-10-05)

- **IMPLEMENTED:** common agent contract, `AgentOrchestrator`, JSON output validation, tenant-scoped run/event persistence, safe error fields, correlation IDs, and active `DiscoveryAgent`, `WebsiteIntelligenceAgent`, and `LeadScoringAgent`.
- **PARTIAL:** `AgentContext` does not enforce deadlines, cancellation, capabilities, or per-run budgets. Schemas are lightweight required-field lists, and lifecycle events are not full operational telemetry.
- **PLANNED:** durable cancellation, budget enforcement, richer schemas, AI usage/cost telemetry, and future agent functionality. Marketing, follow-up, sales, proposal, and autonomous orchestration agents remain disabled.

Run failures use `error_code`, safe `error_summary`, and `correlation_id`. Raw exception text is not copied to these fields or agent events. Restrict failed-job and application log access operationally.

## Common contract

Every agent implements `AgentInterface`:

```php
interface AgentInterface
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;
    public function outputSchema(): array;
    public function tools(): array;
    public function execute(AgentContext $context, array $input): AgentResult;
}
```

`AgentContext` currently carries tenant and actor IDs, run ID, correlation ID, and optional configuration. It must not carry provider credentials or unbounded page bodies. `AgentResult` contains output, summary, and evidence references. Tool definitions and execution are currently empty for active agents.

`AgentOrchestrator` resolves a registered agent, validates declared required fields, creates or resumes an `agent_runs` row, executes work, validates output, and appends ordered `agent_events`. Jobs own dispatch and retry. Composite foreign keys and tenant-scoped queries protect run/event ownership. Deadlines and cancellation are not yet implemented.

## Phase 1 agents

### DiscoveryAgent

Input: tenant-scoped discovery query, target geography/industry filters, permitted seed sources, and result limits. Output: normalized candidate records and source evidence. It uses registered discovery source adapters and deduplication services; it does not scrape arbitrary search results or bypass source restrictions. Candidate creation is idempotent and records source and observation time. Sprint 2 adapters are supplied seeds, confirmed user CSV/domain import, and an explicitly local/test-only fictional source. Verification is queued separately; acceptance reuses existing website intelligence and scoring workflows.

### WebsiteIntelligenceAgent

Input: website/scan reference and bounded scan policy. Output: structured business summary, technologies, issues, and evidence references. It consumes sanitized, size-limited crawler output. Raw fetched content is untrusted evidence and is clearly separated from system instructions. It cannot ask tools to navigate outside the crawler policy or execute page-provided instructions. Store each claim with source page, capture time, confidence, and model/prompt version.

### LeadScoringAgent

Input: company ID, applicable ICP/scoring-rule version, and evidence snapshot. Output: normalized 0–100 score, component breakdown, and evidence-backed rationale. A configurable rule evaluator owns the supplied initial points: ICP fit +10; relevant industry +10; outdated website +15; poor mobile UX +10; poor lead capture +10; no WhatsApp +10; no CRM +10; technology opportunity +5; decision maker identified +10; strong business fit +10. Missing evidence contributes zero, and confidence/coverage should be visible. Rules live in tenant configuration, not controllers or prompts. Clamp/normalize according to a versioned, documented rule-set policy.

## Planned agents (interfaces only in Phase 1)

Register stable names/descriptions and contracts for MarketingAgent, FollowUpAgent, SalesAgent, ProposalAgent, and OrchestratorAgent, but do not activate outbound actions or build their workflow behavior. Future agents must use the same run/event lifecycle and human approval gates appropriate to their actions.

## AI calls and reliability

Agents use `AIModelRouter` for AI tasks and domain services for deterministic work. Validate all model output against JSON schema and reject malformed/over-budget output. Use per-task token, cost, and time budgets; retry transient transport failures with bounded exponential backoff, but do not blindly retry non-idempotent actions. Persist input/output hashes and config versions rather than duplicating secrets or unnecessary personal data. Provider failures must be surfaced in run state and allow rerun with captured version context.
