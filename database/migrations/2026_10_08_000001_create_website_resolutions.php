<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('website_resolutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('candidate_id');
            $table->uuid('discovery_run_id');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 32)->default('PENDING');
            $table->jsonb('identity_snapshot');
            $table->uuid('resolved_candidate_id')->nullable();
            $table->string('resolved_domain', 253)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('confidence_band', 16)->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->string('failure_summary', 500)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'candidate_id'])->references(['tenant_id', 'id'])->on('discovery_candidates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'discovery_run_id'])->references(['tenant_id', 'id'])->on('discovery_runs')->cascadeOnDelete();
            $table->index(['tenant_id', 'candidate_id', 'created_at']);
            $table->index(['tenant_id', 'state', 'created_at']);
        });

        Schema::create('website_resolution_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('resolution_id');
            $table->string('normalized_domain', 253);
            $table->text('candidate_url');
            $table->string('source', 64);
            $table->string('candidate_type', 32)->default('business');
            $table->string('status', 24)->default('proposed');
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('confidence_band', 16)->default('LOW');
            $table->jsonb('match_summary')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'resolution_id', 'normalized_domain']);
            $table->foreign(['tenant_id', 'resolution_id'])->references(['tenant_id', 'id'])->on('website_resolutions')->cascadeOnDelete();
            $table->index(['tenant_id', 'resolution_id', 'score']);
        });

        Schema::create('website_resolution_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('resolution_id');
            $table->uuid('resolution_candidate_id')->nullable();
            $table->string('source', 64);
            $table->string('signal', 48);
            $table->string('polarity', 12);
            $table->smallInteger('points')->default(0);
            $table->string('evidence_key', 64);
            $table->string('source_reference', 500)->nullable();
            $table->text('summary');
            $table->jsonb('details')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'resolution_id', 'evidence_key']);
            $table->foreign(['tenant_id', 'resolution_id'])->references(['tenant_id', 'id'])->on('website_resolutions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'resolution_candidate_id'])->references(['tenant_id', 'id'])->on('website_resolution_candidates')->cascadeOnDelete();
            $table->index(['tenant_id', 'resolution_candidate_id', 'signal']);
        });

        Schema::create('website_resolution_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('resolution_id');
            $table->string('source', 64);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('state', 24);
            $table->string('failure_code', 48)->nullable();
            $table->string('failure_summary', 500)->nullable();
            $table->jsonb('metrics')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'resolution_id', 'source', 'attempt_number']);
            $table->foreign(['tenant_id', 'resolution_id'])->references(['tenant_id', 'id'])->on('website_resolutions')->cascadeOnDelete();
            $table->index(['tenant_id', 'resolution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_resolution_attempts');
        Schema::dropIfExists('website_resolution_evidence');
        Schema::dropIfExists('website_resolution_candidates');
        Schema::dropIfExists('website_resolutions');
    }
};
