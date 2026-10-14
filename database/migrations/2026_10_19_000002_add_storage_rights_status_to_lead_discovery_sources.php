<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_discovery_sources', function (Blueprint $table): void {
            $table->string('storage_rights_status', 32)->default('not_confirmed');
        });

        // Preserve only the prior explicit operator attestation; credentials or
        // provider identity alone never grant storage rights.
        DB::table('lead_discovery_sources')->orderBy('id')->get(['id', 'source_key', 'configuration'])->each(function (object $source): void {
            $configuration = json_decode((string) $source->configuration, true) ?: [];
            $status = $source->source_key === 'csv_upload'
                ? 'not_required'
                : (($configuration['storage_rights_confirmed'] ?? false) === true
                    && trim((string) ($configuration['storage_rights_reference'] ?? '')) !== ''
                    ? 'confirmed'
                    : 'not_confirmed');

            DB::table('lead_discovery_sources')->where('id', $source->id)->update(['storage_rights_status' => $status]);
        });
    }

    public function down(): void
    {
        Schema::table('lead_discovery_sources', function (Blueprint $table): void {
            $table->dropColumn('storage_rights_status');
        });
    }
};
