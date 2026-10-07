<?php

namespace App\Agents;

use App\Crawling\UrlPolicy;
use App\Discovery\DiscoveryCandidateService;
use App\Discovery\DiscoveryQuery;
use App\Discovery\DiscoverySourceRegistry;
use App\Models\Company;
use App\Jobs\VerifyDiscoveryCandidateJob;
use App\Discovery\DomainNormalizer;
use Illuminate\Support\Str;

final class DiscoveryAgent implements AgentInterface
{
    public function __construct(
        private readonly UrlPolicy $urlPolicy,
        private readonly DomainNormalizer $domains,
        private readonly DiscoverySourceRegistry $sources,
        private readonly DiscoveryCandidateService $candidateService,
    ) {}
    public function name(): string { return 'DiscoveryAgent'; }
    public function description(): string { return 'Normalize and register company candidates from permitted public business sources or user supplied seeds.'; }
    public function inputSchema(): array { return ['required' => []]; }
    public function outputSchema(): array { return ['required' => ['company_ids', 'count']]; }
    public function tools(): array { return []; }

    public function execute(AgentContext $context, array $input): AgentResult
    {
        if (isset($input['discovery_run_id'])) {
            $run = \Illuminate\Support\Facades\DB::table('discovery_runs')->where('tenant_id', $context->tenantId)->where('id', $input['discovery_run_id'])->first();
            if (! $run) throw new \RuntimeException('Discovery run not found in this tenant.');
            $query = new DiscoveryQuery($input['query'] ?? [], min((int) config('discovery.max_candidates_per_run', 100), (int) ($input['limit'] ?? 25)), $input['candidates'] ?? []);
            $source = $this->sources->get((string) ($input['source'] ?? 'supplied_seed'));
            $rows = $source->search($query);
            $candidateIds = [];
            foreach (array_slice($rows, 0, $query->limit) as $index => $row) {
                if (! is_array($row) || empty($row['website'] ?? $row['domain'])) continue;
                $row['source'] = $source->name();
                $row['source_reference'] ??= 'input-row-'.($index + 1);
                $result = $this->candidateService->add($context->tenantId, $run->id, $row);
                $candidateIds[] = $result['candidate_id'];
                if ($result['created'] && $result['deduplication_state'] === 'new') {
                    VerifyDiscoveryCandidateJob::dispatch($context->tenantId, $run->id, $result['candidate_id'])->afterCommit();
                }
            }
            \Illuminate\Support\Facades\DB::table('discovery_runs')->where('tenant_id', $context->tenantId)->where('id', $run->id)
                ->update(['status' => 'verifying', 'source_counts' => json_encode([$source->name() => count($candidateIds)]), 'updated_at' => now()]);
            $pending = \Illuminate\Support\Facades\DB::table('discovery_candidates')->where('tenant_id', $context->tenantId)->where('discovery_run_id', $run->id)->where('verification_state', 'pending')->exists();
            if (! $pending) {
                $counts = \Illuminate\Support\Facades\DB::table('discovery_candidates')->where('tenant_id', $context->tenantId)->where('discovery_run_id', $run->id)
                    ->selectRaw('count(*) as found, sum(case when deduplication_state in (\'existing_company\', \'duplicate_candidate\') then 1 else 0 end) as duplicates, sum(case when verification_state = \'invalid\' then 1 else 0 end) as invalid')
                    ->first();
                \Illuminate\Support\Facades\DB::table('discovery_runs')->where('tenant_id', $context->tenantId)->where('id', $run->id)
                    ->update(['status' => 'completed', 'completed_at' => now(), 'counts' => json_encode($counts), 'updated_at' => now()]);
            }
            return new AgentResult(['company_ids' => [], 'count' => count($candidateIds), 'candidate_ids' => $candidateIds], 'Discovered '.count($candidateIds).' review candidates.');
        }

        $ids = [];
        $seeds = $this->sources->get('supplied_seed')->search(new DiscoveryQuery([], 100, $input['candidates'] ?? []));
        foreach (array_slice($seeds, 0, 100) as $candidate) {
            if (! is_array($candidate) || empty($candidate['name']) || empty($candidate['website'])) continue;
            [$host] = $this->urlPolicy->validatePublicHttpUrl($candidate['website']);
            $domain = $this->domains->normalize($candidate['website'])['normalized_domain'];
            $company = Company::firstOrCreate(['tenant_id' => $context->tenantId, 'normalized_domain' => $domain], [
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
