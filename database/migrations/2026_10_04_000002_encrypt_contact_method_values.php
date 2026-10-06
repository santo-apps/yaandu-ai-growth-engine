<?php

use App\Contacts\ContactMethodValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_methods', function (Blueprint $table): void {
            $table->string('value_hash', 64)->nullable()->index();
        });

        $protection = new ContactMethodValue;
        DB::table('contact_methods')->orderBy('id')->chunk(100, function ($methods) use ($protection): void {
            foreach ($methods as $method) {
                DB::table('contact_methods')->where('id', $method->id)->update([
                    'value' => $protection->encrypt($method->value),
                    'value_hash' => $protection->fingerprint($method->type, $method->value),
                ]);
            }
        });
    }

    public function down(): void
    {
        $protection = new ContactMethodValue;
        DB::table('contact_methods')->orderBy('id')->chunk(100, function ($methods) use ($protection): void {
            foreach ($methods as $method) {
                DB::table('contact_methods')->where('id', $method->id)->update([
                    'value' => $protection->decrypt($method->value),
                ]);
            }
        });

        Schema::table('contact_methods', function (Blueprint $table): void {
            $table->dropIndex(['value_hash']);
            $table->dropColumn('value_hash');
        });
    }
};
