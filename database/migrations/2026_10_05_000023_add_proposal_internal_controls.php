<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $hasLatestVersionConstraint = collect(Schema::getForeignKeys('proposals'))->contains(fn (array $foreign): bool => ($foreign['columns'] ?? []) === ['tenant_id', 'latest_version_id']);
        if (! $hasLatestVersionConstraint) Schema::table('proposals', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'latest_version_id'])->references(['tenant_id', 'id'])->on('proposal_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void { $table->dropForeign(['tenant_id', 'latest_version_id']); });
    }
};
