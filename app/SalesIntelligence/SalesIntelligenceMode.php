<?php

namespace App\SalesIntelligence;

use Illuminate\Support\Facades\DB;

final class SalesIntelligenceMode
{
    public const HUMAN_ASSISTED = 'human_assisted';
    public const EXPERIMENTAL_AUTONOMOUS = 'experimental_autonomous';

    public function forTenant(string $tenantId): string
    {
        $settings = DB::table('tenants')->where('id', $tenantId)->value('settings');
        $settings = is_array($settings) ? $settings : (json_decode((string) $settings, true) ?: []);
        $requested = $settings['sales_intelligence_mode'] ?? config('sales_intelligence.default_mode', self::HUMAN_ASSISTED);

        if ($requested === self::EXPERIMENTAL_AUTONOMOUS && config('sales_intelligence.experimental_autonomous_enabled')) {
            return self::EXPERIMENTAL_AUTONOMOUS;
        }

        return self::HUMAN_ASSISTED;
    }

    public function humanAssisted(string $tenantId): bool
    {
        return $this->forTenant($tenantId) === self::HUMAN_ASSISTED;
    }
}
