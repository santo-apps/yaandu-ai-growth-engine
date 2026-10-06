<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('follow_up_recommendations', function (Blueprint $table): void {
            $table->uuid('source_message_id')->nullable();
            $table->unique(['tenant_id', 'source_message_id']);
            $table->foreign(['tenant_id', 'source_message_id'])->references(['tenant_id', 'id'])->on('conversation_messages');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_recommendations', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'source_message_id']);
            $table->dropUnique(['tenant_id', 'source_message_id']);
            $table->dropColumn('source_message_id');
        });
    }
};
