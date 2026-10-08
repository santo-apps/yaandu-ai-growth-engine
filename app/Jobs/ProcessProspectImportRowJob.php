<?php

namespace App\Jobs;

use App\Contacts\ContactMethodValue;
use App\Models\Company;
use App\Models\CompanyWebsite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessProspectImportRowJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public int $uniqueFor = 600;

    public function __construct(public readonly string $tenantId, public readonly string $rowId, public readonly ?string $actorId)
    {
        $this->onQueue('intake');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->rowId; }

    public function handle(ContactMethodValue $values): void
    {
        DB::transaction(function () use ($values): void {
            $row = DB::table('prospect_import_rows')->where('tenant_id', $this->tenantId)->where('id', $this->rowId)->lockForUpdate()->first();
            if (! $row || $row->status === 'completed' || $row->status === 'duplicate') return;
            if ($row->status !== 'ready' && $row->status !== 'processing') return;
            DB::table('prospect_import_rows')->where('tenant_id', $this->tenantId)->where('id', $row->id)->update(['status' => 'processing', 'updated_at' => now()]);
            $data = json_decode(Crypt::decryptString($row->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);

            $company = Company::query()->where('tenant_id', $this->tenantId)->where('normalized_domain', $row->normalized_domain)->first();
            if ($company) {
                DB::table('prospect_import_rows')->where('tenant_id', $this->tenantId)->where('id', $row->id)->update([
                    'status' => 'duplicate', 'deduplication_status' => 'duplicate_tenant', 'errors' => json_encode(['A prospect with this domain already exists in this tenant.']), 'processed_at' => now(), 'updated_at' => now(),
                ]);
                $this->refreshBatch($row->batch_id);
                return;
            }

            $company = Company::create(['tenant_id' => $this->tenantId, 'name' => $data['business_name'], 'normalized_domain' => $row->normalized_domain,
                'industry' => $data['industry'] ?? null, 'location' => trim(implode(', ', array_filter([$data['city'] ?? null, $data['country'] ?? null]))) ?: ($data['country'] ?? null),
                'description' => $data['notes'] ?? null, 'source' => 'csv_import:'.$row->source, 'status' => 'new']);
            $website = CompanyWebsite::create(['tenant_id' => $this->tenantId, 'company_id' => $company->id,
                'url' => $data['website_normalized']['normalized_url'], 'host' => $row->normalized_domain, 'verification_status' => 'unverified', 'source' => 'user_supplied_import']);

            if (($data['contact_name'] ?? null) || ($data['business_email'] ?? null) || ($data['business_phone'] ?? null)) {
                $contactId = (string) Str::uuid();
                DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $this->tenantId, 'company_id' => $company->id,
                    'name' => $data['contact_name'] ?? null, 'title' => null, 'source_url' => $website->url, 'observed_at' => now(),
                    'extraction_method' => 'user_supplied_import', 'confidence' => 0.6, 'created_at' => now(), 'updated_at' => now()]);
                foreach (['email' => $data['business_email'] ?? null, 'phone' => $data['business_phone'] ?? null] as $type => $value) {
                    if (! $value) continue;
                    DB::table('contact_methods')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'contact_id' => $contactId,
                        'type' => $type, 'value' => $values->encrypt($value), 'value_hash' => $values->fingerprint($type, $value),
                        'source_url' => $website->url, 'observed_at' => now(),
                        'extraction_method' => 'user_supplied_import', 'confidence' => 0.6, 'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            DB::table('prospect_import_rows')->where('tenant_id', $this->tenantId)->where('id', $row->id)->update([
                'company_id' => $company->id, 'status' => 'completed', 'processed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'actor_user_id' => $this->actorId,
                'action' => 'prospect_imported', 'subject_type' => 'company', 'subject_id' => $company->id, 'request_id' => null,
                'metadata' => json_encode(['batch_id' => $row->batch_id, 'row_id' => $row->id, 'source' => $row->source, 'original_website' => $row->original_website,
                    'normalized_domain' => $row->normalized_domain, 'website_verification' => 'unverified', 'imported_at' => now()->toIso8601String()]), 'created_at' => now()]);
            $this->refreshBatch($row->batch_id);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $row = DB::table('prospect_import_rows')->where('tenant_id', $this->tenantId)->where('id', $this->rowId)->first();
        if (! $row || $row->status === 'completed') return;
        DB::table('prospect_import_rows')->where('tenant_id', $this->tenantId)->where('id', $this->rowId)->update([
            'status' => 'failed', 'errors' => json_encode(['Import processing failed; retry is available.']), 'updated_at' => now(),
        ]);
        $this->refreshBatch($row->batch_id);
    }

    private function refreshBatch(string $batchId): void
    {
        $rows = DB::table('prospect_import_rows')->where('tenant_id', $this->tenantId)->where('batch_id', $batchId);
        $counts = ['total' => (clone $rows)->count(), 'valid' => (clone $rows)->where('validation_status', 'valid')->count(),
            'invalid' => (clone $rows)->where('validation_status', 'invalid')->count(), 'imported' => (clone $rows)->where('status', 'completed')->count(),
            'duplicates' => (clone $rows)->where('status', 'duplicate')->count(), 'failed' => (clone $rows)->where('status', 'failed')->count(),
            'pending' => (clone $rows)->whereIn('status', ['ready', 'processing'])->count()];
        $status = $counts['pending'] > 0 ? 'importing' : (($counts['failed'] || $counts['invalid'] || $counts['duplicates']) ? 'partially_completed' : 'completed');
        DB::table('prospect_import_batches')->where('tenant_id', $this->tenantId)->where('id', $batchId)->update(['counts' => json_encode($counts), 'status' => $status, 'updated_at' => now()]);
    }
}
