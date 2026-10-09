<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_icp_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->jsonb('configuration');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'version']);
            $table->index(['tenant_id', 'status', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_icp_configurations');
    }
};
