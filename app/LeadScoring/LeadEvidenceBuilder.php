<?php

namespace App\LeadScoring;

use App\Geography\CountryNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class LeadEvidenceBuilder
{
    public function build(string $tenantId, string $companyId): array
    {
        $company = DB::table('companies')->where('tenant_id', $tenantId)->where('id', $companyId)->first();
        if (! $company) throw new RuntimeException('Company not found in this tenant.');

        $settings = DB::table('tenants')->where('id', $tenantId)->value('settings');
        $settings = is_array($settings) ? $settings : (json_decode($settings ?? '{}', true) ?: []);
        $activeIcp = DB::table('tenant_icp_configurations')->where('tenant_id', $tenantId)->where('status', 'active')->orderByDesc('version')->first();
        if ($activeIcp) {
            $configuration = json_decode($activeIcp->configuration, true) ?: [];
            $icp = ['industries' => $configuration['organization_suitability']['target_industries'] ?? [],
                'locations' => $configuration['geography']['target_locations'] ?: ($configuration['geography']['target_countries'] ?? [])];
        } else {
            $icp = $settings['scoring']['icp'] ?? $settings['icp'] ?? [];
        }
        $icp = is_array($icp) ? $icp : [];
        $industries = $this->normalizeList($icp['industries'] ?? []);
        $locations = $this->normalizeList($icp['locations'] ?? []);
        $industry = $this->normalize((string) $company->industry);
        $location = $this->normalize((string) $company->location);
        $industryConfigured = $industries !== [];
        $geographyConfigured = $locations !== [];
        $industryMatch = $industry !== '' && in_array($industry, $industries, true);
        $locationMatch = $location !== '' ? $this->matchesConfiguredGeography($location, $locations) : null;

        $scan = DB::table('website_scans as scans')
            ->join('company_websites as websites', 'websites.id', '=', 'scans.company_website_id')
            ->where('scans.tenant_id', $tenantId)->where('websites.tenant_id', $tenantId)
            ->where('websites.company_id', $companyId)->where('scans.status', 'completed')
            ->orderByDesc('scans.created_at')->select('scans.*')->first();
        $pages = $scan ? DB::table('website_pages')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->get(['id', 'final_url', 'requested_url', 'extracted_text', 'object_key']) : collect();
        $latestIntelligence = DB::getSchemaBuilder()->hasTable('website_intelligence_results')
            ? DB::table('website_intelligence_results')->where('tenant_id', $tenantId)->where('company_id', $companyId)->orderByDesc('created_at')->first()
            : null;
        $issuesQuery = $scan ? DB::table('website_issues')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id) : null;
        $technologyQuery = $scan ? DB::table('website_technologies')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id) : null;
        if ($latestIntelligence) {
            $issuesQuery?->where('agent_run_id', $latestIntelligence->agent_run_id);
            $technologyQuery?->where('agent_run_id', $latestIntelligence->agent_run_id);
        }
        $issues = $issuesQuery?->get() ?? collect();
        $technologies = $technologyQuery?->get() ?? collect();
        $insight = DB::table('lead_insights as insights')->where('insights.tenant_id', $tenantId)->where('insights.company_id', $companyId)
            ->where('insights.kind', 'opportunity')->where('insights.confidence', '>=', 0.7)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('lead_evidence')->whereColumn('lead_evidence.tenant_id', 'insights.tenant_id')
                    ->whereColumn('lead_evidence.lead_insight_id', 'insights.id');
            })->when($latestIntelligence, fn ($query) => $query->where('insights.agent_run_id', $latestIntelligence->agent_run_id))
            ->orderByDesc('insights.created_at')->first();
        $decisionMaker = DB::table('contacts')->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->whereNotNull('name')->whereNotNull('title')->first();

        $issue = function (array $patterns) use ($issues): ?object {
            return $issues->first(function (object $item) use ($patterns): bool {
                $text = $this->normalize($item->type.' '.$item->summary);
                foreach ($patterns as $pattern) if (str_contains($text, $pattern)) return true;
                return false;
            });
        };
        $outdated = $issue(['outdated', 'legacy', 'obsolete', 'deprecated', 'unmaintained', 'end of life']);
        $mobile = $issue(['mobile', 'responsive', 'viewport', 'touch target']);
        $leadCapture = $issue(['lead capture', 'contact form', 'conversion', 'call to action', 'contact method']);

        $pageHas = function (string $needle) use ($pages): bool {
            foreach ($pages as $page) {
                $content = (string) $page->extracted_text;
                if ($page->object_key && Storage::disk(config('filesystems.default'))->exists($page->object_key)) {
                    $content = mb_substr(Storage::disk(config('filesystems.default'))->get($page->object_key), 0, 300_000);
                }
                if (str_contains(mb_strtolower($content), $needle)) return true;
            }
            return false;
        };
        $pageReference = function (string $needle, string $observation) use ($pages): ?array {
            foreach ($pages as $page) {
                $content = (string) $page->extracted_text;
                if ($page->object_key && Storage::disk(config('filesystems.default'))->exists($page->object_key)) {
                    $content = mb_substr(Storage::disk(config('filesystems.default'))->get($page->object_key), 0, 300_000);
                }
                if (str_contains(mb_strtolower($content), $needle)) {
                    return ['source' => 'website_page', 'page_id' => $page->id, 'url' => $page->final_url ?: $page->requested_url, 'observation' => $observation];
                }
            }
            return null;
        };
        $whatsapp = count($pages) > 0 && ($pageHas('wa.me/') || $pageHas('api.whatsapp.com')
            || $pageHas('whatsapp.com/') || $pageHas('whatsapp://') || $pageHas('message us on whatsapp'));
        $crm = $technologies->first(fn (object $tech) => str_contains($this->normalize($tech->category.' '.$tech->name), 'crm'));
        $explicitNoWhatsapp = $pageHas('we do not use whatsapp') || $pageHas('whatsapp is not available') || $pageHas('do not contact us on whatsapp');
        $explicitNoCrm = $pageHas('we do not use a crm') || $pageHas('we do not use crm') || $pageHas('crm is not used');
        $techOpportunity = $technologies->first(fn (object $tech) => (bool) preg_match('/legacy|deprecated|flash|outdated|unsupported/i', $tech->name.' '.$tech->category));

        $reference = fn (?object $record, string $source, ?string $url = null) => $record ? ['source' => $source, 'id' => $record->id ?? null, 'url' => $url] : null;
        $signal = static fn (string $status, ?array $ref = null, ?string $reason = null): array => [
            'status' => $status,
            'present' => $status === 'positive',
            'reference' => $ref,
            'reason' => $reason,
        ];
        $known = static fn (bool $value, ?array $ref = null, ?string $positive = null, ?string $negative = null): array =>
            $signal($value ? 'positive' : 'negative', $ref, $value ? $positive : $negative);
        $industryKnown = $industry !== '';
        $whatsappState = $whatsapp ? 'negative' : ($explicitNoWhatsapp ? 'positive' : 'unknown');
        $crmState = $crm ? 'negative' : ($explicitNoCrm ? 'positive' : 'unknown');
        return [
            // Industry is scored only by relevant_industry. ICP fit here means geography or a distinct configured service/market keyword.
            'icp_fit' => ! $geographyConfigured
                ? $signal('not_configured', null, 'No target geographies are configured for this tenant.')
                : ($locationMatch === null
                    ? $signal('unknown', null, 'Company location evidence is missing.')
                    : $known($locationMatch, ['source' => 'company_record', 'field' => 'location', 'value' => $company->location, 'criteria' => $locations], 'Company location matches configured ICP geography.', 'Company location does not match configured ICP geography.')),
            'relevant_industry' => ! $industryConfigured
                ? $signal('not_configured', null, 'No target industries are configured for this tenant.')
                : (! $industryKnown
                    ? $signal('unknown', null, 'Company industry evidence is missing.')
                    : $known($industryMatch, ['source' => 'company_record', 'field' => 'industry', 'value' => $company->industry, 'criteria' => $industries], 'Company industry matches configured ICP industries.', 'Company industry does not match configured ICP industries.')),
            'outdated_website' => $outdated ? $signal('positive', $reference($outdated, 'website_issue'), 'A stored website issue identifies outdated technology or content.') : $signal('unknown'),
            'poor_mobile_ux' => $mobile ? $signal('positive', $reference($mobile, 'website_issue'), 'A stored website issue identifies a mobile usability gap.') : $signal('unknown'),
            'poor_lead_capture' => $leadCapture ? $signal('positive', $reference($leadCapture, 'website_issue'), 'A stored website issue identifies a lead-capture gap.') : $signal('unknown'),
            'no_whatsapp' => $signal($whatsappState,
                $whatsapp ? $pageReference('wa.me/', 'A WhatsApp contact link is present, contradicting the no-WhatsApp opportunity.')
                    ?? $pageReference('api.whatsapp.com', 'A WhatsApp contact link is present, contradicting the no-WhatsApp opportunity.')
                    ?? $pageReference('whatsapp.com/', 'A WhatsApp contact link is present, contradicting the no-WhatsApp opportunity.')
                    ?? $pageReference('whatsapp://', 'A WhatsApp contact link is present, contradicting the no-WhatsApp opportunity.')
                    ?? $pageReference('message us on whatsapp', 'WhatsApp contact is offered, contradicting the no-WhatsApp opportunity.')
                    : ($explicitNoWhatsapp ? $pageReference('we do not use whatsapp', 'The page explicitly states WhatsApp is not used.')
                        ?? $pageReference('whatsapp is not available', 'The page explicitly states WhatsApp is unavailable.')
                        ?? $pageReference('do not contact us on whatsapp', 'The page explicitly states WhatsApp is unavailable.') : null),
                $whatsapp ? 'A WhatsApp contact method is present.' : ($explicitNoWhatsapp ? 'The page explicitly states that WhatsApp is unavailable.' : 'No WhatsApp evidence was found; absence is not assumed.')),
            'no_crm' => $signal($crmState, $crm ? ['source' => 'detected_technology', 'name' => $crm->name]
                : ($explicitNoCrm ? $pageReference('we do not use a crm', 'The page explicitly states no CRM is used.')
                    ?? $pageReference('we do not use crm', 'The page explicitly states no CRM is used.')
                    ?? $pageReference('crm is not used', 'The page explicitly states no CRM is used.') : null),
                $crm ? 'A CRM technology was detected, contradicting the no-CRM opportunity.' : ($explicitNoCrm ? 'The page explicitly states no CRM is used.' : 'No CRM evidence was found; absence is not assumed.')),
            'technology_opportunity' => $techOpportunity ? $signal('positive', $reference($techOpportunity, 'detected_technology'), 'Detected technology indicates a configured replacement opportunity.') : $signal('unknown'),
            'decision_maker_identified' => $decisionMaker ? $signal('positive', ['source' => 'public_contact', 'contact_id' => $decisionMaker->id, 'url' => $decisionMaker->source_url], 'A public contact with a decision-making title is recorded.') : $signal('unknown'),
            'strong_business_fit' => $insight ? $signal('positive', $reference($insight, 'business_insight'), 'A high-confidence business-fit insight is recorded.') : $signal('unknown'),
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function normalizeList(mixed $values): array
    {
        if (! is_array($values)) return [];
        $normalized = array_map(fn ($value): string => is_string($value) ? $this->normalize($value) : '', $values);
        return array_values(array_unique(array_filter($normalized)));
    }

    private function matchesConfiguredGeography(string $companyLocation, array $criteria): ?bool
    {
        if (in_array($companyLocation, $criteria, true)) return true;
        $parts = preg_split('/[,;|\/]+/', $companyLocation) ?: [];
        $parts = array_values(array_filter(array_map(fn ($part): string => $this->normalize($part), $parts)));
        $normalizer = app(CountryNormalizer::class);
        if (array_intersect($criteria, $parts) !== []) return true;
        $configuredCountries = array_values(array_filter(array_map($normalizer->normalize(...), $criteria)));

        $observedCountry = null;
        foreach ($parts as $part) {
            $observedCountry = $normalizer->normalize($part);
            if ($observedCountry !== null) break;
        }
        if ($observedCountry === null) return null;

        if ($configuredCountries === []) return false;
        return in_array($observedCountry, $configuredCountries, true);
    }
}
