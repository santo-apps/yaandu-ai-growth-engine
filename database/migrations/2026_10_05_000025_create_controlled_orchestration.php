<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('acquisition_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('workflow_type', 48)->default('B2B_ACQUISITION');
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->uuid('enrollment_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->uuid('opportunity_id')->nullable();
            $table->string('current_stage', 40)->default('DISCOVERY');
            $table->string('status', 32)->default('PENDING');
            $table->string('autonomy_policy', 24)->default('ASSISTED');
            $table->uuid('correlation_id');
            $table->unsignedSmallInteger('step_count')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'correlation_id']);
            $table->index(['tenant_id', 'status', 'updated_at']);
            $table->index(['tenant_id', 'current_stage', 'status']);
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->nullOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->nullOnDelete();
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->nullOnDelete();
            $table->foreign(['tenant_id', 'enrollment_id'])->references(['tenant_id', 'id'])->on('campaign_recipients')->nullOnDelete();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->nullOnDelete();
            $table->foreign(['tenant_id', 'opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->nullOnDelete();
        });

        Schema::create('workflow_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('workflow_id');
            $table->string('stage', 40);
            $table->string('event', 80);
            $table->string('source', 48)->default('application');
            $table->uuid('agent_run_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->uuid('correlation_id');
            $table->string('idempotency_key', 128);
            $table->jsonb('safe_metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'workflow_id', 'created_at']);
            $table->foreign(['tenant_id', 'workflow_id'])->references(['tenant_id', 'id'])->on('acquisition_workflows')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->nullOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('workflow_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('workflow_id');
            $table->string('action', 48);
            $table->string('target_type', 48)->nullable();
            $table->uuid('target_id')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->uuid('agent_run_id')->nullable();
            $table->string('risk', 16);
            $table->string('reason', 500);
            $table->jsonb('payload_snapshot');
            $table->string('payload_hash', 64);
            $table->string('status', 16)->default('PENDING');
            $table->string('idempotency_key', 128);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'requested_at']);
            $table->foreign(['tenant_id', 'workflow_id'])->references(['tenant_id', 'id'])->on('acquisition_workflows')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('tenant_automation_settings', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->string('autonomy_mode', 16)->default('ASSISTED');
            $table->unsignedInteger('daily_ai_call_limit')->nullable();
            $table->unsignedBigInteger('daily_token_limit')->nullable();
            $table->decimal('monthly_estimated_spend_limit', 12, 4)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('tenant_action_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 48);
            $table->string('policy', 24);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'action']);
            $table->foreign('tenant_id', 'tenant_action_policies_settings_fk')->references('tenant_id')->on('tenant_automation_settings')->cascadeOnDelete();
        });

        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('agent_key', 64)->nullable();
            $table->string('task_key', 64);
            $table->string('provider', 32);
            $table->string('model', 180);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reserved_tokens')->default(0);
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->string('status', 16)->default('RESERVED');
            $table->uuid('agent_run_id')->nullable();
            $table->uuid('workflow_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('idempotency_key', 128);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->nullOnDelete();
            $table->foreign(['tenant_id', 'workflow_id'])->references(['tenant_id', 'id'])->on('acquisition_workflows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('tenant_action_policies');
        Schema::dropIfExists('tenant_automation_settings');
        Schema::dropIfExists('workflow_approvals');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('acquisition_workflows');
    }
};
