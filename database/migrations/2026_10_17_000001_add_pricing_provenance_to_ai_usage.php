<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->char('estimated_cost_currency', 3)->nullable();
            $table->date('pricing_effective_date')->nullable();
            $table->string('pricing_version', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->dropColumn(['estimated_cost_currency', 'pricing_effective_date', 'pricing_version']);
        });
    }
};
