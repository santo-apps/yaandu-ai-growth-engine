<?php

namespace App\Agents;

use App\LeadScoring\ScoringRuleEvaluator;
use App\LeadScoring\LeadEvidenceBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LeadScoringAgent implements AgentInterface
{
    public function __construct(private readonly ScoringRuleEvaluator $evaluator, private readonly LeadEvidenceBuilder $evidenceBuilder) {}
    public function name(): string { return 'LeadScoringAgent'; }
    public function description(): string { return 'Score a tenant company using configured deterministic rules and persisted evidence.'; }
    public function inputSchema(): array { return ['required' => ['company_id']]; }
    public function outputSchema(): array { return ['required' => ['score', 'components', 'rule_version']]; }
    public function tools(): array { return []; }

    public function execute(AgentContext $context, array $input): AgentResult
    {
        $company = DB::table('companies')->where('id', $input['company_id'])->where('tenant_id', $context->tenantId)->first();
        if (! $company) throw new RuntimeException('Company not found in this tenant.');
        $evidence = $this->evidenceBuilder->build($context->tenantId, $company->id);
        $settings = DB::table('tenants')->where('id', $context->tenantId)->value('settings');
        $tenantScoring = is_array($settings) ? ($settings['scoring'] ?? []) : (json_decode($settings ?? '{}', true)['scoring'] ?? []);
        $rules = $tenantScoring['rules'] ?? ScoringRuleEvaluator::DEFAULT_RULES;
        $score = $this->evaluator->score($evidence, $rules, (int) ($tenantScoring['version'] ?? 1));
        $existing = DB::table('lead_scores')->where('tenant_id', $context->tenantId)->where('agent_run_id', $context->runId)->first(['id', 'created_at']);
        DB::table('lead_scores')->updateOrInsert(['id' => $existing?->id ?? (string) Str::uuid()], [
            'tenant_id' => $context->tenantId, 'company_id' => $company->id, 'score' => $score['score'],
            'components' => json_encode($score['components']), 'rule_version' => $score['rule_version'],
            'agent_run_id' => $context->runId, 'scored_at' => now(), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
        return new AgentResult($score, 'Company scored '.$score['score'].' / 100.', $evidence);
    }
}
