<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_discovery_candidates', function (Blueprint $table): void {
            // Nullable preserves historical records as safely unclassified.
            $table->string('geographic_relevance', 32)->nullable();
            $table->index(['tenant_id', 'campaign_id', 'geographic_relevance'], 'lead_candidates_geo_relevance_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lead_discovery_candidates', function (Blueprint $table): void {
            $table->dropIndex('lead_candidates_geo_relevance_idx');
            $table->dropColumn('geographic_relevance');
        });
    }
};
