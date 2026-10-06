# AI Provider Abstraction

## Implementation status (2026-10-05)

- **IMPLEMENTED:** three provider adapters, `AIProviderInterface`, task-based `AIModelRouter`, tenant model configuration, structured response validation, bounded retry transport, and deterministic fake-provider tests.
- **PARTIAL:** shared responses can carry usage/latency, but these are not consistently persisted on runs. Per-tenant cost budgets, fallbacks, provider allowlists, and per-task deadlines are not enforced.
- **NOT CONFIGURED:** live provider credentials are absent from repository example settings.
- **NOT RUNTIME VERIFIED:** live provider calls (intentionally excluded from local verification).

## Design

All model access flows through `AIProviderInterface` and `AIModelRouter`. Domain agents request a task capability, not a provider or model name. Provider adapters normalize responses/errors and implement provider-specific transport only. No agent, controller, or frontend code imports OpenAI, Anthropic, or Gemini SDKs directly.

```php
interface AIProviderInterface
{
    public function providerKey(): string;
    public function capabilities(): array;
    public function generate(AIRequest $request): AIResponse;
}
```

Concrete adapters: `OpenAIProvider`, `AnthropicProvider`, and `GeminiProvider`. Shared request fields include task, system policy, untrusted evidence blocks, output schema, temperature/budget, timeout, and correlation ID. Shared response fields include structured output, provider/model identifiers, usage, latency, finish reason, and safe error classification. Normalize tool/function calling behind a constrained capability only if Phase 1 needs it; do not expose raw provider tool execution to models.

Provider HTTP transport uses bounded connect/request timeouts and bounded transient retries according to adapter policy. Agent failures are converted to safe persisted summaries. Live provider behavior is not part of automated tests.

## Model routing

`AIModelRouter` reads versioned `ai_model_configurations` and environment-level secret references. Routing keys include `website_visual_analysis`, `website_reasoning`, `lead_classification`, `sales_reasoning`, `content_generation`, and `structured_extraction`. The configuration chooses provider/model, enabled status, request limits, fallback policy, and structured-output capability. Tenant settings may select among administrator-approved models and bounded parameters. System administrators control available providers and maximum budgets. Missing or invalid configuration fails closed with an actionable run error.

Task selection is configurable. Fallbacks and provider/model/configuration-version capture on every run are **PLANNED**; no silent fallback occurs today.

## Security and data handling

Credentials come from environment/managed secret references and never from request bodies or prompts. Enforce outbound egress allowlists for provider endpoints, TLS verification, timeout/retry limits, per-tenant budgets, and rate limits. Send minimum necessary evidence, strip scripts/hidden content, and do not include contact methods unless the task requires it. Provider retention/training settings must be configured and reviewed before production. Logs capture request metadata and hashes, not secret-bearing headers or full sensitive prompts by default.

## Validation and observability

Each AI task declares input and output schemas. Validate both before and after the provider call; reject extra/invalid fields as configured. Track latency, token usage, estimated cost, errors, fallback rate, and schema failures by provider/task without logging raw personal content. Keep provider-specific errors internal and return safe summaries to users. Use deterministic fakes in application tests and adapter contract tests when provider integration is enabled.
