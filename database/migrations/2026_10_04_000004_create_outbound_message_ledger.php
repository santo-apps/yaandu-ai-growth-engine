<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('campaign_id');
            $table->uuid('campaign_recipient_id');
            $table->uuid('campaign_step_id');
            $table->uuid('contact_method_id');
            $table->string('idempotency_key', 128);
            $table->string('provider', 64);
            $table->string('provider_message_id', 180)->nullable();
            $table->string('status', 32)->default('queued');
            $table->text('subject_ciphertext');
            $table->text('body_ciphertext');
            $table->string('recipient_hash', 64);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->text('safe_error')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'campaign_recipient_id', 'campaign_step_id']);
            $table->unique(['tenant_id', 'provider', 'provider_message_id']);
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campaign_recipient_id'])->references(['tenant_id', 'id'])->on('campaign_recipients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campaign_step_id'])->references(['tenant_id', 'id'])->on('campaign_steps')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_method_id'])->references(['tenant_id', 'id'])->on('contact_methods')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'queued_at']);
        });

        Schema::create('outbound_message_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('outbound_message_id');
            $table->string('provider', 64);
            $table->string('provider_event_id', 180);
            $table->string('event_type', 32);
            $table->timestampTz('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider', 'provider_event_id']);
            $table->foreign(['tenant_id', 'outbound_message_id'])->references(['tenant_id', 'id'])->on('outbound_messages')->cascadeOnDelete();
            $table->index(['tenant_id', 'outbound_message_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_message_events');
        Schema::dropIfExists('outbound_messages');
        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
