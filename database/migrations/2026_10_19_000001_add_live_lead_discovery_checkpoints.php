<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_discovery_candidates', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->nullable();
            $table->unique(['tenant_id', 'fingerprint'], 'lead_candidate_tenant_fingerprint_uq');
        });

        Schema::table('lead_discovery_runs', function (Blueprint $table): void {
            $table->unsignedInteger('raw_candidates')->default(0);
            $table->unsignedInteger('source_requests')->default(0);
        });

        Schema::create('lead_discovery_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('campaign_id');
            $table->uuid('run_id');
            $table->uuid('source_id');
            $table->string('query_key', 80);
            $table->text('query_text');
            $table->string('cursor', 80)->nullable();
            $table->unsignedSmallInteger('pages_completed')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('raw_results')->default(0);
            $table->string('status', 24)->default('queued');
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('last_requested_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'run_id', 'query_key'], 'lead_discovery_checkpoint_query_uq');
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('lead_discovery_campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('lead_discovery_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'source_id'])->references(['tenant_id', 'id'])->on('lead_discovery_sources')->restrictOnDelete();
            $table->index(['tenant_id', 'run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_discovery_checkpoints');
        Schema::table('lead_discovery_runs', function (Blueprint $table): void {
            $table->dropColumn(['raw_candidates', 'source_requests']);
        });
        Schema::table('lead_discovery_candidates', function (Blueprint $table): void {
            $table->dropUnique('lead_candidate_tenant_fingerprint_uq');
            $table->dropColumn('fingerprint');
        });
    }
};
