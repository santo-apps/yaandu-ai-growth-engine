<?php

use App\WebsiteIntelligence\WebsiteIntelligenceFailureTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_scores', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'lead_scores_tenant_id_id_unique');
        });

        Schema::table('website_scans', function (Blueprint $table): void {
            $table->string('failure_category', 32)->nullable();
            $table->string('retryable', 7)->default('UNKNOWN');
            $table->text('safe_error_summary')->nullable();
            $table->uuid('original_scan_id')->nullable();
            $table->index(['tenant_id', 'failure_category'], 'website_scans_tenant_failure_category_idx');
            $table->foreign(['tenant_id', 'original_scan_id'], 'website_scans_tenant_original_scan_fk')
                ->references(['tenant_id', 'id'])->on('website_scans')->restrictOnDelete();
        });

        $taxonomy = new WebsiteIntelligenceFailureTaxonomy();
        DB::table('website_scans')->where('status', 'failed')->orderBy('id')->chunkById(200, function ($scans) use ($taxonomy): void {
            foreach ($scans as $scan) {
                $candidate = strtoupper((string) ($scan->error_code ?? ''));
                $category = in_array($candidate, ['DNS', 'TLS', 'HTTP_4XX', 'HTTP_5XX', 'ROBOTS', 'REDIRECT_POLICY', 'SSRF_POLICY', 'TIMEOUT', 'UNSUPPORTED_CONTENT', 'RENDER_FAILURE', 'PARSER_FAILURE'], true)
                    ? $candidate
                    : 'UNKNOWN';
                DB::table('website_scans')->where('tenant_id', $scan->tenant_id)->where('id', $scan->id)->update([
                    'failure_category' => $category,
                    // Historical generic CRAWL_FAILED rows cannot prove retry safety.
                    'retryable' => $category === 'UNKNOWN' ? 'UNKNOWN' : $taxonomy->retryability($category),
                    'safe_error_summary' => $taxonomy->safeSummary($category),
                    // Existing scan records represent their own original attempt. Future retries can point here.
                    'original_scan_id' => $scan->id,
                ]);
            }
        }, 'id');

        Schema::create('pilot_review_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('prospect_import_row_id');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at');
            $table->string('review_type', 32);
            $table->uuid('intelligence_run_id')->nullable();
            $table->uuid('lead_score_id')->nullable();
            $table->unsignedInteger('review_version')->default(1);
            $table->string('intelligence_rating', 32)->nullable();
            $table->unsignedTinyInteger('rubric_score')->nullable();
            $table->string('lead_score_rating', 32)->nullable();
            $table->string('recommendation_rating', 32)->nullable();
            $table->string('technology_accuracy_rating', 24)->nullable();
            $table->string('next_action_rating', 24)->nullable();
            $table->unsignedSmallInteger('claim_count')->default(0);
            $table->unsignedSmallInteger('unsupported_claim_count')->default(0);
            $table->jsonb('claim_reviews')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'pilot_review_history_tenant_id_id_unique');
            $table->unique(['tenant_id', 'prospect_import_row_id', 'review_version'], 'pilot_review_history_version_unique');
            $table->foreign(['tenant_id', 'prospect_import_row_id'], 'pilot_review_history_tenant_row_fk')
                ->references(['tenant_id', 'id'])->on('prospect_import_rows')->restrictOnDelete();
            $table->foreign(['tenant_id', 'intelligence_run_id'], 'pilot_review_history_tenant_run_fk')
                ->references(['tenant_id', 'id'])->on('agent_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'lead_score_id'], 'pilot_review_history_tenant_score_fk')
                ->references(['tenant_id', 'id'])->on('lead_scores')->restrictOnDelete();
            $table->index(['tenant_id', 'prospect_import_row_id', 'reviewed_at'], 'pilot_review_history_row_time_idx');
        });

        $rows = DB::table('prospect_import_rows')->where('review_status', 'reviewed')->whereNotNull('company_id')->orderBy('id')->get();
        foreach ($rows as $row) {
            $reviewedAt = $row->reviewed_at ?? $row->updated_at ?? now();
            $claimReviews = is_array($row->claim_reviews) ? $row->claim_reviews : (json_decode((string) $row->claim_reviews, true) ?: []);
            $intelligenceRunId = DB::table('lead_insights')->where('tenant_id', $row->tenant_id)
                ->where('company_id', $row->company_id)->orderByDesc('created_at')->value('agent_run_id');
            $scoreId = DB::table('lead_scores')->where('tenant_id', $row->tenant_id)->where('company_id', $row->company_id)
                ->orderByDesc('scored_at')->value('id');
            DB::table('pilot_review_history')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'prospect_import_row_id' => $row->id,
                'reviewer_id' => $row->reviewed_by, 'reviewed_at' => $reviewedAt, 'review_type' => 'full_assessment',
                'intelligence_run_id' => $intelligenceRunId, 'lead_score_id' => $scoreId, 'review_version' => 1,
                'intelligence_rating' => $row->intelligence_rating, 'rubric_score' => $row->intelligence_rubric_score,
                'lead_score_rating' => $row->lead_score_rating, 'recommendation_rating' => $row->recommendation_rating,
                'technology_accuracy_rating' => $row->technology_accuracy_rating, 'next_action_rating' => $row->next_action_rating,
                'claim_count' => count($claimReviews), 'unsupported_claim_count' => $row->unsupported_claim_count ?? 0,
                'claim_reviews' => json_encode($claimReviews, JSON_THROW_ON_ERROR), 'notes' => $row->reviewer_notes,
                'created_at' => $reviewedAt, 'updated_at' => $reviewedAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_review_history');

        Schema::table('website_scans', function (Blueprint $table): void {
            $table->dropForeign('website_scans_tenant_original_scan_fk');
            $table->dropIndex('website_scans_tenant_failure_category_idx');
            $table->dropColumn(['failure_category', 'retryable', 'safe_error_summary', 'original_scan_id']);
        });

        Schema::table('lead_scores', function (Blueprint $table): void {
            $table->dropUnique('lead_scores_tenant_id_id_unique');
        });
    }
};
