<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_human_sales_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('company_id');
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->uuid('selected_service_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'company_id']);
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'selected_service_id'])->references(['tenant_id', 'id'])->on('tenant_services')->restrictOnDelete();
            $table->index(['tenant_id', 'decision', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_human_sales_reviews');
    }
};
