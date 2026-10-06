<?php

namespace App\AI;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ApprovedPromptRepository
{
    public function get(string $tenantId, string $agentKey, string $fallbackSystem): ApprovedPromptTemplate
    {
        $prompt = DB::table('prompt_templates')->where('tenant_id', $tenantId)->where('agent_key', $agentKey)
            ->where('status', 'approved')->where('active', true)->orderByDesc('version')->first();
        if (! $prompt) throw new RuntimeException('No approved active prompt is configured for this agent.');

        return new ApprovedPromptTemplate($prompt->id, (int) $prompt->version,
            trim((string) ($prompt->system_instruction ?: $fallbackSystem)), (string) $prompt->template);
    }
}
