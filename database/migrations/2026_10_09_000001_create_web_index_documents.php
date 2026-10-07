<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('web_index_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('canonical_url', 2048);
            $table->string('normalized_domain', 253);
            $table->string('page_title', 500)->nullable();
            $table->string('organization_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('visible_text_excerpt')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->text('address_text')->nullable();
            $table->jsonb('phone_values')->nullable();
            $table->jsonb('email_values')->nullable();
            $table->jsonb('structured_data')->nullable();
            $table->jsonb('outbound_business_links')->nullable();
            $table->string('document_type', 24)->default('business');
            $table->string('source', 64);
            $table->string('source_reference', 1000)->nullable();
            $table->timestampTz('source_timestamp')->nullable();
            $table->timestampTz('indexed_at');
            $table->string('content_hash', 64);
            $table->string('normalized_name', 255)->nullable();
            $table->string('normalized_city', 120)->nullable();
            $table->string('normalized_country', 100)->nullable();
            $table->string('normalized_address', 500)->nullable();
            $table->string('normalized_phone', 64)->nullable();
            $table->text('search_text');
            $table->timestampsTz();
            $table->unique(['normalized_domain', 'canonical_url']);
            $table->index(['normalized_name', 'normalized_city', 'normalized_country'], 'web_index_identity_idx');
            $table->index(['normalized_domain', 'document_type'], 'web_index_domain_type_idx');
            $table->index(['source', 'indexed_at'], 'web_index_source_indexed_idx');
        });

        Schema::create('web_index_ingestion_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 64);
            $table->unsignedSmallInteger('requested_limit');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 24)->default('running');
            $table->string('failure_summary', 500)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['source', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX web_index_search_vector_idx ON web_index_documents USING GIN (to_tsvector('simple', search_text))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_index_ingestion_runs');
        Schema::dropIfExists('web_index_documents');
    }
};
