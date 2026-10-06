<?php

namespace Tests\Feature;

use App\AI\AIModelRouter;
use App\AI\Providers\DeterministicAIProvider;
use App\Conversations\ConversationWorkflowStatus;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Contacts\ContactMethodValue;
use App\Jobs\SendConversationReply;
use App\Messaging\FakeOutboundMessagingProvider;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\StaticAIProvider;
use Tests\TestCase;

class ConversationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_http_happy_path_uses_local_deterministic_provider(): void
    {
        [$tenant, $owner, $conversation] = $this->conversation('deterministic-classification');
        ConversationMessage::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id,
            'direction' => 'inbound', 'body' => '', 'body_ciphertext' => Crypt::encryptString('We are interested in improving our ecommerce experience. Can we book a meeting?')]);
        config(['ai.local_acceptance.enabled' => true]);
        $this->app->instance(AIModelRouter::class, new AIModelRouter([new DeterministicAIProvider()], [
            'sales_reasoning' => ['provider' => 'deterministic', 'model' => 'local-acceptance-v1'],
        ]));
        Sanctum::actingAs($owner);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/conversations/'.$conversation->id.'/classify')
            ->assertOk()->assertJsonPath('analysis.intent', 'interested')
            ->assertJsonPath('analysis.confidence', 0.97)
            ->assertJsonPath('analysis.recommended_action', 'continue_qualification')
            ->assertJsonPath('analysis.risk', 'none')
            ->assertJsonStructure(['analysis' => ['summary', 'reason', 'evidence_references']])
            ->assertJsonPath('conversation.intent', 'interested');
    }

    public function test_classification_is_tenant_scoped_and_low_confidence_sets_human_review(): void
    {
        [$tenant, $user, $conversation] = $this->conversation('conversation-owner');
        [$otherTenant, , $otherConversation] = $this->conversation('other-conversation');
        ConversationMessage::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id,
            'direction' => 'inbound', 'body' => '', 'body_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('Could you tell me more?')]);
        Sanctum::actingAs($user);
        $provider = new StaticAIProvider([
            'intent' => 'unclear', 'confidence' => 0.2, 'summary' => 'Unclear reply', 'reason' => 'Insufficient context.', 'risk' => 'none', 'evidence_references' => [],
        ]);
        $router = new AIModelRouter([$provider], ['sales_reasoning' => ['provider' => 'conversation-test', 'model' => 'test-model']]);
        $this->app->instance(AIModelRouter::class, $router);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/conversations/'.$otherConversation->id)->assertNotFound();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/conversations/'.$conversation->id.'/classify')
            ->assertOk()->assertJsonPath('analysis.recommended_action', 'request_human_review')
            ->assertJsonPath('conversation.status', ConversationWorkflowStatus::HumanReview->value)
            ->assertJsonPath('decision.action', 'handoff_to_sales')->assertJsonPath('decision.status', 'applied');
        $this->assertDatabaseHas('conversations', ['tenant_id' => $tenant->id, 'id' => $conversation->id,
            'status' => ConversationWorkflowStatus::HumanReview->value, 'handoff_reason' => 'low_confidence']);
        $this->assertDatabaseMissing('conversations', ['tenant_id' => $otherTenant->id, 'id' => $otherConversation->id,
            'status' => ConversationWorkflowStatus::HumanReview->value]);
    }

    public function test_recommended_autonomous_actions_require_owner_approval_and_are_audited(): void
    {
        [$tenant, $owner, $conversation] = $this->conversation('decision-approval');
        ConversationMessage::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id,
            'direction' => 'inbound', 'body' => '', 'body_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString('We are interested in next steps.')]);
        Sanctum::actingAs($owner);
        $provider = new StaticAIProvider([
            'intent' => 'interested', 'confidence' => 0.94, 'summary' => 'Interested in next steps',
            'reason' => 'The reply explicitly asks to proceed.', 'risk' => 'none', 'evidence_references' => [],
        ]);
        $this->app->instance(AIModelRouter::class, new AIModelRouter([$provider], [
            'sales_reasoning' => ['provider' => 'conversation-test', 'model' => 'test-model'],
        ]));
        $decision = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/conversations/'.$conversation->id.'/classify')
            ->assertOk()->assertJsonPath('decision.action', 'schedule_followup')
            ->assertJsonPath('decision.status', 'pending_approval')->assertJsonPath('decision.requires_human_approval', true)
            ->json('decision');

        $member = User::create(['name' => 'Decision member', 'email' => 'decision-member@example.test', 'password' => 'hashed-test-password']);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($member);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/agent-decisions/'.$decision['id'].'/approve')->assertForbidden();

        Sanctum::actingAs($owner);
        $otherTenant = \App\Models\Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Second tenant', 'slug' => 'decision-second-tenant']);
        $owner->tenants()->attach($otherTenant->id, ['role' => 'owner', 'status' => 'active']);
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/agent-decisions/'.$decision['id'])->assertNotFound();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/agent-decisions/'.$decision['id'].'/approve')
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'subject_id' => $decision['id'], 'action' => 'agent_decision.approved']);
    }

    public function test_human_takeover_and_resolution_are_assigned_and_audited(): void
    {
        [$tenant, $assignedUser, $conversation] = $this->conversation('handoff-owner');
        $workflow = app(\App\Orchestration\WorkflowService::class)->create($tenant->id, ['company_id' => $conversation->company_id, 'conversation_id' => $conversation->id]);
        $conversation->update(['status' => ConversationWorkflowStatus::HumanReview, 'handoff_reason' => 'low_confidence']);
        Sanctum::actingAs($assignedUser);

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/conversations/'.$conversation->id.'/takeover')
            ->assertOk()->assertJsonPath('status', ConversationWorkflowStatus::HumanActive->value)
            ->assertJsonPath('owner_user_id', $assignedUser->id);

        $otherUser = User::create(['name' => 'Other member', 'email' => 'other-'.$tenant->slug.'@example.test', 'password' => 'hashed-test-password']);
        $otherUser->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($otherUser);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/conversations/'.$conversation->id.'/resolve')->assertForbidden();

        Sanctum::actingAs($assignedUser);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/conversations/'.$conversation->id.'/resolve')
            ->assertOk()->assertJsonPath('status', ConversationWorkflowStatus::Resolved->value);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'subject_id' => $conversation->id, 'action' => 'conversation.taken_over']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'subject_id' => $conversation->id, 'action' => 'conversation.resolved']);
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'id' => $workflow->id, 'current_stage' => 'HUMAN_HANDOFF', 'status' => 'PAUSED']);
    }

    public function test_ai_reply_draft_is_encrypted_editable_and_only_sent_after_human_approval(): void
    {
        Queue::fake();
        [$tenant, $owner, $conversation] = $this->conversation('reply-draft');
        $conversation->update(['status' => ConversationWorkflowStatus::HumanActive, 'owner_user_id' => $owner->id]);
        ConversationMessage::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'direction' => 'inbound',
            'body' => '', 'body_ciphertext' => Crypt::encryptString('Can you tell me what you do?')]);
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $conversation->company_id,
            'name' => 'Buyer', 'source_url' => 'https://reply-draft.test/team', 'observed_at' => now(), 'extraction_method' => 'test',
            'confidence' => .9, 'created_at' => now(), 'updated_at' => now()]);
        $conversation->update(['contact_id' => $contactId]);
        DB::table('contact_methods')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'contact_id' => $contactId,
            'type' => 'email', 'value' => app(ContactMethodValue::class)->encrypt('buyer@reply-draft.test'),
            'value_hash' => app(ContactMethodValue::class)->fingerprint('email', 'buyer@reply-draft.test'),
            'source_url' => 'https://reply-draft.test/contact', 'observed_at' => now(), 'extraction_method' => 'test', 'confidence' => .9,
            'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_messaging_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'provider' => 'fake',
            'enabled' => true, 'from_name' => 'Yaandu', 'from_email' => 'sales@yaandu.example', 'hourly_limit' => 10, 'daily_limit' => 100,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $provider = new StaticAIProvider(['subject' => 'Re: Your question', 'body' => 'We help businesses improve their digital presence.']);
        $this->app->instance(AIModelRouter::class, new AIModelRouter([$provider], ['content_generation' => ['provider' => 'conversation-test', 'model' => 'test-model']]));
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $draft = $this->withHeaders($headers)->postJson('/api/v1/conversations/'.$conversation->id.'/reply-drafts', [])
            ->assertCreated()->assertJsonPath('status', 'draft')->json();
        $stored = DB::table('conversation_reply_drafts')->where('id', $draft['id'])->first();
        self::assertSame('We help businesses improve their digital presence.', Crypt::decryptString($stored->body_ciphertext));
        self::assertSame('', $stored->body ?? '');
        $this->withHeaders($headers)->putJson('/api/v1/conversations/'.$conversation->id.'/reply-drafts/'.$draft['id'], [
            'subject' => 'Re: Your question', 'body' => 'Edited and reviewed by our team.',
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/conversations/'.$conversation->id.'/reply-drafts/'.$draft['id'].'/approve', [])
            ->assertAccepted()->assertJsonPath('status', 'approved');
        Queue::assertPushed(SendConversationReply::class, 1);
        (new SendConversationReply($tenant->id, $draft['id']))->handle(new OutboundMessagingProviderRouter([new FakeOutboundMessagingProvider]), app(ContactMethodValue::class));
        $this->assertDatabaseHas('conversation_reply_drafts', ['tenant_id' => $tenant->id, 'id' => $draft['id'], 'status' => 'sent']);
        $message = DB::table('conversation_messages')->where('conversation_id', $conversation->id)->where('direction', 'outbound')->first();
        self::assertSame('Edited and reviewed by our team.', Crypt::decryptString($message->body_ciphertext));
    }

    private function conversation(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $user = User::create(['name' => 'Conversation member', 'email' => $slug.'@example.test', 'password' => 'hashed-test-password']);
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'Conversation Co', 'normalized_domain' => $slug.'.test', 'status' => 'new']);
        $conversation = Conversation::create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'channel' => 'email', 'status' => 'ai_active']);

        return [$tenant, $user, $conversation];
    }
}
