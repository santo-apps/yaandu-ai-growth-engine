<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AIConfigurationController extends Controller
{
    private const TASKS = ['website_visual_analysis', 'website_reasoning', 'lead_classification', 'sales_reasoning', 'content_generation', 'structured_extraction', 'proposal_generation'];
    private const PROVIDERS = ['openai', 'anthropic', 'gemini'];

    public function index()
    {
        $configured = DB::table('ai_model_configurations')->where('tenant_id', app('tenant.id'))
            ->get(['id', 'task_key', 'provider', 'model', 'enabled', 'parameters', 'version', 'updated_at'])->keyBy('task_key');

        $providers = $this->availableProviders();
        return collect(config('ai.tasks', []))->map(function (array $default, string $task) use ($configured, $providers): array {
            $tenantConfig = $configured->get($task);
            $parameters = $tenantConfig
                ? (is_array($tenantConfig->parameters) ? $tenantConfig->parameters : (json_decode($tenantConfig->parameters ?? '{}', true) ?: []))
                : [];
            return [
                'id' => $tenantConfig->id ?? null,
                'task_key' => $task,
                'provider' => $tenantConfig->provider ?? $default['provider'],
                'model' => $tenantConfig->model ?? $default['model'],
                'enabled' => $tenantConfig->enabled ?? true,
                'parameters' => (object) array_intersect_key($parameters, array_flip(['temperature', 'max_output_tokens'])),
                'version' => $tenantConfig->version ?? 1,
                'is_tenant_override' => (bool) $tenantConfig,
                'available_providers' => $providers,
                'updated_at' => $tenantConfig->updated_at ?? null,
            ];
        })->values();
    }

    public function update(Request $request, string $id)
    {
        abort_unless(in_array($request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role'), ['owner', 'admin'], true), 403);
        $config = DB::table('ai_model_configurations')->where('tenant_id', app('tenant.id'))->where('id', $id)->first();
        abort_unless($config, 404);
        $data = $request->validate(['provider' => ['required', 'string', 'in:'.implode(',', $this->availableProviders())], 'model' => ['required', 'string', 'max:120'], 'enabled' => ['required', 'boolean'], 'parameters' => ['nullable', 'array:temperature,max_output_tokens'], 'parameters.temperature' => ['sometimes', 'numeric', 'between:0,2'], 'parameters.max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:8192']]);
        DB::table('ai_model_configurations')->where('id', $id)->update([...$data, 'parameters' => json_encode($data['parameters'] ?? []), 'version' => $config->version + 1, 'updated_at' => now()]);
        return response()->json(['id' => $id, 'task_key' => $config->task_key, ...$data, 'version' => $config->version + 1]);
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role'), ['owner', 'admin'], true), 403);
        $data = $request->validate(['task_key' => ['required', 'string', 'in:'.implode(',', self::TASKS)], 'provider' => ['required', 'string', 'in:'.implode(',', $this->availableProviders())], 'model' => ['required', 'string', 'max:120'], 'enabled' => ['sometimes', 'boolean'], 'parameters' => ['nullable', 'array:temperature,max_output_tokens'], 'parameters.temperature' => ['sometimes', 'numeric', 'between:0,2'], 'parameters.max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:8192']]);
        $existing = DB::table('ai_model_configurations')->where('tenant_id', app('tenant.id'))->where('task_key', $data['task_key'])->first();
        $values = ['provider' => $data['provider'], 'model' => $data['model'], 'enabled' => $data['enabled'] ?? true,
            'parameters' => json_encode($data['parameters'] ?? []), 'version' => $existing ? $existing->version + 1 : 1, 'updated_at' => now()];
        if ($existing) DB::table('ai_model_configurations')->where('id', $existing->id)->update($values);
        else DB::table('ai_model_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => app('tenant.id'), 'task_key' => $data['task_key'], ...$values, 'created_at' => now()]);
        return response()->json(['task_key' => $data['task_key'], 'provider' => $data['provider'], 'model' => $data['model'], 'enabled' => $data['enabled'] ?? true], 201);
    }

    private function availableProviders(): array
    {
        if (app()->environment(['local', 'testing']) && config('ai.local_acceptance.enabled')) {
            return [...self::PROVIDERS, 'deterministic'];
        }

        return self::PROVIDERS;
    }
}
