<?php

namespace Tests\Feature;

use App\LeadScoring\LeadEvidenceBuilder;
use App\LeadScoring\ScoringRuleEvaluator;
use App\Models\Company;
use App\Models\Tenant;
use App\WebsiteIntelligence\WebsiteTechnicalEvidenceAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebsiteTechnicalEvidenceAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_deterministic_detectors_persist_page_provenance_and_are_idempotent(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant('tech-a');
        $company = $this->company($tenant->id, 'Observed Co', 'observed.test');
        $website = $this->website($tenant->id, $company->id);
        $scan = $this->scan($tenant->id, $website);
        $page = $this->page($tenant->id, $scan, 'https://observed.test/contact?session=secret#form', <<<'HTML'
<!doctype html><html lang="en"><head><title>Observed Co</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="generator" content="WordPress"><meta name="description" content="Observed company">
<meta name="robots" content="index,follow"><link rel="canonical" href="https://observed.test/contact">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product"}</script></head><body>
<h1>Contact us</h1><form action="/enquiry"><input type="email"><textarea></textarea><button>Request a quote</button></form>
<a href="https://wa.me/971500000000">WhatsApp</a><a href="tel:+971500000000">Call us</a>
<button>Add to cart</button><script src="https://cdn.shopify.com/store.js"></script><script src="https://js.hsforms.net/forms/embed/v2.js"></script>
<script src="/_next/static/chunks/app.js"></script><div data-reactroot=""></div><marquee>Sale</marquee>
</body></html>
HTML);
        $secondPage = $this->page($tenant->id, $scan, 'https://observed.test/shop', '<html><head><title>Shop</title></head><body><a href="/cart">Basket</a><script src="https://cdn.shopify.com/shop.js"></script></body></html>');

        $analyzer = app(WebsiteTechnicalEvidenceAnalyzer::class);
        $first = $analyzer->analyze($tenant->id, $scan);
        $counts = [DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->count(),
            DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->count()];
        $issueIds = DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->orderBy('id')->pluck('id')->all();
        $technologyIds = DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->orderBy('id')->pluck('id')->all();
        $this->artisan('website:evidence:enrich', ['tenant' => $tenant->id, 'scan' => $scan])
            ->expectsOutput('Deterministic website evidence refreshed.')
            ->assertExitCode(0);

        self::assertSame(2, $first['pages_inspected']);
        self::assertSame($counts, [DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->count(),
            DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->count()]);
        self::assertSame($issueIds, DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->orderBy('id')->pluck('id')->all());
        self::assertSame($technologyIds, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->orderBy('id')->pluck('id')->all());
        self::assertSame(1, DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('type', 'lead_capture_mechanism_present')->count());
        $leadCaptureEvidence = json_decode(DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('type', 'lead_capture_mechanism_present')->value('evidence'), true);
        self::assertStringContainsString('form', $leadCaptureEvidence['excerpt']);
        self::assertStringContainsString('telephone link', $leadCaptureEvidence['excerpt']);
        self::assertSame(1, DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('type', 'poor_mobile_ux')->count());
        self::assertSame(1, DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('type', 'outdated_website')->count());
        self::assertSame(1, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'WordPress')->count());
        self::assertSame(1, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'WhatsApp business contact')->count());
        self::assertSame(2, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'Shopify')->count());
        self::assertSame(1, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'Next.js')->count());
        self::assertSame(1, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'React')->count());
        self::assertSame(1, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'HubSpot web-form integration')->count());
        foreach (['Document title present', 'Meta description present', 'Canonical link present', 'Robots meta directive present', 'JSON-LD structured data present', 'HTML language present', 'H1 heading present'] as $metadataSignal) {
            self::assertGreaterThan(0, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', $metadataSignal)->count(), $metadataSignal);
        }
        self::assertGreaterThanOrEqual(2, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('category', 'ecommerce')->count());
        $technologyEvidence = json_decode(DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'WordPress')->value('evidence'), true);
        self::assertSame($page, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'WordPress')->value('website_page_id'));
        self::assertSame('https://observed.test/contact', $technologyEvidence['source_url']);
        self::assertStringContainsString('generator', $technologyEvidence['marker']);
        $titleEvidence = json_decode(DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'Document title present')->value('evidence'), true);
        self::assertStringContainsString('Observed Co', $titleEvidence['marker']);
        self::assertStringNotContainsString('session=secret', $technologyEvidence['source_url']);
        self::assertNull(DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->value('agent_run_id'));
        self::assertSame('negative', app(LeadEvidenceBuilder::class)->build($tenant->id, $company->id)['no_crm']['status']);
        self::assertSame(0, DB::table('campaigns')->where('tenant_id', $tenant->id)->count());
        self::assertSame(0, DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->count());
    }

    public function test_absent_whatsapp_crm_and_contact_mechanism_remain_unknown_and_tenant_scoped(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant('tech-b');
        $other = $this->tenant('tech-c');
        $company = $this->company($tenant->id, 'Sparse Co', 'sparse.test');
        $website = $this->website($tenant->id, $company->id);
        $scan = $this->scan($tenant->id, $website);
        $this->page($tenant->id, $scan, 'https://sparse.test/', '<html><head><title>Sparse</title></head><body><p>Business facts only.</p><a href="/contact">Contact us</a></body></html>');
        $otherCompany = $this->company($other->id, 'Foreign Co', 'foreign.test');
        $otherWebsite = $this->website($other->id, $otherCompany->id);
        $otherScan = $this->scan($other->id, $otherWebsite);
        $this->page($other->id, $otherScan, 'https://foreign.test/', '<html><body><a href="https://wa.me/1">WhatsApp</a></body></html>');

        app(WebsiteTechnicalEvidenceAnalyzer::class)->analyze($tenant->id, $scan);
        app(WebsiteTechnicalEvidenceAnalyzer::class)->analyze($other->id, $otherScan);
        $evidence = app(LeadEvidenceBuilder::class)->build($tenant->id, $company->id);
        self::assertSame('unknown', $evidence['no_whatsapp']['status']);
        self::assertSame('unknown', $evidence['no_crm']['status']);
        self::assertSame('unknown', $evidence['poor_lead_capture']['status']);
        self::assertGreaterThan(0, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('category', 'contact_navigation')->count());
        self::assertSame(0, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('category', 'contact_channel')->count());
        self::assertSame(0, DB::table('website_issues')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('type', 'lead_capture_mechanism_present')->count());
        self::assertSame(1, DB::table('website_technologies')->where('tenant_id', $other->id)->where('website_scan_id', $otherScan)->where('name', 'WhatsApp business contact')->count());
        self::assertSame(0, DB::table('website_technologies')->where('tenant_id', $tenant->id)->where('website_scan_id', $scan)->where('name', 'WhatsApp business contact')->count());
    }

    public function test_deterministic_evidence_makes_only_supported_dimensions_evaluable_and_sparse_site_stays_unknown(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant('tech-d');
        $company = $this->company($tenant->id, 'Signal Co', 'signals.test');
        $website = $this->website($tenant->id, $company->id);
        $scan = $this->scan($tenant->id, $website);
        $this->page($tenant->id, $scan, 'https://signals.test/', '<html><head><title>Signal</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><h1>Signal</h1><a href="/contact">Contact</a><form action="/contact"><input type="email"><button>Send enquiry</button></form><a href="https://api.whatsapp.com/send">WhatsApp</a><font>Old marker</font></body></html>');
        app(WebsiteTechnicalEvidenceAnalyzer::class)->analyze($tenant->id, $scan);

        $runId = (string) Str::uuid();
        $promptId = (string) Str::uuid();
        DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenant->id, 'agent_key' => 'WebsiteIntelligenceAgent', 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prompt_templates')->insert(['id' => $promptId, 'tenant_id' => $tenant->id, 'agent_key' => 'WebsiteIntelligenceAgent', 'version' => 1,
            'system_instruction' => 'test', 'template' => 'test', 'schema_version' => 'test-v1', 'active' => true, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_intelligence_results')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'website_scan_id' => $scan, 'agent_run_id' => $runId, 'prompt_template_id' => $promptId, 'prompt_version' => 1, 'schema_version' => 'test-v1',
            'provider' => 'openai', 'model' => 'test-model', 'correlation_id' => (string) Str::uuid(), 'confidence' => 0.9,
            'structured_output' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $evidence = app(LeadEvidenceBuilder::class)->build($tenant->id, $company->id);

        self::assertSame('negative', $evidence['poor_lead_capture']['status']);
        self::assertSame('negative', $evidence['no_whatsapp']['status']);
        self::assertSame('positive', $evidence['outdated_website']['status']);
        self::assertSame('positive', $evidence['technology_opportunity']['status']);
        self::assertSame('unknown', $evidence['poor_mobile_ux']['status'], 'A viewport tag by itself does not establish mobile UX quality.');
        self::assertSame('https://signals.test/', $evidence['no_whatsapp']['reference']['url']);
        self::assertSame('unknown', $evidence['strong_business_fit']['status']);
        $score = app(ScoringRuleEvaluator::class)->score($evidence);
        self::assertSame('scored', $score['evaluation_status']);
        self::assertNotNull($score['score']);
    }

    public function test_responsive_capability_cannot_score_as_mobile_usability_defect(): void
    {
        $tenant = $this->tenant('ebarza-responsive-capability');
        $company = $this->company($tenant->id, 'Ebarza', 'ebarza.com');
        $website = $this->website($tenant->id, $company->id);
        $scan = $this->scan($tenant->id, $website);
        $page = $this->page($tenant->id, $scan, 'https://ebarza.com/', <<<'HTML'
<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0, height=device-height, minimum-scale=1.0, maximum-scale=5.0"></head><body>Responsive viewport metadata is present enabling mobile-friendly scaling. Legacy contact form.</body></html>
HTML);
        $responsiveIssue = (string) Str::uuid();
        DB::table('website_issues')->insert(['id' => $responsiveIssue, 'tenant_id' => $tenant->id, 'website_scan_id' => $scan, 'website_page_id' => $page,
            'type' => 'responsive', 'severity' => 'low', 'summary' => 'Responsive viewport metadata is present enabling mobile-friendly scaling. Legacy contact form.',
            'evidence' => json_encode(['source_url' => 'https://ebarza.com/', 'excerpt' => 'maximum-scale=5.0']), 'confidence' => 0.95,
            'detector_version' => 'ai-v1', 'created_at' => now(), 'updated_at' => now()]);
        $responsiveTechnology = (string) Str::uuid();
        DB::table('website_technologies')->insert(['id' => $responsiveTechnology, 'tenant_id' => $tenant->id, 'website_scan_id' => $scan, 'website_page_id' => $page,
            'name' => 'Responsive viewport metadata', 'category' => 'responsive', 'detection_method' => 'deterministic-v1', 'confidence' => 0.99,
            'evidence' => json_encode(['source_url' => 'https://ebarza.com/', 'excerpt' => 'maximum-scale=5.0']), 'created_at' => now(), 'updated_at' => now()]);

        $builder = app(LeadEvidenceBuilder::class);
        $responsiveEvidence = $builder->build($tenant->id, $company->id);
        $responsiveScore = app(ScoringRuleEvaluator::class)->score($responsiveEvidence);
        self::assertSame('unknown', $responsiveEvidence['poor_mobile_ux']['status']);
        self::assertNull($responsiveEvidence['poor_mobile_ux']['reference']);
        self::assertSame(0, $responsiveScore['components']['poor_mobile_ux']['raw_points']);
        self::assertSame(0, $responsiveScore['components']['poor_mobile_ux']['points']);
        self::assertSame(0, $responsiveScore['components']['poor_mobile_ux']['possible_points']);
        self::assertSame(0, $responsiveScore['evidence_coverage']);
        self::assertNull($responsiveScore['score']);
        self::assertSame('unknown', $responsiveEvidence['outdated_website']['status'], 'A non-canonical responsive issue cannot create an outdated-website signal from summary text.');
        self::assertSame('unknown', $responsiveEvidence['poor_lead_capture']['status'], 'A non-canonical responsive issue cannot create a lead-capture gap from summary text.');
        self::assertSame('unknown', $responsiveEvidence['technology_opportunity']['status'], 'Responsive capability is not a technology replacement opportunity.');
        $this->assertDatabaseHas('website_technologies', ['id' => $responsiveTechnology, 'tenant_id' => $tenant->id, 'category' => 'responsive']);
        self::assertNotSame($responsiveIssue, $responsiveEvidence['poor_mobile_ux']['reference']['id'] ?? null);

        $defectIssue = (string) Str::uuid();
        DB::table('website_issues')->insert(['id' => $defectIssue, 'tenant_id' => $tenant->id, 'website_scan_id' => $scan, 'website_page_id' => $page,
            'type' => 'poor_mobile_ux', 'severity' => 'medium', 'summary' => 'Viewport metadata disables user zoom on the inspected page.',
            'evidence' => json_encode(['source_url' => 'https://ebarza.com/', 'excerpt' => 'maximum-scale=1, user-scalable=no']), 'confidence' => 0.95,
            'detector_version' => 'ai-v1', 'created_at' => now(), 'updated_at' => now()]);

        $defectEvidence = $builder->build($tenant->id, $company->id);
        $defectScore = app(ScoringRuleEvaluator::class)->score($defectEvidence);
        self::assertSame('positive', $defectEvidence['poor_mobile_ux']['status']);
        self::assertSame($defectIssue, $defectEvidence['poor_mobile_ux']['reference']['id']);
        self::assertSame('poor_mobile_ux', DB::table('website_issues')->where('id', $defectEvidence['poor_mobile_ux']['reference']['id'])->value('type'));
        self::assertSame(10, $defectScore['components']['poor_mobile_ux']['raw_points']);
        self::assertSame(10, $defectScore['components']['poor_mobile_ux']['points']);
    }

    public function test_site_with_no_directly_observed_technical_evidence_stays_insufficient(): void
    {
        $tenant = $this->tenant('tech-e');
        $company = $this->company($tenant->id, 'Unobserved Co', 'unobserved.test');
        $evidence = app(LeadEvidenceBuilder::class)->build($tenant->id, $company->id);
        self::assertSame('unknown', $evidence['poor_mobile_ux']['status']);
        self::assertSame('unknown', $evidence['poor_lead_capture']['status']);
        self::assertSame('unknown', $evidence['technology_opportunity']['status']);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
    }

    private function company(string $tenantId, string $name, string $domain): Company
    {
        return Company::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => $name, 'normalized_domain' => $domain, 'status' => 'new']);
    }

    private function website(string $tenantId, string $companyId): string
    {
        $id = (string) Str::uuid();
        DB::table('company_websites')->insert(['id' => $id, 'tenant_id' => $tenantId, 'company_id' => $companyId,
            'url' => 'https://'.$companyId.'.test/', 'host' => 'example.test', 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function scan(string $tenantId, string $websiteId): string
    {
        $id = (string) Str::uuid();
        DB::table('website_scans')->insert(['id' => $id, 'tenant_id' => $tenantId, 'company_website_id' => $websiteId, 'status' => 'completed', 'max_depth' => 1, 'max_pages' => 5, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function page(string $tenantId, string $scanId, string $url, string $html): string
    {
        $id = (string) Str::uuid();
        $key = "tenants/{$tenantId}/crawls/{$scanId}/{$id}.html";
        Storage::disk('local')->put($key, $html);
        DB::table('website_pages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'website_scan_id' => $scanId,
            'requested_url' => $url, 'final_url' => $url, 'object_key' => $key, 'extracted_text' => strip_tags($html),
            'depth' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
}
