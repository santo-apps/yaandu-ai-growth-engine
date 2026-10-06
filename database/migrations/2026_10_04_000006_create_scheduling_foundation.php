<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_scheduling_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 64)->default('fake');
            $table->boolean('enabled')->default(false);
            $table->boolean('autonomous_booking_enabled')->default(false);
            $table->string('default_timezone', 64)->default('UTC');
            $table->timestampsTz();
        });

        Schema::create('meeting_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('conversation_id');
            $table->string('provider', 64);
            $table->string('provider_booking_id', 180);
            $table->string('status', 32)->default('booked');
            $table->string('timezone', 64);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('idempotency_key', 128);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'provider', 'provider_booking_id']);
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_bookings');
        Schema::dropIfExists('tenant_scheduling_configurations');
    }
};
