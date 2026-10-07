<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_methods', fn (Blueprint $table) => $table->string('classification', 40)->nullable()->after('type'));
    }

    public function down(): void
    {
        Schema::table('contact_methods', fn (Blueprint $table) => $table->dropColumn('classification'));
    }
};
