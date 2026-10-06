<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table): void {
            $table->text('system_instruction')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agent_key', 'status', 'version']);
        });

        Schema::create('tenant_marketing_knowledge', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('title', 180);
            $table->text('content');
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'kind']);
        });

        Schema::create('marketing_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('company_id');
            $table->uuid('contact_id')->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->uuid('agent_run_id')->nullable();
            $table->uuid('campaign_template_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('supersedes_id')->nullable();
            $table->string('subject', 500);
            $table->text('message');
            $table->text('reasoning_summary');
            $table->jsonb('personalization_points');
            $table->jsonb('evidence_references');
            $table->string('recommended_call_to_action', 500);
            $table->decimal('confidence', 5, 4);
            $table->string('provider', 32);
            $table->string('model', 180);
            $table->string('task_key', 64)->default('content_generation');
            $table->uuid('prompt_template_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('correlation_id', 64);
            $table->string('idempotency_key', 128)->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'company_id', 'version'], 'marketing_draft_version_unique');
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs');
            $table->foreign(['tenant_id', 'campaign_template_id'])->references(['tenant_id', 'id'])->on('campaign_templates');
            $table->foreign(['tenant_id', 'prompt_template_id'])->references(['tenant_id', 'id'])->on('prompt_templates');
            $table->foreign(['tenant_id', 'supersedes_id'])->references(['tenant_id', 'id'])->on('marketing_drafts');
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('follow_up_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('conversation_id');
            $table->uuid('agent_run_id')->nullable();
            $table->string('action', 32);
            $table->string('intent', 32);
            $table->decimal('confidence', 5, 4);
            $table->text('reasoning_summary');
            $table->text('draft_message')->nullable();
            $table->jsonb('evidence_references');
            $table->unsignedSmallInteger('recommended_delay_hours')->default(0);
            $table->boolean('requires_human_review')->default(true);
            $table->string('provider', 32);
            $table->string('model', 180);
            $table->string('task_key', 64)->default('sales_reasoning');
            $table->uuid('prompt_template_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('correlation_id', 64);
            $table->string('idempotency_key', 128)->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'correlation_id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs');
            $table->foreign(['tenant_id', 'prompt_template_id'])->references(['tenant_id', 'id'])->on('prompt_templates');
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_recommendations');
        Schema::dropIfExists('marketing_drafts');
        Schema::dropIfExists('tenant_marketing_knowledge');
        Schema::table('prompt_templates', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'agent_key', 'status', 'version']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['system_instruction', 'status', 'created_by', 'approved_by', 'approved_at']);
        });
    }
};
