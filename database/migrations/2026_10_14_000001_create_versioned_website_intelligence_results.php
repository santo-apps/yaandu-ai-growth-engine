<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('website_intelligence_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('company_id');
            $table->uuid('website_scan_id');
            $table->uuid('agent_run_id');
            $table->uuid('prompt_template_id');
            $table->unsignedInteger('prompt_version');
            $table->string('schema_version', 64);
            $table->string('provider', 32);
            $table->string('model', 180);
            $table->string('correlation_id', 64);
            $table->decimal('confidence', 5, 4);
            $table->jsonb('structured_output');
            $table->unsignedInteger('provider_latency_ms')->nullable();
            $table->unsignedInteger('execution_duration_ms')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'agent_run_id'], 'website_intelligence_results_run_unique');
            $table->unique(['tenant_id', 'id'], 'website_intelligence_results_tenant_id_unique');
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'website_scan_id'])->references(['tenant_id', 'id'])->on('website_scans')->restrictOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'prompt_template_id'])->references(['tenant_id', 'id'])->on('prompt_templates')->restrictOnDelete();
            $table->index(['tenant_id', 'company_id', 'created_at'], 'website_intel_results_company_time_idx');
        });

        Schema::table('website_issues', function (Blueprint $table): void {
            $table->uuid('agent_run_id')->nullable();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->restrictOnDelete();
            $table->index(['tenant_id', 'website_scan_id', 'agent_run_id'], 'website_issues_scan_run_idx');
        });
        Schema::table('website_technologies', function (Blueprint $table): void {
            $table->uuid('agent_run_id')->nullable();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->restrictOnDelete();
            $table->index(['tenant_id', 'website_scan_id', 'agent_run_id'], 'website_technologies_scan_run_idx');
        });
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->unsignedInteger('provider_latency_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', fn (Blueprint $table) => $table->dropColumn('provider_latency_ms'));
        Schema::table('website_technologies', function (Blueprint $table): void {
            $table->dropIndex('website_technologies_scan_run_idx');
            $table->dropForeign(['tenant_id', 'agent_run_id']);
            $table->dropColumn('agent_run_id');
        });
        Schema::table('website_issues', function (Blueprint $table): void {
            $table->dropIndex('website_issues_scan_run_idx');
            $table->dropForeign(['tenant_id', 'agent_run_id']);
            $table->dropColumn('agent_run_id');
        });
        Schema::dropIfExists('website_intelligence_results');
    }
};
