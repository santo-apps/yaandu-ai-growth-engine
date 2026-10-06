<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->uuid('campaign_recipient_id')->nullable();
            $table->foreign(['tenant_id', 'campaign_recipient_id', 'campaign_id'])
                ->references(['tenant_id', 'id', 'campaign_id'])->on('campaign_recipients')->cascadeOnDelete();
            $table->index(['tenant_id', 'campaign_recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'campaign_recipient_id', 'campaign_id']);
            $table->dropIndex(['tenant_id', 'campaign_recipient_id']);
            $table->dropColumn('campaign_recipient_id');
        });
    }
};
