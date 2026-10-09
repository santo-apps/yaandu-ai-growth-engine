<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pilot_cohorts', function (Blueprint $table): void {
            $table->string('data_classification', 16)->default('real');
        });
        \Illuminate\Support\Facades\DB::table('pilot_cohorts')->whereRaw('lower(name) like ?', ['%simulat%'])->update(['data_classification' => 'simulated']);
        Schema::table('prospect_import_rows', function (Blueprint $table): void {
            $table->text('source_url')->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->text('provenance_note')->nullable();
            $table->string('review_status', 24)->default('not_reviewed');
            $table->string('intelligence_rating', 32)->nullable();
            $table->string('lead_score_rating', 32)->nullable();
            $table->string('recommendation_rating', 32)->nullable();
            $table->unsignedSmallInteger('unsupported_claim_count')->default(0);
            $table->text('reviewer_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->index(['tenant_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::table('pilot_cohorts', function (Blueprint $table): void {
            $table->dropColumn('data_classification');
        });
        Schema::table('prospect_import_rows', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'review_status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['source_url', 'collected_at', 'provenance_note', 'review_status', 'intelligence_rating',
                'lead_score_rating', 'recommendation_rating', 'unsupported_claim_count', 'reviewer_notes', 'reviewed_at']);
        });
    }
};
