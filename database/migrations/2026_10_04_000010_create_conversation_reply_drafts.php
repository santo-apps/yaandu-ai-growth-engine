<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversation_reply_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('conversation_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('subject_ciphertext');
            $table->text('body_ciphertext');
            $table->jsonb('evidence_references')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('idempotency_key', 128)->unique();
            $table->string('provider_message_id', 180)->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->index(['tenant_id', 'conversation_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('conversation_reply_drafts'); }
};
