<?php

namespace App\Campaigns;

use App\Contacts\ContactMethodValue;
use Illuminate\Support\Facades\DB;

final class SuppressionChecker
{
    public function __construct(private readonly ContactMethodValue $values) {}

    public function isMethodSuppressed(string $tenantId, string $methodId): bool
    {
        $method = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('id', $methodId)->first(['type', 'value']);
        if (! $method) return true;

        try { $hash = $this->values->fingerprint($method->type, $this->values->decrypt($method->value)); }
        catch (\Throwable) { return true; }

        return DB::table('suppression_lists')->where('tenant_id', $tenantId)->where('identifier_type', $method->type)
            ->where('identifier_hash', $hash)->exists();
    }
}
