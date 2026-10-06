<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\LeadScoring\ScoringRuleEvaluator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScoringRuleController extends Controller
{
    public function show()
    {
        $settings = DB::table('tenants')->where('id', app('tenant.id'))->value('settings');
        $scoring = is_array($settings) ? ($settings['scoring'] ?? []) : (json_decode($settings ?? '{}', true)['scoring'] ?? []);
        return ['version' => $scoring['version'] ?? 1, 'rules' => $scoring['rules'] ?? ScoringRuleEvaluator::DEFAULT_RULES,
            'icp' => $scoring['icp'] ?? ['industries' => [], 'locations' => [], 'keywords' => []]];
    }

    public function update(Request $request)
    {
        abort_unless(in_array($request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role'), ['owner', 'admin'], true), 403);
        $data = $request->validate([
            'rules' => ['sometimes', 'array', 'min:1'], 'rules.*' => ['required', 'integer', 'min:0', 'max:100'],
            'icp' => ['sometimes', 'array:industries,locations,keywords'],
            'icp.industries' => ['sometimes', 'array', 'max:30'], 'icp.industries.*' => ['required', 'string', 'max:150'],
            'icp.locations' => ['sometimes', 'array', 'max:30'], 'icp.locations.*' => ['required', 'string', 'max:150'],
            'icp.keywords' => ['sometimes', 'array', 'max:50'], 'icp.keywords.*' => ['required', 'string', 'max:80'],
        ]);
        $unknown = array_diff(array_keys($data['rules'] ?? []), array_keys(ScoringRuleEvaluator::DEFAULT_RULES));
        if ($unknown) return response()->json(['message' => 'Unknown scoring keys.', 'keys' => array_values($unknown)], 422);
        $tenant = DB::table('tenants')->where('id', app('tenant.id'))->first();
        $settings = is_array($tenant->settings) ? $tenant->settings : (json_decode($tenant->settings ?? '{}', true) ?? []);
        $current = $settings['scoring'] ?? [];
        $settings['scoring'] = [
            'version' => (int) ($current['version'] ?? 1) + (isset($data['rules']) ? 1 : 0),
            'rules' => isset($data['rules']) ? [...ScoringRuleEvaluator::DEFAULT_RULES, ...$data['rules']] : ($current['rules'] ?? ScoringRuleEvaluator::DEFAULT_RULES),
            'icp' => isset($data['icp']) ? [
                'industries' => array_values(array_unique(array_map('trim', $data['icp']['industries'] ?? []))),
                'locations' => array_values(array_unique(array_map('trim', $data['icp']['locations'] ?? []))),
                'keywords' => array_values(array_unique(array_map('trim', $data['icp']['keywords'] ?? []))),
            ] : ($current['icp'] ?? ['industries' => [], 'locations' => [], 'keywords' => []]),
        ];
        DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
        return ['version' => $settings['scoring']['version'], 'rules' => $settings['scoring']['rules'], 'icp' => $settings['scoring']['icp']];
    }
}
