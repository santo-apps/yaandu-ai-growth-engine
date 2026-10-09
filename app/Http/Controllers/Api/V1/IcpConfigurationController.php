<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\WebsiteIntelligence\TenantServiceCatalog;
use App\WebsiteIntelligence\YaanduServiceTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class IcpConfigurationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeManager($request);
        $tenantId = (string) app('tenant.id');

        return response()->json([
            'active' => DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('status', 'active')->first(),
            'versions' => DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->orderByDesc('version')->get(),
            'available_service_keys' => app(TenantServiceCatalog::class)->recommendationServices($tenantId)->keys()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeManager($request);
        $data = $this->validatedConfiguration($request);
        $tenantId = (string) app('tenant.id');

        return DB::transaction(function () use ($request, $data, $tenantId) {
            $version = ((int) DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->max('version')) + 1;
            $id = (string) Str::uuid();
            DB::table('tenant_icp_configurations')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'version' => $version, 'status' => 'draft',
                'configuration' => json_encode($data, JSON_THROW_ON_ERROR), 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, 'icp_configuration.draft_created', $id, ['version' => $version]);

            return response()->json(DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->first(), 201);
        });
    }

    public function update(Request $request, string $id)
    {
        $this->authorizeManager($request);
        $tenantId = (string) app('tenant.id');
        $record = DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($record && $record->status === 'draft', 404);
        $data = $this->validatedConfiguration($request);

        DB::transaction(function () use ($request, $tenantId, $id, $data, $record): void {
            DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->where('status', 'draft')
                ->update(['configuration' => json_encode($data, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $this->audit($request, 'icp_configuration.draft_updated', $id, ['version' => $record->version]);
        });

        return response()->json(DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->first());
    }

    public function activate(Request $request, string $id)
    {
        $this->authorizeManager($request);
        $tenantId = (string) app('tenant.id');
        return DB::transaction(function () use ($request, $tenantId, $id) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $record = DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'draft', 404);
            $configuration = json_decode($record->configuration, true, flags: JSON_THROW_ON_ERROR);
            $this->assertActivatable($tenantId, $configuration);
            DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('status', 'active')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->update([
                'status' => 'active', 'activated_by' => $request->user()->id, 'activated_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, 'icp_configuration.activated', $id, ['version' => $record->version]);
            return response()->json(DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->first());
        });
    }

    public function deactivate(Request $request, string $id)
    {
        $this->authorizeManager($request);
        $tenantId = (string) app('tenant.id');

        return DB::transaction(function () use ($request, $tenantId, $id) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $record = DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'active', 404);
            DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->update(['status' => 'inactive', 'updated_at' => now()]);
            $this->audit($request, 'icp_configuration.deactivated', $id, ['version' => $record->version]);

            return response()->json(DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('id', $id)->first());
        });
    }

    private function validatedConfiguration(Request $request): array
    {
        return $request->validate([
            'geography' => ['required', 'array:target_countries,target_locations'],
            'geography.target_countries' => ['present', 'array', 'max:30'], 'geography.target_countries.*' => ['required', 'string', 'max:100'],
            'geography.target_locations' => ['present', 'array', 'max:100'], 'geography.target_locations.*' => ['required', 'string', 'max:150'],
            'organization_suitability' => ['required', 'array:target_industries,business_types,scale_bands,commercial_viability_criteria'],
            'organization_suitability.target_industries' => ['present', 'array', 'max:50'], 'organization_suitability.target_industries.*' => ['required', 'string', 'max:150'],
            'organization_suitability.business_types' => ['present', 'array', 'max:30'], 'organization_suitability.business_types.*' => ['required', 'string', 'max:120'],
            'organization_suitability.scale_bands' => ['present', 'array', 'max:10'], 'organization_suitability.scale_bands.*' => ['required', 'string', 'max:80'],
            'organization_suitability.commercial_viability_criteria' => ['present', 'array', 'max:20'], 'organization_suitability.commercial_viability_criteria.*' => ['required', 'string', 'max:300'],
            'digital_opportunity' => ['required', 'array:evidence_types'],
            'digital_opportunity.evidence_types' => ['present', 'array', 'max:20'], 'digital_opportunity.evidence_types.*' => ['required', Rule::in([
                'website_modernization_need', 'ecommerce_gap', 'lead_capture_gap', 'manual_workflow_opportunity', 'automation_opportunity', 'customer_engagement_gap',
                'ecommerce_enablement_migration', 'lead_capture_cro', 'whatsapp_customer_engagement_automation', 'erp_workflow_automation',
                'ai_process_automation', 'seo_content_discoverability', 'mobile_custom_software', 'cloud_devops_modernization',
            ])],
            'service_fit' => ['required', 'array:service_keys'], 'service_fit.service_keys' => ['present', 'array', 'max:30'],
            'service_fit.service_keys.*' => ['required', 'string', 'in:'.implode(',', YaanduServiceTaxonomy::keys())],
            'commercial_contact_readiness' => ['required', 'array:evidence_requirements'],
            'commercial_contact_readiness.evidence_requirements' => ['present', 'array', 'max:10'],
            'commercial_contact_readiness.evidence_requirements.*' => ['required', 'string', Rule::in([
                'public_company_contact_path', 'role_relevant_public_contact', 'source_and_timestamp_recorded', 'public_company_contact_channel',
                'contact_enquiry_form', 'public_business_email_phone', 'named_public_business_contact', 'explicit_sales_contact_mechanism',
            ])],
        ]);
    }

    private function assertActivatable(string $tenantId, array $configuration): void
    {
        abort_if(($configuration['geography']['target_countries'] ?? []) === [], 422, 'Configure at least one target geography before activation.');
        abort_if(($configuration['organization_suitability']['target_industries'] ?? []) === [], 422, 'Configure target industries before activation.');
        abort_if(($configuration['organization_suitability']['business_types'] ?? []) === [], 422, 'Define suitable business types before activation.');
        abort_if(($configuration['organization_suitability']['commercial_viability_criteria'] ?? []) === [], 422, 'Define evidence-based commercial viability criteria before activation.');
        abort_if(($configuration['digital_opportunity']['evidence_types'] ?? []) === [], 422, 'Select evidence-driven digital opportunity types before activation.');
        abort_if(($configuration['commercial_contact_readiness']['evidence_requirements'] ?? []) === [], 422, 'Configure public contact-readiness evidence requirements before activation.');
        $activeKeys = app(TenantServiceCatalog::class)->recommendationServices($tenantId)->keys()->all();
        $selected = $configuration['service_fit']['service_keys'] ?? [];
        abort_if($activeKeys === [], 422, 'At least one active approved tenant service is required before ICP activation.');
        abort_if($selected === [] || array_diff($selected, $activeKeys) !== [], 422, 'Service fit must select only active approved tenant service keys.');
    }

    private function authorizeManager(Request $request): void
    {
        $role = DB::table('tenant_user')->where('tenant_id', app('tenant.id'))->where('user_id', $request->user()->id)->where('status', 'active')->value('role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403);
    }

    private function audit(Request $request, string $action, string $subjectId, array $metadata): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => app('tenant.id'), 'actor_user_id' => $request->user()->id,
            'action' => $action, 'subject_type' => 'tenant_icp_configuration', 'subject_id' => $subjectId,
            'request_id' => request()->header('X-Request-ID'), 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
