<?php

namespace App\Console\Commands;

use App\WebsiteIntelligence\WebsiteTechnicalEvidenceAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class EnrichWebsiteTechnicalEvidence extends Command
{
    protected $signature = 'website:evidence:enrich {tenant} {scan}';
    protected $description = 'Extract deterministic technical evidence from one already-completed tenant website scan without network or AI requests.';

    public function handle(WebsiteTechnicalEvidenceAnalyzer $analyzer): int
    {
        $tenantId = (string) $this->argument('tenant');
        $scanId = (string) $this->argument('scan');
        if (! DB::table('website_scans')->where('tenant_id', $tenantId)->where('id', $scanId)->where('status', 'completed')->exists()) {
            $this->error('No completed scan with that ID exists in the specified tenant. No data was changed.');
            return self::FAILURE;
        }

        $result = $analyzer->analyze($tenantId, $scanId);
        $this->info('Deterministic website evidence refreshed.');
        $this->line('Pages inspected: '.$result['pages_inspected']);
        $this->line('Issues: '.$result['issues']);
        $this->line('Technology observations: '.$result['technologies']);
        $this->line('Detector: '.$result['detector_version']);
        return self::SUCCESS;
    }
}
