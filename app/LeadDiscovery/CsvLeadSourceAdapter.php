<?php

namespace App\LeadDiscovery;

use InvalidArgumentException;

final class CsvLeadSourceAdapter implements LeadSourceAdapterInterface
{
    public function key(): string { return 'csv_upload'; }

    public function capabilities(): LeadSourceCapabilities
    {
        return new LeadSourceCapabilities('csv_upload', 'licensed_manual_dataset', false, true, false, false, true, true, true, true, true,
            false, [], ['company_name', 'website', 'email', 'phone', 'industry', 'address'], true, true, true, false,
            'Operator-supplied dataset license', null, 'Retain the dataset license and attribution recorded by the tenant.', 0, 'manual_import', 'LICENSED_PERSISTABLE');
    }

    public function validateConfiguration(array $configuration): void
    {
        if (trim((string) ($configuration['license_or_authorization'] ?? '')) === '') {
            throw new InvalidArgumentException('Document the CSV data license or authorization before enabling this source.');
        }
        if (trim((string) ($configuration['allowed_collection_method'] ?? '')) === '') {
            throw new InvalidArgumentException('Specify the permitted CSV collection method.');
        }
    }

    public function readCsv(string $path, int $maximumRows): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) throw new InvalidArgumentException('The CSV file could not be opened.');
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (! is_array($header)) throw new InvalidArgumentException('The CSV file must include a header row.');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? '')) ?? (string) ($header[0] ?? '');
            $header = array_map(fn ($value) => $this->normalizeHeader((string) $value), $header);
            if (! in_array('company_name', $header, true) || ! in_array('website', $header, true)) {
                throw new InvalidArgumentException('CSV requires company_name and website columns.');
            }
            $mappedHeaders = array_values(array_filter($header, static fn (string $value): bool => $value !== ''));
            if (count($mappedHeaders) !== count(array_unique($mappedHeaders))) {
                throw new InvalidArgumentException('CSV headers must not map multiple columns to the same field.');
            }
            $rows = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (count($rows) >= $maximumRows) throw new InvalidArgumentException('The CSV exceeds the configured row limit.');
                if ($values === [null] || $values === []) continue;
                if (count($values) > count($header)) throw new InvalidArgumentException('A CSV row contains more values than the header.');
                $row = [];
                foreach ($header as $index => $key) {
                    $value = (string) ($values[$index] ?? '');
                    if (! mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('The CSV must use valid UTF-8 text.');
                    if ($key !== '') $row[$key] = trim($value);
                }
                if (implode('', $row) === '') continue;
                $row['source_reference'] = trim((string) ($row['source_reference'] ?? '')) ?: 'csv-row-'.(count($rows) + 2);
                if (trim((string) ($row['company_name'] ?? '')) === '') {
                    throw new InvalidArgumentException('Every non-empty CSV row must include a company_name value.');
                }
                $rows[] = $row;
            }
            if ($rows === []) throw new InvalidArgumentException('The CSV contains no candidate rows.');
            return $rows;
        } finally { fclose($handle); }
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/[^a-z0-9]+/i', '_', trim(mb_strtolower($header))) ?: '';
        $header = trim($header, '_');
        return match ($header) {
            'name', 'business', 'business_name', 'company' => 'company_name',
            'domain', 'website_url', 'company_website' => 'website',
            'category', 'business_category' => 'industry',
            'country' => 'country_code',
            'state', 'province' => 'region',
            'source_url', 'provenance_url' => 'source_url',
            'reference', 'source_id' => 'source_reference',
            'email', 'public_email' => 'business_email',
            'phone', 'public_phone' => 'business_phone',
            default => $header,
        };
    }
}
