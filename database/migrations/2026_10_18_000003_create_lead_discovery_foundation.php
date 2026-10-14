<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_discovery_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 80);
            $table->string('provider_name', 160);
            $table->string('source_type', 40);
            $table->string('authorization_status', 32)->default('pending');
            $table->string('allowed_collection_method', 80)->nullable();
            $table->string('retention_policy', 120)->nullable();
            $table->string('attribution_requirement', 255)->nullable();
            $table->jsonb('configuration')->nullable();
            $table->jsonb('rate_limit_config')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('health_state', 32)->default('not_configured');
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            // source_key identifies an adapter type, not a configured source instance.
            $table->index(['tenant_id', 'source_key']);
            $table->index(['tenant_id', 'enabled', 'authorization_status']);
        });

        Schema::create('lead_discovery_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('industry', 150);
            $table->char('country_code', 2);
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->jsonb('configuration')->nullable();
            $table->unsignedInteger('target_leads')->default(5000);
            $table->unsignedInteger('accepted_leads')->default(0);
            $table->unsignedInteger('processed_candidates')->default(0);
            $table->string('status', 32)->default('draft');
            $table->boolean('auto_promotion_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('lead_discovery_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('campaign_id');
            $table->uuid('source_id');
            $table->string('run_status', 32)->default('queued');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('candidates_found')->default(0);
            $table->unsignedInteger('duplicates_found')->default(0);
            $table->unsignedInteger('verified_candidates')->default(0);
            $table->unsignedInteger('eligible_candidates')->default(0);
            $table->unsignedInteger('promoted_candidates')->default(0);
            $table->unsignedInteger('rejected_candidates')->default(0);
            $table->unsignedInteger('source_errors')->default(0);
            $table->unsignedInteger('processing_errors')->default(0);
            $table->jsonb('cost_metrics')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'campaign_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('lead_discovery_campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_id'])->references(['tenant_id', 'id'])->on('lead_discovery_sources')->restrictOnDelete();
            $table->index(['tenant_id', 'campaign_id', 'run_status']);
        });

        Schema::create('lead_discovery_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('campaign_id');
            $table->uuid('run_id');
            $table->uuid('source_id');
            $table->string('company_name', 255);
            $table->string('normalized_name', 255);
            $table->text('claimed_website')->nullable();
            $table->string('normalized_domain', 253)->nullable();
            $table->string('official_domain_status', 32)->default('unverified');
            $table->string('industry', 150)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('location', 255)->nullable();
            $table->jsonb('raw_metadata')->nullable();
            $table->string('processing_status', 32)->default('discovered');
            $table->string('validation_status', 32)->default('pending');
            $table->string('promotion_status', 32)->default('not_promoted');
            $table->string('validation_reason_code', 80)->nullable();
            $table->text('validation_reason')->nullable();
            $table->uuid('matched_company_id')->nullable();
            $table->decimal('identity_confidence', 5, 4)->nullable();
            $table->decimal('website_confidence', 5, 4)->nullable();
            $table->decimal('eligibility_confidence', 5, 4)->nullable();
            $table->string('eligibility_status', 32)->default('needs_review');
            $table->jsonb('verification_facts')->nullable();
            $table->timestampTz('discovered_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('promoted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'campaign_id'], 'lead_candidates_tenant_campaign_fk')->references(['tenant_id', 'id'])->on('lead_discovery_campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'run_id'], 'lead_candidates_tenant_run_fk')->references(['tenant_id', 'id'])->on('lead_discovery_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_id'], 'lead_candidates_tenant_source_fk')->references(['tenant_id', 'id'])->on('lead_discovery_sources')->restrictOnDelete();
            $table->foreign(['tenant_id', 'matched_company_id'], 'lead_candidates_tenant_company_fk')->references(['tenant_id', 'id'])->on('companies')->nullOnDelete();
            $table->index(['tenant_id', 'campaign_id', 'normalized_domain'], 'lead_candidates_campaign_domain_idx');
            $table->index(['tenant_id', 'campaign_id', 'eligibility_status', 'promotion_status']);
            $table->index(['tenant_id', 'normalized_domain']);
            $table->index(['tenant_id', 'processing_status', 'validation_status']);
        });

        Schema::create('lead_discovery_candidate_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('candidate_id');
            $table->uuid('campaign_id');
            $table->uuid('source_id');
            $table->string('field_name', 80);
            $table->string('evidence_type', 40);
            $table->text('source_url')->nullable();
            $table->text('raw_value')->nullable();
            $table->text('normalized_value')->nullable();
            $table->decimal('confidence_score', 5, 4)->default(0.5);
            $table->timestampTz('collected_at');
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('attribution')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'candidate_id'], 'lead_evidence_tenant_candidate_fk')->references(['tenant_id', 'id'])->on('lead_discovery_candidates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campaign_id'], 'lead_evidence_tenant_campaign_fk')->references(['tenant_id', 'id'])->on('lead_discovery_campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_id'], 'lead_evidence_tenant_source_fk')->references(['tenant_id', 'id'])->on('lead_discovery_sources')->restrictOnDelete();
            $table->index(['tenant_id', 'candidate_id', 'field_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_discovery_candidate_evidence');
        Schema::dropIfExists('lead_discovery_candidates');
        Schema::dropIfExists('lead_discovery_runs');
        Schema::dropIfExists('lead_discovery_campaigns');
        Schema::dropIfExists('lead_discovery_sources');
    }
};
