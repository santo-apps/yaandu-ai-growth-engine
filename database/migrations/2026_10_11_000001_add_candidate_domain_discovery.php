<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('website_resolutions', function (Blueprint $table): void {
            $table->string('discovery_status', 32)->default('PENDING')->after('state');
            $table->jsonb('discovery_metrics')->nullable()->after('identity_snapshot');
            $table->index(['tenant_id', 'discovery_status', 'created_at'], 'website_resolutions_discovery_status_idx');
        });
        DB::table('website_resolutions')->where('discovery_status', 'PENDING')->whereIn('state', ['RESOLVED', 'AMBIGUOUS', 'UNRESOLVED', 'FAILED'])
            ->update(['discovery_status' => 'COMPLETED']);

        Schema::table('website_resolution_candidates', function (Blueprint $table): void {
            $table->string('result_type', 32)->default('UNKNOWN')->after('candidate_type');
            $table->unsignedSmallInteger('discovery_rank')->nullable()->after('source');
            $table->unsignedSmallInteger('discovery_score')->nullable()->after('discovery_rank');
        });

        Schema::create('website_resolution_search_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('resolution_id');
            $table->uuid('attempt_id');
            $table->uuid('resolution_candidate_id')->nullable();
            $table->string('source', 64);
            $table->char('query_hash', 64);
            $table->char('result_hash', 64);
            $table->text('query_text')->nullable();
            $table->text('result_url')->nullable();
            $table->text('target_url')->nullable();
            $table->string('title', 500)->nullable();
            $table->text('snippet')->nullable();
            $table->unsignedSmallInteger('source_rank')->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->string('result_type', 32)->default('UNKNOWN');
            $table->string('target_type', 32)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('retrieved_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'resolution_id', 'source', 'query_hash', 'result_hash'], 'website_resolution_search_result_unique');
            $table->foreign(['tenant_id', 'resolution_id'])->references(['tenant_id', 'id'])->on('website_resolutions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'attempt_id'])->references(['tenant_id', 'id'])->on('website_resolution_attempts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'resolution_candidate_id'])->references(['tenant_id', 'id'])->on('website_resolution_candidates')->cascadeOnDelete();
            $table->index(['tenant_id', 'resolution_id', 'source_rank'], 'website_resolution_search_results_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_resolution_search_results');
        Schema::table('website_resolution_candidates', function (Blueprint $table): void {
            $table->dropColumn(['result_type', 'discovery_rank', 'discovery_score']);
        });
        Schema::table('website_resolutions', function (Blueprint $table): void {
            $table->dropIndex('website_resolutions_discovery_status_idx');
            $table->dropColumn(['discovery_status', 'discovery_metrics']);
        });
    }
};
