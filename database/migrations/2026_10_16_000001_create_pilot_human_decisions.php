<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pilot_human_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('prospect_import_row_id');
            $table->uuid('intelligence_run_id')->nullable();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('service_decision', 24);
            $table->jsonb('selected_service_ids')->default('[]');
            $table->string('priority', 32);
            $table->text('notes')->nullable();
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'prospect_import_row_id'], 'pilot_human_decisions_tenant_row_fk')
                ->references(['tenant_id', 'id'])->on('prospect_import_rows')->restrictOnDelete();
            $table->foreign(['tenant_id', 'intelligence_run_id'], 'pilot_human_decisions_tenant_run_fk')
                ->references(['tenant_id', 'id'])->on('agent_runs')->restrictOnDelete();
            $table->index(['tenant_id', 'prospect_import_row_id', 'decided_at'], 'pilot_human_decisions_row_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_human_decisions');
    }
};
