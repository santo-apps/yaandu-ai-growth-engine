<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('web_index_documents', function (Blueprint $table): void {
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_fetched_at')->nullable();
            $table->timestampTz('next_refresh_at')->nullable()->index();
            $table->timestampTz('content_changed_at')->nullable();
            $table->string('availability', 24)->default('available')->index();
            $table->unsignedInteger('stored_bytes')->default(0);
            $table->boolean('has_public_contact')->default(false);
            $table->boolean('has_structured_data')->default(false);
            $table->unsignedTinyInteger('identity_completeness')->default(0);
            $table->string('business_category', 120)->nullable()->index();
        });

        Schema::table('web_index_ingestion_runs', function (Blueprint $table): void {
            $table->jsonb('options')->nullable();
            $table->jsonb('cursor')->nullable();
            $table->timestampTz('checkpointed_at')->nullable();
            $table->unsignedInteger('unique_domains_added')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedBigInteger('bytes_processed')->default(0);
            $table->unsignedBigInteger('stored_bytes_delta')->default(0);
            $table->timestampTz('resumed_at')->nullable();
        });

        Schema::create('web_index_document_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('web_index_documents')->cascadeOnDelete();
            $table->string('source', 64);
            $table->string('source_reference', 1000)->nullable();
            $table->jsonb('source_query')->nullable();
            $table->string('evidence_type', 32)->default('business_website');
            $table->timestampTz('source_timestamp')->nullable();
            $table->timestampTz('first_observed_at');
            $table->timestampTz('last_observed_at');
            $table->uuid('last_ingestion_run_id')->nullable()->index();
            $table->timestampsTz();
            $table->unique(['document_id', 'source', 'source_reference'], 'web_index_document_source_unique');
            $table->index(['source', 'last_observed_at'], 'web_index_document_source_recent_idx');
        });

        DB::table('web_index_documents')->whereNull('first_seen_at')->update([
            'first_seen_at' => DB::raw('indexed_at'), 'last_seen_at' => DB::raw('indexed_at'),
            'last_fetched_at' => DB::raw('indexed_at'), 'content_changed_at' => DB::raw('indexed_at'),
            'stored_bytes' => DB::raw('length(search_text)'),
        ]);
        DB::table('web_index_documents')->whereNotNull('organization_name')->update(['identity_completeness' => 30]);
        DB::table('web_index_documents')->orderBy('id')->chunk(500, function ($documents): void {
            foreach ($documents as $document) {
                DB::table('web_index_document_sources')->insert([
                    'id' => (string) Str::uuid(), 'document_id' => $document->id, 'source' => $document->source,
                    'source_reference' => $document->source_reference ?: 'legacy:'.$document->id, 'source_query' => json_encode(['migration' => 'legacy_corpus_import']),
                    'evidence_type' => 'legacy_import', 'source_timestamp' => $document->source_timestamp,
                    'first_observed_at' => $document->first_seen_at ?: $document->indexed_at, 'last_observed_at' => $document->last_seen_at ?: $document->indexed_at,
                    'last_ingestion_run_id' => null, 'created_at' => $document->created_at, 'updated_at' => $document->updated_at,
                ]);
                $phones = json_decode((string) $document->phone_values, true) ?: [];
                $emails = json_decode((string) $document->email_values, true) ?: [];
                $structured = json_decode((string) $document->structured_data, true) ?: [];
                $legacyIdentity = is_array($structured['page_identity'] ?? null) ? $structured['page_identity'] : [];
                $hasStructuredData = (is_array($structured['entities'] ?? null) && $structured['entities'] !== [])
                    || count(array_intersect(array_keys($legacyIdentity), ['legal_name', 'url', 'telephone', 'email', 'address', 'city', 'country', 'same_as'])) > 0;
                $completeness = ($document->organization_name ? 30 : 0) + (($document->city || $document->country) ? 20 : 0)
                    + (($phones || $emails) ? 20 : 0) + ($hasStructuredData ? 10 : 0) + (($document->description || $document->visible_text_excerpt) ? 10 : 0)
                    + ($document->address_text ? 5 : 0) + ($document->page_title ? 5 : 0);
                DB::table('web_index_documents')->where('id', $document->id)->update(['has_public_contact' => (bool) ($phones || $emails),
                    'has_structured_data' => $hasStructuredData, 'identity_completeness' => min(100, $completeness)]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_index_document_sources');
        Schema::table('web_index_ingestion_runs', function (Blueprint $table): void {
            $table->dropColumn(['options', 'cursor', 'checkpointed_at', 'unique_domains_added', 'duplicates', 'bytes_processed', 'stored_bytes_delta', 'resumed_at']);
        });
        Schema::table('web_index_documents', function (Blueprint $table): void {
            $table->dropColumn(['first_seen_at', 'last_seen_at', 'last_fetched_at', 'next_refresh_at', 'content_changed_at', 'availability', 'stored_bytes', 'has_public_contact', 'has_structured_data', 'identity_completeness', 'business_category']);
        });
    }
};
