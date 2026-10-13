<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\AI\AIProviderInterface;
use App\AI\AIRequest;
use App\AI\AIResponse;
use App\AI\Providers\AIProviderException;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class AIModelRouterUsageAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_call_reconciles_reservation_to_actual_usage(): void
    {
        $tenant = $this->tenant('usage-success');
        $router = $this->router(new AccountingTestProvider(fn (AIRequest $request, string $model) => new AIResponse(['ok' => true], 'accounting-test', $model, 17, 6)));

        $router->generate($this->request($tenant->id));

        $usage = DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->first();
        self::assertSame('COMPLETED', $usage->status);
        self::assertSame(0, (int) $usage->reserved_tokens);
        self::assertSame(17, (int) $usage->input_tokens);
        self::assertSame(6, (int) $usage->output_tokens);
    }

    public function test_failure_without_provider_usage_clears_reservation(): void
    {
        $tenant = $this->tenant('usage-failure-unknown');
        $router = $this->router(new AccountingTestProvider(fn () => throw new RuntimeException('synthetic failure')));

        try { $router->generate($this->request($tenant->id)); self::fail('Expected provider failure.'); }
        catch (RuntimeException $error) { self::assertSame('synthetic failure', $error->getMessage()); }

        $usage = DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->first();
        self::assertSame('FAILED', $usage->status);
        self::assertSame(0, (int) $usage->reserved_tokens);
        self::assertNull($usage->input_tokens);
        self::assertNull($usage->output_tokens);
    }

    public function test_provider_failure_with_known_usage_persists_real_counts_and_clears_reservation(): void
    {
        $tenant = $this->tenant('usage-failure-known');
        $router = $this->router(new AccountingTestProvider(fn () => throw new AIProviderException(
            'Synthetic response parse failure.', 'response_truncated', 'OPENAI_RESPONSE_RECEIVED', inputTokens: 27_300, outputTokens: 2_048)));

        try { $router->generate($this->request($tenant->id)); self::fail('Expected provider failure.'); }
        catch (AIProviderException $error) { self::assertSame(27_300, $error->inputTokens); }

        $usage = DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->first();
        self::assertSame('FAILED', $usage->status);
        self::assertSame(0, (int) $usage->reserved_tokens);
        self::assertSame(27_300, (int) $usage->input_tokens);
        self::assertSame(2_048, (int) $usage->output_tokens);
    }

    public function test_retried_parse_failure_accumulates_known_usage_from_both_attempts(): void
    {
        $tenant = $this->tenant('usage-parse-retry');
        $calls = 0;
        $provider = new AccountingTestProvider(function (AIRequest $request) use (&$calls): never {
            $calls++;
            throw new AIProviderException('Synthetic truncated output.', 'response_truncated', 'OPENAI_RESPONSE_RECEIVED',
                inputTokens: $calls === 1 ? 100 : 110, outputTokens: $calls === 1 ? 20 : 22);
        });
        $router = new AIModelRouter([$provider], ['website_reasoning' => ['provider' => 'accounting-test', 'model' => 'fake-model']]);

        try { $router->generate($this->request($tenant->id, 'website_reasoning')); self::fail('Expected both parse attempts to fail.'); }
        catch (AIProviderException) {}

        self::assertSame(2, $calls, 'The existing single parse retry remains in place.');
        $usage = DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->first();
        self::assertSame('FAILED', $usage->status);
        self::assertSame(0, (int) $usage->reserved_tokens);
        self::assertSame(210, (int) $usage->input_tokens);
        self::assertSame(42, (int) $usage->output_tokens);
    }

    public function test_failure_after_retried_response_does_not_double_count_first_attempt(): void
    {
        $tenant = $this->tenant('usage-parse-retry-schema');
        $calls = 0;
        $provider = new AccountingTestProvider(function (AIRequest $request, string $model) use (&$calls): AIResponse {
            $calls++;
            if ($calls === 1) throw new AIProviderException('Synthetic malformed output.', 'response_malformed', 'OPENAI_RESPONSE_RECEIVED',
                inputTokens: 100, outputTokens: 20);
            return new AIResponse([], 'accounting-test', $model, 110, 22, parseDiagnostics: ['top_level_type' => 'object']);
        });
        $router = new AIModelRouter([$provider], ['website_reasoning' => ['provider' => 'accounting-test', 'model' => 'fake-model']]);

        try { $router->generate($this->request($tenant->id, 'website_reasoning')); self::fail('Expected schema validation failure.'); }
        catch (AIProviderException $error) { self::assertSame('response_missing_required_fields', $error->stage); }

        $usage = DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->first();
        self::assertSame(210, (int) $usage->input_tokens);
        self::assertSame(42, (int) $usage->output_tokens);
        self::assertSame(0, (int) $usage->reserved_tokens);
    }

    public function test_daily_token_budget_ignores_failed_reservations_and_is_tenant_scoped(): void
    {
        config(['ai.daily_token_limit' => 100, 'ai.daily_call_limit' => 50]);
        $tenant = $this->tenant('usage-budget-own');
        $otherTenant = $this->tenant('usage-budget-other');
        $this->settings($tenant->id, 100);
        $this->usageRow($tenant->id, 'FAILED', 99, null, null);
        $this->usageRow($otherTenant->id, 'RESERVED', 1000, null, null);
        $router = $this->router(new AccountingTestProvider(fn (AIRequest $request, string $model) => new AIResponse(['ok' => true], 'accounting-test', $model, 1, 1)));

        $router->generate($this->request($tenant->id, maxOutputTokens: 10));

        self::assertSame(2, DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->count());
        self::assertSame(1, DB::table('ai_usage_records')->where('tenant_id', $otherTenant->id)->count());
    }

    public function test_reserved_in_flight_row_still_counts_against_daily_token_budget(): void
    {
        config(['ai.daily_token_limit' => 100, 'ai.daily_call_limit' => 50]);
        $tenant = $this->tenant('usage-budget-reserved');
        $this->settings($tenant->id, 100);
        $this->usageRow($tenant->id, 'RESERVED', 95, null, null);
        $router = $this->router(new AccountingTestProvider(fn (AIRequest $request, string $model) => new AIResponse(['ok' => true], 'accounting-test', $model, 1, 1)));

        try { $router->generate($this->request($tenant->id, maxOutputTokens: 10)); self::fail('Expected reservation to exceed the budget.'); }
        catch (RuntimeException $error) { self::assertSame('Tenant daily AI token budget would be exceeded.', $error->getMessage()); }

        self::assertSame(1, DB::table('ai_usage_records')->where('tenant_id', $tenant->id)->count());
    }

    public function test_failed_real_usage_counts_against_budget_instead_of_its_stale_reservation(): void
    {
        config(['ai.daily_token_limit' => 100, 'ai.daily_call_limit' => 50]);
        $tenant = $this->tenant('usage-budget-failed-real');
        $this->settings($tenant->id, 100);
        $this->usageRow($tenant->id, 'FAILED', 4096, 70, 30);
        $router = $this->router(new AccountingTestProvider(fn (AIRequest $request, string $model) => new AIResponse(['ok' => true], 'accounting-test', $model, 1, 1)));

        try { $router->generate($this->request($tenant->id, maxOutputTokens: 1)); self::fail('Actual failed-call usage should exhaust the budget.'); }
        catch (RuntimeException $error) { self::assertSame('Tenant daily AI token budget would be exceeded.', $error->getMessage()); }
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
    }

    private function settings(string $tenantId, int $tokenLimit): void
    {
        DB::table('tenant_automation_settings')->insert(['tenant_id' => $tenantId, 'autonomy_mode' => 'ASSISTED',
            'daily_token_limit' => $tokenLimit, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function usageRow(string $tenantId, string $status, int $reserved, ?int $input, ?int $output): void
    {
        $id = (string) Str::uuid();
        DB::table('ai_usage_records')->insert(['id' => $id, 'tenant_id' => $tenantId, 'task_key' => 'test_task',
            'provider' => 'accounting-test', 'model' => 'fake-model', 'input_tokens' => $input, 'output_tokens' => $output,
            'reserved_tokens' => $reserved, 'status' => $status, 'idempotency_key' => $id, 'created_at' => now()]);
    }

    private function request(string $tenantId, string $task = 'test_task', int $maxOutputTokens = 10): AIRequest
    {
        return new AIRequest($task, 'Synthetic test instruction.', [], ['type' => 'object', 'required' => ['ok'],
            'properties' => ['ok' => ['type' => 'boolean']]], maxOutputTokens: $maxOutputTokens, tenantId: $tenantId);
    }

    private function router(AccountingTestProvider $provider): AIModelRouter
    {
        return new AIModelRouter([$provider], ['test_task' => ['provider' => 'accounting-test', 'model' => 'fake-model']]);
    }
}

final class AccountingTestProvider implements AIProviderInterface
{
    public function __construct(private readonly \Closure $handler) {}
    public function providerKey(): string { return 'accounting-test'; }
    public function capabilities(): array { return ['text', 'json']; }
    public function generate(AIRequest $request, string $model): AIResponse { return ($this->handler)($request, $model); }
}
