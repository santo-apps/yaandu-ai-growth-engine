<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_scores', function (Blueprint $table): void {
            $table->string('evaluation_status', 32)->default('legacy_unclassified');
            $table->unsignedTinyInteger('evidence_coverage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lead_scores', function (Blueprint $table): void {
            $table->dropColumn(['evaluation_status', 'evidence_coverage']);
        });
    }
};
