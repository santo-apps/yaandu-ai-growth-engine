<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->text('input_ciphertext')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropColumn('input_ciphertext');
        });
    }
};
