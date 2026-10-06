<?php

namespace App\LeadScoring;

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
        $icp = $settings['scoring']['icp'] ?? $settings['icp'] ?? [];
        $industries = array_map([$this, 'normalize'], $icp['industries'] ?? []);
        $locations = array_map([$this, 'normalize'], $icp['locations'] ?? []);
        $keywords = array_filter(array_map([$this, 'normalize'], $icp['keywords'] ?? []));
        $companyText = $this->normalize(implode(' ', array_filter([$company->name, $company->industry, $company->location, $company->description])));
        $industry = $this->normalize((string) $company->industry);
        $location = $this->normalize((string) $company->location);
        $hasIcp = $industries !== [] || $locations !== [] || $keywords !== [];
        $industryMatch = $industry !== '' && in_array($industry, $industries, true);
        $locationMatch = $location !== '' && in_array($location, $locations, true);
        $keywordMatches = array_values(array_filter($keywords, fn (string $keyword) => $keyword !== '' && str_contains($companyText, $keyword)));

        $scan = DB::table('website_scans as scans')
            ->join('company_websites as websites', 'websites.id', '=', 'scans.company_website_id')
            ->where('scans.tenant_id', $tenantId)->where('websites.tenant_id', $tenantId)
            ->where('websites.company_id', $companyId)->where('scans.status', 'completed')
            ->orderByDesc('scans.created_at')->select('scans.*')->first();
        $pages = $scan ? DB::table('website_pages')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->get(['final_url', 'requested_url', 'extracted_text', 'object_key']) : collect();
        $issues = $scan ? DB::table('website_issues')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->get() : collect();
        $technologies = $scan ? DB::table('website_technologies')->where('tenant_id', $tenantId)->where('website_scan_id', $scan->id)->get() : collect();
        $insight = DB::table('lead_insights')->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->where('kind', 'business_fit')->where('confidence', '>=', 0.7)->orderByDesc('created_at')->first();
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
        $whatsapp = count($pages) > 0 && ($pageHas('wa.me/') || $pageHas('api.whatsapp.com')
            || $pageHas('whatsapp.com/') || $pageHas('whatsapp://') || $pageHas('message us on whatsapp'));
        $crm = $technologies->first(fn (object $tech) => str_contains($this->normalize($tech->category.' '.$tech->name), 'crm'));
        $explicitNoWhatsapp = $pageHas('we do not use whatsapp') || $pageHas('whatsapp is not available') || $pageHas('do not contact us on whatsapp');
        $explicitNoCrm = $pageHas('we do not use a crm') || $pageHas('we do not use crm') || $pageHas('crm is not used');
        $techOpportunity = $technologies->first(fn (object $tech) => (bool) preg_match('/legacy|deprecated|flash|outdated|unsupported/i', $tech->name.' '.$tech->category));

        $reference = fn (?object $record, string $source, ?string $url = null) => $record ? ['source' => $source, 'id' => $record->id ?? null, 'url' => $url] : null;
        $signal = static fn (string $status, ?array $ref = null): array => ['status' => $status, 'present' => $status === 'confirmed_present', 'reference' => $ref];
        $known = static fn (bool $value, ?array $ref = null): array => $signal($value ? 'confirmed_present' : 'confirmed_absent', $ref);
        $industryKnown = $industry !== '';
        $whatsappState = $whatsapp ? 'confirmed_absent' : ($explicitNoWhatsapp ? 'confirmed_present' : 'unknown');
        $crmState = $crm ? 'confirmed_absent' : ($explicitNoCrm ? 'confirmed_present' : 'unknown');
        return [
            'icp_fit' => $hasIcp ? $known($industryMatch || $locationMatch || $keywordMatches !== [], ['source' => 'tenant_icp', 'industry_match' => $industryMatch, 'location_match' => $locationMatch, 'keyword_matches' => $keywordMatches]) : $signal('unknown'),
            'relevant_industry' => $industryKnown ? $known($industryMatch, ['source' => 'company_record', 'industry' => $company->industry]) : $signal('unknown'),
            'outdated_website' => $outdated ? $signal('confirmed_present', $reference($outdated, 'website_issue')) : $signal('unknown'),
            'poor_mobile_ux' => $mobile ? $signal('confirmed_present', $reference($mobile, 'website_issue')) : $signal('unknown'),
            'poor_lead_capture' => $leadCapture ? $signal('confirmed_present', $reference($leadCapture, 'website_issue')) : $signal('unknown'),
            'no_whatsapp' => $signal($whatsappState, $whatsapp ? ['source' => 'website_page', 'observation' => 'WhatsApp contact method found'] : null),
            'no_crm' => $signal($crmState, $crm ? ['source' => 'detected_technology', 'name' => $crm->name] : null),
            'technology_opportunity' => $techOpportunity ? $signal('confirmed_present', $reference($techOpportunity, 'detected_technology')) : $signal('unknown'),
            'decision_maker_identified' => $decisionMaker ? $signal('confirmed_present', ['source' => 'public_contact', 'contact_id' => $decisionMaker->id, 'url' => $decisionMaker->source_url]) : $signal('unknown'),
            'strong_business_fit' => $insight ? $signal('confirmed_present', $reference($insight, 'business_insight')) : $signal('unknown'),
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
