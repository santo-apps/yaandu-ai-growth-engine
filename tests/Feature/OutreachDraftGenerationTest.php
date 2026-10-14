<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Outreach\OutreachDraftGroundingValidator;
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

    public function test_ebarza_h1_finding_allows_measured_observation_and_general_service_capability_only(): void
    {
        [$tenant,$user,$company,$service,$mobileFinding]=$this->fixture('outreach-ebarza-grounded');
        DB::table('companies')->where('tenant_id',$tenant->id)->where('id',$company->id)->update(['name'=>'Ebarza']);
        $insight=(string)Str::uuid();$evidence=(string)Str::uuid();
        $observation='Improving the presence and content of H1 headings on pages could enhance SEO and user clarity.';
        DB::table('lead_insights')->insert(['id'=>$insight,'tenant_id'=>$tenant->id,'company_id'=>$company->id,'kind'=>'business_fit',
            'statement'=>$observation,'confidence'=>.95,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_evidence')->insert(['id'=>$evidence,'tenant_id'=>$tenant->id,'lead_insight_id'=>$insight,'evidence_type'=>'website_content',
            'source_url'=>'https://ebarza.com/','excerpt'=>$observation,'observed_at'=>now(),'confidence'=>.95,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('lead_scores')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->update(['components'=>json_encode([
            'poor_mobile_ux'=>['status'=>'POSITIVE','evidence_reference'=>$mobileFinding],
            'strong_business_fit'=>['status'=>'POSITIVE','evidence_reference'=>['source'=>'business_insight','id'=>$insight]],
        ])]);
        $output=['pitch_summary'=>'An opportunity to improve H1 heading content could enhance SEO and user clarity.',
            'recommended_channel'=>'email','recommended_channel_reason'=>'A verified email is available.',
            'email'=>['subject'=>'An idea for H1 heading content','body'=>'We noticed an opportunity to improve H1 heading presence and content, which could enhance SEO and user clarity. Our Website Performance Optimization service can include performance and technical improvements where relevant. Would you be open to a brief discussion?'],
            'whatsapp'=>'A WhatsApp message for the prospect.','linkedin'=>'A LinkedIn message for the prospect.','follow_up_email'=>'','follow_up_short'=>'','evidence_used'=>[['finding_id'=>$insight,'summary'=>'Model supplied summary is not trusted.']]];
        $provider=$this->provider($tenant->id,$insight,$output);Sanctum::actingAs($user);

        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'ebarza-h1-grounded'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$insight]])->assertCreated();

        self::assertSame('contact_page',$response->json('draft_payload.recommended_channel'));
        self::assertSame('No verified direct email, WhatsApp, or LinkedIn channel is available. A public contact page is available.',
            $response->json('draft_payload.recommended_channel_reason'));
        self::assertSame('',$response->json('draft_payload.whatsapp'));
        self::assertSame('',$response->json('draft_payload.linkedin'));
        self::assertCount(1,$provider->requests,'Incorrect model channel output does not consume a grounding repair call.');
        self::assertSame([$insight],array_column($provider->requests[0]->evidence['prospect']['selected_validated_findings'],'id'));
        self::assertArrayNotHasKey('score_context',$provider->requests[0]->evidence['prospect']);
        self::assertArrayNotHasKey('human_sales_review',$provider->requests[0]->evidence['prospect']);
        self::assertSame('Website Performance Optimization',$provider->requests[0]->evidence['prospect']['selected_service_capability']['name']);
        self::assertSame([['finding_id'=>$insight,'summary'=>$observation]],$response->json('draft_payload.evidence_used'));
        self::assertDatabaseCount('outbound_messages',0);
        self::assertDatabaseCount('campaign_recipients',0);
        self::assertDatabaseCount('campaign_events',0);

        $unsafe=$output;
        $unsafe['email']['body']='We noticed your website needs speed, responsiveness and technical performance improvements. This could significantly enhance SEO.';
        $this->provider($tenant->id,$insight,$unsafe);
        $this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'ebarza-h1-unsupported'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$insight]])->assertUnprocessable();
        self::assertSame(1,DB::table('outreach_drafts')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->count());
        self::assertDatabaseCount('outbound_messages',0);
        self::assertDatabaseCount('campaign_recipients',0);
        self::assertDatabaseHas('company_human_sales_reviews',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'decision'=>'pursue','selected_service_id'=>$service]);
    }

    public function test_ebarza_grounding_validator_rejects_unselected_claims_in_every_prospect_message(): void
    {
        $validator=app(OutreachDraftGroundingValidator::class);
        $evidence='Improving the presence and content of H1 headings on pages could enhance SEO and user clarity.';
        $finding=['id'=>(string)Str::uuid(),'key'=>'strong_business_fit','label'=>'Strong business fit','summary'=>$evidence,'evidence'=>$evidence];
        $service=(object)['name'=>'Website Performance Optimization','description'=>'Website usability and performance'];
        $contactContext=[['channel'=>'contact_page','status'=>'publicly_listed','confidence'=>.95]];
        $base=['pitch_summary'=>'A measured opportunity to improve H1 headings and SEO.','recommended_channel'=>'contact_page',
            'recommended_channel_reason'=>'No verified direct email, WhatsApp, or LinkedIn channel is available. A public contact page is available.','email'=>['subject'=>'H1 heading content','body'=>'H1 heading content could enhance SEO and user clarity.'],
            'whatsapp'=>'','linkedin'=>'','follow_up_email'=>'','follow_up_short'=>'','evidence_used'=>[]];
        $validator->assertGrounded($base,[$finding],$service,'Ebarza',$contactContext);
        $capability='Our Website Performance Optimization service can include performance and technical improvements where relevant.';
        $base['linkedin']=$capability;
        $validator->assertGrounded($base,[$finding],$service,'Ebarza',$contactContext);
        $base['linkedin']='Website Performance Optimization is the selected service for this opportunity.';
        $validator->assertGrounded($base,[$finding],$service,'Ebarza',$contactContext);

        $claims=['We noticed poor mobile UX.','Your website has a responsiveness problem.','Your website is slow.',
            'We found a website speed problem.','We found a technical performance problem.','This could significantly enhance SEO.',
            'Ebarza has website performance problems.','Your website needs performance improvements.',
            'Improving speed and responsiveness would help.','Website Performance Optimization shows that Ebarza has poor performance.',
            'Our service description says Ebarza has website usability and performance problems.'];
        $fields=['pitch_summary','email.subject','email.body','whatsapp','linkedin','follow_up_email','follow_up_short'];
        foreach($fields as $field){
            foreach($claims as $claim){
                $candidate=$base;
                if($field==='pitch_summary')$candidate[$field]=$claim;
                elseif($field==='email.subject')$candidate['email']['subject']=$claim;
                elseif($field==='email.body')$candidate['email']['body']=$claim;
                else $candidate[$field]=$claim;
                try{$validator->assertGrounded($candidate,[$finding],$service,'Ebarza',$contactContext);self::fail("Unsupported claim was accepted in {$field}: {$claim}");}
                catch(\App\Outreach\OutreachDraftGroundingException $error){self::assertContains($error->reasonCode,['OUTREACH_GROUNDING_STRENGTH','OUTREACH_GROUNDING_TOPIC','OUTREACH_GROUNDING_PROSPECT_CLAIM']);}
            }
        }
    }

    public function test_grounding_validator_aggregates_deduplicated_reason_field_violations_without_text(): void
    {
        $validator=app(OutreachDraftGroundingValidator::class);
        $finding=['id'=>(string)Str::uuid(),'key'=>'strong_business_fit','label'=>'Strong business fit',
            'summary'=>'Improving the presence and content of H1 headings on pages could enhance SEO and user clarity.',
            'evidence'=>'No non-empty H1 heading was found.'];
        $service=(object)['name'=>'Website Performance Optimization','description'=>'General service capability.'];
        $draft=['pitch_summary'=>'This could significantly enhance SEO.','recommended_channel'=>'contact_page',
            'recommended_channel_reason'=>'No verified direct email, WhatsApp, or LinkedIn channel is available. A public contact page is available.',
            'email'=>['subject'=>'H1 heading content','body'=>'Ebarza has an issue that needs attention.'],
            'whatsapp'=>'','linkedin'=>'','follow_up_email'=>'','follow_up_short'=>'',
            'evidence_used'=>[]];

        $result=$validator->validate($draft,[$finding],$service,'Ebarza',[
            ['channel'=>'contact_page','status'=>'publicly_listed','confidence'=>.95],
        ]);

        self::assertFalse($result->valid);
        self::assertSame([
            ['reason_code'=>'OUTREACH_GROUNDING_STRENGTH','field'=>'pitch_summary'],
            ['reason_code'=>'OUTREACH_GROUNDING_PROSPECT_CLAIM','field'=>'email.body'],
        ],$result->violations);
        self::assertStringNotContainsString('Ebarza',json_encode($result->violations));
        self::assertStringNotContainsString('significantly',json_encode($result->violations));
    }

    public function test_channel_reason_is_grounded_only_in_verified_public_contact_context(): void
    {
        $validator=app(OutreachDraftGroundingValidator::class);
        $finding=['id'=>(string)Str::uuid(),'key'=>'strong_business_fit','label'=>'Strong business fit',
            'summary'=>'Improving H1 heading content could enhance SEO.','evidence'=>'Improving H1 heading content could enhance SEO.'];
        $service=(object)['name'=>'Website Performance Optimization','description'=>'General service capability.'];
        $context=[['channel'=>'contact_page','status'=>'publicly_listed','confidence'=>.95]];
        $draft=['pitch_summary'=>'H1 heading content could enhance SEO.','recommended_channel'=>'contact_page',
            'recommended_channel_reason'=>'No verified direct email, WhatsApp, or LinkedIn channel is available. A public contact page is available.',
            'email'=>['subject'=>'H1 content','body'=>'H1 heading content could enhance SEO.'],'whatsapp'=>'','linkedin'=>'',
            'follow_up_email'=>'','follow_up_short'=>'','evidence_used'=>[]];
        $validator->assertGrounded($draft,[$finding],$service,'Ebarza',$context);

        foreach ([
            ['channel'=>'contact_page','reason'=>'A verified email address is available.','field'=>'recommended_channel_reason'],
            ['channel'=>'email','reason'=>'No verified email address is available.','field'=>'recommended_channel'],
            ['channel'=>'contact_page','reason'=>'No verified direct channel is available; use the public contact page.','field'=>'recommended_channel_reason'],
            ['channel'=>'contact_page','reason'=>'The contact page is best because Ebarza has poor mobile UX.'],
        ] as $case) {
            $candidate=$draft;
            $candidate['recommended_channel']=$case['channel'];
            $candidate['recommended_channel_reason']=$case['reason'];
            try {
                $validator->assertGrounded($candidate,[$finding],$service,'Ebarza',$context);
                self::fail('Unsupported channel context was accepted: '.$case['reason']);
            } catch (\App\Outreach\OutreachDraftGroundingException $error) {
                self::assertSame('OUTREACH_GROUNDING_CHANNEL',$error->reasonCode);
                self::assertSame($case['field']??'recommended_channel_reason',$error->field);
            }
        }
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

        $provider=$this->provider($tenant->id,$issue,['pitch_summary'=>'Reviewed finding only.','recommended_channel'=>'contact_page','recommended_channel_reason'=>'Use the public contact page.',
            'email'=>['subject'=>'A note on mobile usability','body'=>'A mobile usability observation for your review.'],'whatsapp'=>'','linkedin'=>'','follow_up_email'=>'','follow_up_short'=>'',
            'evidence_used'=>[['finding_id'=>$issue,'summary'=>'The viewport metadata disables user zoom on mobile.']]]);
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
        self::assertSame([$issue],array_column($provider->requests[0]->evidence['prospect']['selected_validated_findings'],'id'));
        $persisted=json_decode(DB::table('outreach_drafts')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->value('validated_findings'),true);
        self::assertSame('poor_mobile_ux',$persisted[0]['key']);
        self::assertSame($issue,$persisted[0]['evidence_reference']['id']);
        self::assertDatabaseCount('outbound_messages',0);
        self::assertDatabaseCount('campaign_recipients',0);
        self::assertDatabaseCount('campaign_events',0);
        $this->assertDatabaseHas('company_human_sales_reviews',['tenant_id'=>$tenant->id,'company_id'=>$company->id,'decision'=>'pursue']);
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

    public function test_ebarza_grounding_failure_is_repaired_once_with_same_selected_context_and_accounted_calls(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-ebarza-repair');
        $finding=$this->makeEbarzaH1Finding($tenant->id,$company->id,$finding);
        $rejected='Ebarza has an issue that needs attention.';
        $firstPitch='This could significantly enhance SEO.';
        $provider=new SequencedAIProvider([
            $this->ebarzaDraft($finding,$firstPitch,emailBody:$rejected),
            $this->ebarzaDraft($finding,'Ebarza could benefit from reviewing the H1 heading structure on pages where no non-empty H1 was found. Website Performance Optimization is the selected service for this opportunity.'),
        ]);
        $this->bindSequencedProvider($tenant->id,$provider);
        Log::spy();
        Sanctum::actingAs($user);

        $response=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'ebarza-grounding-repair'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])
            ->assertCreated();

        self::assertCount(2,$provider->requests);
        self::assertSame($provider->requests[0]->evidence,$provider->requests[1]->evidence);
        self::assertSame($provider->requests[0]->tenantId,$provider->requests[1]->tenantId);
        self::assertSame($provider->requests[0]->correlationId,$provider->requests[1]->correlationId,
            'Both independently accounted provider calls remain associated with the same workflow correlation.');
        self::assertStringContainsString('OUTREACH_GROUNDING_STRENGTH: pitch_summary',$provider->requests[1]->systemInstruction);
        self::assertStringContainsString('OUTREACH_GROUNDING_PROSPECT_CLAIM: email.body',$provider->requests[1]->systemInstruction);
        self::assertStringContainsString('email.body',$provider->requests[1]->systemInstruction);
        self::assertStringNotContainsString($rejected,$provider->requests[1]->systemInstruction,
            'Rejected generated text must never be echoed into the repair request.');
        self::assertStringNotContainsString($firstPitch,$provider->requests[1]->systemInstruction,
            'Rejected generated text must never be echoed into the repair request.');
        self::assertStringContainsString('significant, significantly, major, substantial, poor, slow, weak, broken, or lacking',$provider->requests[1]->systemInstruction);
        self::assertSame($provider->requests[0]->outputSchema,$provider->requests[1]->outputSchema);
        self::assertSame(1800,$provider->requests[1]->maxOutputTokens);
        self::assertSame(0.3,$provider->requests[1]->temperature);
        self::assertSame(1,DB::table('outreach_drafts')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->count());
        self::assertSame(2,DB::table('ai_usage_records')->where('tenant_id',$tenant->id)->where('task_key','outreach_drafting')->count());
        self::assertSame(2,DB::table('ai_usage_records')->where('tenant_id',$tenant->id)->where('task_key','outreach_drafting')->where('status','COMPLETED')->count());
        $usageRows=DB::table('ai_usage_records')->where('tenant_id',$tenant->id)->where('task_key','outreach_drafting')->orderBy('created_at')->get();
        self::assertCount(2,$usageRows);
        self::assertCount(2,$usageRows->pluck('idempotency_key')->unique(),'Each model call receives its own usage/idempotency record.');
        self::assertCount(1,$usageRows->pluck('correlation_id')->unique(),'Both calls remain correlated with the same agent workflow.');
        self::assertSame([1349,1349],$usageRows->pluck('input_tokens')->map(fn($tokens)=>(int)$tokens)->all());
        self::assertSame([352,352],$usageRows->pluck('output_tokens')->map(fn($tokens)=>(int)$tokens)->all());
        self::assertSame(0,(int)DB::table('ai_usage_records')->where('tenant_id',$tenant->id)->where('task_key','outreach_drafting')->sum('reserved_tokens'));
        self::assertSame(2698,(int)DB::table('ai_usage_records')->where('tenant_id',$tenant->id)->where('task_key','outreach_drafting')->sum('input_tokens'));
        self::assertSame(704,(int)DB::table('ai_usage_records')->where('tenant_id',$tenant->id)->where('task_key','outreach_drafting')->sum('output_tokens'));
        self::assertDatabaseCount('outbound_messages',0);
        self::assertDatabaseCount('campaign_recipients',0);
        self::assertDatabaseCount('campaign_events',0);
        Log::shouldHaveReceived('notice')->once()->withArgs(function(string $message,array $context):bool {
            return $message==='Outreach draft grounding repair finished.'
                && $context===['repair_attempted'=>true,
                    'original_violations'=>[
                        ['reason_code'=>'OUTREACH_GROUNDING_STRENGTH','field'=>'pitch_summary'],
                        ['reason_code'=>'OUTREACH_GROUNDING_PROSPECT_CLAIM','field'=>'email.body'],
                    ],
                    'final_outcome'=>'accepted','remaining_violations'=>[]];
        });
        self::assertSame('Ebarza could benefit from reviewing the H1 heading structure on pages where no non-empty H1 was found. Website Performance Optimization is the selected service for this opportunity.',
            json_decode(DB::table('outreach_drafts')->where('tenant_id',$tenant->id)->value('draft_payload'),true)['pitch_summary']);
        self::assertSame(201,$response->status());
    }

    public function test_second_grounding_failure_fails_safely_without_persisting_a_draft(): void
    {
        [$tenant,$user,$company,,$finding]=$this->fixture('outreach-ebarza-repair-fails');
        $finding=$this->makeEbarzaH1Finding($tenant->id,$company->id,$finding);
        Log::spy();
        $provider=new SequencedAIProvider([
            $this->ebarzaDraft($finding,'This could significantly enhance SEO.',emailBody:'Ebarza has an issue that needs attention.'),
            $this->ebarzaDraft($finding,'This could substantially enhance SEO.'),
        ]);
        $this->bindSequencedProvider($tenant->id,$provider);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'ebarza-grounding-repair-fails'])
            ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]])->assertUnprocessable();

        self::assertCount(2,$provider->requests,'Grounding repair is bounded to one attempt.');
        self::assertDatabaseCount('outreach_drafts',0);
        self::assertSame(2,DB::table('ai_usage_records')->where('tenant_id',$tenant->id)->where('task_key','outreach_drafting')->where('status','COMPLETED')->count());
        Log::shouldHaveReceived('warning')->withArgs(function(string $message,array $context):bool {
            return $message==='Outreach draft grounding repair finished.'
                && $context===['repair_attempted'=>true,
                    'original_violations'=>[
                        ['reason_code'=>'OUTREACH_GROUNDING_STRENGTH','field'=>'pitch_summary'],
                        ['reason_code'=>'OUTREACH_GROUNDING_PROSPECT_CLAIM','field'=>'email.body'],
                    ],
                    'final_outcome'=>'rejected',
                    'remaining_violations'=>[
                        ['reason_code'=>'OUTREACH_GROUNDING_STRENGTH','field'=>'pitch_summary'],
                    ]];
        })->once();
        self::assertStringContainsString('OUTREACH_GROUNDING_STRENGTH: pitch_summary',$provider->requests[1]->systemInstruction);
        self::assertStringContainsString('OUTREACH_GROUNDING_PROSPECT_CLAIM: email.body',$provider->requests[1]->systemInstruction);
        $this->assertDatabaseCount('outbound_messages',0);
        $this->assertDatabaseCount('campaign_recipients',0);
        $this->assertDatabaseCount('campaign_events',0);
    }

    public function test_grounding_repair_still_rejects_unsupported_prospect_topics(): void
    {
        $badRepairs=[
            'Your site has speed problems.',
            'Your website has responsiveness problems.',
            'Your site has poor mobile UX.',
            'Ebarza has performance problems.',
            'This could significantly enhance SEO.',
        ];

        foreach($badRepairs as $index=>$badPitch){
            [$tenant,$user,$company,,$finding]=$this->fixture('outreach-repair-rejected-'.$index);
            $finding=$this->makeEbarzaH1Finding($tenant->id,$company->id,$finding);
            $provider=new SequencedAIProvider([
                $this->ebarzaDraft($finding,'This could substantially enhance SEO.'),
                $this->ebarzaDraft($finding,$badPitch),
            ]);
            $this->bindSequencedProvider($tenant->id,$provider);
            Sanctum::actingAs($user);
            $repairResponse=$this->withHeaders(['X-Tenant-ID'=>$tenant->id,'Idempotency-Key'=>'repair-reject-'.$index])
                ->postJson('/api/v1/companies/'.$company->id.'/outreach-drafts',['finding_ids'=>[$finding]]);
            self::assertSame(422,$repairResponse->status(),'Unexpected repair acceptance for test case: '.$badPitch.'; '.$repairResponse->getContent());
            self::assertCount(2,$provider->requests);
            self::assertSame(0,DB::table('outreach_drafts')->where('tenant_id',$tenant->id)->where('company_id',$company->id)->count());
            self::assertDatabaseCount('outbound_messages',0);
            self::assertDatabaseCount('campaign_recipients',0);
            self::assertDatabaseCount('campaign_events',0);
        }

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

    private function ebarzaDraft(string $findingId,string $pitchSummary,?string $channelReason=null,?string $emailBody=null):array
    {
        return ['pitch_summary'=>$pitchSummary,'recommended_channel'=>'contact_page',
            'recommended_channel_reason'=>$channelReason??'No verified direct channel is available; use the public contact page.',
            'email'=>['subject'=>'A note on H1 headings','body'=>$emailBody??'We noticed an opportunity to review H1 heading usage on pages where no non-empty H1 heading was found.'],
            'whatsapp'=>'','linkedin'=>'','follow_up_email'=>'','follow_up_short'=>'',
            'evidence_used'=>[['finding_id'=>$findingId,'summary'=>'Improving the presence and content of H1 headings on pages could enhance SEO and user clarity.']]];
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

    private function provider(string $tenantId,string $finding,?array $output=null): StaticAIProvider
    {
        $provider=new StaticAIProvider($output ?? ['pitch_summary'=>'A measured opportunity.','recommended_channel'=>'contact_page','recommended_channel_reason'=>'No verified direct channel is available; use the public contact page.','email'=>['subject'=>'A thought on mobile usability','body'=>'We noticed a mobile layout detail that may be worth reviewing.'],'whatsapp'=>'','linkedin'=>'','follow_up_email'=>'','follow_up_short'=>'','evidence_used'=>[['finding_id'=>$finding,'summary'=>'Mobile layout overflows on a narrow viewport.']]]);
        $this->app->instance(AIModelRouter::class,new AIModelRouter([$provider],['outreach_drafting'=>['provider'=>'conversation-test','model'=>'test-model']]));
        return $provider;
    }
}
