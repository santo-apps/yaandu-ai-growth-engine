<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('ownership_state', 24)->default('AI_ACTIVE');
            $table->string('conversation_stage', 32)->default('NEW');
            $table->uuid('sales_opportunity_id')->nullable();
            $table->index(['tenant_id', 'ownership_state', 'updated_at'], 'sales_handoff_queue_idx');
            $table->foreign(['tenant_id', 'sales_opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->nullOnDelete();
        });

        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->uuid('contact_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->unsignedTinyInteger('qualification_score')->default(0);
            $table->string('qualification_level', 24)->default('LOW');
            $table->timestampTz('qualified_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('source', 40)->nullable();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->nullOnDelete();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->nullOnDelete();
            $table->index(['tenant_id', 'stage', 'status']);
        });

        Schema::create('tenant_sales_policies', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->jsonb('weights');
            $table->jsonb('thresholds');
            $table->jsonb('high_value_handoff')->nullable();
            $table->timestampsTz();
        });

        Schema::create('sales_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->uuid('sales_opportunity_id')->nullable();
            $table->uuid('agent_run_id');
            $table->text('body_ciphertext');
            $table->jsonb('evidence_references');
            $table->jsonb('knowledge_references');
            $table->jsonb('missing_information');
            $table->jsonb('qualification_snapshot');
            $table->string('intent', 40);
            $table->string('risk_level', 16);
            $table->decimal('confidence', 5, 4);
            $table->string('provider', 32);
            $table->string('model', 180);
            $table->string('task_key', 64)->default('sales_reasoning');
            $table->uuid('prompt_template_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('correlation_id', 64);
            $table->string('idempotency_key', 128);
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sales_opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->nullOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'prompt_template_id'])->references(['tenant_id', 'id'])->on('prompt_templates')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::table('opportunity_activities', function (Blueprint $table): void {
            $table->uuid('agent_run_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->foreign(['tenant_id', 'sales_opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->nullOnDelete();
        });

        DB::table('sales_opportunities')->whereIn('stage', ['new','engaged','discovery','qualified','meeting_ready','proposal_ready','closed','not_qualified','won','lost'])
            ->update(['stage' => DB::raw("CASE lower(stage) WHEN 'new' THEN 'NEW' WHEN 'engaged' THEN 'ENGAGED' WHEN 'discovery' THEN 'DISCOVERY' WHEN 'qualified' THEN 'QUALIFIED' WHEN 'meeting_ready' THEN 'MEETING_READY' WHEN 'proposal_ready' THEN 'PROPOSAL_READY' WHEN 'closed' THEN 'CLOSED' WHEN 'won' THEN 'CLOSED' ELSE 'NOT_QUALIFIED' END")]);
        DB::table('conversations')->whereIn('status', ['human_review','human_active','resolved'])->update(['ownership_state' => DB::raw("CASE status WHEN 'human_review' THEN 'HUMAN_REVIEW' WHEN 'human_active' THEN 'HUMAN_ACTIVE' ELSE 'RESOLVED' END")]);
    }

    public function down(): void
    {
        Schema::table('opportunity_activities', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'sales_opportunity_id']);
            $table->dropForeign(['tenant_id', 'agent_run_id']);
            $table->dropColumn(['agent_run_id', 'correlation_id']);
        });
        Schema::dropIfExists('sales_drafts');
        Schema::dropIfExists('tenant_sales_policies');
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'contact_id']);
            $table->dropForeign(['tenant_id', 'conversation_id']);
            $table->dropIndex(['tenant_id', 'stage', 'status']);
            $table->dropColumn(['contact_id', 'conversation_id', 'qualification_score', 'qualification_level', 'qualified_at', 'closed_at', 'source']);
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'sales_opportunity_id']);
            $table->dropIndex('sales_handoff_queue_idx');
            $table->dropColumn(['ownership_state', 'conversation_stage', 'sales_opportunity_id']);
        });
    }
};
