<?php

namespace App\WebsiteResolution;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class LocalWebIndexSearchService
{
    public function __construct(private readonly BusinessIdentityNormalizer $normalizer) {}

    /** @return list<array{document:object,search_rank:int,matching_fields:array}> */
    public function search(array $identity, int $limit = 10): array
    {
        $name = $this->normalizer->name($identity['business_name'] ?? null);
        $identityTokens = $this->normalizer->tokens($name);
        if (count($identityTokens) < 2) return [];

        $query = DB::table('web_index_documents');
        if (DB::getDriverName() === 'pgsql') {
            $terms = implode(' & ', array_map(fn (string $token) => "'".str_replace("'", "''", $token)."'", $identityTokens));
            $query->whereRaw("to_tsvector('simple', search_text) @@ to_tsquery('simple', ?)", [$terms]);
        } else {
            $query->where(function (Builder $builder) use ($identityTokens): void {
                foreach ($identityTokens as $token) $builder->orWhere('search_text', 'like', '%'.$token.'%');
            });
        }

        $rows = $query->limit(min(200, max(1, $limit * 10)))->get();
        $ranked = [];
        foreach ($rows as $row) {
            $fields = [];
            $docName = $this->normalizer->name($row->organization_name ?: $row->page_title);
            $docTokens = $this->normalizer->tokens($docName);
            $overlap = count(array_intersect($identityTokens, $docTokens));
            if ($docName === $name && $name !== '') { $fields[] = 'business_name'; $rank = 50; }
            elseif ($overlap >= 2 && count($identityTokens) >= 2 && ($overlap / count($identityTokens)) >= 0.66) { $fields[] = 'business_name'; $rank = 30 + (int) (20 * $overlap / count($identityTokens)); }
            else continue;

            $city = $this->normalizer->text($identity['city'] ?? null);
            $country = $this->normalizer->country($identity['country'] ?? null);
            $address = $this->normalizer->text($identity['address'] ?? null);
            $phone = $this->normalizer->phone($identity['public_phone'] ?? null);
            if ($city !== '' && $city === $this->normalizer->text($row->city)) { $rank += 25; $fields[] = 'city'; }
            if ($country !== '' && $country === $this->normalizer->country($row->country)) { $rank += 20; $fields[] = 'country'; }
            elseif ($country !== '' && $row->country) $rank -= 35;
            $docAddress = $this->normalizer->text($row->address_text);
            if ($address !== '' && $docAddress !== '' && count(array_intersect($this->normalizer->tokens($address), $this->normalizer->tokens($docAddress))) >= 2) { $rank += 15; $fields[] = 'address'; }
            $indexedPhones = json_decode((string) $row->phone_values, true) ?: [];
            $phoneMatches = strlen($phone) >= 7 && ($phone === $this->normalizer->phone($row->normalized_phone)
                || collect($indexedPhones)->contains(fn ($value) => $phone === $this->normalizer->phone((string) $value)));
            if ($phoneMatches) { $rank += 40; $fields[] = 'phone'; }
            $email = mb_strtolower(trim((string) ($identity['public_email'] ?? '')));
            $indexedEmails = json_decode((string) $row->email_values, true) ?: [];
            if ($email !== '' && collect($indexedEmails)->contains(fn ($value) => $email === mb_strtolower(trim((string) $value)))) { $rank += 45; $fields[] = 'email'; }
            $category = $this->normalizer->text($identity['category'] ?? null);
            if ($category !== '' && str_contains($this->normalizer->text($row->search_text), $category)) { $rank += 5; $fields[] = 'category_context'; }
            if (($row->document_type ?? 'business') === 'directory') $rank -= 10;

            $ranked[] = ['document' => $row, 'search_rank' => max(0, min(100, $rank)), 'matching_fields' => array_values(array_unique($fields))];
        }
        usort($ranked, fn (array $a, array $b) => $b['search_rank'] <=> $a['search_rank']);

        return array_slice($ranked, 0, min(50, max(1, $limit)));
    }
}
