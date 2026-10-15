<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_discovery_campaigns', function (Blueprint $table): void {
            $table->string('industry', 150)->nullable()->change();
            $table->string('normalized_industry_key', 100)->nullable()->after('industry');
            $table->char('country_code', 2)->nullable()->change();
        });
        Schema::table('lead_discovery_checkpoints', function (Blueprint $table): void {
            $table->string('partition_key', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->jsonb('bbox')->nullable();
            $table->string('industry_key', 100)->nullable();
            $table->index(['tenant_id', 'campaign_id', 'partition_key'], 'lead_discovery_partition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lead_discovery_checkpoints', function (Blueprint $table): void {
            $table->dropIndex('lead_discovery_partition_idx');
            $table->dropColumn(['partition_key', 'country_code', 'region', 'city', 'bbox', 'industry_key']);
        });
        Schema::table('lead_discovery_campaigns', function (Blueprint $table): void {
            $table->dropColumn('normalized_industry_key');
            $table->string('industry', 150)->nullable(false)->change();
            $table->char('country_code', 2)->nullable(false)->change();
        });
    }
};
