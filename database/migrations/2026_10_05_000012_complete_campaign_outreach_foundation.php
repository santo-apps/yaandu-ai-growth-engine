<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
        });

        Schema::table('campaign_steps', function (Blueprint $table): void {
            $table->boolean('active')->default(true);
            $table->unique(['tenant_id', 'id', 'campaign_id']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id', 'company_id']);
        });
        Schema::table('contact_methods', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id', 'contact_id']);
        });
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id', 'campaign_id']);
            $table->foreign(['tenant_id', 'contact_id', 'company_id'])->references(['tenant_id', 'id', 'company_id'])->on('contacts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_method_id', 'contact_id'])->references(['tenant_id', 'id', 'contact_id'])->on('contact_methods')->cascadeOnDelete();
        });

        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->uuid('campaign_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
        });

        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'campaign_recipient_id']);
            $table->uuid('campaign_recipient_id')->nullable()->change();
            $table->foreign(['tenant_id', 'campaign_recipient_id'])->references(['tenant_id', 'id'])->on('campaign_recipients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campaign_recipient_id', 'campaign_id'])->references(['tenant_id', 'id', 'campaign_id'])->on('campaign_recipients')->cascadeOnDelete();
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->string('channel', 32)->default('email');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->foreign(['tenant_id', 'campaign_recipient_id', 'campaign_id'])->references(['tenant_id', 'id', 'campaign_id'])->on('campaign_recipients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campaign_step_id', 'campaign_id'])->references(['tenant_id', 'id', 'campaign_id'])->on('campaign_steps')->cascadeOnDelete();
            $table->index(['tenant_id', 'attempted_at']);
            $table->index(['tenant_id', 'campaign_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'campaign_recipient_id', 'campaign_id']);
            $table->dropForeign(['tenant_id', 'campaign_step_id', 'campaign_id']);
            $table->dropIndex(['tenant_id', 'attempted_at']);
            $table->dropIndex(['tenant_id', 'campaign_id', 'attempted_at']);
            $table->dropColumn(['channel', 'scheduled_at', 'attempted_at', 'failed_at', 'correlation_id']);
        });
        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'campaign_id']);
            $table->dropForeign(['tenant_id', 'campaign_recipient_id']);
            $table->dropForeign(['tenant_id', 'campaign_recipient_id', 'campaign_id']);
        });
        DB::table('campaign_events')->whereNull('campaign_recipient_id')->delete();
        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->uuid('campaign_recipient_id')->nullable(false)->change();
            $table->foreign(['tenant_id', 'campaign_recipient_id'])->references(['tenant_id', 'id'])->on('campaign_recipients')->cascadeOnDelete();
            $table->dropColumn(['campaign_id', 'correlation_id']);
        });
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'contact_id', 'company_id']);
            $table->dropForeign(['tenant_id', 'contact_method_id', 'contact_id']);
            $table->dropUnique(['tenant_id', 'id', 'campaign_id']);
        });
        Schema::table('contact_methods', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'id', 'contact_id']));
        Schema::table('contacts', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'id', 'company_id']));
        Schema::table('campaign_steps', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id', 'campaign_id']);
            $table->dropColumn('active');
        });
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn(['started_at', 'cancelled_at']));
    }
};
