<?php

namespace App\Console\Commands;

use App\WebsiteIntelligence\WebsiteIntelligencePilotPrompt;
use App\WebsiteIntelligence\WebsiteIntelligencePilotPromptV2;
use App\WebsiteIntelligence\WebsiteIntelligencePilotPromptV3;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PrepareWebsiteIntelligencePrompt extends Command
{
    protected $signature = 'pilot:prepare-website-intelligence-prompt {tenant} {--prompt-version=1}';
    protected $description = 'Create an inactive draft Website Intelligence prompt for human review; never approves it.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This draft preparation command is restricted to local/testing environments.');
            return self::FAILURE;
        }
        $versionOption = (int) $this->option('prompt-version');
        if (! in_array($versionOption, [1, 2, 3], true)) {
            $this->error('Supported draft versions are 1, 2 and 3.');
            return self::FAILURE;
        }
        $schemaVersion = match ($versionOption) {
            3 => WebsiteIntelligencePilotPromptV3::SCHEMA_VERSION,
            2 => WebsiteIntelligencePilotPromptV2::SCHEMA_VERSION,
            default => WebsiteIntelligencePilotPrompt::SCHEMA_VERSION,
        };
        $systemInstruction = match ($versionOption) {
            3 => WebsiteIntelligencePilotPromptV3::SYSTEM_INSTRUCTION,
            2 => WebsiteIntelligencePilotPromptV2::SYSTEM_INSTRUCTION,
            default => WebsiteIntelligencePilotPrompt::SYSTEM_INSTRUCTION,
        };
        $template = match ($versionOption) {
            3 => WebsiteIntelligencePilotPromptV3::TEMPLATE,
            2 => WebsiteIntelligencePilotPromptV2::TEMPLATE,
            default => WebsiteIntelligencePilotPrompt::TEMPLATE,
        };
        $tenantId = (string) $this->argument('tenant');
        if (! DB::table('tenants')->where('id', $tenantId)->where('status', 'active')->exists()) {
            $this->error('Tenant not found. No prompt was created.');
            return self::FAILURE;
        }
        $existing = DB::table('prompt_templates')->where('tenant_id', $tenantId)->where('agent_key', 'WebsiteIntelligenceAgent')
            ->where('schema_version', $schemaVersion)->first();
        if ($existing) {
            $this->line('Prompt version already exists; current status: '.$existing->status.'. No change was made.');
            return self::SUCCESS;
        }
        $id = (string) Str::uuid();
        $version = (int) DB::table('prompt_templates')->where('tenant_id', $tenantId)->where('agent_key', 'WebsiteIntelligenceAgent')->max('version') + 1;
        DB::transaction(function () use ($tenantId, $id, $version, $schemaVersion, $systemInstruction, $template): void {
            DB::table('prompt_templates')->insert(['id' => $id, 'tenant_id' => $tenantId, 'agent_key' => 'WebsiteIntelligenceAgent', 'version' => $version,
                'system_instruction' => $systemInstruction, 'template' => $template,
                'schema_version' => $schemaVersion, 'active' => false, 'status' => 'draft', 'created_by' => null,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => null,
                'action' => 'website_intelligence_prompt.draft_prepared', 'subject_type' => 'prompt_template', 'subject_id' => $id,
                'metadata' => json_encode(['agent_key' => 'WebsiteIntelligenceAgent', 'version' => $version, 'schema_version' => $schemaVersion]),
                'created_at' => now()]);
        });
        $this->info("Created WebsiteIntelligenceAgent prompt version {$version} as inactive draft. A tenant manager must review and approve it.");
        return self::SUCCESS;
    }
}
