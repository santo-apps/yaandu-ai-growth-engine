<?php

namespace App\Discovery;

use Illuminate\Support\Facades\DB;

final class DiscoveryAnalysisBudget
{
    public function reserve(string $tenantId, string $candidateId): bool
    {
        return DB::transaction(function () use ($tenantId, $candidateId): bool {
            $candidate = DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->lockForUpdate()->first();
            if (! $candidate || ! $candidate->eligible_for_analysis) return false;
            if ($candidate->analysis_reserved) return true;
            $run = DB::table('discovery_runs')->where('tenant_id', $tenantId)->where('id', $candidate->discovery_run_id)->lockForUpdate()->first();
            if (! $run) return false;
            $budget = is_array($run->budget) ? $run->budget : (json_decode((string) $run->budget, true) ?: []);
            $max = max(0, (int) ($budget['max_ai_analyses'] ?? config('discovery.max_ai_analyses_per_run', 25)));
            $used = max(0, (int) ($budget['ai_analyses_reserved'] ?? 0));
            if ($used >= $max) {
                DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->update([
                    'analysis_status' => 'budget_reached', 'analysis_reason' => 'Analysis budget reached for this run.', 'updated_at' => now(),
                ]);
                return false;
            }
            $budget['ai_analyses_reserved'] = $used + 1;
            DB::table('discovery_runs')->where('tenant_id', $tenantId)->where('id', $run->id)->update(['budget' => json_encode($budget), 'updated_at' => now()]);
            DB::table('discovery_candidates')->where('tenant_id', $tenantId)->where('id', $candidateId)->update([
                'analysis_reserved' => true, 'analysis_status' => 'queued', 'analysis_reason' => null, 'updated_at' => now(),
            ]);
            return true;
        });
    }
}
