<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_services', function (Blueprint $table): void {
            $table->decimal('unit_price', 14, 2)->nullable()->change();
            $table->string('unit', 60)->nullable()->default('project')->change();
        });
        Schema::table('proposal_items', function (Blueprint $table): void {
            $table->string('unit', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve data safety: custom-quote rows must be resolved before rollback.
        if (DB::table('tenant_services')->where('commercial_model', 'custom_quote')->whereNull('unit_price')->exists()
            || DB::table('proposal_items')->whereNull('unit')->exists()) {
            throw new RuntimeException('Resolve custom-quote service pricing and proposal units before rolling back this migration.');
        }

        Schema::table('tenant_services', function (Blueprint $table): void {
            $table->decimal('unit_price', 14, 2)->nullable(false)->change();
            $table->string('unit', 60)->nullable(false)->default('project')->change();
        });
        Schema::table('proposal_items', function (Blueprint $table): void {
            $table->string('unit', 60)->nullable(false)->default('project')->change();
        });
    }
};
