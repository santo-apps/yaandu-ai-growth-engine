<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_scheduling_configurations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('meeting_duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedSmallInteger('minimum_notice_minutes')->default(60);
            $table->unsignedSmallInteger('maximum_horizon_days')->default(30);
            $table->jsonb('allowed_weekdays')->default('[1,2,3,4,5]');
            $table->time('working_hours_start')->default('09:00');
            $table->time('working_hours_end')->default('17:00');
            $table->string('meeting_title_template', 180)->default('Discovery meeting with {{company}}');
            $table->text('meeting_description_template')->nullable();
            $table->foreignId('default_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('scheduling_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->uuid('sales_opportunity_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('meeting_type', 64)->default('discovery');
            $table->string('status', 32)->default('REQUESTED');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->timestampTz('requested_at');
            $table->timestampTz('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 64);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id'])->references(['tenant_id'])->on('tenant_scheduling_configurations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sales_opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->nullOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::create('scheduling_offered_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('scheduling_request_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone', 64);
            $table->timestampTz('expires_at');
            $table->string('status', 20)->default('OFFERED');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'scheduling_request_id', 'starts_at']);
            $table->foreign(['tenant_id', 'scheduling_request_id'])->references(['tenant_id', 'id'])->on('scheduling_requests')->cascadeOnDelete();
        });

        Schema::table('meeting_bookings', function (Blueprint $table): void {
            $table->uuid('scheduling_request_id')->nullable();
            $table->uuid('sales_opportunity_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180)->nullable();
            $table->text('description')->nullable();
            $table->text('meeting_url')->nullable();
            $table->foreign(['tenant_id', 'scheduling_request_id'])->references(['tenant_id', 'id'])->on('scheduling_requests')->nullOnDelete();
            $table->foreign(['tenant_id', 'sales_opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->nullOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_bookings', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'scheduling_request_id']);
            $table->dropForeign(['tenant_id', 'sales_opportunity_id']);
            $table->dropForeign(['tenant_id', 'contact_id']);
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn(['scheduling_request_id', 'sales_opportunity_id', 'contact_id', 'owner_user_id', 'title', 'description', 'meeting_url']);
        });
        Schema::dropIfExists('scheduling_offered_slots');
        Schema::dropIfExists('scheduling_requests');
        Schema::table('tenant_scheduling_configurations', function (Blueprint $table): void {
            $table->dropForeign(['default_owner_user_id']);
            $table->dropColumn(['meeting_duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'minimum_notice_minutes', 'maximum_horizon_days', 'allowed_weekdays', 'working_hours_start', 'working_hours_end', 'meeting_title_template', 'meeting_description_template', 'default_owner_user_id']);
        });
    }
};
