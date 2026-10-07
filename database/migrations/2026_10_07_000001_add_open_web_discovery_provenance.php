<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('discovery_candidates', function (Blueprint $table): void {
            $table->string('analysis_status', 32)->default('not_eligible');
            $table->boolean('eligible_for_analysis')->default(false);
            $table->text('analysis_reason')->nullable();
            $table->boolean('analysis_reserved')->default(false);
        });
        Schema::create('discovery_candidate_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('candidate_id');
            $table->uuid('discovery_run_id');
            $table->string('source', 80);
            $table->text('source_reference')->nullable();
            $table->timestampTz('source_timestamp')->nullable();
            $table->timestampTz('discovered_at');
            $table->jsonb('source_query')->nullable();
            $table->jsonb('source_metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'candidate_id', 'source', 'source_reference'], 'discovery_candidate_sources_identity_unique');
            $table->foreign(['tenant_id', 'candidate_id'], 'candidate_sources_tenant_candidate_fk')->references(['tenant_id', 'id'])->on('discovery_candidates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'discovery_run_id'], 'candidate_sources_tenant_run_fk')->references(['tenant_id', 'id'])->on('discovery_runs')->cascadeOnDelete();
            $table->index(['tenant_id', 'discovery_run_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_candidate_sources');
        Schema::table('discovery_candidates', function (Blueprint $table): void {
            $table->dropColumn(['analysis_status', 'eligible_for_analysis', 'analysis_reason', 'analysis_reserved']);
        });
    }
};
