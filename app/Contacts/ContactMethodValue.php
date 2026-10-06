<?php

namespace App\Contacts;

use Illuminate\Support\Facades\Crypt;

final class ContactMethodValue
{
    public function encrypt(string $value): string
    {
        return Crypt::encryptString($value);
    }

    public function fingerprint(string $type, string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        return hash_hmac('sha256', $type."\0".$normalized, (string) config('app.key'));
    }

    public function decrypt(string $value): string
    {
        return Crypt::decryptString($value);
    }
}
