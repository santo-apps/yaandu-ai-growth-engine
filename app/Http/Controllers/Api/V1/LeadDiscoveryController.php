<?php

namespace App\Http\Controllers\Api\V1;

use App\Discovery\DomainNormalizer;
use App\Http\Controllers\Controller;
use App\Jobs\ScanWebsiteJob;
use App\Jobs\StartLeadDiscoveryCampaignJob;
use App\Jobs\DiscoverLeadSourcePageJob;
use App\Jobs\VerifyLeadDiscoveryCandidateJob;
use App\LeadDiscovery\DiscoveryEligibilityService;
use App\LeadDiscovery\LeadSourceAdapterRegistry;
use App\LeadDiscovery\LeadSourceCapabilityCatalog;
use App\LeadDiscovery\LeadDiscoveryCountryCatalog;
use App\LeadDiscovery\LeadDiscoveryIndustryTaxonomy;
use App\LeadDiscovery\LeadDiscoveryPilotSummary;
use App\LeadDiscovery\GeographicRelevanceClassifier;
use App\LeadDiscovery\LiveCandidateStager;
use App\Models\Contact;
use App\Models\ContactMethod;
use App\Models\Company;
use App\Models\CompanyWebsite;
use App\Contacts\ContactMethodValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LeadDiscoveryController extends Controller
{
    public function __construct(private readonly DomainNormalizer $domains) {}

    public function sources(Request $request)
    {
        $tenant = app('tenant.id');
        return DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->orderBy('provider_name')->paginate(50)
            ->through(fn ($row) => $this->sourcePayload($row));
    }

    public function sourceCatalog(LeadSourceCapabilityCatalog $catalog)
    {
        $configured = DB::table('lead_discovery_sources')->where('tenant_id', app('tenant.id'))->get()->keyBy('source_key');
        $safeOsmPartitions = array_map(static fn (array $partition): array => array_intersect_key($partition, array_flip(['key', 'country_code', 'region', 'city'])), config('lead_discovery.osm_partitions', []));
        $names = ['osm_overpass' => 'OpenStreetMap / Overpass', 'wikidata' => 'Wikidata', 'india_ogd_mca' => 'India OGD / MCA',
            'gleif' => 'GLEIF', 'brave_search' => 'Brave Search', 'csv_upload' => 'Licensed CSV'];
        $entries = array_map(function ($capability) use ($configured, $names, $safeOsmPartitions): array {
            $source = $configured->get($capability->sourceKey);
            return [...$capability->toArray(), 'display_name' => $names[$capability->sourceKey] ?? $capability->sourceKey,
                'configured' => $source !== null, 'tenant_enabled' => (bool) ($source->enabled ?? false),
                'authorization_status' => $source->authorization_status ?? 'not_approved', 'storage_rights_status' => $source->storage_rights_status ?? 'not_confirmed',
                'credential_status' => $this->credentialStatus($capability->sourceKey), 'configuration_status' => $this->configurationStatus($capability->sourceKey),
                'health_status' => $source->health_state ?? 'not_configured',
                'safe_partitions' => $capability->sourceKey === 'osm_overpass' ? $safeOsmPartitions : []];
        }, $catalog->selectable());
        $entries[] = [...$catalog->get('google_maps')->toArray(), 'display_name' => 'Google Maps', 'configured' => false, 'tenant_enabled' => false,
            'authorization_status' => 'prohibited', 'storage_rights_status' => 'prohibited', 'credential_status' => 'not_applicable', 'health_status' => 'unsupported'];
        return response()->json(['data' => $entries]);
    }

    public function industries(LeadDiscoveryIndustryTaxonomy $taxonomy)
    {
        return response()->json(['data' => collect($taxonomy->all())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()]);
    }

    public function countries(LeadDiscoveryCountryCatalog $catalog)
    {
        return response()->json(['data' => collect($catalog->all())->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()]);
    }

    public function sourceHealth()
    {
        $rows = DB::table('lead_discovery_sources')->where('tenant_id', app('tenant.id'))->get();
        $catalog = app(LeadSourceCapabilityCatalog::class);
        $sourceStates = $rows->map(function ($source) use ($catalog): array {
            $capability = $catalog->get((string) $source->source_key);
            return ['source_key' => $source->source_key, 'credential_status' => $this->credentialStatus((string) $source->source_key),
                'authorization_status' => $source->authorization_status, 'storage_rights_status' => $source->storage_rights_status,
                'health_status' => $source->health_state, 'execution_ready' => $capability?->supportsLiveQuery && $this->sourceExecutionReady($source)];
        })->values();
        $credentialDetected = $sourceStates->contains(fn (array $state) => $state['credential_status'] === 'available') || filled(config('services.brave_search.key'));
        $fullyApproved = $sourceStates->contains(fn (array $state) => $state['execution_ready']);
        $storageConfirmed = $sourceStates->contains(fn (array $state) => $state['storage_rights_status'] === 'confirmed');
        $authorizationApproved = $sourceStates->contains(fn (array $state) => $state['authorization_status'] === 'authorized');
        $status = $fullyApproved ? 'ready' : ($rows->isEmpty() || ! $storageConfirmed ? 'STORAGE_RIGHTS_NOT_CONFIRMED'
            : (! $authorizationApproved ? 'SOURCE_NOT_AUTHORIZED' : 'SOURCE_DISABLED'));

        return response()->json([
            'live_enabled' => (bool) config('lead_discovery.live_enabled'),
            'credential_status' => $credentialDetected ? 'available' : 'missing',
            'credential_detected' => $credentialDetected,
            'authorization_status' => $authorizationApproved ? 'approved' : 'not_approved',
            'storage_rights_status' => $storageConfirmed ? 'confirmed' : 'not_confirmed',
            'health_status' => $fullyApproved && config('lead_discovery.live_enabled') ? 'ready' : 'blocked',
            'storage_rights_required' => true,
            'status' => $status,
            'sources' => $rows->map(fn ($row) => $this->sourcePayload($row))->values(),
            'source_states' => $sourceStates,
        ]);
    }

    public function createSource(Request $request, LeadSourceAdapterRegistry $registry)
    {
        $this->requireManager($request, ['owner', 'admin']);
        $data = $request->validate([
            'source_key' => ['required', 'in:csv_upload,brave_search,osm_overpass,wikidata,india_ogd_mca,gleif'], 'provider_name' => ['nullable', 'string', 'max:160'],
            'license_or_authorization' => ['nullable', 'string', 'max:500'], 'allowed_collection_method' => ['nullable', 'string', 'max:80'],
            'retention_policy' => ['nullable', 'string', 'max:120'], 'attribution_requirement' => ['nullable', 'string', 'max:255'],
            'storage_rights_confirmed' => ['sometimes', 'boolean'],
            'storage_rights_reference' => ['nullable', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
        ]);
        $adapter = $registry->get($data['source_key']); $capability = $adapter->capabilities();
        $reusableOpenSource = in_array($data['source_key'], ['osm_overpass', 'wikidata', 'gleif'], true);
        $storageRightsConfirmed = $reusableOpenSource || (($data['storage_rights_confirmed'] ?? false) === true
            && trim((string) ($data['storage_rights_reference'] ?? '')) !== '');
        $storageRightsStatus = $data['source_key'] === 'csv_upload' ? 'not_required' : ($storageRightsConfirmed ? 'confirmed' : 'not_confirmed');
        if ($capability->persistentStorageAllowed && $data['source_key'] !== 'csv_upload' && $data['enabled'] && ! $storageRightsConfirmed) {
            throw ValidationException::withMessages(['storage_rights_confirmed' => ['Confirm persistent storage rights and record the governing license or agreement reference before enabling this source.']]);
        }
        $licenseReference = trim((string) ($data['license_or_authorization'] ?? '')) ?: ($reusableOpenSource ? $capability->licenseName : '');
        if ($licenseReference === '') throw ValidationException::withMessages(['license_or_authorization' => ['Record the applicable license or resource authorization terms.']]);
        $storageReference = $reusableOpenSource ? $capability->licenseName : trim((string) ($data['storage_rights_reference'] ?? ''));
        $configuration = ['license_or_authorization' => $licenseReference,
            'storage_rights_confirmed' => $storageRightsConfirmed,
            'storage_rights_reference' => mb_substr($storageReference, 0, 255)];
        if ($data['source_key'] !== 'csv_upload' && $data['enabled']) {
            try {
                $adapter->validateConfiguration([...$configuration, 'allowed_collection_method' => $data['allowed_collection_method'],
                    'source_enabled' => true, 'authorization_status' => 'authorized', 'storage_rights_status' => $storageRightsStatus]);
            } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
                return response()->json(['message' => $error->getMessage(), 'reason_code' => $error->errorCode], 422);
            }
        }
        $tenant = app('tenant.id'); $id = (string) Str::uuid();
        $providerName = trim((string) ($data['provider_name'] ?? '')) ?: match ($data['source_key']) {
            'osm_overpass' => 'OpenStreetMap / Overpass', 'wikidata' => 'Wikidata Query Service', 'india_ogd_mca' => 'India OGD / MCA',
            'gleif' => 'GLEIF', 'brave_search' => 'Brave Search API', default => 'Licensed CSV dataset',
        };
        DB::transaction(function () use ($id, $tenant, $data, $configuration, $storageRightsStatus, $capability, $providerName): void {
            DB::table('lead_discovery_sources')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'source_key' => $data['source_key'], 'provider_name' => $providerName,
                'source_type' => $data['source_key'] === 'csv_upload' ? 'LICENSED_PERSISTABLE' : $capability->governanceClass, 'authorization_status' => 'authorized', 'allowed_collection_method' => $data['allowed_collection_method'] ?? $capability->recommendedSyncMode,
                'storage_rights_status' => $storageRightsStatus,
                'retention_policy' => $data['retention_policy'] ?? null, 'attribution_requirement' => $data['attribution_requirement'] ?? null,
                'configuration' => json_encode($configuration), 'rate_limit_config' => json_encode(['requests_per_minute' => $capability->defaultRateLimit, 'max_rows_per_import' => config('lead_discovery.max_rows_per_import')]),
                'enabled' => (bool) $data['enabled'], 'health_state' => (bool) $data['enabled'] ? 'ready' : ($storageRightsStatus === 'not_confirmed' ? 'not_configured' : 'disabled'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('lead_discovery_source_configured', $id, ['source_key' => $data['source_key'], 'enabled' => (bool) $data['enabled']]);
        });
        return response()->json($this->sourcePayload(DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $id)->first()), 201);
    }

    public function updateSource(Request $request, string $source)
    {
        $this->requireManager($request, ['owner', 'admin']);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $tenant = app('tenant.id');
        $row = DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $source)->first();
        abort_unless($row, 404);
        $configuration = json_decode((string) $row->configuration, true) ?: [];
        $capability = app(LeadSourceAdapterRegistry::class)->get((string) $row->source_key)->capabilities();
        $reusableOpenSource = in_array($row->source_key, ['osm_overpass', 'wikidata', 'gleif'], true);
        if ($reusableOpenSource) {
            $configuration['license_or_authorization'] = trim((string) ($configuration['license_or_authorization'] ?? '')) ?: $capability->licenseName;
            $configuration['storage_rights_confirmed'] = true;
            $configuration['storage_rights_reference'] = $capability->licenseName;
        }
        if ($row->source_key !== 'csv_upload' && $data['enabled']) {
            try {
                app(LeadSourceAdapterRegistry::class)->get((string) $row->source_key)->validateConfiguration([...$configuration,
                    'source_enabled' => true, 'authorization_status' => $row->authorization_status,
                    'storage_rights_status' => $reusableOpenSource ? 'confirmed' : $row->storage_rights_status]);
            } catch (\App\LeadDiscovery\LiveLeadSourceException $error) {
                return response()->json(['message' => $error->getMessage(), 'reason_code' => $error->errorCode], 422);
            }
        }
        DB::transaction(function () use ($tenant, $source, $data, $configuration, $reusableOpenSource): void {
            DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $source)->update([
                'enabled' => (bool) $data['enabled'], 'health_state' => $data['enabled'] ? 'ready' : 'disabled',
                ...($reusableOpenSource ? ['storage_rights_status' => 'confirmed', 'configuration' => json_encode($configuration)] : []),
                'updated_at' => now(),
            ]);
            $this->audit('lead_discovery_source_updated', $source, ['enabled' => (bool) $data['enabled']]);
        });
        return response()->json($this->sourcePayload(DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $source)->first()));
    }

    public function campaigns()
    {
        return DB::table('lead_discovery_campaigns')->where('tenant_id', app('tenant.id'))->orderByDesc('created_at')->paginate(25)
            ->through(fn ($campaign) => $this->withPilotFields($campaign));
    }

    public function createCampaign(Request $request)
    {
        abort_unless(config('lead_discovery.enabled'), 503, 'Lead Discovery is disabled by configuration.');
        $this->requireManager($request, ['owner', 'admin', 'manager']);
        if ($request->filled('country_code')) $request->merge(['country_code' => strtoupper(trim((string) $request->input('country_code')))]);
        $countryCodes = implode(',', array_keys(app(LeadDiscoveryCountryCatalog::class)->all()));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'industry' => ['nullable', 'string', 'max:150'],
            'country_code' => ['nullable', 'string', 'size:2', 'in:'.$countryCodes], 'region' => ['nullable', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:120'],
            // Preserve the Phase 2A campaign-target contract. Actual provider
            // work remains bounded by the stricter per-run candidate/request
            // limits below; the default target remains intentionally small.
            'keywords' => ['nullable', 'string', 'max:150'], 'target_leads' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'pilot_mode' => ['sometimes', 'boolean'],
            'pilot_target' => ['exclude_unless:pilot_mode,true', 'required', 'integer', 'min:1', 'max:25'],
            'source_mode' => ['sometimes', 'in:all_eligible,selected'], 'source_ids' => ['sometimes', 'array', 'max:6'], 'source_ids.*' => ['required', 'uuid'], 'limits' => ['sometimes', 'array'],
            'limits.source_requests' => ['sometimes', 'integer', 'min:1', 'max:500'], 'limits.candidates' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'limits.processing' => ['sometimes', 'integer', 'min:1', 'max:500'], 'limits.enrichment' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'limits.runtime_seconds' => ['sometimes', 'integer', 'min:30', 'max:3600'], 'limits.http_requests' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'limits.ai_calls' => ['sometimes', 'integer', 'in:0'], 'limits.provider_spend_minor' => ['sometimes', 'integer', 'in:0'],
        ]);
        $id = (string) Str::uuid(); $tenant = app('tenant.id');
        $selectedSources = array_values(array_unique($data['source_ids'] ?? []));
        if ($selectedSources) {
            $selectedRows = DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->whereIn('id', $selectedSources)->get();
            abort_unless($selectedRows->count() === count($selectedSources), 422, 'Choose only sources configured for this tenant.');
            foreach ($selectedRows as $source) {
                $adapter = app(LeadSourceAdapterRegistry::class)->get((string) $source->source_key);
                abort_unless($adapter instanceof \App\LeadDiscovery\SearchLeadSourceAdapterInterface && $adapter->capabilities()->supportsLiveQuery,
                    422, 'Campaign sources must support live discovery queries.');
                abort_unless($adapter->capabilities()->governanceClass !== 'PROHIBITED_FOR_BULK_STORAGE', 422, 'This provider is not permitted for persistent bulk discovery.');
            }
        }
        $limits = array_replace([
            'source_requests' => config('lead_discovery.max_source_requests_per_run'), 'candidates' => config('lead_discovery.max_candidates_per_run'),
            'processing' => config('lead_discovery.max_candidate_processing'), 'enrichment' => config('lead_discovery.max_enrichment_per_run'),
            'runtime_seconds' => config('lead_discovery.max_runtime_seconds'), 'http_requests' => config('lead_discovery.max_http_requests_per_candidate'),
            'ai_calls' => 0, 'provider_spend_minor' => 0,
        ], $data['limits'] ?? []);
        $limits['partitions'] = min((int) config('lead_discovery.live_max_partitions', 20), (int) ($data['limits']['partitions'] ?? config('lead_discovery.live_max_partitions', 20)));
        $limits['provider_requests'] = min((int) config('lead_discovery.live_max_provider_requests', 50), (int) ($data['limits']['provider_requests'] ?? config('lead_discovery.live_max_provider_requests', 50)));
        $target = $data['target_leads'] ?? config('lead_discovery.live_default_target', 25);
        $industryInput = (string) ($data['industry'] ?? '');
        $industry = trim($industryInput) === '' ? null : $industryInput;
        $normalizedIndustryKey = app(LeadDiscoveryIndustryTaxonomy::class)->keyFor($industry);
        $pilotMode = $request->boolean('pilot_mode');
        $pilotTarget = $pilotMode ? (int) $data['pilot_target'] : null;
        DB::transaction(function () use ($id, $tenant, $data, $limits, $request, $selectedSources, $target, $industry, $normalizedIndustryKey, $pilotMode, $pilotTarget): void {
            DB::table('lead_discovery_campaigns')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'name' => $data['name'], 'industry' => $industry, 'normalized_industry_key' => $normalizedIndustryKey,
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null, 'region' => $data['region'] ?? null, 'city' => $data['city'] ?? null,
                'configuration' => json_encode(['limits' => $limits, 'pilot_mode' => $pilotMode, 'pilot_target' => $pilotTarget,
                    'keywords' => trim((string) ($data['keywords'] ?? '')), 'source_mode' => $data['source_mode'] ?? ($selectedSources ? 'selected' : 'all_eligible'),
                    'website_verification' => true, 'contact_enrichment' => true, 'deduplication' => true, 'discovery_source_ids' => $selectedSources]),
                'target_leads' => $target, 'auto_promotion_enabled' => false, 'created_by' => $request->user()->id,
                'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('lead_discovery_campaign_created', $id, ['auto_promotion' => false, 'target_leads' => $target, 'pilot_mode' => $pilotMode]);
        });
        $campaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $id)->first();
        $campaign->auto_promotion_enabled = (bool) $campaign->auto_promotion_enabled;
        return response()->json($this->withPilotFields($campaign), 201);
    }

    public function startDiscovery(Request $request, string $campaign)
    {
        abort_unless(config('lead_discovery.enabled'), 503, 'Lead Discovery is disabled by configuration.');
        $this->requireManager($request, ['owner', 'admin', 'manager']);
        $request->validate(['source_ids' => ['sometimes', 'array', 'max:6'], 'source_ids.*' => ['required', 'uuid']]);
        $tenant = app('tenant.id');
        $row = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->first();
        abort_unless($row, 404);
        abort_unless($row->status === 'draft', 409, 'Only a draft campaign can be started.');
        $config = json_decode((string) $row->configuration, true) ?: [];
        $sourceIds = array_values(array_unique($request->input('source_ids', data_get($config, 'discovery_source_ids', []))));
        $requestedSourceIds = $sourceIds;
        $skippedSources = [];
        if ($sourceIds) {
            $selected = DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->whereIn('id', $sourceIds)->get();
            abort_unless($selected->count() === count($sourceIds), 422, 'One or more selected sources are not configured for this tenant.');
            $readyIds = [];
            foreach ($selected as $source) {
                $adapter = app(LeadSourceAdapterRegistry::class)->get((string) $source->source_key);
                abort_unless($adapter instanceof \App\LeadDiscovery\SearchLeadSourceAdapterInterface
                    && $adapter->capabilities()->governanceClass !== 'PROHIBITED_FOR_BULK_STORAGE', 422, 'This provider is not approved for persistent discovery.');
                $reason = $this->sourceBlockReason($source);
                if ($reason === null) $reason = app(\App\LeadDiscovery\LeadDiscoverySourceHealthPolicy::class)->blockReason($source);
                if ($reason !== null) {
                    $skippedSources[] = ['source_id' => $source->id, 'reason_code' => $reason];
                    Log::notice('Lead discovery source skipped before campaign execution.', ['operation' => 'lead_discovery_source_skipped', 'tenant_id' => $tenant,
                        'campaign_id' => $campaign, 'source_id' => $source->id, 'reason_code' => $reason, 'health_status' => (string) $source->health_state]);
                    continue;
                }
                $readyIds[] = (string) $source->id;
            }
            if ($readyIds === []) {
                $reason = (string) ($skippedSources[0]['reason_code'] ?? 'SOURCE_DISABLED');
                return response()->json(['message' => $this->sourceBlockMessage($reason), 'reason_code' => $reason, 'skipped_sources' => $skippedSources], 422);
            }
            $sourceIds = $readyIds;
        } else {
            $eligibleOpenSources = DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->whereIn('source_key', ['osm_overpass', 'wikidata', 'india_ogd_mca', 'gleif'])
                ->where('enabled', true)->where('authorization_status', 'authorized')->where('storage_rights_status', 'confirmed')->exists();
            abort_unless($eligibleOpenSources, 422, 'No enabled, authorized and storage-approved open source is configured for this tenant.');
        }
        $config['discovery_source_ids'] = $requestedSourceIds;
        $config['pilot_requested_sources'] = $requestedSourceIds;
        $config['pilot_skipped_sources'] = $skippedSources;
        DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->update([
            'configuration' => json_encode($config), 'status' => 'queued', 'updated_at' => now(),
        ]);
        $this->audit('lead_discovery_campaign_started', $campaign, ['source_ids' => $sourceIds, 'skipped_sources' => $skippedSources, 'target_leads' => (int) $row->target_leads, 'automatic_promotion' => false]);
        $this->audit('lead_discovery.campaign_started', $campaign, ['source_ids' => $sourceIds, 'skipped_sources' => $skippedSources, 'target_leads' => (int) $row->target_leads, 'automatic_promotion' => false]);
        StartLeadDiscoveryCampaignJob::dispatch($tenant, $campaign, $sourceIds)->afterCommit();
        return response()->json(['campaign_id' => $campaign, 'status' => 'queued', 'auto_promotion' => false, 'skipped_sources' => $skippedSources], 202);
    }

    public function campaign(string $campaign, LeadDiscoveryPilotSummary $pilotSummary)
    {
        $tenant = app('tenant.id');
        $row = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->first();
        abort_unless($row, 404);
        $runs = DB::table('lead_discovery_runs as r')->join('lead_discovery_sources as s', function ($join) use ($tenant): void {
            $join->on('s.id', '=', 'r.source_id')->where('s.tenant_id', '=', $tenant);
        })->where('r.tenant_id', $tenant)->where('r.campaign_id', $campaign)->select('r.*', 's.source_key', 's.provider_name')->orderByDesc('r.created_at')->limit(20)->get();
        $providers = $runs->groupBy('source_id')->map(function ($providerRuns) use ($tenant, $campaign): array {
            $source = $providerRuns->first();
            $runIds = $providerRuns->pluck('id')->all();
            $checkpoints = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->whereIn('run_id', $runIds);
            return ['source_id' => $source->source_id, 'source_key' => $source->source_key, 'source' => $source->provider_name,
                'status' => $providerRuns->contains(fn ($run) => $run->run_status === 'running') ? 'running' : $source->run_status,
                'partitions_total' => (clone $checkpoints)->count(), 'partitions_completed' => (clone $checkpoints)->where('status', 'completed')->count(),
                'requests' => (int) $providerRuns->sum('source_requests'), 'raw_records' => (int) $providerRuns->sum('raw_candidates'),
                'unique_candidates' => (int) $providerRuns->sum('candidates_found'), 'duplicates' => (int) $providerRuns->sum('duplicates_found'),
                'eligible' => (int) $providerRuns->sum('eligible_candidates'), 'errors' => (int) $providerRuns->sum('source_errors')];
        })->values();
        $campaignConfiguration = is_array($row->configuration) ? $row->configuration : (json_decode((string) $row->configuration, true) ?: []);
        $campaignPayload = $this->withPilotFields($row);
        return response()->json(['campaign' => $campaignPayload, 'pilot_mode' => $campaignPayload->pilot_mode, 'pilot_target' => $campaignPayload->pilot_target, 'providers' => $providers,
            ...((bool) data_get($campaignConfiguration, 'pilot_mode') ? ['pilot_summary' => $pilotSummary->build($tenant, $campaign)] : []),
            'counts' => DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('campaign_id', $campaign)
                ->selectRaw("count(*) as discovered, sum(case when official_domain_status = 'verified' then 1 else 0 end) as verified, sum(case when eligibility_status = 'eligible' then 1 else 0 end) as eligible, sum(case when eligibility_status = 'needs_review' then 1 else 0 end) as needs_review, sum(case when eligibility_status = 'rejected' then 1 else 0 end) as rejected, sum(case when processing_status = 'duplicate' then 1 else 0 end) as duplicates, sum(case when processing_status = 'invalid' then 1 else 0 end) as invalid, sum(case when promotion_status = 'promoted' then 1 else 0 end) as promoted, sum(case when processing_status = 'failed' then 1 else 0 end) as processing_errors")
                ->first(),
            'contacts_enriched' => DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant)->where('campaign_id', $campaign)
                ->whereIn('field_name', ['business_email', 'business_phone'])->distinct()->count('candidate_id'),
            'source_errors' => (int) DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->sum('source_errors'),
            'runs' => $runs,
            'checkpoints' => DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->orderBy('query_key')->get()]);
    }

    public function import(Request $request, string $campaign, LeadSourceAdapterRegistry $registry, LiveCandidateStager $stager,
        GeographicRelevanceClassifier $geography, LeadDiscoveryPilotSummary $pilotSummary)
    {
        abort_unless(config('lead_discovery.enabled'), 503, 'Lead Discovery is disabled by configuration.');
        $this->requireManager($request, ['owner', 'admin', 'manager']);
        $request->validate(['source_id' => ['required', 'uuid'], 'csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'idempotency_key' => ['nullable', 'string', 'max:128']]);
        $tenant = app('tenant.id');
        $campaignRow = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->first();
        abort_unless($campaignRow, 404);
        abort_unless(in_array($campaignRow->status, ['draft', 'queued', 'running'], true), 409, 'This campaign is not accepting imports.');
        $source = DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $request->input('source_id'))
            ->where('enabled', true)->where('authorization_status', 'authorized')->first();
        abort_unless($source, 422, 'An enabled, authorized source is required.');
        abort_unless($source->source_key === 'csv_upload', 422, 'The selected source cannot process CSV uploads.');
        $file = $request->file('csv');
        if ($file->getSize() > (int) config('lead_discovery.max_csv_bytes')) throw ValidationException::withMessages(['csv' => ['The uploaded CSV exceeds the configured byte limit.']]);
        $key = $request->input('idempotency_key') ?: $request->header('Idempotency-Key');
        if ($key) {
            $existing = DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->where('idempotency_key', $key)->first();
            if ($existing) return response()->json(['run_id' => $existing->id, 'status' => $existing->run_status,
                'candidate_count' => DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('run_id', $existing->id)->count(), 'replayed' => true]);
        }
        $campaignConfig = is_array($campaignRow->configuration) ? $campaignRow->configuration : (json_decode((string) $campaignRow->configuration, true) ?: []);
        $pilotMode = (bool) data_get($campaignConfig, 'pilot_mode', false);
        $pilotTarget = $pilotMode ? LeadDiscoveryPilotSummary::configuredTarget($campaignConfig) : PHP_INT_MAX;
        $pilotRemaining = $pilotMode ? max(0, $pilotTarget - $pilotSummary->targetCount($tenant, $campaign)) : PHP_INT_MAX;
        $campaignLimit = (int) data_get($campaignConfig, 'limits.candidates', config('lead_discovery.max_candidates_per_run'));
        $processingLimit = (int) data_get($campaignConfig, 'limits.processing', config('lead_discovery.max_candidate_processing'));
        $stagedCandidates = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->count();
        // Every staged, non-invalid CSV record consumes bounded candidate-processing capacity,
        // including queued work and completed verification, so repeated imports cannot bypass it.
        $processingConsumed = (int) DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('campaign_id', $campaign)
            ->where(function ($query): void {
                $query->whereNotIn('processing_status', ['invalid', 'duplicate'])->orWhereNotNull('matched_company_id');
            })->count();
        $rowLimit = min((int) config('lead_discovery.max_rows_per_import'), max(0, $campaignLimit - $stagedCandidates),
            max(0, $processingLimit - $processingConsumed), $pilotRemaining);
        abort_unless($rowLimit > 0, 429, 'The campaign candidate or processing budget has been reached.');
        $maximumSourceRequests = (int) data_get($campaignConfig, 'limits.source_requests', config('lead_discovery.max_source_requests_per_run'));
        $existingSourceRequests = DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->count();
        abort_unless($existingSourceRequests < $maximumSourceRequests, 429, 'The campaign source-request budget has been reached.');
        abort_unless((int) DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->where('eligibility_status', 'eligible')->count() < (int) $campaignRow->target_leads,
            409, 'The verified eligible-lead target has been reached.');
        $rows = $registry->get($source->source_key)->readCsv((string) $file->getRealPath(), $rowLimit);
        $runId = (string) Str::uuid(); $now = now(); $jobs = []; $csvHash = hash_file('sha256', (string) $file->getRealPath());
        DB::transaction(function () use ($tenant, $campaignRow, $source, $rows, $runId, $key, $now, $csvHash, &$jobs, $stager, $geography, $pilotMode, $pilotTarget, $pilotSummary): void {
        $lockedCampaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaignRow->id)->lockForUpdate()->first();
        abort_unless($lockedCampaign, 404);
        if ($pilotMode && $pilotSummary->targetCount($tenant, (string) $campaignRow->id) >= $pilotTarget) {
            throw ValidationException::withMessages(['campaign' => ['The controlled pilot has reached its unique-candidate limit.']]);
        }
        DB::table('lead_discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenant, 'campaign_id' => $campaignRow->id, 'source_id' => $source->id,
                'run_status' => 'running', 'started_at' => $now, 'candidates_found' => count($rows), 'idempotency_key' => $key,
                'cost_metrics' => json_encode(['csv_sha256' => $csvHash, 'rows' => count($rows), 'ai_calls' => 0, 'provider_spend_minor' => 0]),
                'created_at' => $now, 'updated_at' => $now]);
            foreach ($rows as $index => $row) {
                if ($pilotMode && $pilotSummary->targetCount($tenant, (string) $campaignRow->id) >= $pilotTarget) break;
                $name = trim((string) ($row['company_name'] ?? ''));
                if ($name === '') continue;
                $normalized = null; $website = null; $domain = null; $invalidWebsite = false;
                try {
                    $normalized = $this->domains->normalize((string) ($row['website'] ?? ''));
                    $domain = $normalized['normalized_domain'];
                    $website = strtolower((string) parse_url($normalized['normalized_url'], PHP_URL_SCHEME)).'://'.$domain.'/';
                } catch (Throwable) { $invalidWebsite = true; }
                $existingCompany = $domain ? DB::table('companies')->where('tenant_id', $tenant)->where('normalized_domain', $domain)->first(['id']) : null;
                $existingCandidate = $domain ? DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('normalized_domain', $domain)->first(['id']) : null;
                $id = (string) Str::uuid();
                $rawCountry = trim((string) ($row['country_code'] ?? ''));
                $country = $geography->normalizeCountry($rawCountry);
                $location = trim(implode(', ', array_filter([(string) ($row['city'] ?? ''), (string) ($row['region'] ?? ''), $country ?? ''])));
                $dup = (bool) ($existingCompany || $existingCandidate);
                DB::table('lead_discovery_candidates')->insert([
                    'id' => $id, 'tenant_id' => $tenant, 'campaign_id' => $campaignRow->id, 'run_id' => $runId, 'source_id' => $source->id,
                    'company_name' => mb_substr($name, 0, 255), 'normalized_name' => mb_substr($this->normalizeName($name), 0, 255),
                    'claimed_website' => $website, 'normalized_domain' => $domain, 'official_domain_status' => 'unverified',
                    'industry' => mb_substr(trim((string) ($row['industry'] ?? '')), 0, 150) ?: null, 'country_code' => $country ?: null,
                    'region' => mb_substr(trim((string) ($row['region'] ?? '')), 0, 120) ?: null, 'city' => mb_substr(trim((string) ($row['city'] ?? '')), 0, 120) ?: null,
                    'location' => $location ?: null, 'raw_metadata' => json_encode(['row_reference' => mb_substr((string) ($row['source_reference'] ?? 'csv-row-'.($index + 2)), 0, 200)]),
                    'processing_status' => $invalidWebsite ? 'invalid' : ($dup ? 'duplicate' : 'discovered'),
                    'validation_status' => $invalidWebsite ? 'failed' : 'pending', 'promotion_status' => 'not_promoted',
                    'validation_reason_code' => $invalidWebsite ? 'INVALID_WEBSITE' : ($dup ? 'POSSIBLE_DUPLICATE' : null),
                    'validation_reason' => $invalidWebsite ? 'Website URL could not be safely normalized.' : ($dup ? 'An existing tenant company or candidate has this domain; review the match before promotion.' : null),
                    'matched_company_id' => $existingCompany?->id, 'eligibility_status' => $invalidWebsite ? 'rejected' : 'needs_review',
                    'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $attribution = ['source_key' => $source->source_key, 'source_id' => $source->id, 'run_id' => $runId,
                    'source_reference' => mb_substr((string) ($row['source_reference'] ?? 'csv-row-'.($index + 2)), 0, 200), 'authorization_status' => $source->authorization_status];
                $sourceUrl = $this->safeSourceUrl((string) ($row['source_url'] ?? ''));
                foreach ([
                    'company_name' => [$name, $name, 0.8], 'industry' => [trim((string) ($row['industry'] ?? '')), trim((string) ($row['industry'] ?? '')), 0.7],
                    'country_code' => [$rawCountry, $country, 0.8], 'region' => [trim((string) ($row['region'] ?? '')), trim((string) ($row['region'] ?? '')), 0.7],
                    'city' => [trim((string) ($row['city'] ?? '')), trim((string) ($row['city'] ?? '')), 0.7],
                ] as $field => [$raw, $norm, $confidence]) {
                    if (is_string($raw) && $raw !== '') {
                        $fieldAttribution = $attribution;
                        if (in_array($field, ['country_code', 'region', 'city'], true)) {
                            $fieldAttribution['geographic_evidence_field'] = match ($field) {
                                'country_code' => 'country', 'region' => 'region', 'city' => 'city',
                            };
                        }
                        $this->insertEvidence($tenant, $id, $campaignRow->id, $source->id, $field, 'licensed_source_row', $sourceUrl, $raw, $norm, $confidence, $fieldAttribution, $now);
                    }
                }
                if ($domain) $this->insertEvidence($tenant, $id, $campaignRow->id, $source->id, 'claimed_website', 'licensed_source_row', $sourceUrl, $website, $domain, 0.75, $attribution, $now);
                if ($sourceUrl) foreach ($this->safeContactValues($row, (string) $domain) as [$field, $value]) {
                    $this->insertEvidence($tenant, $id, $campaignRow->id, $source->id, $field, 'licensed_public_business_contact', $sourceUrl, $value, $value, 0.7, $attribution, $now);
                }
                $stager->classifyAndPersistGeography($tenant, $id);
                if (! $invalidWebsite && (! $dup || $existingCompany)) $jobs[] = [$tenant, $id];
            }
            DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaignRow->id)->update(['status' => 'running', 'started_at' => $campaignRow->started_at ?? $now, 'updated_at' => $now]);
            DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $source->id)->update(['last_success_at' => $now, 'last_error_code' => null, 'health_state' => 'ready', 'updated_at' => $now]);
            if ($jobs === []) {
                $errors = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('run_id', $runId)->where('processing_status', 'invalid')->count();
                DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('id', $runId)->update([
                    'run_status' => $errors > 0 ? 'completed_with_errors' : 'completed', 'processing_errors' => $errors, 'finished_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->audit('lead_discovery_csv_imported', $runId, ['campaign_id' => $campaignRow->id, 'source_id' => $source->id, 'row_count' => count($rows)]);
        });
        foreach ($jobs as [$jobTenant, $candidateId]) VerifyLeadDiscoveryCandidateJob::dispatch($jobTenant, $candidateId)->afterCommit();
        return response()->json(['run_id' => $runId, 'status' => 'running', 'candidate_count' => count($rows), 'verification_queued' => count($jobs), 'auto_promotion' => false], 202);
    }

    public function controlCampaign(Request $request, string $campaign, string $operation)
    {
        abort_unless(config('lead_discovery.enabled'), 503, 'Lead Discovery is disabled by configuration.');
        $this->requireManager($request, ['owner', 'admin', 'manager']);
        $tenant = app('tenant.id');
        DB::transaction(function () use ($tenant, $campaign, $operation): void {
            $row = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($operation === 'pause') {
                abort_unless($row->status === 'running', 409, 'Only running campaigns can be paused.');
                DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->update(['status' => 'paused', 'updated_at' => now()]);
                DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->where('run_status', 'running')->update(['run_status' => 'paused', 'updated_at' => now()]);
            } elseif ($operation === 'resume') {
                abort_unless($row->status === 'paused', 409, 'Only paused campaigns can be resumed.');
                DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->update(['status' => 'running', 'updated_at' => now()]);
                DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->where('run_status', 'paused')->update(['run_status' => 'running', 'updated_at' => now()]);
                DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->where('processing_status', 'validating')->update(['processing_status' => 'discovered', 'updated_at' => now()]);
            } else {
                abort_unless(in_array($row->status, ['running', 'paused', 'queued', 'draft'], true), 409, 'This campaign cannot be cancelled in its current state.');
                DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
                DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->whereNotIn('run_status', ['completed', 'completed_with_errors'])->update(['run_status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);
                DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->whereNotIn('promotion_status', ['promoted', 'rejected'])->update(['processing_status' => 'cancelled', 'updated_at' => now()]);
                DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->whereIn('status', ['queued', 'running'])->update(['status' => 'stopped', 'error_code' => 'CAMPAIGN_CANCELLED', 'completed_at' => now(), 'updated_at' => now()]);
            }
            $action = match ($operation) { 'pause' => 'lead_discovery_campaign_paused', 'resume' => 'lead_discovery_campaign_resumed', default => 'lead_discovery_campaign_cancelled' };
            $this->audit($action, $campaign, []);
            $this->audit(match ($operation) { 'pause' => 'lead_discovery.campaign_paused', 'resume' => 'lead_discovery.campaign_resumed', default => 'lead_discovery.campaign_cancelled' }, $campaign, []);
        });
        if ($operation === 'resume') {
            DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->where('processing_status', 'discovered')
                ->whereNotNull('claimed_website')->where('promotion_status', 'not_promoted')->pluck('id')
                ->each(fn ($id) => VerifyLeadDiscoveryCandidateJob::dispatch($tenant, (string) $id));
            DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->whereIn('status', ['queued', 'running'])
                ->pluck('id')->each(fn ($id) => DiscoverLeadSourcePageJob::dispatch($tenant, (string) $id));
        }
        return response()->json(['campaign_id' => $campaign, 'status' => DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->value('status')]);
    }

    public function retryFailed(Request $request, string $campaign)
    {
        $this->requireManager($request, ['owner', 'admin', 'manager']);
        $tenant = app('tenant.id');
        $row = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->first();
        abort_unless($row, 404);
        abort_unless(in_array($row->status, ['completed_with_errors', 'failed'], true), 409, 'Only a completed campaign with errors can be retried.');
        $ids = DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->where('status', 'failed')->where('attempts', '<', 3)->pluck('id');
        abort_unless($ids->isNotEmpty(), 409, 'No failed source pages remain within the retry limit.');
        DB::table('lead_discovery_checkpoints')->where('tenant_id', $tenant)->whereIn('id', $ids)->update(['status' => 'queued', 'error_code' => null, 'completed_at' => null, 'updated_at' => now()]);
        DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $campaign)->update(['status' => 'running', 'completed_at' => null, 'last_error_code' => null, 'updated_at' => now()]);
        DB::table('lead_discovery_runs')->where('tenant_id', $tenant)->where('campaign_id', $campaign)->whereIn('run_status', ['failed', 'completed_with_errors'])->update(['run_status' => 'running', 'finished_at' => null, 'updated_at' => now()]);
        $ids->each(fn ($id) => DiscoverLeadSourcePageJob::dispatch($tenant, (string) $id));
        $this->audit('lead_discovery_source_retry_requested', $campaign, ['retry_count' => $ids->count()]);
        return response()->json(['campaign_id' => $campaign, 'status' => 'running', 'retry_count' => $ids->count()]);
    }

    public function candidates(Request $request, GeographicRelevanceClassifier $geography)
    {
        $request->validate(['campaign_id' => ['nullable', 'uuid'], 'eligibility_status' => ['nullable', 'in:eligible,needs_review,rejected'], 'promotion_status' => ['nullable', 'string', 'max:32'], 'country_code' => ['nullable', 'string', 'size:2'], 'industry' => ['nullable', 'string', 'max:150'],
            'geographic_relevance' => ['nullable', 'in:city_match,region_match,country_match,location_unknown,location_mismatch']]);
        $tenant = app('tenant.id');
        $page = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)
            ->when($request->filled('campaign_id'), fn ($q) => $q->where('campaign_id', $request->input('campaign_id')))
            ->when($request->filled('eligibility_status'), fn ($q) => $q->where('eligibility_status', $request->input('eligibility_status')))
            ->when($request->filled('promotion_status'), fn ($q) => $q->where('promotion_status', $request->input('promotion_status')))
            ->when($request->filled('country_code'), fn ($q) => $q->where('country_code', strtoupper((string) $request->input('country_code'))))
            ->when($request->input('geographic_relevance') === 'location_unknown', fn ($q) => $q->where(function ($nested): void {
                $nested->whereNull('geographic_relevance')->orWhere('geographic_relevance', 'location_unknown');
            }))
            ->when($request->filled('geographic_relevance') && $request->input('geographic_relevance') !== 'location_unknown', fn ($q) => $q->where('geographic_relevance', $request->input('geographic_relevance')))
            ->when($request->filled('industry'), fn ($q) => $q->where('industry', $request->input('industry')))
            ->orderByRaw("CASE geographic_relevance WHEN 'city_match' THEN 0 WHEN 'region_match' THEN 1 WHEN 'country_match' THEN 2 WHEN 'location_unknown' THEN 3 WHEN 'location_mismatch' THEN 4 ELSE 3 END")
            ->orderByDesc('created_at')->paginate(50);

        $items = $page->getCollection();
        $campaigns = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->whereIn('id', $items->pluck('campaign_id')->unique())->get()->keyBy('id');
        $evidence = DB::table('lead_discovery_candidate_evidence as evidence')
            ->join('lead_discovery_candidates as candidates', function ($join): void {
                $join->on('candidates.tenant_id', '=', 'evidence.tenant_id')
                    ->on('candidates.id', '=', 'evidence.candidate_id')
                    ->on('candidates.campaign_id', '=', 'evidence.campaign_id');
            })
            ->where('evidence.tenant_id', $tenant)->where('candidates.tenant_id', $tenant)
            ->whereIn('evidence.candidate_id', $items->pluck('id'))
            ->whereIn('evidence.field_name', ['country_code', 'region', 'city'])
            ->select('evidence.*')->get()->groupBy('candidate_id');
        $page->setCollection($items->map(function ($candidate) use ($campaigns, $evidence, $geography): object {
            $campaign = $campaigns->get($candidate->campaign_id);
            $candidate->geographic_relevance = $campaign
                ? $geography->classify($campaign, $evidence->get($candidate->id, collect()))
                : $this->unknownGeography();
            return $candidate;
        }));

        return $page;
    }

    public function candidate(string $candidate, GeographicRelevanceClassifier $geography)
    {
        $tenant = app('tenant.id');
        $row = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->first();
        abort_unless($row, 404);
        $campaign = DB::table('lead_discovery_campaigns')->where('tenant_id', $tenant)->where('id', $row->campaign_id)->first();
        $evidence = DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant)->where('campaign_id', $row->campaign_id)
            ->where('candidate_id', $row->id)->orderBy('field_name')->get();
        $row->geographic_relevance = $campaign ? $geography->classify($campaign, $evidence) : $this->unknownGeography();
        return response()->json(['candidate' => $row,
            'campaign' => $campaign,
            'source' => $this->sourcePayload(DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $row->source_id)->first()),
            'evidence' => $evidence,
            'matched_company' => $row->matched_company_id ? Company::where('tenant_id', $tenant)->where('id', $row->matched_company_id)->first() : null]);
    }

    private function unknownGeography(): array
    {
        return ['classification' => 'location_unknown', 'requested_location' => ['city' => null, 'region' => null, 'country' => null],
            'evidence_location' => ['city' => null, 'region' => null, 'country' => null], 'evidence_source' => null,
            'explanation' => 'No reliable source location evidence is available.'];
    }

    public function promote(Request $request, string $candidate)
    {
        abort_unless(config('lead_discovery.enabled'), 503, 'Lead Discovery is disabled by configuration.');
        $this->requireManager($request, ['owner', 'admin', 'manager']);
        $tenant = app('tenant.id'); $actor = (int) $request->user()->id;
        $result = DB::transaction(function () use ($tenant, $candidate, $actor): array {
            $row = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->promotion_status === 'promoted' && $row->matched_company_id) {
                $company = Company::where('tenant_id', $tenant)->where('id', $row->matched_company_id)->firstOrFail();
                return ['company' => $company, 'replayed' => true];
            }
            abort_unless(config('lead_discovery.auto_promotion') === false, 409);
            abort_unless($row->eligibility_status === 'eligible' || ($row->matched_company_id && $row->official_domain_status === 'verified'), 422,
                'Candidate must be eligible or be a verified existing-company match before manual promotion.');
            $source = DB::table('lead_discovery_sources')->where('tenant_id', $tenant)->where('id', $row->source_id)->where('enabled', true)->where('authorization_status', 'authorized')->first();
            abort_unless($source, 422, 'The candidate source is no longer authorized.');
            $company = $row->matched_company_id ? Company::where('tenant_id', $tenant)->where('id', $row->matched_company_id)->lockForUpdate()->first() : null;
            $company ??= Company::where('tenant_id', $tenant)->where('normalized_domain', $row->normalized_domain)->lockForUpdate()->first();
            if (! $company) $company = Company::firstOrCreate(['tenant_id' => $tenant, 'normalized_domain' => $row->normalized_domain], [
                'id' => (string) Str::uuid(), 'name' => $row->company_name, 'industry' => $row->industry,
                'location' => $row->location, 'source' => 'lead_discovery', 'status' => 'new',
            ]);
            $reusedCompany = ! $company->wasRecentlyCreated;
            if ($row->claimed_website && $row->official_domain_status === 'verified') {
                $website = CompanyWebsite::firstOrCreate(['tenant_id' => $tenant, 'company_id' => $company->id, 'host' => $row->normalized_domain], [
                    'id' => (string) Str::uuid(), 'url' => $row->claimed_website, 'canonical_url' => $row->claimed_website,
                    'verification_status' => 'verified', 'source' => 'lead_discovery',
                ]);
            } else $website = null;
            $this->promoteContacts($tenant, $company, $row);
            DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->update([
                'matched_company_id' => $company->id, 'promotion_status' => 'promoted', 'promoted_at' => now(), 'processing_status' => 'promoted', 'updated_at' => now(),
            ]);
            $this->audit('lead_discovery_candidate_promoted', $candidate, ['company_id' => $company->id, 'reused' => $reusedCompany]);
            if ($website) $this->queueExistingIntelligence($tenant, $actor, $company, $website);
            return ['company' => $company->load('websites'), 'replayed' => false];
        });
        return response()->json(['candidate_id' => $candidate, 'company' => $result['company'], 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function reject(Request $request, string $candidate)
    {
        $this->requireManager($request, ['owner', 'admin', 'manager']);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]); $tenant = app('tenant.id');
        $row = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->first();
        abort_unless($row, 404);
        DB::transaction(function () use ($tenant, $candidate, $data): void {
            DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('id', $candidate)->where('promotion_status', 'not_promoted')->update([
                'promotion_status' => 'rejected', 'eligibility_status' => 'rejected', 'processing_status' => 'rejected',
                'validation_reason_code' => 'HUMAN_REJECTED', 'validation_reason' => $data['reason'], 'updated_at' => now(),
            ]);
            $this->audit('lead_discovery_candidate_rejected', $candidate, ['reason' => $data['reason']]);
        });
        return response()->noContent();
    }

    private function queueExistingIntelligence(string $tenant, int $actor, Company $company, CompanyWebsite $website): void
    {
        $scanId = (string) Str::uuid(); $runId = (string) Str::uuid(); $now = now();
        $idempotencyKey = 'lead-discovery-scan:'.$website->id.':'.now()->format('Ymd');
        $inserted = DB::table('agent_runs')->insertOrIgnore(['id' => $runId, 'tenant_id' => $tenant, 'agent_key' => 'WebsiteIntelligenceAgent', 'status' => 'queued',
            'requested_by' => $actor, 'input_hash' => hash('sha256', json_encode(['company_website_id' => $website->id, 'lead_discovery' => true])),
            'correlation_id' => (string) Str::uuid(), 'idempotency_key' => $idempotencyKey,
            'created_at' => $now, 'updated_at' => $now]);
        if ($inserted === 0) return;
        DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant, 'company_website_id' => $website->id,
            'status' => 'queued', 'max_depth' => min(2, (int) config('crawling.max_depth', 2)), 'max_pages' => min(10, (int) config('crawling.max_pages', 30)),
            'crawler_version' => 'lead-discovery-promotion-v1', 'policy_snapshot' => json_encode(['respect_robots' => true, 'tenant_id' => $tenant]),
            'created_at' => $now, 'updated_at' => $now]);
        ScanWebsiteJob::dispatch($tenant, $website->id, $scanId, $runId, (string) $actor, true)->afterCommit();
    }

    private function promoteContacts(string $tenant, Company $company, object $candidate): void
    {
        $evidence = DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant)->where('candidate_id', $candidate->id)
            ->whereIn('field_name', ['business_email', 'business_phone'])->get();
        if ($evidence->isEmpty()) return;
        $contact = Contact::firstOrCreate(['tenant_id' => $tenant, 'company_id' => $company->id, 'source_url' => $evidence->first()->source_url,
            'extraction_method' => 'lead_discovery_public_source'], ['id' => (string) Str::uuid(), 'observed_at' => now(), 'confidence' => 0.7]);
        foreach ($evidence as $item) {
            $type = $item->field_name === 'business_email' ? 'email' : 'phone';
            $value = (string) $item->normalized_value;
            $fingerprint = app(ContactMethodValue::class)->fingerprint($type, $value);
            if (DB::table('contact_methods')->where('tenant_id', $tenant)->where('value_hash', $fingerprint)->exists()) continue;
            ContactMethod::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'contact_id' => $contact->id, 'type' => $type,
                'value' => $value, 'source_url' => $item->source_url, 'observed_at' => $item->collected_at, 'extraction_method' => 'lead_discovery_public_source',
                'confidence' => $item->confidence_score, 'verification_status' => 'publicly_observed']);
        }
    }

    private function insertEvidence(string $tenant, string $candidate, string $campaign, string $source, string $field, string $type, ?string $sourceUrl, ?string $raw, ?string $normalized, float $confidence, array $attribution, mixed $now): void
    {
        DB::table('lead_discovery_candidate_evidence')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'candidate_id' => $candidate,
            'campaign_id' => $campaign, 'source_id' => $source, 'field_name' => $field, 'evidence_type' => $type, 'source_url' => $sourceUrl,
            'raw_value' => mb_substr((string) $raw, 0, 2000), 'normalized_value' => mb_substr((string) $normalized, 0, 2000),
            'confidence_score' => $confidence, 'collected_at' => $now, 'attribution' => json_encode($attribution), 'created_at' => $now, 'updated_at' => $now]);
    }

    private function sourcePayload(?object $row): ?array
    {
        if (! $row) return null;
        $configuration = json_decode((string) $row->configuration, true) ?: [];
        $reason = $this->sourceBlockReason($row);
        $capability = app(LeadSourceCapabilityCatalog::class)->get((string) $row->source_key);
        return ['id' => $row->id, 'source_key' => $row->source_key, 'provider_name' => $row->provider_name, 'source_type' => $row->source_type,
            'authorization_status' => $row->authorization_status, 'allowed_collection_method' => $row->allowed_collection_method,
            'authorization_summary' => mb_substr((string) ($configuration['license_or_authorization'] ?? ''), 0, 500),
            'retention_policy' => $row->retention_policy, 'attribution_requirement' => $row->attribution_requirement,
            'enabled' => (bool) $row->enabled, 'health_state' => $row->health_state, 'last_success_at' => $row->last_success_at, 'last_error_code' => $row->last_error_code,
            'credential_status' => $this->credentialStatus((string) $row->source_key), 'configuration_status' => $this->configurationStatus((string) $row->source_key),
            'storage_rights_status' => $capability?->persistentStorageAllowed ? $row->storage_rights_status : 'not_required',
            'storage_rights_confirmed' => ! $capability?->persistentStorageAllowed || $row->storage_rights_status === 'confirmed',
            'execution_ready' => $reason === null,
            'readiness_reason_code' => $reason];
    }

    private function sourceExecutionReady(object $source): bool
    {
        return $this->sourceBlockReason($source) === null;
    }

    private function sourceBlockReason(object $source): ?string
    {
        $configuration = json_decode((string) ($source->configuration ?? '{}'), true) ?: [];
        $capability = app(LeadSourceCapabilityCatalog::class)->get((string) $source->source_key);
        if (! $capability?->supportsLiveQuery) return 'SOURCE_NOT_APPLICABLE';
        if ($capability?->persistentStorageAllowed && ($source->storage_rights_status !== 'confirmed'
            || ($configuration['storage_rights_confirmed'] ?? false) !== true
            || trim((string) ($configuration['storage_rights_reference'] ?? '')) === '')) return 'STORAGE_RIGHTS_NOT_CONFIRMED';
        if ($source->authorization_status !== 'authorized') return 'SOURCE_NOT_AUTHORIZED';
        if ($this->credentialStatus((string) $source->source_key) === 'missing') return 'SOURCE_CREDENTIAL_MISSING';
        if (! (bool) $source->enabled) return 'SOURCE_DISABLED';
        $configuration['source_enabled'] = (bool) $source->enabled;
        $configuration['authorization_status'] = $source->authorization_status;
        $configuration['storage_rights_status'] = $source->storage_rights_status;
        try {
            $adapter = app(LeadSourceAdapterRegistry::class)->get((string) $source->source_key);
            if (! $adapter instanceof \App\LeadDiscovery\SearchLeadSourceAdapterInterface) return 'SOURCE_NOT_APPLICABLE';
            $adapter->validateConfiguration($configuration);
            return app(\App\LeadDiscovery\LeadDiscoverySourceHealthPolicy::class)->blockReason($source);
        } catch (\App\LeadDiscovery\LiveLeadSourceException $error) { return $error->errorCode; }
        catch (\InvalidArgumentException) { return 'SOURCE_NOT_APPLICABLE'; }
    }

    private function credentialStatus(string $sourceKey): string
    {
        return match ($sourceKey) {
            'brave_search' => filled(config('services.brave_search.key')) ? 'available' : 'missing',
            'india_ogd_mca' => filled(config('services.data_gov_in.api_key')) ? 'available' : 'missing',
            default => 'not_required',
        };
    }

    private function configurationStatus(string $sourceKey): string
    {
        return match ($sourceKey) {
            'osm_overpass' => filled(config('lead_discovery.user_agent')) && (array) config('lead_discovery.overpass_endpoints', []) !== [] ? 'available' : 'missing',
            'wikidata' => filled(config('lead_discovery.wikidata_user_agent')) && (string) parse_url((string) config('lead_discovery.endpoints.wikidata'), PHP_URL_HOST) === 'query.wikidata.org' ? 'available' : 'missing',
            'india_ogd_mca' => filled(config('lead_discovery.mca_resource_id')) ? 'available' : 'missing',
            'gleif' => (string) parse_url((string) config('lead_discovery.endpoints.gleif'), PHP_URL_HOST) === 'api.gleif.org' ? 'available' : 'missing',
            'brave_search' => filled(config('services.brave_search.key')) ? 'available' : 'missing',
            default => 'not_required',
        };
    }

    private function sourceBlockMessage(string $reason): string
    {
        return match ($reason) {
            'STORAGE_RIGHTS_NOT_CONFIRMED' => 'Persistent search-result storage rights are not explicitly confirmed for this source.',
            'SOURCE_CREDENTIAL_MISSING' => 'The live search provider credential is not configured.',
            'SOURCE_NOT_AUTHORIZED' => 'The source is not approved for this tenant.',
            'SOURCE_NOT_APPLICABLE' => 'This source is for manual imports or is not a live discovery provider.',
            default => 'Live search is disabled or the source is not enabled.',
        };
    }

    private function safeSourceUrl(string $value): ?string
    {
        if (trim($value) === '') return null;
        try { $result = $this->domains->normalize($value); return strtolower((string) parse_url($result['normalized_url'], PHP_URL_SCHEME)).'://'.$result['normalized_domain'].'/'; }
        catch (Throwable) { return null; }
    }

    private function safeContactValues(array $row, string $domain): array
    {
        $values = [];
        $email = mb_strtolower(trim((string) ($row['business_email'] ?? '')));
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            [$local, $host] = explode('@', $email, 2);
            if ($host === $domain && in_array($local, ['info', 'sales', 'contact', 'enquiry', 'enquiries', 'hello', 'support'], true)) $values[] = ['business_email', $email];
        }
        $phone = preg_replace('/[^0-9+(). -]/', '', (string) ($row['business_phone'] ?? ''));
        if ($phone && strlen($phone) <= 50) $values[] = ['business_phone', $phone];
        return $values;
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/[^\pL\pN ]/u', ' ', $name) ?: $name)) ?: '');
    }

    private function requireManager(Request $request, array $roles): void
    {
        $role = $request->user()->tenants()->whereKey(app('tenant.id'))->wherePivot('status', 'active')->value('tenant_user.role');
        abort_unless(in_array($role, $roles, true), 403, 'An authorized active tenant role is required.');
    }

    private function withPilotFields(object $campaign): object
    {
        $configuration = is_array($campaign->configuration ?? null)
            ? $campaign->configuration
            : (json_decode((string) ($campaign->configuration ?? '{}'), true) ?: []);
        $campaign->pilot_mode = (bool) data_get($configuration, 'pilot_mode', false);
        $campaign->pilot_target = $campaign->pilot_mode
            ? LeadDiscoveryPilotSummary::configuredTarget($configuration)
            : null;

        return $campaign;
    }

    private function audit(string $action, string $subject, array $metadata): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => app('tenant.id'), 'actor_user_id' => request()->user()?->id,
            'action' => $action, 'subject_type' => 'lead_discovery', 'subject_id' => $subject, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
