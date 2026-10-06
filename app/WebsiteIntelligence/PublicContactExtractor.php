<?php

namespace App\WebsiteIntelligence;

use App\Contacts\ContactMethodValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class PublicContactExtractor
{
    public function __construct(private readonly ContactMethodValue $values) {}

    /** Extract contact methods that are literally present in public crawled pages. */
    public function extract(string $tenantId, string $scanId): int
    {
        $scan = DB::table('website_scans as scans')
            ->join('company_websites as websites', 'websites.id', '=', 'scans.company_website_id')
            ->where('scans.id', $scanId)->where('scans.tenant_id', $tenantId)
            ->where('websites.tenant_id', $tenantId)
            ->select('scans.id', 'websites.company_id')
            ->first();
        if (! $scan) throw new RuntimeException('Scan not found in this tenant.');

        $pages = DB::table('website_pages')->where('tenant_id', $tenantId)->where('website_scan_id', $scanId)
            ->orderBy('depth')->limit(100)->get(['final_url', 'requested_url', 'object_key', 'extracted_text']);
        $count = 0;

        DB::transaction(function () use ($tenantId, $scan, $pages, &$count): void {
            DB::table('companies')->where('tenant_id', $tenantId)->where('id', $scan->company_id)->lockForUpdate()->first();

            foreach ($pages as $page) {
                $sourceUrl = (string) ($page->final_url ?: $page->requested_url);
                $html = $page->object_key && Storage::disk(config('filesystems.default'))->exists($page->object_key)
                    ? Storage::disk(config('filesystems.default'))->get($page->object_key)
                    : (string) $page->extracted_text;
                $visibleText = html_entity_decode(strip_tags(preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html));
                $methods = [...$this->emails($html, $visibleText), ...$this->phones($html, $visibleText)];

                foreach ($methods as $method) {
                    $existing = DB::table('contact_methods as methods')
                        ->join('contacts', 'contacts.id', '=', 'methods.contact_id')
                        ->where('methods.tenant_id', $tenantId)->where('contacts.company_id', $scan->company_id)
                        ->where('methods.type', $method['type'])->where('methods.value_hash', $this->values->fingerprint($method['type'], $method['value']))
                        ->exists();
                    if ($existing) continue;

                    $observedAt = now();
                    $contactId = (string) Str::uuid();
                    DB::table('contacts')->insert([
                        'id' => $contactId, 'tenant_id' => $tenantId, 'company_id' => $scan->company_id,
                        'name' => null, 'title' => null, 'source_url' => $sourceUrl, 'observed_at' => $observedAt,
                        'extraction_method' => $method['method'], 'confidence' => $method['confidence'],
                        'created_at' => $observedAt, 'updated_at' => $observedAt,
                    ]);
                    DB::table('contact_methods')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'contact_id' => $contactId,
                        'type' => $method['type'], 'value' => $this->values->encrypt($method['value']),
                        'value_hash' => $this->values->fingerprint($method['type'], $method['value']), 'source_url' => $sourceUrl,
                        'observed_at' => $observedAt, 'extraction_method' => $method['method'],
                        'confidence' => $method['confidence'], 'verification_status' => 'unverified',
                        'created_at' => $observedAt, 'updated_at' => $observedAt,
                    ]);
                    $count++;
                }
            }
        });

        return $count;
    }

    private function emails(string $html, string $visibleText): array
    {
        preg_match_all('/mailto:([^"\'\s>?]+)/i', $html, $mailto);
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $visibleText, $visible);
        $emails = [];
        foreach ([...($mailto[1] ?? []), ...($visible[0] ?? [])] as $candidate) {
            $email = mb_strtolower(trim(rawurldecode(explode('?', $candidate)[0]), " \t\n\r\0\x0B.,;:()<>[]{}\"'"));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/\.(png|jpe?g|gif|svg|webp)$/i', $email)) continue;
            $emails[$email] = ['type' => 'email', 'value' => $email,
                'method' => str_contains(mb_strtolower($html), 'mailto:'.$email) ? 'public_mailto_or_page_text' : 'public_page_text',
                'confidence' => str_contains(mb_strtolower($html), 'mailto:'.$email) ? 0.98 : 0.9];
        }
        return array_values($emails);
    }

    private function phones(string $html, string $visibleText): array
    {
        preg_match_all('/tel:([^"\'\s>?]+)/i', $html, $tel);
        preg_match_all('/(?<![\w])(?:\+\d{1,3}[\s().-]?)?(?:\(?\d{2,4}\)?[\s.-]?)?\d{3,4}[\s.-]\d{3,4}(?!\w)/', $visibleText, $visible);
        $phones = [];
        foreach ([...($tel[1] ?? []), ...($visible[0] ?? [])] as $candidate) {
            $value = trim(rawurldecode(explode('?', $candidate)[0]));
            $digits = preg_replace('/\D/', '', $value) ?? '';
            if (strlen($digits) < 8 || strlen($digits) > 15) continue;
            $normalized = (str_starts_with($value, '+') ? '+' : '').$digits;
            $phones[$normalized] = ['type' => 'phone', 'value' => $value,
                'method' => str_starts_with(mb_strtolower($candidate), 'tel:') ? 'public_tel_link' : 'public_page_text',
                'confidence' => str_starts_with(mb_strtolower($candidate), 'tel:') ? 0.98 : 0.82];
        }
        return array_values($phones);
    }
}
