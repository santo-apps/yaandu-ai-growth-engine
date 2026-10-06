<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('conversations')->where('status', 'open')->update(['status' => 'ai_active']);
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('status')->default('ai_active')->change();
            $table->string('intent', 40)->nullable();
            $table->decimal('intent_confidence', 5, 4)->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('recommended_next_action', 48)->nullable();
            $table->text('recommendation_reason')->nullable();
            $table->jsonb('recommendation_evidence')->nullable();
            $table->string('handoff_reason', 64)->nullable();
            $table->timestampTz('handed_off_at')->nullable();
            $table->foreignId('handed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('last_inbound_at')->nullable();
            $table->unsignedTinyInteger('misunderstanding_count')->default(0);
            $table->uuid('campaign_id')->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'updated_at']);
            $table->index(['tenant_id', 'intent', 'intent_confidence']);
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $table->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns');
            $table->unique(['tenant_id', 'campaign_id', 'contact_id', 'channel']);
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->text('body_ciphertext')->nullable();
            $table->string('intent', 40)->nullable();
            $table->decimal('intent_confidence', 5, 4)->nullable();
            $table->jsonb('evidence_references')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('outbound_message_id')->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'outbound_message_id']);
            $table->index(['tenant_id', 'conversation_id', 'created_at']);
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'outbound_message_id'])->references(['tenant_id', 'id'])->on('outbound_messages');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'conversation_id']);
            $table->dropForeign(['tenant_id', 'outbound_message_id']);
            $table->dropForeign(['created_by']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropUnique(['tenant_id', 'outbound_message_id']);
            $table->dropIndex(['tenant_id', 'conversation_id', 'created_at']);
            $table->dropColumn(['body_ciphertext', 'intent', 'intent_confidence', 'evidence_references', 'correlation_id', 'created_by', 'outbound_message_id']);
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'company_id']);
            $table->dropForeign(['tenant_id', 'contact_id']);
            $table->dropForeign(['tenant_id', 'campaign_id']);
            $table->dropForeign(['handed_off_by']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropUnique(['tenant_id', 'campaign_id', 'contact_id', 'channel']);
            $table->dropIndex(['tenant_id', 'status', 'updated_at']);
            $table->dropIndex(['tenant_id', 'intent', 'intent_confidence']);
            $table->dropColumn(['intent', 'intent_confidence', 'ai_summary', 'recommended_next_action', 'recommendation_reason', 'recommendation_evidence', 'handoff_reason', 'handed_off_at', 'handed_off_by', 'correlation_id', 'last_inbound_at', 'misunderstanding_count', 'campaign_id']);
            $table->string('status')->default('open')->change();
        });
        DB::table('conversations')->where('status', 'ai_active')->update(['status' => 'open']);
    }
};
