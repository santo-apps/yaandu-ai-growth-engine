<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebsiteIntelligenceSmokeTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_smoke_uses_a_uuid_compatible_correlation_id_and_records_provider_metadata(): void
    {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenantId, 'name' => 'Smoke fixture', 'slug' => 'smoke-fixture', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_model_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId,
            'task_key' => 'website_reasoning', 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'enabled' => true,
            'parameters' => json_encode([]), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config(['ai.providers.openai.key' => 'test-only-key', 'ai.providers.openai.endpoint' => 'https://api.openai.test/v1']);
        Http::fake(['api.openai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"result":"ok"}']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]], 200)]);

        self::assertSame(0, Artisan::call('ai:smoke-test-website-reasoning', ['tenant' => $tenantId]));
        $record = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue(Str::isUuid($record['correlation_id']));
        self::assertSame('OPENAI_RESPONSE_RECEIVED', $record['request_outcome']);
        self::assertSame(200, $record['http_status']);
        self::assertSame('https://api.openai.test/v1/chat/completions', $record['endpoint']);
        self::assertSame(['model', 'temperature', 'max_tokens', 'response_format', 'messages'], $record['request_shape']['request_keys']);
        self::assertSame(['type', 'additionalProperties', 'required', 'properties'], $record['request_shape']['schema_top_level_keys']);
        self::assertGreaterThan(0, $record['request_shape']['synthetic_input_bytes']);
        self::assertSame(10, $record['input_tokens']);
        self::assertSame(2, $record['output_tokens']);
        self::assertSame('COMPLETED', DB::table('ai_usage_records')->where('tenant_id', $tenantId)->value('status'));
        self::assertSame($record['correlation_id'], DB::table('ai_usage_records')->where('tenant_id', $tenantId)->value('correlation_id'));
    }

    public function test_prompt_v3_is_created_as_an_inactive_draft_without_mutating_approved_v2(): void
    {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenantId, 'name' => 'Prompt fixture', 'slug' => 'prompt-fixture', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        $v2Id = (string) Str::uuid();
        DB::table('prompt_templates')->insert(['id' => $v2Id, 'tenant_id' => $tenantId, 'agent_key' => 'WebsiteIntelligenceAgent', 'version' => 2,
            'system_instruction' => 'Keep approved v2 intact.', 'template' => 'Approved v2 template.', 'schema_version' => 'website-intelligence-pilot-v2',
            'active' => true, 'status' => 'approved', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        self::assertSame(0, Artisan::call('pilot:prepare-website-intelligence-prompt', ['tenant' => $tenantId, '--prompt-version' => 3]));
        $v2 = DB::table('prompt_templates')->where('tenant_id', $tenantId)->where('id', $v2Id)->first();
        $v3 = DB::table('prompt_templates')->where('tenant_id', $tenantId)->where('version', 3)->first();

        self::assertSame('approved', $v2->status);
        self::assertTrue((bool) $v2->active);
        self::assertSame('Keep approved v2 intact.', $v2->system_instruction);
        self::assertSame('draft', $v3->status);
        self::assertFalse((bool) $v3->active);
        self::assertSame('website-intelligence-pilot-v3', $v3->schema_version);
        self::assertStringContainsString('If any link in evidence -> opportunity -> active tenant service -> next action is absent', $v3->template);
        self::assertDatabaseHas('audit_logs', ['tenant_id' => $tenantId, 'action' => 'website_intelligence_prompt.draft_prepared', 'subject_id' => $v3->id]);
    }
}
