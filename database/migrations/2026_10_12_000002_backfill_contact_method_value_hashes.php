<?php

use App\Contacts\ContactMethodValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $values = new ContactMethodValue();

        DB::table('contact_methods')->whereNull('value_hash')->orderBy('id')->chunkById(250, function ($methods) use ($values): void {
            foreach ($methods as $method) {
                $plainValue = $values->decrypt($method->value);
                DB::table('contact_methods')->where('id', $method->id)->whereNull('value_hash')->update([
                    'value_hash' => $values->fingerprint($method->type, $plainValue),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Backfilled HMAC fingerprints are derived lookup metadata and remain valid after rollback.
    }
};
