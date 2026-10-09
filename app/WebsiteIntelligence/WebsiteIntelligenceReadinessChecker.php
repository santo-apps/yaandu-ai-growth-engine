<?php

namespace App\WebsiteIntelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

final class WebsiteIntelligenceReadinessChecker
{
    public function check(string $tenantId): array
    {
        $serviceCatalog = app(TenantServiceCatalog::class);
        $activeApprovedServices = $serviceCatalog->activeApprovedServices($tenantId);
        $recommendationServices = $serviceCatalog->recommendationServices($tenantId);
        $tenantServiceCount = DB::table('tenant_services')->where('tenant_id', $tenantId)->count();
        $serviceReady = $recommendationServices->isNotEmpty();
        $servicePromptCompatible = DB::table('prompt_templates')->where('tenant_id', $tenantId)->where('agent_key', 'WebsiteIntelligenceAgent')
            ->where('status', 'approved')->where('active', true)->where('version', '>=', 2)->exists();
        $route = DB::table('ai_model_configurations')->where('tenant_id', $tenantId)->where('task_key', 'website_reasoning')->first();
        $provider = $route->provider ?? null;
        $model = $route->model ?? null;
        $enabled = (bool) ($route->enabled ?? false);
        $registered = collect(app()->tagged('ai.providers'))->map(fn ($registeredProvider) => $registeredProvider->providerKey())->all();
        $credentialLoaded = in_array($provider, ['openai', 'anthropic', 'gemini'], true) && filled(config('ai.providers.'.$provider.'.key'));
        $routeReady = $route && $enabled && $provider && $model && in_array($provider, $registered, true) && $credentialLoaded;

        $prompt = DB::table('prompt_templates')->where('tenant_id', $tenantId)->where('agent_key', 'WebsiteIntelligenceAgent')
            ->where('status', 'approved')->where('active', true)->orderByDesc('version')->first();
        $promptReady = $prompt && filled($prompt->system_instruction) && filled($prompt->template);
        $servicePromptCompatible = $servicePromptCompatible && $serviceReady;
        $activeIcp = DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('status', 'active')->orderByDesc('version')->first();
        $icpReady = (bool) $activeIcp;
        $icpServiceKeys = $icpReady ? (json_decode($activeIcp->configuration, true)['service_fit']['service_keys'] ?? []) : [];
        $icpServiceCompatible = $icpReady && $icpServiceKeys !== [] && array_diff($icpServiceKeys, $recommendationServices->keys()->all()) === [];

        $smoke = Cache::get($this->smokeKey($tenantId));
        $smokeReady = false;
        if (is_array($smoke) && ($smoke['status'] ?? null) === 'passed' && ($smoke['provider'] ?? null) === $provider
            && ($smoke['model'] ?? null) === $model && isset($smoke['completed_at'])) {
            try { $smokeReady = \Illuminate\Support\Carbon::parse($smoke['completed_at'])->greaterThanOrEqualTo(now()->subHours(24)); }
            catch (\Throwable) {}
        }

        $horizon = 'unavailable';
        $redis = false;
        try {
            Queue::connection('redis')->size('default');
            $redis = true;
            $horizon = collect(app(MasterSupervisorRepository::class)->all())->contains(fn ($master) => $master->status === 'running') ? 'running' : 'inactive';
        } catch (\Throwable) {}

        $hasCrawlEvidence = DB::table('website_scans')->where('tenant_id', $tenantId)->where('status', 'completed')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('website_pages')->whereColumn('website_pages.website_scan_id', 'website_scans.id')->whereColumn('website_pages.tenant_id', 'website_scans.tenant_id'))->exists();
        $reasons = [];
        if (! $routeReady) $reasons[] = 'An enabled tenant website_reasoning route with a supported live provider and loaded credential is required.';
        if (! $promptReady) $reasons[] = 'An active approved versioned WebsiteIntelligenceAgent prompt with system instruction and template is required.';
        if (! $serviceReady) $reasons[] = 'At least one active, approved tenant service with a supported canonical service key is required for service recommendations.';
        if (! $servicePromptCompatible) $reasons[] = 'An active approved Website Intelligence v2 prompt compatible with the active tenant service catalog is required.';
        if (! $icpReady) $reasons[] = 'An active, versioned tenant ICP configuration is required.';
        elseif (! $icpServiceCompatible) $reasons[] = 'The active tenant ICP service-fit keys must match active approved tenant services.';
        if (! $smokeReady) $reasons[] = 'A successful synthetic provider smoke test for the current provider/model within 24 hours is required.';
        if (! $redis) $reasons[] = 'Redis queue connection is unavailable.';
        if ($horizon !== 'running') $reasons[] = 'A running Horizon worker is required.';
        if (! $hasCrawlEvidence) $reasons[] = 'Completed tenant-scoped crawl evidence is required.';

        return ['ready' => $reasons === [], 'provider' => $provider, 'model' => $model, 'configuration_source' => $route ? 'tenant_ai_model_configurations' : null, 'route_configured' => (bool) $route,
            'route_enabled' => $enabled, 'credential_loaded' => $credentialLoaded, 'prompt' => ['approved_active' => (bool) $promptReady, 'version' => $promptReady ? (int) $prompt->version : null],
            'service_catalog' => ['exists' => $tenantServiceCount > 0, 'active_approved_count' => $activeApprovedServices->count(),
                'recommendation_capable_count' => $recommendationServices->count(), 'prompt_service_key_compatible' => (bool) $servicePromptCompatible],
            'icp' => ['configured' => $icpReady, 'version' => $icpReady ? (int) $activeIcp->version : null,
                'active' => $icpReady, 'service_key_compatible' => (bool) $icpServiceCompatible],
            'smoke_test' => ['passed_recently' => $smokeReady, 'completed_at' => $smokeReady ? $smoke['completed_at'] : null],
            'queue' => ['redis' => $redis, 'horizon' => $horizon], 'crawl_evidence' => $hasCrawlEvidence, 'reasons' => $reasons,
            'secrets_exposed' => false];
    }

    public function smokeKey(string $tenantId): string
    {
        return 'website-intelligence-smoke:'.hash('sha256', $tenantId);
    }
}
