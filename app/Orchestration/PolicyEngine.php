<?php

namespace App\Orchestration;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PolicyEngine
{
    public function __construct(private readonly ActionRegistry $registry) {}

    public function evaluate(string $tenantId, string $action): array
    {
        $definition = $this->registry->get($action);
        if (! $definition) throw new InvalidArgumentException('Action is not registered.');
        $settings = DB::table('tenant_automation_settings')->where('tenant_id', $tenantId)->first();
        $mode = AutonomyMode::tryFrom($settings->autonomy_mode ?? 'ASSISTED') ?? AutonomyMode::Assisted;
        $modePolicy = match ($mode) {
            AutonomyMode::Manual => ActionPolicy::ApprovalRequired,
            AutonomyMode::Assisted => ActionPolicy::AutoAllowed,
            AutonomyMode::Controlled => in_array($action, ['RUN_WEBSITE_ANALYSIS','RUN_LEAD_SCORING','GENERATE_MARKETING_DRAFT','GENERATE_FOLLOW_UP','RUN_SALES_ANALYSIS','GENERATE_PROPOSAL','CREATE_OPPORTUNITY','HUMAN_HANDOFF'], true)
                ? ActionPolicy::AutoAllowed : ActionPolicy::ApprovalRequired,
        };
        $tenantPolicy = DB::table('tenant_action_policies')->where('tenant_id', $tenantId)->where('action', $action)->value('policy');
        $configured = ActionPolicy::tryFrom((string) $tenantPolicy) ?? $definition['default'];
        $policy = $this->stricter($this->stricter($definition['default'], $modePolicy), $configured);

        return ['action' => $action, 'policy' => $policy->value, 'risk' => $definition['risk'], 'permission' => $definition['permission'],
            'side_effect' => $definition['side_effect'], 'idempotency_required' => $definition['idempotent'], 'mode' => $mode->value];
    }

    public function validateTenantPolicy(string $action, string $policy): ActionPolicy
    {
        $definition = $this->registry->get($action);
        $requested = ActionPolicy::tryFrom($policy);
        if (! $definition || ! $requested) throw new InvalidArgumentException('Unsupported action policy.');
        if ($requested->restrictiveness() < $definition['default']->restrictiveness()) throw new InvalidArgumentException('Tenant policy cannot weaken system policy.');
        return $requested;
    }

    private function stricter(ActionPolicy $left, ActionPolicy $right): ActionPolicy
    {
        return $left->restrictiveness() >= $right->restrictiveness() ? $left : $right;
    }
}
