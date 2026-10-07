<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discovery_searches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('status', 32)->default('active');
            $table->jsonb('criteria')->nullable();
            $table->string('source', 40);
            $table->unsignedSmallInteger('max_candidates')->default(25);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('discovery_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('discovery_search_id')->nullable();
            $table->uuid('agent_run_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('queued');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('counts')->nullable();
            $table->jsonb('source_counts')->nullable();
            $table->jsonb('budget')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'discovery_search_id'], 'discovery_runs_tenant_search_fk')->references(['tenant_id', 'id'])->on('discovery_searches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'agent_run_id'], 'discovery_runs_tenant_agent_run_fk')->references(['tenant_id', 'id'])->on('agent_runs')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('discovery_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('discovery_run_id');
            $table->uuid('company_id')->nullable();
            $table->string('company_name', 255)->nullable();
            $table->text('original_url')->nullable();
            $table->string('normalized_domain', 253)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('industry', 150)->nullable();
            $table->string('source', 80);
            $table->text('source_reference')->nullable();
            $table->timestampTz('discovered_at');
            $table->string('lifecycle_status', 32)->default('discovered');
            $table->string('verification_state', 32)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('page_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('has_mobile_viewport')->nullable();
            $table->boolean('uses_https')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('recommended_service', 150)->nullable();
            $table->jsonb('recommendation_evidence')->nullable();
            $table->string('deduplication_state', 32)->default('new');
            $table->text('deduplication_reason')->nullable();
            $table->string('import_state', 32)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_summary', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'discovery_run_id'], 'discovery_candidates_tenant_run_fk')->references(['tenant_id', 'id'])->on('discovery_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'company_id'], 'discovery_candidates_tenant_company_fk')->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->unique(['tenant_id', 'discovery_run_id', 'source_reference'], 'discovery_candidate_source_ref_unique');
            $table->index(['tenant_id', 'discovery_run_id', 'lifecycle_status']);
            $table->index(['tenant_id', 'normalized_domain']);
            $table->index(['tenant_id', 'verification_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_candidates');
        Schema::dropIfExists('discovery_runs');
        Schema::dropIfExists('discovery_searches');
    }
};
