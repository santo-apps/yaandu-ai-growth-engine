<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('web_index_ingestion_runs', function (Blueprint $table): void {
            $table->unsignedInteger('source_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('web_index_ingestion_runs', function (Blueprint $table): void {
            $table->dropColumn('source_failures');
        });
    }
};
