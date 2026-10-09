<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('prospect_import_rows', function (Blueprint $table): void {
            $table->unsignedTinyInteger('intelligence_rubric_score')->nullable();
            $table->string('technology_accuracy_rating', 24)->nullable();
            $table->string('next_action_rating', 24)->nullable();
            $table->jsonb('claim_reviews')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prospect_import_rows', function (Blueprint $table): void {
            $table->dropColumn(['intelligence_rubric_score', 'technology_accuracy_rating', 'next_action_rating', 'claim_reviews']);
        });
    }
};
