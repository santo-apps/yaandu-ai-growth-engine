<?php

namespace Tests\Feature;

use App\LeadDiscovery\CandidateIdentityResolver;
use App\LeadDiscovery\LiveCandidateStager;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeadDiscoverySourceStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_entity_without_website_is_staged_without_guessing_and_preserves_attribution(): void
    {
        [$tenant, $campaign, $source, $run, $checkpoint] = $this->records('india_ogd_mca');
        $result = app(LiveCandidateStager::class)->stage($tenant, $campaign, $run, $source, $checkpoint, [
            'company_name' => 'Synthetic Legal Entity Limited', 'legal_name' => 'Synthetic Legal Entity Limited',
            'registration_identifier' => 'CIN-SYNTHETIC-001', 'country_code' => 'IN', 'website' => null,
            'source_reference' => 'CIN-SYNTHETIC-001', 'source_record_type' => 'mca_company',
            'source_url' => 'https://www.data.gov.in/catalog/company-master-data',
            'attribution' => ['license_name' => 'India OGD / NDSAP', 'attribution_text' => 'Ministry of Corporate Affairs, Government of India via data.gov.in'],
        ], 1);
        self::assertTrue($result['created']);
        self::assertNull($result['verification_id']);
        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->first();
        self::assertNull($candidate->claimed_website);
        self::assertSame('unknown', $candidate->official_domain_status);
        self::assertDatabaseHas('lead_discovery_candidate_evidence', ['tenant_id' => $tenant, 'candidate_id' => $candidate->id, 'field_name' => 'registration_identifier']);
        $metadata = json_decode((string) DB::table('lead_discovery_candidate_evidence')->where('candidate_id', $candidate->id)->where('field_name', 'registration_identifier')->value('attribution'), true);
        self::assertSame('India OGD / NDSAP', $metadata['license_name']);
        self::assertSame(0, DB::table('companies')->where('tenant_id', $tenant)->count());
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant)->count());
        self::assertSame(0, DB::table('outbound_messages')->where('tenant_id', $tenant)->count());
    }

    public function test_strong_identifier_merges_sources_and_updates_canonical_name_by_field_priority(): void
    {
        [$tenant, $campaign, $osm, $run, $checkpoint] = $this->records('osm_overpass');
        app(LiveCandidateStager::class)->stage($tenant, $campaign, $run, $osm, $checkpoint, [
            'company_name' => 'Synthetic OSM Name', 'lei' => 'SYNTHETIC-LEI-0001', 'country_code' => 'AE', 'city' => 'Dubai',
            'source_reference' => 'node/123', 'source_record_type' => 'node', 'source_url' => 'https://www.openstreetmap.org/node/123',
            'attribution' => ['license_name' => 'ODbL', 'attribution_text' => '© OpenStreetMap contributors'],
        ], 1);
        [$tenant2, $campaign2, $gleif, $run2, $checkpoint2] = $this->records('gleif', $tenant);
        $result = app(LiveCandidateStager::class)->stage($tenant2, $campaign2, $run2, $gleif, $checkpoint2, [
            'company_name' => 'Synthetic GLEIF Legal Name', 'legal_name' => 'Synthetic GLEIF Legal Name',
            'lei' => 'SYNTHETIC-LEI-0001', 'country_code' => 'AE', 'city' => 'Dubai', 'source_reference' => 'SYNTHETIC-LEI-0001',
            'source_record_type' => 'gleif_lei', 'source_url' => 'https://api.gleif.org/api/v1/lei-records/SYNTHETIC-LEI-0001',
            'attribution' => ['license_name' => 'CC0 1.0'],
        ], 1);
        self::assertFalse($result['created']);
        self::assertTrue($result['duplicate']);
        self::assertSame(1, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->count());
        self::assertSame('Synthetic GLEIF Legal Name', DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->value('company_name'));
        self::assertSame(2, DB::table('lead_discovery_candidate_evidence')->where('tenant_id', $tenant)->where('field_name', 'lei')->count());
    }

    public function test_registry_identifier_requires_country_registry_and_type_namespace_but_preserves_raw_evidence(): void
    {
        [$tenant, $campaign, $source, $run, $checkpoint] = $this->records('india_ogd_mca');
        $rawCin = 'U12345TN2020PTC123456';
        $base = ['registration_identifier' => $rawCin, 'source_record_id' => $rawCin, 'source_record_type' => 'company_number'];
        $first = app(LiveCandidateStager::class)->stage($tenant, $campaign, $run, $source, $checkpoint, [
            'company_name' => 'Registry One', 'country_code' => 'IN', 'registration_identifier_jurisdiction' => 'IN',
            'registration_identifier_registry' => 'MCA', 'registration_identifier_type' => 'CIN', ...$base,
        ], 1);
        [$tenant, $campaign2, $source2, $run2, $checkpoint2] = $this->records('gleif', $tenant);
        $second = app(LiveCandidateStager::class)->stage($tenant, $campaign2, $run2, $source2, $checkpoint2, [
            'company_name' => 'Registry Two', 'country_code' => 'GB', 'registration_identifier_jurisdiction' => 'GB',
            'registration_identifier_registry' => 'COMPANIES_HOUSE', 'registration_identifier_type' => 'COMPANY_NUMBER', ...$base,
        ], 1);
        self::assertTrue($first['created']);
        self::assertTrue($second['created'], 'Identical raw values in distinct registry namespaces do not merge.');
        self::assertSame(2, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->count());
        self::assertDatabaseHas('lead_discovery_candidate_evidence', ['tenant_id' => $tenant, 'field_name' => 'registration_identifier', 'raw_value' => $rawCin]);
        self::assertDatabaseHas('lead_discovery_candidate_evidence', ['tenant_id' => $tenant, 'field_name' => 'namespaced_registration_identifier',
            'normalized_value' => 'IN:MCA:CIN:'.$rawCin]);

        [$tenant, $campaign3, $source3, $run3, $checkpoint3] = $this->records('india_ogd_mca', $tenant);
        $sameCin = app(LiveCandidateStager::class)->stage($tenant, $campaign3, $run3, $source3, $checkpoint3, [
            'company_name' => 'Another MCA Display Name', 'country_code' => 'IN', 'registration_identifier_jurisdiction' => 'IN',
            'registration_identifier_registry' => 'MCA', 'registration_identifier_type' => 'CIN', ...$base,
        ], 1);
        self::assertTrue($sameCin['duplicate']);
        self::assertSame(2, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->count());

        foreach (['Unnamespaced Registry One', 'Unnamespaced Registry Two'] as $name) {
            [$tenant, $unscopedCampaign, $unscopedSource, $unscopedRun, $unscopedCheckpoint] = $this->records('gleif', $tenant);
            $unscoped = app(LiveCandidateStager::class)->stage($tenant, $unscopedCampaign, $unscopedRun, $unscopedSource, $unscopedCheckpoint, [
                'company_name' => $name, 'country_code' => 'US', 'registration_identifier' => 'RAW-REG-77',
                'source_record_id' => 'RAW-REG-77', 'source_record_type' => 'registry_entry',
            ], 1);
            self::assertTrue($unscoped['created'], 'A raw registration value without an explicit registry namespace is not used for auto-merge.');
        }
        self::assertSame(4, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->count());

        $otherTenant = (string) Str::uuid();
        [$otherTenant, $campaign4, $source4, $run4, $checkpoint4] = $this->records('india_ogd_mca', $otherTenant);
        $crossTenant = app(LiveCandidateStager::class)->stage($otherTenant, $campaign4, $run4, $source4, $checkpoint4, [
            'company_name' => 'Registry One', 'country_code' => 'IN', 'registration_identifier_jurisdiction' => 'IN',
            'registration_identifier_registry' => 'MCA', 'registration_identifier_type' => 'CIN', ...$base,
        ], 1);
        self::assertTrue($crossTenant['created']);
        self::assertSame(1, DB::table('lead_discovery_candidates')->where('tenant_id', $otherTenant)->count());
    }

    public function test_global_lei_and_wikidata_ids_merge_but_osm_object_types_do_not_collide(): void
    {
        [$tenant, $campaign, $source, $run, $checkpoint] = $this->records('osm_overpass');
        $stager = app(LiveCandidateStager::class);
        $node = $stager->stage($tenant, $campaign, $run, $source, $checkpoint, [
            'company_name' => 'OSM Node Business', 'country_code' => 'IN', 'osm_object_id' => '123',
            'source_record_id' => '123', 'source_record_type' => 'node',
        ], 1);
        self::assertSame('none', app(CandidateIdentityResolver::class)->resolve($tenant, null, ['osm_object_id' => '123'], 'unrelated', 'IN', 'Delhi')['match'],
            'A bare numeric OSM ID is not independently strong.');
        [$tenant, $campaign2, $source2, $run2, $checkpoint2] = $this->records('osm_overpass', $tenant);
        $way = $stager->stage($tenant, $campaign2, $run2, $source2, $checkpoint2, [
            'company_name' => 'OSM Way Business', 'country_code' => 'IN', 'osm_object_id' => '123',
            'source_record_id' => '123', 'source_record_type' => 'way',
        ], 1);
        self::assertTrue($node['created']);
        self::assertTrue($way['created'], 'OSM numeric IDs are scoped by object type.');
        self::assertSame(2, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->count());

        foreach ([['lei', '529900T8BM49AURSDO55'], ['wikidata_qid', 'Q123']] as [$field, $value]) {
            [$tenant, $c1, $s1, $r1, $p1] = $this->records('gleif', $tenant);
            $stager->stage($tenant, $c1, $r1, $s1, $p1, ['company_name' => 'Global '.$field.' One', 'country_code' => 'AE', $field => $value], 1);
            [$tenant, $c2, $s2, $r2, $p2] = $this->records('wikidata', $tenant);
            $merged = $stager->stage($tenant, $c2, $r2, $s2, $p2, ['company_name' => 'Global '.$field.' Two', 'country_code' => 'GB', $field => $value], 1);
            self::assertTrue($merged['duplicate'], $field.' remains a globally strong identifier.');
        }

        [$tenant, $campaign3, $source3, $run3, $checkpoint3] = $this->records('osm_overpass', $tenant);
        $sameNode = $stager->stage($tenant, $campaign3, $run3, $source3, $checkpoint3, [
            'company_name' => 'Another Node Name', 'country_code' => 'GB', 'osm_object_id' => '123',
            'source_record_id' => '123', 'source_record_type' => 'node',
        ], 1);
        self::assertTrue($sameNode['duplicate']);
    }

    public function test_medium_name_country_city_match_is_reviewed_not_merged_and_identifiers_are_tenant_scoped(): void
    {
        [$tenant, $campaign, $source, $run, $checkpoint] = $this->records('osm_overpass');
        app(LiveCandidateStager::class)->stage($tenant, $campaign, $run, $source, $checkpoint, [
            'company_name' => 'Similar Local Business', 'country_code' => 'IN', 'city' => 'Chennai', 'source_reference' => 'node/501', 'source_record_id' => 'node/501',
            'source_url' => 'https://www.openstreetmap.org/node/501', 'source_record_type' => 'node',
        ], 1);
        $firstCandidateId = (string) DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->value('id');
        $diagnosticMatch = app(CandidateIdentityResolver::class)->resolve($tenant, null, ['registration_identifier' => null, 'lei' => null, 'wikidata_qid' => null, 'osm_object_id' => null], 'similar local business', 'IN', 'Chennai');
        self::assertSame('possible_match', $diagnosticMatch['match']);
        $result = app(LiveCandidateStager::class)->stage($tenant, $campaign, $run, $source, $checkpoint, [
            'company_name' => 'Similar Local Business', 'country_code' => 'IN', 'city' => 'Chennai', 'source_reference' => 'node/502', 'source_record_id' => 'node/502',
            'source_url' => 'https://www.openstreetmap.org/node/502', 'source_record_type' => 'node',
        ], 2);
        self::assertTrue($result['created']);
        self::assertSame(2, DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->count());
        $candidate = DB::table('lead_discovery_candidates')->where('tenant_id', $tenant)->where('id', '<>', $firstCandidateId)->first();
        $metadata = json_decode((string) $candidate->raw_metadata, true);
        self::assertSame('POSSIBLE_CROSS_SOURCE_MATCH', $candidate->validation_reason_code);
        self::assertNotEmpty($metadata['possible_match_candidate_id']);

        [$otherTenant, $otherCampaign, $otherSource, $otherRun, $otherCheckpoint] = $this->records('gleif');
        $resolver = app(CandidateIdentityResolver::class);
        $match = $resolver->resolve($otherTenant, null, ['lei' => 'SYNTHETIC-LEI-0001'], 'similar local business', 'IN', 'Chennai');
        self::assertSame('none', $match['match']);
        self::assertNull($match['candidate']);
    }

    private function records(string $sourceKey, ?string $tenantId = null): array
    {
        $tenantId ??= (string) Str::uuid();
        if (! Tenant::whereKey($tenantId)->exists()) {
            $tenant = Tenant::create(['name' => 'Discovery '.$sourceKey, 'slug' => 'discovery-'.Str::lower(Str::random(8)), 'status' => 'active']);
            $tenantId = (string) $tenant->id;
        }
        $campaignId = (string) Str::uuid(); $sourceId = (string) Str::uuid(); $runId = (string) Str::uuid(); $checkpointId = (string) Str::uuid();
        DB::table('lead_discovery_campaigns')->insert(['id' => $campaignId, 'tenant_id' => $tenantId, 'name' => 'Synthetic', 'industry' => null,
            'country_code' => null, 'configuration' => '{}', 'target_leads' => 5, 'auto_promotion_enabled' => false, 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lead_discovery_sources')->insert(['id' => $sourceId, 'tenant_id' => $tenantId, 'source_key' => $sourceKey, 'provider_name' => $sourceKey,
            'source_type' => 'OPEN_PERSISTABLE', 'authorization_status' => 'authorized', 'storage_rights_status' => 'confirmed',
            'configuration' => json_encode(['license_or_authorization' => 'Synthetic source terms', 'storage_rights_confirmed' => true, 'storage_rights_reference' => 'synthetic']),
            'enabled' => true, 'health_state' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lead_discovery_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'source_id' => $sourceId,
            'run_status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lead_discovery_checkpoints')->insert(['id' => $checkpointId, 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'run_id' => $runId,
            'source_id' => $sourceId, 'query_key' => 'synthetic', 'query_text' => '', 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        return [$tenantId, DB::table('lead_discovery_campaigns')->where('id', $campaignId)->first(),
            DB::table('lead_discovery_sources')->where('id', $sourceId)->first(), DB::table('lead_discovery_runs')->where('id', $runId)->first(),
            DB::table('lead_discovery_checkpoints')->where('id', $checkpointId)->first()];
    }
}
