<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pilot_cohorts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->date('starts_on')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('planned');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'starts_on']);
        });

        Schema::create('prospect_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('pilot_cohort_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('file_sha256', 64);
            $table->string('status', 24)->default('ready');
            $table->jsonb('counts')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'pilot_cohort_id'], 'imports_tenant_cohort_fk')->references(['tenant_id', 'id'])->on('pilot_cohorts');
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'file_sha256']);
        });

        Schema::create('prospect_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->uuid('company_id')->nullable();
            $table->unsignedSmallInteger('row_number');
            $table->text('encrypted_payload');
            $table->string('original_name', 255)->nullable();
            $table->text('original_website')->nullable();
            $table->string('normalized_domain', 253)->nullable();
            $table->string('source', 160)->nullable();
            $table->string('validation_status', 24);
            $table->string('deduplication_status', 24)->default('not_checked');
            $table->string('status', 24)->default('pending');
            $table->jsonb('errors')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'batch_id', 'row_number']);
            $table->foreign(['tenant_id', 'batch_id'], 'import_rows_tenant_batch_fk')->references(['tenant_id', 'id'])->on('prospect_import_batches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'company_id'], 'import_rows_tenant_company_fk')->references(['tenant_id', 'id'])->on('companies');
            $table->index(['tenant_id', 'batch_id', 'status']);
            $table->index(['tenant_id', 'normalized_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_import_rows');
        Schema::dropIfExists('prospect_import_batches');
        Schema::dropIfExists('pilot_cohorts');
    }
};
