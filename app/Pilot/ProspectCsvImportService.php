<?php

namespace App\Pilot;

use App\Discovery\DomainNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ProspectCsvImportService
{
    public function __construct(private readonly DomainNormalizer $domains) {}

    /** @return array<string,mixed> */
    public function preview(string $tenantId, int $userId, UploadedFile $file, ?string $cohortId): array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'csv' || $file->getSize() > 2_000_000) {
            throw new InvalidArgumentException('Upload a CSV file no larger than 2 MB.');
        }
        $path = $file->getRealPath();
        $handle = $path ? fopen($path, 'rb') : false;
        if ($handle === false) throw new RuntimeException('The uploaded CSV could not be read.');

        try {
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (! is_array($headers)) throw new InvalidArgumentException('The CSV is empty.');
            $headers = array_map(fn ($header) => $this->header((string) $header), $headers);
            foreach (['business_name', 'website', 'country', 'source'] as $required) {
                if (! in_array($required, $headers, true)) throw new InvalidArgumentException("Required CSV column missing: {$required}.");
            }

            $rows = [];
            $seen = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $line++;
                if (count($rows) >= (int) config('pilot.max_import_rows', 100)) throw new InvalidArgumentException('The CSV exceeds the configured row limit.');
                if ($values === [null] || $values === []) continue;
                $raw = [];
                foreach ($headers as $index => $header) if ($header !== '') $raw[$header] = trim((string) ($values[$index] ?? ''));
                $normalized = [
                    'business_name' => $raw['business_name'] ?? '', 'website' => $raw['website'] ?? '',
                    'country' => strtoupper($raw['country'] ?? ''), 'city' => $raw['city'] ?? null,
                    'industry' => $raw['industry'] ?? null, 'business_email' => $raw['business_email'] ?? null,
                    'business_phone' => $raw['business_phone'] ?? null, 'contact_name' => $raw['contact_name'] ?? null,
                    'source' => $raw['source'] ?? '', 'source_url' => $raw['source_url'] ?? null,
                    'collected_at' => $raw['collected_at'] ?? null, 'provenance_note' => $raw['provenance_note'] ?? null,
                    'notes' => $raw['notes'] ?? null,
                ];
                foreach ($normalized as $key => $value) if ($value === '') $normalized[$key] = null;
                $errors = [];
                if (! $normalized['business_name']) $errors[] = 'Business name is required.';
                if (! $normalized['website']) $errors[] = 'Website is required.';
                $countryName = $normalized['country'] ? (class_exists(\Locale::class) ? \Locale::getDisplayRegion('und_'.$normalized['country'], 'en_US') : '') : '';
                if (! $normalized['country'] || ! preg_match('/^[A-Z]{2}$/', $normalized['country']) || in_array($countryName, ['', $normalized['country'], 'Unknown Region'], true)) $errors[] = 'Country must be a valid ISO 3166-1 alpha-2 code (for example, IN or AE).';
                if (! $normalized['source']) $errors[] = 'Source is required.';
                if ($normalized['source_url'] && ! filter_var($normalized['source_url'], FILTER_VALIDATE_URL)) $errors[] = 'Source URL must be a valid URL.';
                if ($normalized['source_url'] && ! in_array(strtolower((string) parse_url($normalized['source_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) $errors[] = 'Source URL must use HTTP or HTTPS.';
                if ($normalized['collected_at'] && strtotime($normalized['collected_at']) === false) $errors[] = 'Collection timestamp is invalid.';
                if ($normalized['collected_at'] && strtotime($normalized['collected_at']) !== false) $normalized['collected_at'] = date(DATE_ATOM, strtotime($normalized['collected_at']));
                if ($normalized['business_email'] && ! filter_var($normalized['business_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Business email is malformed.';
                if ($normalized['business_phone'] && ! preg_match('/^[+0-9(). \-]{7,32}$/', $normalized['business_phone'])) $errors[] = 'Business phone is malformed.';
                foreach (['business_name' => 255, 'website' => 2048, 'city' => 200, 'industry' => 150, 'business_email' => 254, 'business_phone' => 32, 'contact_name' => 255, 'source' => 160, 'source_url' => 2048, 'provenance_note' => 2000, 'notes' => 5000] as $key => $limit) {
                    if (is_string($normalized[$key]) && mb_strlen($normalized[$key]) > $limit) $errors[] = ucfirst(str_replace('_', ' ', $key))." exceeds {$limit} characters.";
                }
                $domain = null;
                if ($normalized['website']) {
                    try { $normalized['website_normalized'] = $this->domains->normalize($normalized['website']); $domain = $normalized['website_normalized']['normalized_domain']; }
                    catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
                }
                $dedupe = 'new';
                if ($domain) {
                    if (isset($seen[$domain])) { $dedupe = 'duplicate_file'; $errors[] = 'Website domain duplicates CSV row '.$seen[$domain].'.'; }
                    else {
                        $seen[$domain] = $line;
                        if (DB::table('companies')->where('tenant_id', $tenantId)->where('normalized_domain', $domain)->exists()) { $dedupe = 'duplicate_tenant'; $errors[] = 'A prospect with this domain already exists in this tenant.'; }
                    }
                }
                $rows[] = ['row_number' => $line, 'data' => $normalized, 'domain' => $domain, 'errors' => $errors, 'dedupe' => $dedupe];
            }
        } finally { fclose($handle); }

        if ($rows === []) throw new InvalidArgumentException('The CSV has no data rows.');
        $batchId = (string) Str::uuid();
        $validCount = count(array_filter($rows, fn ($row) => $row['errors'] === []));
        DB::transaction(function () use ($tenantId, $userId, $file, $cohortId, $rows, $batchId, $validCount): void {
            DB::table('prospect_import_batches')->insert(['id' => $batchId, 'tenant_id' => $tenantId, 'pilot_cohort_id' => $cohortId,
                'created_by' => $userId, 'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'file_sha256' => hash_file('sha256', (string) $file->getRealPath()), 'status' => 'ready',
                'counts' => json_encode(['total' => count($rows), 'valid' => $validCount, 'invalid' => count($rows) - $validCount, 'imported' => 0]),
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($rows as $row) {
                DB::table('prospect_import_rows')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'batch_id' => $batchId,
                    'row_number' => $row['row_number'], 'encrypted_payload' => Crypt::encryptString(json_encode($row['data'], JSON_THROW_ON_ERROR)),
                    'original_name' => $row['data']['business_name'], 'original_website' => $row['data']['website'],
                    'normalized_domain' => $row['domain'], 'source' => $row['data']['source'], 'source_url' => $row['data']['source_url'],
                    'collected_at' => $row['data']['collected_at'], 'provenance_note' => $row['data']['provenance_note'],
                    'validation_status' => $row['errors'] === [] ? 'valid' : 'invalid', 'deduplication_status' => $row['dedupe'],
                    'status' => $row['errors'] === [] ? 'ready' : ($row['dedupe'] === 'new' ? 'invalid' : 'duplicate'),
                    'errors' => json_encode($row['errors']), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return ['id' => $batchId, 'status' => 'ready', 'counts' => ['total' => count($rows), 'valid' => $validCount, 'invalid' => count($rows) - $validCount],
            'rows' => array_map(fn ($row) => ['row_number' => $row['row_number'], 'business_name' => $row['data']['business_name'], 'website' => $row['data']['website'],
                'normalized_domain' => $row['domain'], 'country' => $row['data']['country'], 'validation_status' => $row['errors'] === [] ? 'valid' : 'invalid',
                'deduplication_status' => $row['dedupe'], 'errors' => $row['errors']], $rows)];
    }

    private function header(string $value): string
    {
        $key = str_replace([' ', '-', '/', '(', ')'], '_', strtolower(trim($value)));
        $key = preg_replace('/_+/', '_', $key) ?: $key;
        return match ($key) {
            'name', 'business' => 'business_name', 'domain', 'website_domain', 'website_url', 'company_website' => 'website',
            'country_code' => 'country', 'category' => 'industry', 'email' => 'business_email', 'phone' => 'business_phone',
            'contact' => 'contact_name', 'lead_source' => 'source', 'provenance_source_url' => 'source_url',
            'collection_timestamp' => 'collected_at', 'collection_date' => 'collected_at', default => $key,
        };
    }
}
