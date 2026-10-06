<?php

namespace Database\Seeders;

use App\AI\AIModelRouter;
use App\Agents\AgentOrchestrator;
use App\Marketing\GenerateMarketingDraftCommand;
use App\Marketing\MarketingDraftService;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\Tenant;
use App\Models\TenantService;
use App\Models\User;
use App\Orchestration\WorkflowService;
use App\Proposals\GenerateProposalCommand;
use App\Proposals\ProposalGenerationService;
use App\Proposals\ProposalStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Proposals\DecimalMoney;
use Tests\Fakes\VisualAcceptanceAIProvider;

/** Explicit local-only fixture seeder. Never called by normal application initialization. */
final class Sprint1B2VisualAcceptanceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Visual acceptance fixtures are permitted only when APP_ENV=local.');
        }

        if (Tenant::where('slug', 'sprint-1b-2-visual-qa')->exists()) {
            $this->command?->warn('Visual acceptance tenant already exists. Remove it first with: DELETE FROM tenants WHERE slug = sprint-1b-2-visual-qa;');
            return;
        }

        DB::transaction(function (): void {
            $tenant = Tenant::create(['name' => 'Sprint 1B-2 Visual QA (TEST FIXTURE)', 'slug' => 'sprint-1b-2-visual-qa', 'status' => 'active']);
            $owner = User::create(['name' => 'Visual QA Owner', 'email' => 'visual-qa-owner@example.test', 'password' => Hash::make('Visual-QA-Only-2026!')]);
            $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
            app()->instance('tenant.id', $tenant->id);

            $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'Acme Test Outfitters', 'normalized_domain' => 'acme-test-outfitters.fixture.test',
                'industry' => 'Outdoor Retail (fictional)', 'location' => 'Sample City', 'description' => 'Fictional company for local visual acceptance only.', 'source' => 'visual_fixture', 'status' => 'qualified']);
            $contactId = (string) Str::uuid();
            DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Riley Sample',
                'title' => 'Fictional Ecommerce Director', 'source_url' => 'https://acme-test-outfitters.fixture.test/team', 'observed_at' => now(),
                'extraction_method' => 'local_visual_fixture', 'confidence' => .99, 'created_at' => now(), 'updated_at' => now()]);

            $websiteId = (string) Str::uuid(); $scanId = (string) Str::uuid(); $pageId = (string) Str::uuid(); $issueId = (string) Str::uuid();
            $sourceUrl = 'https://acme-test-outfitters.fixture.test/contact';
            DB::table('company_websites')->insert(['id' => $websiteId, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'url' => 'https://acme-test-outfitters.fixture.test',
                'host' => 'acme-test-outfitters.fixture.test', 'canonical_url' => 'https://acme-test-outfitters.fixture.test', 'verification_status' => 'unverified', 'source' => 'visual_fixture', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_scans')->insert(['id' => $scanId, 'tenant_id' => $tenant->id, 'company_website_id' => $websiteId, 'status' => 'completed', 'max_depth' => 1,
                'max_pages' => 1, 'crawler_version' => 'fixture-only', 'policy_snapshot' => json_encode(['fixture' => true]), 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_pages')->insert(['id' => $pageId, 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId, 'requested_url' => $sourceUrl, 'final_url' => $sourceUrl,
                'canonical_url' => $sourceUrl, 'http_status' => 200, 'content_type' => 'text/html', 'title' => 'Contact Acme Test Outfitters', 'fetched_at' => now(),
                'content_hash' => hash('sha256', 'fixture:contact-page'), 'extracted_text' => 'TEST FIXTURE: fictional contact page with no visible post-submit confirmation or next step.', 'depth' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('website_issues')->insert(['id' => $issueId, 'tenant_id' => $tenant->id, 'website_scan_id' => $scanId, 'website_page_id' => $pageId,
                'type' => 'poor_lead_capture', 'severity' => 'medium', 'summary' => 'Sample contact page has no post-submit guidance.',
                'evidence' => json_encode(['excerpt' => 'TEST FIXTURE: no visible confirmation or next step.']), 'confidence' => .93, 'detector_version' => 'visual-fixture', 'created_at' => now(), 'updated_at' => now()]);
            $insightId = (string) Str::uuid(); $evidenceId = (string) Str::uuid();
            DB::table('lead_insights')->insert(['id' => $insightId, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'kind' => 'website_opportunity',
                'statement' => 'Fictional contact page could make post-submit expectations clearer.', 'confidence' => .93, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lead_evidence')->insert(['id' => $evidenceId, 'tenant_id' => $tenant->id, 'lead_insight_id' => $insightId, 'evidence_type' => 'website_issue',
                'source_url' => $sourceUrl, 'excerpt' => 'TEST FIXTURE: the sample form has no visible post-submit confirmation or next step.', 'content_hash' => hash('sha256', 'fixture:lead-evidence'),
                'observed_at' => now(), 'confidence' => .93, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lead_scores')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'score' => 82,
                'components' => json_encode(['icp_fit' => 10, 'industry' => 10, 'website_issue' => 15, 'public_contact' => 10]), 'rule_version' => 1,
                'scored_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            $service = TenantService::create(['tenant_id' => $tenant->id, 'sku' => 'FIX-WEB-REVIEW', 'name' => 'Website Enquiry Journey Review',
                'description' => 'A focused review of public-facing enquiry flow.', 'unit_price' => '12000.00', 'currency' => 'INR', 'active' => true,
                'category' => 'Website advisory', 'capabilities' => ['Enquiry-flow review'], 'standard_deliverables' => ['Enquiry journey review', 'Prioritized improvement brief'],
                'commercial_model' => 'FIXED_PRICE', 'unit' => 'project', 'approved_by' => $owner->id]);
            DB::table('tenant_pricing_policies')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'currency' => 'INR', 'max_discount_percent' => 10,
                'default_validity_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('tenant_automation_settings')->insert(['tenant_id' => $tenant->id, 'autonomy_mode' => 'ASSISTED', 'created_at' => now(), 'updated_at' => now()]);
            $knowledgeId = (string) Str::uuid();
            DB::table('tenant_marketing_knowledge')->insert(['id' => $knowledgeId, 'tenant_id' => $tenant->id, 'kind' => 'service', 'title' => 'Fixture: website enquiry journey review',
                'content' => 'TEST FIXTURE ONLY. Yaandu provides structured reviews of public website enquiry journeys.', 'status' => 'approved', 'created_by' => $owner->id,
                'approved_by' => $owner->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $marketingPromptId = $this->prompt($tenant->id, $owner->id, 'MarketingAgent', 'TEST FIXTURE ONLY: deterministic local copy for visual acceptance; never invoke a live provider.');
            $proposalPromptId = $this->prompt($tenant->id, $owner->id, 'ProposalAgent', 'TEST FIXTURE ONLY: deterministic local proposal narrative for visual acceptance; never invoke a live provider.');

            $campaign = Campaign::create(['tenant_id' => $tenant->id, 'created_by' => $owner->id, 'name' => 'TEST FIXTURE · Website enquiry review',
                'description' => 'Fictional, local-only campaign fixture. Do not activate or contact anyone.', 'objective' => 'Illustrate evidence-grounded outreach review.',
                'status' => 'draft', 'timezone' => 'Asia/Kolkata', 'sending_windows' => ['weekdays' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00']]);
            $approvedTemplate = CampaignTemplate::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'name' => 'TEST FIXTURE · approved review step',
                'channel' => 'email', 'subject' => 'Fixture only', 'body' => 'Fixture only. No message will be sent.', 'status' => 'approved', 'version' => 1]);
            CampaignStep::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'template_id' => $approvedTemplate->id, 'ordinal' => 1,
                'step_type' => 'email', 'delay_seconds' => 0, 'active' => true]);

            $this->fakeRouter($service->id, $evidenceId, $knowledgeId);
            app(AgentOrchestrator::class); // resolve against the fake router below
            app()->forgetInstance(AgentOrchestrator::class);
            app(MarketingDraftService::class)->generate(new GenerateMarketingDraftCommand($tenant->id, $company->id, $contactId, $campaign->id,
                'Fictional public website improvement; test fixture only.', (string) $owner->id, 'visual-acceptance-marketing-draft-v1'));
            $draft = DB::table('marketing_drafts')->where('tenant_id', $tenant->id)->where('company_id', $company->id)->firstOrFail();
            DB::table('marketing_drafts')->where('tenant_id', $tenant->id)->where('id', $draft->id)->update(['prompt_template_id' => $marketingPromptId]);

            $opportunityId = (string) Str::uuid();
            DB::table('sales_opportunities')->insert(['id' => $opportunityId, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'contact_id' => $contactId,
                'owner_user_id' => $owner->id, 'stage' => 'QUALIFIED', 'value' => null, 'currency' => null, 'status' => 'open',
                'qualification' => json_encode(['NEED' => 'STRONG', 'FIT' => 'STRONG', 'AUTHORITY' => 'UNKNOWN', 'BUDGET' => 'UNKNOWN', 'TIMELINE' => 'UNKNOWN']),
                'qualification_score' => 82, 'qualification_level' => 'QUALIFIED', 'qualified_at' => now(), 'source' => 'visual_fixture', 'created_at' => now(), 'updated_at' => now()]);
            $proposal = Proposal::create(['tenant_id' => $tenant->id, 'sales_opportunity_id' => $opportunityId, 'status' => ProposalStatus::Draft,
                'version' => 0, 'title' => 'TEST FIXTURE · Enquiry journey improvement plan', 'terms' => 'Fixture terms: valid for review only. No client offer or commitment is made.',
                'currency' => null, 'valid_until' => now()->addDays(30)->toDateString(), 'generated_by' => $owner->id]);
            DB::table('proposal_requirements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'proposal_id' => $proposal->id,
                'company_id' => $company->id, 'contact_id' => $contactId, 'requested_services' => json_encode([$service->name]),
                'business_requirements' => json_encode(['Clarify what happens after a website enquiry is submitted.']),
                'business_objectives' => json_encode(['Make enquiry expectations easier to understand.']), 'known_pain_points' => json_encode(['No visible post-submit guidance in the fixture page.']),
                'technical_requirements' => json_encode([]), 'deliverables' => json_encode($service->standard_deliverables), 'constraints' => json_encode(['Review only; implementation excluded.']),
                'timeline_type' => 'TARGET', 'requested_timeline' => 'Confirm after scope review.', 'approved_budget_information' => null,
                'special_notes' => 'All parties and website evidence in this workspace are fictional test fixtures.', 'source_references' => json_encode([$evidenceId]),
                'qualification_override' => false, 'override_reason' => null, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
            app(WorkflowService::class)->recordOpportunityEvent($tenant->id, $opportunityId, 'proposal_requested', 'visual-fixture-proposal-requested:'.$proposal->id, ['proposal_id' => $proposal->id]);
            $this->fakeRouter($service->id, $evidenceId, $knowledgeId);
            app()->forgetInstance(AgentOrchestrator::class);
            app(ProposalGenerationService::class)->generate(new GenerateProposalCommand($tenant->id, $proposal->id, (string) $owner->id, 'visual-acceptance-proposal-v1'));
            app(ProposalGenerationService::class)->generate(new GenerateProposalCommand($tenant->id, $proposal->id, (string) $owner->id, 'visual-acceptance-proposal-v2'));

            // Use the application's decimal-safe commercial arithmetic while recording the
            // same catalog and human-override provenance fields as the commercial workflow.
            $catalogCents = DecimalMoney::cents((string) $service->unit_price);
            $overrideCents = DecimalMoney::cents('13500.00');
            $subtotalCents = ($catalogCents * 2) + $overrideCents;
            $discountCents = intdiv(($subtotalCents * DecimalMoney::basisPoints('5.00')) + 5000, 10000);
            DB::table('proposal_items')->insert([
                ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'proposal_id' => $proposal->id, 'service_id' => $service->id, 'service_name' => $service->name,
                    'description' => 'Two units at the approved catalog price.', 'quantity' => 2, 'unit' => $service->unit, 'unit_price' => DecimalMoney::decimal($catalogCents),
                    'line_total' => DecimalMoney::decimal($catalogCents * 2), 'tax_amount' => '0.00', 'discount_amount' => DecimalMoney::decimal($discountCents),
                    'discount_reason' => 'Human-authorized fixture discount for pricing provenance display.', 'discount_approved_by' => $owner->id, 'discount_approved_at' => now(),
                    'price_override_reason' => null, 'price_approved_by' => null, 'price_approved_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'proposal_id' => $proposal->id, 'service_id' => $service->id, 'service_name' => $service->name,
                    'description' => 'One human-approved adjusted unit price.', 'quantity' => 1, 'unit' => $service->unit, 'unit_price' => DecimalMoney::decimal($overrideCents),
                    'line_total' => DecimalMoney::decimal($overrideCents), 'tax_amount' => '0.00', 'discount_amount' => '0.00', 'discount_reason' => null,
                    'discount_approved_by' => null, 'discount_approved_at' => null, 'price_override_reason' => 'Human-approved fixture scope adjustment.', 'price_approved_by' => $owner->id,
                    'price_approved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);
            $totalCents = $subtotalCents - $discountCents;
            $proposal->update(['currency' => 'INR', 'subtotal' => DecimalMoney::decimal($subtotalCents), 'discount_type' => 'percent', 'discount_value' => '5.00',
                'total' => DecimalMoney::decimal($totalCents), 'status' => ProposalStatus::ReviewRequired]);
            $latestVersion = DB::table('proposal_versions')->where('tenant_id', $tenant->id)->where('proposal_id', $proposal->id)->orderByDesc('version')->first();
            DB::table('proposal_versions')->where('tenant_id', $tenant->id)->where('id', $latestVersion->id)->update(['status' => 'review_required',
                'commercial_snapshot' => json_encode(['currency' => 'INR', 'subtotal' => DecimalMoney::decimal($subtotalCents), 'discount_percent' => 5,
                    'discount_amount' => DecimalMoney::decimal($discountCents), 'total' => DecimalMoney::decimal($totalCents), 'provenance' => 'local test fixture']), 'updated_at' => now()]);
            DB::table('proposal_internal_notes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'proposal_id' => $proposal->id,
                'note_ciphertext' => Crypt::encryptString('TEST FIXTURE ONLY: confirm discovery assumptions before sharing any future proposal.'), 'created_by' => $owner->id, 'created_at' => now()]);

            DB::table('tenant_messaging_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'provider' => 'fake', 'enabled' => false,
                'from_name' => 'Fixture only', 'from_email' => 'no-send@example.test', 'hourly_limit' => 60, 'daily_limit' => 500, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('tenant_scheduling_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'provider' => 'fake', 'enabled' => false,
                'default_timezone' => 'Asia/Kolkata', 'autonomous_booking_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);

            $this->command?->info('Created fictional visual-acceptance fixture tenant: '.$tenant->id);
            $this->command?->line('Sign in: visual-qa-owner@example.test / Visual-QA-Only-2026!');
            $this->command?->line('Fixture company: '.$company->name.' ('.$company->normalized_domain.')');
            $this->command?->line('No outbound messages or external meetings were created. AI text is deterministic fake-provider output.');
        });
    }

    private function fakeRouter(string $serviceId, string $evidenceId, string $knowledgeId): void
    {
        $provider = new VisualAcceptanceAIProvider($serviceId, $evidenceId, $knowledgeId);
        app()->instance(AIModelRouter::class, new AIModelRouter([$provider], [
            'content_generation' => ['provider' => 'conversation-test', 'model' => 'visual-fixture-deterministic'],
            'proposal_generation' => ['provider' => 'conversation-test', 'model' => 'visual-fixture-deterministic'],
        ]));
    }

    private function prompt(string $tenantId, int $ownerId, string $agent, string $text): string
    {
        $id = (string) Str::uuid();
        DB::table('prompt_templates')->insert(['id' => $id, 'tenant_id' => $tenantId, 'agent_key' => $agent, 'version' => 1,
            'system_instruction' => $text, 'template' => $text, 'schema_version' => 'visual-fixture-v1', 'active' => true, 'status' => 'approved',
            'created_by' => $ownerId, 'approved_by' => $ownerId, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
}
