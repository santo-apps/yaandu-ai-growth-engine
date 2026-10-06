<?php

namespace App\Agents;

use App\Crawling\UrlPolicy;
use App\Models\Company;
use Illuminate\Support\Str;

final class DiscoveryAgent implements AgentInterface
{
    public function __construct(private readonly UrlPolicy $urlPolicy) {}
    public function name(): string { return 'DiscoveryAgent'; }
    public function description(): string { return 'Normalize and register company candidates from permitted public business sources or user supplied seeds.'; }
    public function inputSchema(): array { return ['required' => ['candidates']]; }
    public function outputSchema(): array { return ['required' => ['company_ids', 'count']]; }
    public function tools(): array { return []; }

    public function execute(AgentContext $context, array $input): AgentResult
    {
        $ids = [];
        foreach (array_slice($input['candidates'], 0, 100) as $candidate) {
            if (! is_array($candidate) || empty($candidate['name']) || empty($candidate['website'])) continue;
            [$host] = $this->urlPolicy->validatePublicHttpUrl($candidate['website']);
            $company = Company::firstOrCreate(['tenant_id' => $context->tenantId, 'normalized_domain' => $host], [
                'id' => (string) Str::uuid(), 'name' => mb_substr($candidate['name'], 0, 255),
                'industry' => isset($candidate['industry']) ? mb_substr($candidate['industry'], 0, 150) : null,
                'location' => isset($candidate['location']) ? mb_substr($candidate['location'], 0, 255) : null,
                'source' => $candidate['source'] ?? 'user_seed', 'status' => 'new',
            ]);
            $company->websites()->firstOrCreate(['tenant_id' => $context->tenantId, 'host' => $host], [
                'id' => (string) Str::uuid(), 'url' => $candidate['website'], 'source' => $candidate['source'] ?? 'user_seed',
            ]);
            $ids[] = $company->id;
        }
        return new AgentResult(['company_ids' => array_values(array_unique($ids)), 'count' => count(array_unique($ids))], 'Registered '.count(array_unique($ids)).' company candidates.');
    }
}
