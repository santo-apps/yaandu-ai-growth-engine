<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_human_sales_reviews', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'human_sales_review_tenant_id_unique');
        });
        Schema::create('outreach_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('company_id');
            $table->uuid('human_sales_review_id');
            $table->uuid('selected_service_id');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('supersedes_id')->nullable();
            $table->jsonb('validated_findings');
            $table->jsonb('draft_payload');
            $table->string('provider', 32);
            $table->string('model', 180);
            $table->string('task_key', 64)->default('outreach_drafting');
            $table->uuid('prompt_template_id');
            $table->unsignedInteger('prompt_version');
            $table->string('correlation_id', 64);
            $table->string('idempotency_key', 128);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'company_id', 'version']);
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'human_sales_review_id'])->references(['tenant_id', 'id'])->on('company_human_sales_reviews')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'selected_service_id'])->references(['tenant_id', 'id'])->on('tenant_services')->restrictOnDelete();
            $table->foreign(['tenant_id', 'prompt_template_id'])->references(['tenant_id', 'id'])->on('prompt_templates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supersedes_id'])->references(['tenant_id', 'id'])->on('outreach_drafts')->restrictOnDelete();
            $table->index(['tenant_id', 'company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_drafts');
        Schema::table('company_human_sales_reviews', function (Blueprint $table): void {
            $table->dropUnique('human_sales_review_tenant_id_unique');
        });
    }
};
