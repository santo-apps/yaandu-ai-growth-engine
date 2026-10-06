<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->uuid('sales_opportunity_id')->nullable();
            $table->uuid('source_message_id');
            $table->uuid('agent_run_id');
            $table->string('idempotency_key', 128);
            $table->string('intent', 40);
            $table->string('risk_level', 16);
            $table->decimal('confidence', 5, 4);
            $table->jsonb('qualification_snapshot');
            $table->jsonb('missing_information');
            $table->jsonb('evidence_references');
            $table->jsonb('knowledge_references');
            $table->text('reasoning_summary');
            $table->boolean('requires_human_review');
            $table->string('recommended_action', 32);
            $table->string('provider', 32);
            $table->string('model', 180);
            $table->uuid('prompt_template_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('correlation_id', 64);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sales_opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->nullOnDelete();
            $table->foreign(['tenant_id', 'source_message_id'])->references(['tenant_id', 'id'])->on('conversation_messages')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'prompt_template_id'])->references(['tenant_id', 'id'])->on('prompt_templates')->nullOnDelete();
            $table->index(['tenant_id', 'conversation_id', 'created_at']);
        });
        Schema::table('sales_drafts', function (Blueprint $table): void {
            $table->uuid('sales_analysis_id')->nullable();
            $table->foreign(['tenant_id', 'sales_analysis_id'])->references(['tenant_id', 'id'])->on('sales_analyses')->nullOnDelete();
            $table->unique(['tenant_id', 'sales_analysis_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_drafts', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'sales_analysis_id']);
            $table->dropUnique(['tenant_id', 'sales_analysis_id']);
            $table->dropColumn('sales_analysis_id');
        });
        Schema::dropIfExists('sales_analyses');
    }
};
