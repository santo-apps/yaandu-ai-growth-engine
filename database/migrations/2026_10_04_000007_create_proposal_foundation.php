<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'stage']);
        });
        Schema::table('proposals', function (Blueprint $table): void {
            $table->string('title', 255)->nullable();
            $table->text('summary')->nullable();
            $table->text('terms')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('discount_type', 16)->default('none');
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'sales_opportunity_id'])->references(['tenant_id', 'id'])->on('sales_opportunities')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'updated_at']);
        });

        Schema::create('tenant_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('sku', 80);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sku']);
        });
        Schema::create('tenant_pricing_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->char('currency', 3)->default('INR');
            $table->decimal('max_discount_percent', 5, 2)->default(0);
            $table->unsignedSmallInteger('default_validity_days')->default(30);
            $table->timestampsTz();
        });
        Schema::create('proposal_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('proposal_id');
            $table->uuid('service_id');
            $table->string('service_name', 255);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'proposal_id'])->references(['tenant_id', 'id'])->on('proposals')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('tenant_services');
            $table->index(['tenant_id', 'proposal_id']);
        });
        Schema::create('proposal_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('proposal_id');
            $table->uuid('contact_method_id');
            $table->string('provider', 64);
            $table->string('provider_message_id', 180)->nullable();
            $table->string('status', 32)->default('queued');
            $table->string('idempotency_key', 128);
            $table->text('subject_ciphertext');
            $table->text('body_ciphertext');
            $table->text('safe_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'proposal_id'])->references(['tenant_id', 'id'])->on('proposals')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_method_id'])->references(['tenant_id', 'id'])->on('contact_methods');
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_deliveries');
        Schema::dropIfExists('proposal_items');
        Schema::dropIfExists('tenant_pricing_policies');
        Schema::dropIfExists('tenant_services');
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'sales_opportunity_id']);
            $table->dropForeign(['approved_by']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropIndex(['tenant_id', 'status', 'updated_at']);
            $table->dropColumn(['title', 'summary', 'terms', 'subtotal', 'discount_type', 'discount_value', 'valid_until', 'approved_by', 'sent_at', 'viewed_at', 'accepted_at', 'rejected_at']);
        });
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'company_id']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropIndex(['tenant_id', 'status', 'stage']);
        });
    }
};
