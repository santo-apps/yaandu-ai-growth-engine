<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('proposals', 'safe_document_error')) Schema::table('proposals', function (Blueprint $table): void {
            $table->text('safe_document_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void { $table->dropColumn('safe_document_error'); });
    }
};
