<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('thread_key', 64)->nullable();
            $table->unique(['tenant_id', 'thread_key']);
        });
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('provider_message_id', 180)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->unique(['tenant_id', 'idempotency_key']);
        });
        Schema::create('inbound_message_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('conversation_id');
            $table->string('provider', 64);
            $table->string('provider_event_id', 180);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider', 'provider_event_id']);
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->index(['tenant_id', 'conversation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_message_events');
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropColumn(['provider_message_id', 'idempotency_key']);
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'thread_key']);
            $table->dropColumn('thread_key');
        });
    }
};
