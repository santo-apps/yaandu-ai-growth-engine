<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\StaticAIProvider;
use Tests\Fakes\SequencedAIProvider;
use Tests\TestCase;

final class OutreachDraftGenerationTest extends TestCase
{
    public function test_outreach_readiness_allows_deterministic_composition_without_optional_ai_route(): void
    {
        [$tenant, $owner, $company] = $this->fixture('outreach-readiness-route');
        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Tenant-ID' => $tenant->id])->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk();
        self::assertTrue($response->json('eligible'), 'The existing sales eligibility contract remains Pursue plus approved service.');
        self::assertTrue($response->json('generation_ready'));
        self::assertFalse(collect($response->json('readiness.checks'))->firstWhere('key', 'route')['ready']);
        self::assertFalse(collect($response->json('readiness.checks'))->firstWhere('key', 'credential')['ready']);
        self::assertTrue(collect($response->json('readiness.checks'))->firstWhere('key', 'route')['optional']);
        self::assertTrue(collect($response->json('readiness.checks'))->firstWhere('key', 'prompt')['ready']);

        $this->app->instance(AIModelRouter::class, new AIModelRouter([], []));
        $draft = $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'no-ai-route-fallback'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts', ['finding_ids' => [$response->json('findings.0.id')]])
            ->assertCreated();
        self::assertSame('deterministic', $draft->json('provider'));
        self::assertDatabaseCount('outreach_drafts', 1);
        self::assertDatabaseCount('ai_usage_records', 0);
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
        self::assertSame('outreach_drafting',$provider->requests[0]->task);
        self::assertCount(1,$provider->requests[0]->evidence['selected_findings']);
        self::assertSame(['tone','opening_variant','cta_variant','service_mention','finding_order'],array_keys($provider->requests[0]->outputSchema['properties']));
        self::assertArrayNotHasKey('email',$provider->requests[0]->outputSchema['properties']);
        self::assertStringContainsString('do not write sentences',strtolower($provider->requests[0]->systemInstruction));
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

    public function test_ebarza_h1_finding_is_composed_deterministically_from_selected_evidence(): void
    {
        [$tenant,$user,$company,$service,$unused]=$this->fixture('outreach-ebarza-grounded');
        DB::table('companies')->where('tenant_id',$tenant->id)->where('id',$company->id)->update(['name'=>'Ebarza']);
        $insight=(string)Str::uuid();$evidence=(string)Str::uuid();
        $summary='Improving the presence and content of H1 headings on pages could enhance SEO and user clarity.';
        DB::table('lead_insights')->insert(['id'=>$insight,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'kind'=>'business_fit','statement'=>$summary,'confidence'=>.95,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_evidence')->insert(['id'=>$evidence,'tenant_id'=>$tenant->id,'lead_insight_id'=>$insight,'evidence_type'=>'website_content','source_url'=>'https://ebarza.com/','excerpt'=>'No non-empty H1 heading was found.','observed_at'=>now(),'confidence'=>.95,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'strong_business_fit'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'business_insight','id'=>$insight]],
        ])]);
        $provider=$this->provider($tenant->id,$insight,$this->stylePlan([$insight]));
        Log::spy();Sanctum::actingAs($user);

        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'ebarza-h1-deterministic'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$insight]])->assertCreated();
        $payload=$response->json('draft_payload');
        self::assertSame('contact_page',$payload['recommended_channel']);
        self::assertSame('No verified direct email, WhatsApp, or LinkedIn channel is available. A public contact page is available.',$payload['recommended_channel_reason']);
        self::assertSame('',$payload['whatsapp']);self::assertSame('',$payload['linkedin']);
        self::assertSame(1,count($provider->requests));
        self::assertSame(['tone','opening_variant','cta_variant','service_mention','finding_order'],array_keys($provider->requests[0]->outputSchema['properties']));
        self::assertSame($insight,$provider->requests[0]->evidence['selected_findings'][0]['finding_id']);
        self::assertStringNotContainsString('No non-empty H1 heading was found',$provider->requests[0]->systemInstruction);
        self::assertStringContainsString('No non-empty H1 heading was found.',$payload['email']['body']);
        self::assertStringContainsString('could enhance SEO and user clarity.',$payload['email']['body']);
        self::assertStringContainsString('Our Website Performance Optimization work can support this type of improvement where relevant.',$payload['email']['body']);
        foreach (['speed problem','responsiveness problem','poor mobile UX','performance problems','significantly'] as $unsupported) {
            self::assertStringNotContainsString($unsupported,strtolower(json_encode($payload)));
        }
        self::assertSame([['finding_id'=>$insight,'summary'=>$summary]],$payload['evidence_used']);
        self::assertDatabaseCount('outreach_drafts',1);self::assertDatabaseCount('outbound_messages',0);
        self::assertDatabaseCount('campaign_recipients',0);self::assertDatabaseCount('campaign_events',0);
        self::assertDatabaseHas('company_human_sales_reviews',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'decision'=>'pursue','selected_service_id'=>$service]);
        self::assertDatabaseHas('ai_usage_records',['tenant_id'=>$tenant->id,'task_key'=>'outreach_drafting','status'=>'COMPLETED']);
        Log::shouldHaveReceived('info')->withArgs(fn(string $message,array $context):bool =>
            $message==='Outreach draft composed.' && $context['composition_mode']==='ai_style_plan'
            && $context['selected_finding_count']===1 && $context['recommended_channel']==='contact_page'
            && $context['style_plan_valid']===true && !array_key_exists('draft',$context))->once();
    }
    public function test_channel_resolver_uses_only_supported_contact_context_and_deterministic_fallbacks(): void
    {
        $resolver=app(\App\Outreach\OutreachChannelResolver::class);
        $cases=[
            [[['channel'=>'email','status'=>'verified']], 'email','An email channel is verified.'],
            [[['channel'=>'whatsapp','status'=>'publicly_listed']], 'whatsapp','A WhatsApp channel is publicly listed.'],
            [[['channel'=>'linkedin','status'=>'verified']], 'linkedin','A LinkedIn channel is verified.'],
            [[['channel'=>'phone','status'=>'verified']], 'manual_contact','A phone contact is verified. Use manual contact.'],
            [[['channel'=>'contact_page','status'=>'publicly_listed']], 'contact_page','No verified direct email, WhatsApp, or LinkedIn channel is available. A public contact page is available.'],
            [[], 'none','No verified direct contact channel or public contact page is available.'],
        ];
        foreach($cases as [$context,$channel,$reason]){
            self::assertSame(['recommended_channel'=>$channel,'recommended_channel_reason'=>$reason],$resolver->resolve($context));
            self::assertTrue($resolver->isAvailable($channel==='manual_contact'?'phone':$channel,$context)||$channel==='none');
        }
        self::assertSame('email',$resolver->resolve([
            ['channel'=>'contact_page','status'=>'publicly_listed'],['channel'=>'linkedin','status'=>'publicly_listed'],
            ['channel'=>'email','status'=>'verified'],['channel'=>'whatsapp','status'=>'verified'],
        ])['recommended_channel'],'Existing supported direct channels use stable priority before contact page.');
        self::assertSame('contact_page',$resolver->resolve([
            ['channel'=>'email','status'=>'unverified'],['channel'=>'whatsapp','status'=>'unavailable'],
            ['channel'=>'linkedin','status'=>'pending'],['channel'=>'contact_page','status'=>'publicly_listed'],
        ])['recommended_channel'],'Unverified methods cannot become available recommendations.');
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
        $evidenceQuery=collect($queries)->first(fn(array $query):bool=>str_contains($query['sql'],'lead_evidence')&&str_contains($query['sql'],'lead_insights')&&str_contains($query['sql'],'confidence'));
        self::assertNotNull($evidenceQuery,'The canonical lead evidence query should execute.');
        $normalizedSql=str_replace('"','',$evidenceQuery['sql']);
        self::assertStringContainsString('lead_evidence.tenant_id',$normalizedSql);
        self::assertStringContainsString('lead_insights.tenant_id',$normalizedSql);
        self::assertStringContainsString('lead_insights.company_id',$normalizedSql);
        self::assertMatchesRegularExpression('/lead_evidence.*id.*=\s*\?/',$normalizedSql);
        self::assertSame([$finding,$tenant->id,$tenant->id,$company->id,0.7,''],$evidenceQuery['bindings'],
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

    public function test_ebarza_shaped_workspace_offers_only_verified_positive_issue_and_business_insight_findings(): void
    {
        [$tenant,$user,$company]=$this->fixture('outreach-ebarza-candidates');
        $website=(string)Str::uuid();$scan=(string)Str::uuid();$page=(string)Str::uuid();$issue=(string)Str::uuid();
        DB::table('company_websites')->insert(['id'=>$website,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'url'=>'https://ebarza.com/','host'=>'ebarza.com','verification_status'=>'verified','created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_scans')->insert(['id'=>$scan,'tenant_id'=>$tenant->id,'company_website_id'=>$website,'status'=>'completed','max_depth'=>2,'max_pages'=>10,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_pages')->insert(['id'=>$page,'tenant_id'=>$tenant->id,'website_scan_id'=>$scan,'requested_url'=>'https://ebarza.com/','final_url'=>'https://ebarza.com/','http_status'=>200,'content_type'=>'text/html','title'=>'Ebarza','created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_issues')->insert(['id'=>$issue,'tenant_id'=>$tenant->id,'website_scan_id'=>$scan,'website_page_id'=>$page,'type'=>'poor_mobile_ux','severity'=>'medium',
            'summary'=>'The viewport metadata disables user zoom on mobile.','evidence'=>json_encode(['source_url'=>'https://ebarza.com/','excerpt'=>'meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"']),
            'confidence'=>.94,'detector_version'=>'ai-v1','created_at'=>now(),'updated_at'=>now()]);

        $insight=(string)Str::uuid();$support=(string)Str::uuid();
        DB::table('lead_insights')->insert(['id'=>$insight,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'kind'=>'opportunity','statement'=>'Ebarza is a strong business fit for Yaandu services.','confidence'=>.91,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_evidence')->insert(['id'=>$support,'tenant_id'=>$tenant->id,'lead_insight_id'=>$insight,'evidence_type'=>'website_page','source_url'=>'https://ebarza.com/about',
            'excerpt'=>'Ebarza operates a regional B2B commerce business.','observed_at'=>now(),'confidence'=>.9,'created_at'=>now(),'updated_at'=>now()]);

        $otherCompany=(string)Str::uuid();$otherWebsite=(string)Str::uuid();$otherScan=(string)Str::uuid();$otherIssue=(string)Str::uuid();
        DB::table('companies')->insert(['id'=>$otherCompany,'tenant_id'=>$tenant->id,'name'=>'Other Company','normalized_domain'=>'other-company.test','status'=>'new','created_at'=>now(),'updated_at'=>now()]);
        DB::table('company_websites')->insert(['id'=>$otherWebsite,'tenant_id'=>$tenant->id,'company_id'=>$otherCompany,'url'=>'https://other-company.test/','host'=>'other-company.test','created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_scans')->insert(['id'=>$otherScan,'tenant_id'=>$tenant->id,'company_website_id'=>$otherWebsite,'status'=>'completed','max_depth'=>2,'max_pages'=>10,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_issues')->insert(['id'=>$otherIssue,'tenant_id'=>$tenant->id,'website_scan_id'=>$otherScan,'type'=>'outdated_website','severity'=>'medium',
            'summary'=>'This belongs to a different company.','evidence'=>json_encode(['excerpt'=>'Other company evidence.']),'confidence'=>.95,'detector_version'=>'ai-v1','created_at'=>now(),'updated_at'=>now()]);

        $wrongPageIssue=(string)Str::uuid();$lowConfidenceIssue=(string)Str::uuid();$pendingScan=(string)Str::uuid();$pendingIssue=(string)Str::uuid();
        DB::table('website_issues')->insert(['id'=>$wrongPageIssue,'tenant_id'=>$tenant->id,'website_scan_id'=>$scan,'website_page_id'=>$page,'type'=>'poor_lead_capture','severity'=>'medium',
            'summary'=>'Reference page does not match.','evidence'=>json_encode(['excerpt'=>'This reference must not be offered.']),'confidence'=>.95,'detector_version'=>'ai-v1','created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_issues')->insert(['id'=>$lowConfidenceIssue,'tenant_id'=>$tenant->id,'website_scan_id'=>$scan,'website_page_id'=>$page,'type'=>'digital_opportunity','severity'=>'medium',
            'summary'=>'Confidence is below threshold.','evidence'=>json_encode(['excerpt'=>'Low confidence issue.']),'confidence'=>.69,'detector_version'=>'ai-v1','created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_scans')->insert(['id'=>$pendingScan,'tenant_id'=>$tenant->id,'company_website_id'=>$website,'status'=>'queued','max_depth'=>2,'max_pages'=>10,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_issues')->insert(['id'=>$pendingIssue,'tenant_id'=>$tenant->id,'website_scan_id'=>$pendingScan,'type'=>'service_relevance','severity'=>'medium',
            'summary'=>'Issue from a scan that is not complete.','evidence'=>json_encode(['excerpt'=>'Pending scan evidence.']),'confidence'=>.95,'detector_version'=>'ai-v1','created_at'=>now(),'updated_at'=>now()]);

        [$otherTenant]=$this->fixture('outreach-other-tenant-issue');
        $foreignWebsite=(string)Str::uuid();$foreignScan=(string)Str::uuid();$foreignIssue=(string)Str::uuid();
        DB::table('company_websites')->insert(['id'=>$foreignWebsite,'tenant_id'=>$otherTenant->id,'company_id'=>DB::table('companies')->where('tenant_id',$otherTenant->id)->value('id'),
            'url'=>'https://foreign-company.test/','host'=>'foreign-company.test','created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_scans')->insert(['id'=>$foreignScan,'tenant_id'=>$otherTenant->id,'company_website_id'=>$foreignWebsite,'status'=>'completed','max_depth'=>2,'max_pages'=>10,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('website_issues')->insert(['id'=>$foreignIssue,'tenant_id'=>$otherTenant->id,'website_scan_id'=>$foreignScan,'type'=>'technology_opportunity','severity'=>'medium',
            'summary'=>'Foreign tenant issue.','evidence'=>json_encode(['excerpt'=>'Must not cross tenant boundary.']),'confidence'=>.95,'detector_version'=>'ai-v1','created_at'=>now(),'updated_at'=>now()]);

        $unrelated=(string)Str::uuid();
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$issue,'page_id'=>$page,'url'=>'https://ebarza.com/','observed'=>'viewport excerpt']],
            'strong_business_fit'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'business_insight','id'=>$insight,'url'=>'https://ebarza.com/']],
            'outdated_website'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$otherIssue]],
            'poor_lead_capture'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$wrongPageIssue,'page_id'=>(string)Str::uuid()]],
            'digital_opportunity'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$lowConfidenceIssue]],
            'service_relevance'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$pendingIssue]],
            'technology_opportunity'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$foreignIssue]],
            'no_whatsapp'=>['status'=>'NEGATIVE','evidence_reference'=>['source'=>'website_issue','id'=>$issue]],
            'no_crm'=>['status'=>'UNKNOWN','evidence_reference'=>['source'=>'website_issue','id'=>$issue]],
        ])]);
        DB::table('ai_model_configurations')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant->id,'task_key'=>'outreach_drafting','provider'=>'conversation-test','model'=>'synthetic-test-model','enabled'=>true,
            'parameters'=>json_encode(['max_output_tokens'=>1800]),'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        config(['ai.providers.conversation-test.key'=>'synthetic-test-key']);

        $provider=$this->provider($tenant->id,$issue,$this->stylePlan([$issue]));
        Sanctum::actingAs($user);
        $workspace=$this->withHeaders(['X-Tenant-ID'=>$tenant->id])->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk()->json();

        self::assertSame(['poor_mobile_ux','strong_business_fit'],array_column($workspace['findings'],'key'));
        self::assertSame(['Poor mobile UX','Strong business fit'],array_column($workspace['findings'],'label'));
        self::assertSame(['website issue','business insight'],array_column($workspace['findings'],'source'));
        self::assertSame(['website_issue','business_insight'],array_column(array_column($workspace['findings'],'evidence_reference'),'source'));
        self::assertSame([$issue,$insight],array_column($workspace['findings'],'id'));
        self::assertSame(['website_issue','strong_business_fit'],array_column($workspace['findings'],'type'));
        self::assertSame('meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"',$workspace['findings'][0]['evidence']);
        self::assertTrue(collect($workspace['readiness']['checks'])->firstWhere('key','findings')['ready']);
        self::assertTrue($workspace['generation_ready']);
        self::assertCount(0,$provider->requests,'Readiness/workspace loading must not call AI.');
        self::assertSame(0,DB::table('outreach_drafts')->where('tenant_id',$tenant->id)->count(),'Loading candidate findings must not create an outreach record.');
        $this->assertDatabaseCount('outbound_messages',0);
        $this->assertDatabaseCount('campaign_recipients',0);
        self::assertDatabaseHas('lead_scores',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'score'=>60]);
        $this->assertDatabaseHas('company_human_sales_reviews',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'decision'=>'pursue']);

        DB::table('website_issues')->where('tenant_id',$tenant->id)->where('id',$issue)->update(['evidence'=>json_encode(['source_url'=>'https://ebarza.com/','marker'=>'internal detector marker'])]);
        $referenceExcerpt='meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"';
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$issue,'page_id'=>$page,'observed'=>$referenceExcerpt]],
            'strong_business_fit'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'business_insight','id'=>$insight]],
        ])]);
        $referenceEvidenceWorkspace=$this->withHeaders(['X-Tenant-ID'=>$tenant->id])->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk()->json();
        self::assertSame($referenceExcerpt,$referenceEvidenceWorkspace['findings'][0]['evidence'],'Use only the explicit scorer observation when the stored issue payload has no reviewer-safe excerpt.');
        self::assertStringNotContainsString('internal detector marker',$referenceEvidenceWorkspace['findings'][0]['evidence']);
        DB::table('website_issues')->where('tenant_id',$tenant->id)->where('id',$issue)->update(['evidence'=>json_encode(['source_url'=>'https://ebarza.com/','excerpt'=>$referenceExcerpt])]);

        $unsupportedInsight=(string)Str::uuid();
        DB::table('lead_insights')->insert(['id'=>$unsupportedInsight,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'kind'=>'business_fit','statement'=>'Unsubstantiated fit claim.','confidence'=>.95,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$issue]],
            'strong_business_fit'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'business_insight','id'=>$unsupportedInsight]],
        ])]);
        $unsupportedWorkspace=$this->withHeaders(['X-Tenant-ID'=>$tenant->id])->getJson('/api/v1/companies/'.$company->id.'/outreach-drafts')->assertOk()->json();
        self::assertSame(['poor_mobile_ux'],array_column($unsupportedWorkspace['findings'],'key'),'Business fit without supporting lead evidence is excluded.');
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'website_issue','id'=>$issue]],
            'strong_business_fit'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'business_insight','id'=>$insight]],
        ])]);

        $generation=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'ebarza-selected-mobile'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$issue]]);
        self::assertSame(201,$generation->status(),$generation->getContent().' '.json_encode(DB::table('agent_runs')->where('tenant_id',$tenant->id)->get(['agent_key','status','error_summary'])));
        self::assertCount(1,$provider->requests,'The fake provider is called only after explicit Generate Draft action.');
        self::assertSame([$issue],array_column($provider->requests[0]->evidence['selected_findings'],'finding_id'));
        $persisted=json_decode(DB::table('outreach_drafts')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->value('validated_findings'),true);
        self::assertSame('poor_mobile_ux',$persisted[0]['key']);
        self::assertSame($issue,$persisted[0]['evidence_reference']['id']);
        self::assertDatabaseCount('outbound_messages',0);
        self::assertDatabaseCount('campaign_recipients',0);
        self::assertDatabaseCount('campaign_events',0);
        $this->assertDatabaseHas('company_human_sales_reviews',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'decision'=>'pursue']);
        $this->assertDatabaseHas('lead_scores',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'score'=>60]);
    }

    public function test_invalid_ai_style_schema_uses_deterministic_fallback_without_leaking_provider_data(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-invalid-style');
        $provider=$this->provider($tenant->id,$finding,['provider_response_secret'=>'should never be returned']);
        Sanctum::actingAs($user);
        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'invalid-outreach-style'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertCreated();
        self::assertSame('deterministic',$response->json('provider'));
        self::assertSame('deterministic-style-plan-v1',$response->json('model'));
        self::assertStringNotContainsString('provider_response_secret',$response->getContent());
        self::assertStringNotContainsString('should never be returned',$response->getContent());
        self::assertCount(1,$provider->requests);
        self::assertDatabaseCount('outreach_drafts',1);
        self::assertDatabaseHas('ai_usage_records',['tenant_id'=>$tenant->id,'task_key'=>'outreach_drafting','status'=>'FAILED']);
        self::assertDatabaseCount('outbound_messages',0);self::assertDatabaseCount('campaign_recipients',0);self::assertDatabaseCount('campaign_events',0);
    }

    public function test_provider_exception_uses_deterministic_fallback_and_preserves_failure_observability(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-style-provider-failure');
        $provider=new SequencedAIProvider([null]);$this->bindSequencedProvider($tenant->id,$provider);
        Log::spy();Sanctum::actingAs($user);
        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'style-provider-failure'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertCreated();
        self::assertSame('deterministic',$response->json('provider'));self::assertCount(1,$provider->requests);
        self::assertDatabaseCount('outreach_drafts',1);
        self::assertDatabaseHas('ai_usage_records',['tenant_id'=>$tenant->id,'task_key'=>'outreach_drafting','status'=>'FAILED']);
        Log::shouldHaveReceived('warning')->withArgs(fn(string $message,array $context):bool =>
            $message==='Outreach AI style planning unavailable; deterministic composition used.'
            && isset($context['failure_class']) && !isset($context['provider_message']))->once();
        self::assertDatabaseCount('outbound_messages',0);
    }

    public function test_invalid_ai_style_enum_uses_deterministic_fallback(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-style-invalid-enum');
        $provider=$this->provider($tenant->id,$finding,$this->stylePlan([$finding],['tone'=>'inventive']));
        Sanctum::actingAs($user);
        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'style-invalid-enum'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertCreated();
        self::assertSame('deterministic',$response->json('provider'));
        self::assertCount(1,$provider->requests);
        self::assertDatabaseCount('outreach_drafts',1);
        self::assertDatabaseHas('ai_usage_records',['tenant_id'=>$tenant->id,'task_key'=>'outreach_drafting','status'=>'FAILED']);
        self::assertDatabaseCount('outbound_messages',0);
    }

    public function test_unselected_ai_finding_order_is_rejected_and_defaults_to_reviewer_order(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-style-unselected-id');
        $plan=$this->stylePlan([$finding]);$plan['finding_order']=[(string)Str::uuid()];
        $provider=$this->provider($tenant->id,$finding,$plan);Sanctum::actingAs($user);
        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'style-unselected-id'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertCreated();
        self::assertSame('deterministic',$response->json('provider'));
        self::assertSame([$finding],array_column($response->json('draft_payload.evidence_used'),'finding_id'));
        self::assertCount(1,$provider->requests);self::assertDatabaseCount('outreach_drafts',1);
        self::assertDatabaseCount('outbound_messages',0);
    }

    public function test_ai_budget_prevents_style_call_but_deterministic_draft_succeeds(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-style-budget');
        $provider=$this->provider($tenant->id,$finding);
        DB::table('ai_usage_records')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant->id,'task_key'=>'outreach_drafting',
            'provider'=>'conversation-test','model'=>'test-model','status'=>'COMPLETED','idempotency_key'=>'prior-style-budget-call','created_at'=>now()]);
        config(['ai.daily_call_limit'=>1]);Sanctum::actingAs($user);
        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'style-budget-fallback'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertCreated();
        self::assertSame('deterministic',$response->json('provider'));self::assertCount(0,$provider->requests);
        self::assertDatabaseCount('outreach_drafts',1);self::assertDatabaseCount('ai_usage_records',1);
        self::assertDatabaseCount('outbound_messages',0);
    }

    public function test_ebarza_deterministic_composition_failure_paths_never_repair_or_send(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-ebarza-no-repair');
        $finding=$this->makeEbarzaH1Finding($tenant->id,$company->id,$finding);
        $provider=new SequencedAIProvider([$this->stylePlan([$finding]), null]);
        $this->bindSequencedProvider($tenant->id,$provider);Sanctum::actingAs($user);
        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'ebarza-one-call'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertCreated();
        self::assertCount(1,$provider->requests,'Draft generation never makes a prose repair call.');
        self::assertSame('contact_page',$response->json('draft_payload.recommended_channel'));
        self::assertDatabaseCount('outreach_drafts',1);self::assertDatabaseCount('outbound_messages',0);
        self::assertDatabaseCount('campaign_recipients',0);self::assertDatabaseCount('campaign_events',0);
    }
    private function makeEbarzaH1Finding(string $tenantId,string $companyId,string $findingId):string
    {
        DB::table('companies')->where('tenant_id',$tenantId)->where('id',$companyId)->update(['name'=>'Ebarza']);
        $insightId=DB::table('lead_evidence')->where('tenant_id',$tenantId)->where('id',$findingId)->value('lead_insight_id');
        DB::table('lead_insights')->where('tenant_id',$tenantId)->where('id',$insightId)->update([
            'kind'=>'opportunity',
            'statement'=>'Improving the presence and content of H1 headings on pages could enhance SEO and user clarity.',
        ]);
        DB::table('lead_evidence')->where('tenant_id',$tenantId)->where('id',$findingId)->update([
            'excerpt'=>'No non-empty H1 heading was found.',
        ]);
        DB::table('lead_scores')->where('tenant_id',$tenantId)->where('company_id',$companyId)->update(['components'=>json_encode([
            'strong_business_fit'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'business_insight','id'=>$insightId]],
        ])]);

        return (string)$insightId;
    }

    private function bindSequencedProvider(string $tenantId,SequencedAIProvider $provider):void
    {
        DB::table('ai_model_configurations')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenantId,'task_key'=>'outreach_drafting','provider'=>'conversation-test','model'=>'synthetic-test-model','enabled'=>true,
            'parameters'=>json_encode(['max_output_tokens'=>1800]),'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        config(['ai.providers.conversation-test.key'=>'synthetic-test-key']);
        $this->app->instance(AIModelRouter::class,new AIModelRouter([$provider],['outreach_drafting'=>['provider'=>'conversation-test','model'=>'synthetic-test-model']]));
    }

    private function fixture(string $slug): array
    {
        $tenant=Tenant::create(['id'=>(string)Str::uuid(),'name'=>$slug,'slug'=>$slug]);
        $user=User::create(['name'=>'Pilot user','email'=>$slug.'@example.test','password'=>'password']);$user->tenants()->attach($tenant->id,['role'=>'sales','status'=>'active']);
        $company=Company::create(['tenant_id'=>$tenant->id,'name'=>'Prospect Co','normalized_domain'=>$slug.'.test','status'=>'new']);
        $contact=(string)Str::uuid();DB::table('contacts')->insert(['id'=>$contact,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'name'=>null,
            'source_url'=>'https://'.$slug.'.test/contact','observed_at'=>now(),'extraction_method'=>'test_fixture','confidence'=>.95,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('contact_methods')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant->id,'contact_id'=>$contact,'type'=>'contact_page',
            'value'=>'https://'.$slug.'.test/contact','source_url'=>'https://'.$slug.'.test/contact','observed_at'=>now(),'extraction_method'=>'test_fixture',
            'confidence'=>.95,'verification_status'=>'publicly_listed','created_at'=>now(),'updated_at'=>now()]);
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

    private function stylePlan(array $findingIds, array $overrides=[]): array
    {
        return array_replace(['tone'=>'consultative','opening_variant'=>'brief_observation','cta_variant'=>'soft_discussion',
            'service_mention'=>'light','finding_order'=>array_values($findingIds)],$overrides);
    }

    private function provider(string $tenantId,string $finding,?array $output=null): StaticAIProvider
    {
        $provider=new StaticAIProvider($output ?? $this->stylePlan([$finding]));
        $this->app->instance(AIModelRouter::class,new AIModelRouter([$provider],['outreach_drafting'=>['provider'=>'conversation-test','model'=>'test-model']]));
        return $provider;
    }
}
