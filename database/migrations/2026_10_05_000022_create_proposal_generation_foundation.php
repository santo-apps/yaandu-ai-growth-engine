<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_services', function (Blueprint $table): void {
            $table->string('category', 100)->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('standard_deliverables')->nullable();
            $table->jsonb('optional_deliverables')->nullable();
            $table->string('commercial_model', 32)->default('FIXED_PRICE');
            $table->string('unit', 60)->default('project');
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('proposal_items', function (Blueprint $table): void {
            $table->string('unit', 60)->default('project');
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->string('discount_reason', 500)->nullable();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('discount_approved_at')->nullable();
            $table->string('price_override_reason', 500)->nullable();
            $table->foreignId('price_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('price_approved_at')->nullable();
        });

        Schema::create('proposal_internal_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('proposal_id');
            $table->text('note_ciphertext');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'proposal_id'])->references(['tenant_id', 'id'])->on('proposals')->cascadeOnDelete();
            $table->index(['tenant_id', 'proposal_id', 'created_at']);
        });

        Schema::table('proposals', function (Blueprint $table): void {
            $table->uuid('latest_version_id')->nullable();
            $table->text('safe_generation_error')->nullable();
            $table->timestampTz('ready_to_send_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'sales_opportunity_id', 'updated_at']);
        });

        Schema::create('proposal_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('proposal_id');
            $table->uuid('company_id');
            $table->uuid('contact_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->jsonb('requested_services')->default('[]');
            $table->jsonb('business_requirements')->default('[]');
            $table->jsonb('business_objectives')->default('[]');
            $table->jsonb('known_pain_points')->default('[]');
            $table->jsonb('technical_requirements')->default('[]');
            $table->jsonb('deliverables')->default('[]');
            $table->jsonb('constraints')->default('[]');
            $table->string('timeline_type', 16)->nullable();
            $table->string('requested_timeline', 255)->nullable();
            $table->text('approved_budget_information')->nullable();
            $table->text('special_notes')->nullable();
            $table->jsonb('source_references')->default('[]');
            $table->boolean('qualification_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'proposal_id'])->references(['tenant_id', 'id'])->on('proposals')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->nullOnDelete();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->nullOnDelete();
            $table->index(['tenant_id', 'proposal_id', 'created_at']);
        });

        Schema::create('proposal_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('proposal_id');
            $table->unsignedInteger('version');
            $table->uuid('agent_run_id')->nullable();
            $table->uuid('prompt_template_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('model', 180)->nullable();
            $table->jsonb('requirements_snapshot');
            $table->jsonb('source_snapshot');
            $table->jsonb('draft_content');
            $table->jsonb('human_edits')->default('{}');
            $table->jsonb('requested_scope')->default('[]');
            $table->jsonb('recommended_scope')->default('[]');
            $table->jsonb('approved_scope')->default('[]');
            $table->jsonb('commercial_snapshot')->default('{}');
            $table->string('timeline_type', 16)->nullable();
            $table->date('committed_delivery_date')->nullable();
            $table->string('status', 32)->default('review_required');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('document_key', 500)->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestampTz('document_generated_at')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'proposal_id', 'version']);
            $table->unique(['tenant_id', 'proposal_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'proposal_id'])->references(['tenant_id', 'id'])->on('proposals')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'])->references(['tenant_id', 'id'])->on('agent_runs')->nullOnDelete();
            $table->foreign(['tenant_id', 'prompt_template_id'])->references(['tenant_id', 'id'])->on('prompt_templates')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_versions');
        Schema::dropIfExists('proposal_requirements');
        Schema::dropIfExists('proposal_internal_notes');
        Schema::table('proposal_items', function (Blueprint $table): void {
            $table->dropForeign(['discount_approved_by']);
            $table->dropForeign(['price_approved_by']);
            $table->dropColumn(['unit', 'tax_amount', 'discount_amount', 'discount_reason', 'discount_approved_by', 'discount_approved_at', 'price_override_reason', 'price_approved_by', 'price_approved_at']);
        });
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropForeign(['generated_by']);
            $table->dropIndex(['tenant_id', 'sales_opportunity_id', 'updated_at']);
            $table->dropColumn(['latest_version_id', 'generated_by', 'safe_generation_error', 'ready_to_send_at']);
        });
        Schema::table('tenant_services', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['category', 'capabilities', 'standard_deliverables', 'optional_deliverables', 'commercial_model', 'unit', 'effective_from', 'effective_until', 'approved_by']);
        });
    }
};
