<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\StaticAIProvider;
use Tests\TestCase;

final class OutreachDraftGenerationTest extends TestCase
{
    public function test_outreach_readiness_surfaces_missing_tenant_route_without_enabling_generation(): void
    {
        [$tenant, $owner, $company] = $this->fixture('outreach-readiness-route');
        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Tenant-ID' => $tenant->id])->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk();
        self::assertTrue($response->json('eligible'), 'The existing sales eligibility contract remains Pursue plus approved service.');
        self::assertFalse($response->json('generation_ready'));
        self::assertFalse(collect($response->json('readiness.checks'))->firstWhere('key', 'route')['ready']);
        self::assertFalse(collect($response->json('readiness.checks'))->firstWhere('key', 'credential')['ready']);
        self::assertTrue(collect($response->json('readiness.checks'))->firstWhere('key', 'prompt')['ready']);
    }

    public function test_outreach_readiness_requires_enabled_tenant_route_and_runtime_provider_credential(): void
    {
        [$tenant, $owner, $company] = $this->fixture('outreach-readiness-ready');
        DB::table('ai_model_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'task_key' => 'outreach_drafting',
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'enabled' => true, 'parameters' => json_encode(['max_output_tokens' => 1800, 'temperature' => 0.3]),
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config(['ai.providers.openai.key' => 'synthetic-test-key']);
        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Tenant-ID' => $tenant->id])->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk();
        self::assertTrue($response->json('generation_ready'));
        foreach (['sales_decision', 'service', 'findings', 'route', 'credential', 'prompt'] as $key) {
            self::assertTrue(collect($response->json('readiness.checks'))->firstWhere('key', $key)['ready'], $key.' readiness should be true.');
        }
        self::assertSame('openai', $response->json('readiness.route.provider'));
        self::assertSame('gpt-4.1-mini', $response->json('readiness.route.model'));
        self::assertArrayNotHasKey('key', $response->json('readiness.route'));
    }

    use RefreshDatabase;

    public function test_pursue_review_can_create_grounded_drafts_without_sending_or_changing_sales_state(): void
    {
        [$tenant,$user,$company,$service,$finding]=$this->fixture('outreach-ready');
        $provider=$this->provider($tenant->id,$finding);
        Sanctum::actingAs($user);

        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'outreach-draft-one'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding],'tone'=>'consultative'])
            ->assertCreated()->assertJsonPath('task_key','outreach_drafting')->assertJsonPath('version',1);

        self::assertSame('conversation-test',$response->json('provider'));
        self::assertSame('Prospect Co',$provider->requests[0]->evidence['prospect']['company']['name']);
        self::assertSame('outreach_drafting',$provider->requests[0]->task);
        self::assertCount(1,$provider->requests[0]->evidence['prospect']['selected_validated_findings']);
        self::assertStringContainsString('never send',strtolower($provider->requests[0]->systemInstruction));
        $this->assertDatabaseHas('outreach_drafts',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'selected_service_id'=>$service,'version'=>1]);
        $this->assertDatabaseHas('agent_runs',['tenant_id'=>$tenant->id,'agent_key'=>'OutreachDraftAgent','status'=>'succeeded']);
        $this->assertDatabaseHas('audit_logs',['tenant_id'=>$tenant->id,'action'=>'outreach_draft.generated']);
        $this->assertDatabaseCount('outbound_messages',0);
        $this->assertDatabaseCount('campaign_recipients',0);
        $this->assertDatabaseCount('campaign_events',0);
        $this->assertDatabaseCount('website_scans',0);
        $this->assertDatabaseCount('lead_scores',1);
        $this->assertDatabaseHas('company_human_sales_reviews',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'decision'=>'pursue','selected_service_id'=>$service]);
    }

    public function test_generation_is_blocked_until_pursue_and_reviewer_selected_service(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-review-gate');
        DB::table('company_human_sales_reviews')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['decision'=>'hold','selected_service_id'=>null]);
        $provider=$this->provider($tenant->id,$finding); Sanctum::actingAs($user);
        $this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'blocked-outreach'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])
            ->assertUnprocessable()->assertJsonValidationErrors('review');
        self::assertCount(0,$provider->requests);
        $this->assertDatabaseCount('outreach_drafts',0);
        $this->assertDatabaseCount('outbound_messages',0);
    }

    public function test_inactive_or_unapproved_service_cannot_be_used_for_draft_generation(): void
    {
        [$tenant,$user,$company,$service,$finding]=$this->fixture('outreach-unapproved-service');
        DB::table('tenant_services')->where('tenant_id',$tenant->id)->where('id',$service)->update(['approved_by'=>null]);
        $provider=$this->provider($tenant->id,$finding); Sanctum::actingAs($user);
        $this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'unapproved-outreach-service'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])
            ->assertUnprocessable()->assertJsonValidationErrors('service');
        self::assertCount(0,$provider->requests);
        $this->assertDatabaseCount('outreach_drafts',0);
    }

    public function test_unselected_or_cross_tenant_findings_are_rejected_and_company_reads_are_tenant_scoped(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-isolation');
        [$otherTenant,,$otherCompany]=$this->fixture('outreach-other');
        $provider=$this->provider($tenant->id,$finding); Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID',$tenant->id)->getJson('/api/v1/companies/'.$otherCompany->id.'/outreach-drafts')->assertNotFound();
        $this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'fake-finding'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[(string)Str::uuid()]])
            ->assertUnprocessable()->assertJsonValidationErrors('finding_ids');
        self::assertCount(0,$provider->requests);
        $this->assertDatabaseCount('outreach_drafts',0);
    }

    public function test_regeneration_keeps_previous_draft_and_returns_only_new_draft_version(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-version');
        $provider=$this->provider($tenant->id,$finding); Sanctum::actingAs($user);
        $first=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'outreach-version-one'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertCreated()->json();
        $second=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'outreach-version-two'])
            ->postJson('/api/v1/outreach-drafts/'.$first['id'].'/regenerate',['finding_ids'=>[$finding]])->assertCreated()->assertJsonPath('version',2)->json();
        self::assertSame($first['id'],$second['supersedes_id']);
        $this->assertDatabaseCount('outreach_drafts',2);
        $this->assertDatabaseCount('outbound_messages',0);
    }

    public function test_unknown_and_negative_components_are_not_offered_as_validated_opportunity_findings(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-only-positive');
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>$finding],
            'no_whatsapp'=>['status'=>'NEGATIVE','evidence_reference'=>$finding],
            'no_crm'=>['status'=>'UNKNOWN','evidence_reference'=>$finding],
        ])]);
        Sanctum::actingAs($user);
        $workspace=$this->withHeader('X-Tenant-ID',$tenant->id)->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk()->json();
        self::assertSame([$finding],array_column($workspace['findings'],'id'));
        self::assertSame(['poor_mobile_ux'],array_column($workspace['findings'],'type'));
    }

    public function test_workspace_uses_only_canonical_lead_evidence_reference_and_ignores_mixed_reference_metadata(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-mixed-evidence-reference');
        $unrelatedUuid=(string)Str::uuid();
        $lowConfidenceInsight=(string)Str::uuid();$lowConfidenceEvidence=(string)Str::uuid();
        DB::table('lead_insights')->insert(['id'=>$lowConfidenceInsight,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'kind'=>'website','statement'=>'Low confidence finding.','confidence'=>.5,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_evidence')->insert(['id'=>$lowConfidenceEvidence,'tenant_id'=>$tenant->id,'lead_insight_id'=>$lowConfidenceInsight,'evidence_type'=>'website_issue',
            'source_url'=>'https://ebarza.com/','excerpt'=>'Low confidence evidence.','observed_at'=>now(),'confidence'=>.5,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>[
                'source'=>'lead_evidence','id'=>$finding,'url'=>'https://ebarza.com/','evidence_type'=>'website_issue',
                'source_id'=>$unrelatedUuid,'page_id'=>(string)Str::uuid(),'excerpt'=>'meta name="viewport" content="width=device-width"',
            ]],
            'outdated_website'=>['status'=>'POSITIVE','evidence_reference'=>[
                'source'=>'website_issue','id'=>(string)Str::uuid(),'url'=>'https://ebarza.com/','evidence_type'=>'website_issue',
                'source_id'=>$unrelatedUuid,'page_id'=>(string)Str::uuid(),'observed'=>'meta name="viewport" content="width=device-width"',
            ]],
            'technology_opportunity'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'lead_evidence','id'=>$lowConfidenceEvidence]],
            'no_whatsapp'=>['status'=>'NEGATIVE','evidence_reference'=>$finding],
            'no_crm'=>['status'=>'UNKNOWN','evidence_reference'=>$finding],
        ])]);

        $provider=$this->provider($tenant->id,$finding);
        $queries=[];
        DB::listen(function($query)use(&$queries):void{
            if(str_contains($query->sql,'lead_evidence'))$queries[]=['sql'=>$query->sql,'bindings'=>$query->bindings];
        });
        Sanctum::actingAs($user);
        $workspace=$this->withHeaders(['X-Tenant-ID'=>$tenant->id])->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk()->json();

        self::assertSame([$finding],array_column($workspace['findings'],'id'));
        self::assertSame(['poor_mobile_ux'],array_column($workspace['findings'],'type'));
        $evidenceQuery=collect($queries)->first(fn(array $query):bool=>str_contains($query['sql'],'lead_evidence')&&str_contains($query['sql'],' in '));
        self::assertNotNull($evidenceQuery,'The canonical lead evidence query should execute.');
        $normalizedSql=str_replace('"','',$evidenceQuery['sql']);
        self::assertStringContainsString('lead_evidence.tenant_id',$normalizedSql);
        self::assertStringContainsString('lead_insights.tenant_id',$normalizedSql);
        self::assertStringContainsString('lead_insights.company_id',$normalizedSql);
        self::assertMatchesRegularExpression('/lead_evidence.*id.*\s+in\s*\(\?\)/',$normalizedSql);
        self::assertSame([$tenant->id,$tenant->id,$company->id,$finding,0.7],$evidenceQuery['bindings'],
            'The ID filter must receive only the canonical evidence ID; tenant/company/confidence scoping must remain.');
        self::assertNotContains($lowConfidenceEvidence,array_column($workspace['findings'],'id'));
        self::assertNotContains('https://ebarza.com/',$evidenceQuery['bindings']);
        self::assertNotContains('website_issue',$evidenceQuery['bindings']);
        self::assertNotContains('meta name="viewport" content="width=device-width"',$evidenceQuery['bindings']);
        self::assertNotContains($unrelatedUuid,$evidenceQuery['bindings']);
        self::assertSame(0.92,(float)$workspace['findings'][0]['confidence']);
        self::assertCount(0,$provider->requests,'Loading the workspace must not call AI.');
        $this->assertDatabaseCount('outbound_messages',0);
        $this->assertDatabaseCount('campaign_recipients',0);
        $this->assertDatabaseCount('campaign_events',0);
        $this->assertDatabaseCount('outreach_drafts',0);
        $this->assertDatabaseHas('lead_scores',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'score'=>60]);
    }

    public function test_unusable_provider_output_fails_safely_without_persisting_or_leaking_raw_output(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-invalid-output');
        $provider=$this->provider($tenant->id,$finding,['provider_response_secret'=>'should never be returned']);
        Sanctum::actingAs($user);
        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'invalid-outreach-output'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])
            ->assertUnprocessable();
        self::assertStringNotContainsString('should never be returned',$response->getContent());
        self::assertStringNotContainsString('provider_response_secret',$response->getContent());
        self::assertDatabaseCount('outreach_drafts',0);
        $this->assertDatabaseHas('agent_runs',['tenant_id'=>$tenant->id,'agent_key'=>'OutreachDraftAgent','status'=>'failed']);
        $this->assertDatabaseCount('outbound_messages',0);
        self::assertCount(1,$provider->requests);
    }

    private function fixture(string $slug): array
    {
        $tenant=Tenant::create(['id'=>(string)Str::uuid(),'name'=>$slug,'slug'=>$slug]);
        $user=User::create(['name'=>'Pilot user','email'=>$slug.'@example.test','password'=>'password']);$user->tenants()->attach($tenant->id,['role'=>'sales','status'=>'active']);
        $company=Company::create(['tenant_id'=>$tenant->id,'name'=>'Prospect Co','normalized_domain'=>$slug.'.test','status'=>'new']);
        $service=(string)Str::uuid();DB::table('tenant_services')->insert(['id'=>$service,'tenant_id'=>$tenant->id,'sku'=>'WEB-001','name'=>'Website Performance Optimization','description'=>'Website usability and performance','unit_price'=>1000,'currency'=>'INR','active'=>true,'approved_by'=>$user->id,'created_at'=>now(),'updated_at'=>now()]);
        $review=(string)Str::uuid();DB::table('company_human_sales_reviews')->insert(['id'=>$review,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'reviewer_id'=>$user->id,'decision'=>'pursue','selected_service_id'=>$service,'notes'=>'Keep the message concise.','reviewed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $finding=(string)Str::uuid();$insight=(string)Str::uuid();
        DB::table('lead_insights')->insert(['id'=>$insight,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'kind'=>'website','statement'=>'The mobile layout needs improvement.','confidence'=>.92,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_evidence')->insert(['id'=>$finding,'tenant_id'=>$tenant->id,'lead_insight_id'=>$insight,'evidence_type'=>'website_issue','source_url'=>'https://'.$slug.'.test/mobile','excerpt'=>'Layout overflows on a narrow viewport.','observed_at'=>now(),'confidence'=>.92,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_scores')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant->id,'company_id'=>$company->id,'score'=>60,'evaluation_status'=>'scored','evidence_coverage'=>50,
            'components'=>json_encode(['poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>$finding],'no_whatsapp'=>['status'=>'NEGATIVE','evidence_reference'=>$finding],'no_crm'=>['status'=>'UNKNOWN','evidence_reference'=>$finding]]),'rule_version'=>1,'scored_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('prompt_templates')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant->id,'agent_key'=>'OutreachDraftAgent','version'=>1,'system_instruction'=>'Use supplied evidence only.','template'=>'Write respectful business outreach.','schema_version'=>'outreach-v1','active'=>true,'status'=>'approved','created_at'=>now(),'updated_at'=>now()]);
        return [$tenant,$user,$company,$service,$finding];
    }

    private function provider(string $tenantId,string $finding,?array $output=null): StaticAIProvider
    {
        $provider=new StaticAIProvider($output ?? ['pitch_summary'=>'A measured opportunity.','recommended_channel'=>'contact_page','recommended_channel_reason'=>'No verified named decision maker is available.','email'=>['subject'=>'A thought on mobile usability','body'=>'We noticed a mobile layout detail that may be worth reviewing.'],'whatsapp'=>'','linkedin'=>'','follow_up_email'=>'','follow_up_short'=>'','evidence_used'=>[['finding_id'=>$finding,'summary'=>'Mobile layout overflows on a narrow viewport.']]]);
        $this->app->instance(AIModelRouter::class,new AIModelRouter([$provider],['outreach_drafting'=>['provider'=>'conversation-test','model'=>'test-model']]));
        return $provider;
    }
}
