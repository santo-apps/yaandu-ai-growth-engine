<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_scores', function (Blueprint $table): void {
            $table->unsignedTinyInteger('score')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing unscored rows cannot be represented by the original NOT NULL schema.
        if (Schema::hasTable('lead_scores') && ! \Illuminate\Support\Facades\DB::table('lead_scores')->whereNull('score')->exists()) {
            Schema::table('lead_scores', function (Blueprint $table): void {
                $table->unsignedTinyInteger('score')->nullable(false)->change();
            });
        }
    }
};
