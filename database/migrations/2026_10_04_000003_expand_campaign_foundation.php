<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
        });
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
        });
        Schema::table('contact_methods', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->text('objective')->nullable();
            $table->jsonb('target_audience')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->jsonb('sending_windows')->nullable();
            $table->unsignedInteger('rate_limit_per_hour')->default(60);
            $table->unsignedInteger('daily_limit')->default(500);
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('campaign_audiences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('campaign_id');
            $table->string('name');
            $table->jsonb('criteria')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'campaign_id']);
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
        });

        Schema::create('campaign_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('campaign_id')->nullable();
            $table->string('name');
            $table->string('channel', 32)->default('email');
            $table->string('subject', 500)->nullable();
            $table->text('body');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name', 'version']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'channel']);
        });

        Schema::create('tenant_messaging_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 64)->default('fake');
            $table->boolean('enabled')->default(false);
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('secret_reference')->nullable();
            $table->unsignedInteger('hourly_limit')->default(60);
            $table->unsignedInteger('daily_limit')->default(500);
            $table->jsonb('configuration')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::table('campaign_steps', function (Blueprint $table): void {
            $table->uuid('template_id')->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'campaign_id', 'ordinal']);
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'template_id'])->references(['tenant_id', 'id'])->on('campaign_templates')->cascadeOnDelete();
            $table->index(['tenant_id', 'campaign_id', 'ordinal']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->unsignedSmallInteger('current_step_ordinal')->nullable();
            $table->timestampTz('next_step_at')->nullable();
            $table->timestampTz('enrolled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->string('stop_reason', 64)->nullable();
            $table->uuid('contact_method_id')->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['campaign_id', 'contact_id']);
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_method_id'])->references(['tenant_id', 'id'])->on('contact_methods')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'next_step_at']);
        });

        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'campaign_recipient_id'])->references(['tenant_id', 'id'])->on('campaign_recipients')->cascadeOnDelete();
            $table->index(['tenant_id', 'campaign_recipient_id', 'occurred_at']);
        });

        Schema::table('suppression_lists', function (Blueprint $table): void {
            $table->string('identifier_type', 32)->default('email');
            $table->string('scope', 32)->default('tenant');
            $table->timestampTz('suppressed_at')->nullable();
            $table->index(['tenant_id', 'identifier_type', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::table('suppression_lists', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'identifier_type', 'reason']);
            $table->dropColumn(['identifier_type', 'scope', 'suppressed_at']);
        });
        Schema::table('campaign_events', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'campaign_recipient_id']);
            $table->dropIndex(['tenant_id', 'campaign_recipient_id', 'occurred_at']);
        });
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'campaign_id']);
            $table->dropForeign(['tenant_id', 'company_id']);
            $table->dropForeign(['tenant_id', 'contact_id']);
            $table->dropForeign(['tenant_id', 'contact_method_id']);
            $table->dropIndex(['tenant_id', 'status', 'next_step_at']);
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropUnique(['campaign_id', 'contact_id']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropColumn(['idempotency_key', 'current_step_ordinal', 'next_step_at', 'enrolled_at', 'completed_at', 'stopped_at', 'stop_reason', 'contact_method_id']);
        });
        Schema::table('campaign_steps', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'campaign_id']);
            $table->dropForeign(['tenant_id', 'template_id']);
            $table->dropIndex(['tenant_id', 'campaign_id', 'ordinal']);
            $table->dropUnique(['tenant_id', 'campaign_id', 'ordinal']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropColumn('template_id');
        });
        Schema::dropIfExists('tenant_messaging_configurations');
        Schema::dropIfExists('campaign_templates');
        Schema::dropIfExists('campaign_audiences');
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropColumn(['objective', 'target_audience', 'timezone', 'sending_windows', 'rate_limit_per_hour', 'daily_limit', 'paused_at', 'completed_at', 'created_by']);
        });
        Schema::table('contact_methods', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id']);
        });
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id']);
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
