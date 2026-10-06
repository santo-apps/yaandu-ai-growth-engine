<?php

namespace Tests\Feature;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Campaigns\Enums\CampaignStatus;
use App\Campaigns\SuppressionChecker;
use App\Contacts\ContactMethodValue;
use App\Jobs\ExecuteCampaignStep;
use App\Jobs\SendOutboundMessage;
use App\Messaging\OutboundMessageStatus;
use App\Messaging\OutboundProviderEvent;
use App\Messaging\TenantWebhookSecretResolver;
use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutboundExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_job_uses_encrypted_ledger_and_advances_exactly_once(): void
    {
        [$tenant, $user, $campaign, $enrollment, $step, $methodId] = $this->activeSequence('send-once');
        RateLimiter::clear('outbound:tenant:'.$tenant->id.':hour');
        RateLimiter::clear('outbound:campaign:'.$campaign->id.':hour');
        RateLimiter::clear('outbound:tenant:'.$tenant->id.':day');

        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        self::assertNotNull($message);
        $this->approveOutboundMessage($tenant->id, $message->id, (string) $user->id);
        self::assertStringNotContainsString('Alex Contact', $message->body_ciphertext);
        self::assertStringNotContainsString('lead@send-once.test', $message->body_ciphertext);
        self::assertSame('lead@send-once.test', app(ContactMethodValue::class)->decrypt(DB::table('contact_methods')->where('id', $methodId)->value('value')));

        $job = new SendOutboundMessage($tenant->id, $message->id);
        $job->handle(app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class));
        $stored = DB::table('outbound_messages')->where('id', $message->id)->first();
        self::assertSame(OutboundMessageStatus::Accepted->value, $stored->status);
        self::assertSame('Personal note for Alex Contact', \Illuminate\Support\Facades\Crypt::decryptString($stored->subject_ciphertext));
        self::assertStringContainsString('unsubscribe', \Illuminate\Support\Facades\Crypt::decryptString($stored->body_ciphertext));
        self::assertSame(CampaignEnrollmentStatus::Completed->value, DB::table('campaign_recipients')->where('id', $enrollment->id)->value('status'));
        $conversationMessage = DB::table('conversation_messages')->where('tenant_id', $tenant->id)->first();
        $this->assertDatabaseHas('conversations', ['tenant_id' => $tenant->id, 'campaign_id' => $message->campaign_id,
            'campaign_recipient_id' => $enrollment->id]);
        self::assertNotNull($conversationMessage);
        self::assertSame('', $conversationMessage->body);
        self::assertStringContainsString('unsubscribe', \Illuminate\Support\Facades\Crypt::decryptString($conversationMessage->body_ciphertext));

        $job->handle(app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class));
        self::assertDatabaseCount('outbound_messages', 1);
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_accepted')->count());
    }

    public function test_campaign_activities_follow_accepted_sent_and_delivered_transitions_once(): void
    {
        [$tenant, $owner, $campaign, $enrollment] = $this->activeSequence('outbound-event-semantics');
        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->approveOutboundMessage($tenant->id, $message->id, (string) $owner->id);

        $job = new SendOutboundMessage($tenant->id, $message->id);
        $job->handle(app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class));
        $accepted = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->where('id', $message->id)->firstOrFail();
        self::assertSame('accepted', $accepted->status);
        self::assertNull($accepted->sent_at);
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_accepted')->count());
        self::assertSame(0, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_sent')->count());
        self::assertSame(0, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_delivered')->count());

        $sendWebhook = function (string $eventId, string $status) use ($tenant, $accepted): void {
            $payload = json_encode(['tenant_id' => $tenant->id, 'event_id' => $eventId, 'message_id' => $accepted->provider_message_id,
                'status' => $status, 'occurred_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $payload, app(TenantWebhookSecretResolver::class)->forTenant($tenant->id, 'fake'));
            $this->call('POST', '/api/v1/webhooks/outbound/fake', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $signature,
            ], $payload)->assertNoContent();
        };

        $sendWebhook('sent-event-one', 'sent');
        $sendWebhook('sent-event-one', 'sent');
        self::assertSame('sent', DB::table('outbound_messages')->where('id', $message->id)->value('status'));
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_accepted')->count());
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_sent')->count());
        self::assertSame(0, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_delivered')->count());

        $sendWebhook('delivered-event-one', 'delivered');
        $sendWebhook('delivered-event-one', 'delivered');
        self::assertSame('delivered', DB::table('outbound_messages')->where('id', $message->id)->value('status'));
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_accepted')->count());
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_sent')->count());
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'message_delivered')->count());
        self::assertSame(1, DB::table('workflow_events')->where('tenant_id', $tenant->id)->where('event', 'message_sent')->count());
        self::assertSame(1, DB::table('workflow_events')->where('tenant_id', $tenant->id)->where('event', 'message_delivered')->count());
        self::assertSame(CampaignEnrollmentStatus::Completed->value,
            DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->where('id', $enrollment->id)->value('status'));
    }

    public function test_first_touch_delivery_worker_fails_closed_without_executed_approval(): void
    {
        [$tenant, , , $enrollment] = $this->activeSequence('first-touch-approval-boundary');
        Queue::fake();
        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        self::assertNotNull($message);
        $this->assertDatabaseHas('workflow_approvals', ['tenant_id' => $tenant->id, 'target_id' => $message->id, 'action' => 'SEND_OUTREACH', 'status' => 'PENDING']);

        (new SendOutboundMessage($tenant->id, $message->id))->handle(
            app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class),
        );

        self::assertSame('queued', DB::table('outbound_messages')->where('id', $message->id)->value('status'));
        self::assertNull(DB::table('outbound_messages')->where('id', $message->id)->value('provider_message_id'));
    }

    public function test_human_review_conversation_prevents_automated_send(): void
    {
        [$tenant, , $campaign, $enrollment] = $this->activeSequence('human-review-stop');
        \App\Models\Conversation::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'company_id' => $enrollment->company_id, 'contact_id' => $enrollment->contact_id, 'channel' => 'email', 'status' => 'human_review']);

        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));

        self::assertDatabaseCount('outbound_messages', 0);
        self::assertSame(CampaignEnrollmentStatus::HandedOff->value, DB::table('campaign_recipients')->where('id', $enrollment->id)->value('status'));
    }

    public function test_sequence_advances_once_after_acceptance_and_applies_next_step_delay(): void
    {
        [$tenant, $owner, $campaign, $enrollment, ,] = $this->activeSequence('follow-up-delay');
        $template = CampaignTemplate::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'name' => 'Follow up',
            'channel' => 'email', 'subject' => 'Following up', 'body' => 'A short follow-up.', 'status' => 'approved', 'version' => 1]);
        CampaignStep::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'template_id' => $template->id,
            'ordinal' => 2, 'step_type' => 'email', 'delay_seconds' => 259200]);
        RateLimiter::clear('outbound:tenant:'.$tenant->id.':hour');
        RateLimiter::clear('outbound:campaign:'.$campaign->id.':hour');
        RateLimiter::clear('outbound:tenant:'.$tenant->id.':day');

        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        $this->approveOutboundMessage($tenant->id, $message->id, (string) $owner->id);
        (new SendOutboundMessage($tenant->id, $message->id))->handle(
            app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class),
        );

        $advanced = DB::table('campaign_recipients')->where('tenant_id', $tenant->id)->where('id', $enrollment->id)->first();
        self::assertSame(CampaignEnrollmentStatus::Active->value, $advanced->status);
        self::assertSame(1, (int) $advanced->current_step_ordinal);
        self::assertGreaterThanOrEqual(now()->addSeconds(259199)->timestamp, strtotime($advanced->next_step_at));
        self::assertSame(1, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->count());
    }

    public function test_follow_up_stops_for_tenant_approval_and_dispatches_only_after_approval(): void
    {
        [$tenant, $owner, $campaign, $enrollment] = $this->activeSequence('follow-up-approval');
        $template = CampaignTemplate::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'name' => 'Approved follow-up',
            'channel' => 'email', 'subject' => 'Checking in', 'body' => 'A short follow-up.', 'status' => 'approved', 'version' => 1]);
        $step = CampaignStep::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'template_id' => $template->id,
            'ordinal' => 2, 'step_type' => 'email', 'delay_seconds' => 0]);
        Queue::fake();
        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $first = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        $firstApproval = DB::table('workflow_approvals')->where('tenant_id', $tenant->id)->where('target_id', $first->id)->first();
        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/automation/approvals/'.$firstApproval->id.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'EXECUTED');
        (new SendOutboundMessage($tenant->id, $first->id))->handle(app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class));

        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $followup = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->where('campaign_step_id', $step->id)->first();
        self::assertNotNull($followup);
        self::assertSame('queued', $followup->status);
        $approval = DB::table('workflow_approvals')->where('tenant_id', $tenant->id)->where('action', 'SEND_FOLLOW_UP')->first();
        self::assertNotNull($approval);
        self::assertSame('PENDING', $approval->status);
        Queue::assertNotPushed(SendOutboundMessage::class, fn (SendOutboundMessage $job) => $job->messageId === $followup->id);

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/automation/approvals/'.$approval->id.'/approve')->assertOk()->assertJsonPath('data.status', 'EXECUTED');
        Queue::assertPushed(SendOutboundMessage::class, fn (SendOutboundMessage $job) => $job->tenantId === $tenant->id && $job->messageId === $followup->id);
    }

    public function test_paused_campaign_does_not_create_or_send_sequence_messages(): void
    {
        [$tenant, , $campaign, $enrollment] = $this->activeSequence('paused-execution');
        $campaign->update(['status' => CampaignStatus::Paused]);

        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));

        self::assertDatabaseCount('outbound_messages', 0);
        self::assertSame(CampaignEnrollmentStatus::Active->value, DB::table('campaign_recipients')->where('id', $enrollment->id)->value('status'));
    }

    public function test_database_send_history_enforces_campaign_daily_limit_across_enrollments(): void
    {
        [$tenant, $owner, $campaign, $firstEnrollment] = $this->activeSequence('durable-send-limit');
        $campaign->update(['daily_limit' => 1]);
        RateLimiter::clear('outbound:tenant:'.$tenant->id.':hour');
        RateLimiter::clear('outbound:campaign:'.$campaign->id.':hour');
        RateLimiter::clear('outbound:tenant:'.$tenant->id.':day');
        (new ExecuteCampaignStep($tenant->id, $firstEnrollment->id))->handle(app(SuppressionChecker::class));
        $firstMessage = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        $this->approveOutboundMessage($tenant->id, $firstMessage->id, (string) $owner->id);
        (new SendOutboundMessage($tenant->id, $firstMessage->id))->handle(
            app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class),
        );

        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Second company',
            'normalized_domain' => 'second-durable-send-limit.test', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $companyId, 'name' => 'Second contact',
            'source_url' => 'https://second-durable-send-limit.test/team', 'observed_at' => now(), 'extraction_method' => 'test', 'confidence' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $methodId = (string) Str::uuid();
        $values = app(ContactMethodValue::class);
        DB::table('contact_methods')->insert(['id' => $methodId, 'tenant_id' => $tenant->id, 'contact_id' => $contactId, 'type' => 'email',
            'value' => $values->encrypt('second@second-durable-send-limit.test'),
            'value_hash' => $values->fingerprint('email', 'second@second-durable-send-limit.test'),
            'source_url' => 'https://second-durable-send-limit.test/team', 'observed_at' => now(), 'extraction_method' => 'test', 'confidence' => 1,
            'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        $secondEnrollment = CampaignEnrollment::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'company_id' => $companyId,
            'contact_id' => $contactId, 'contact_method_id' => $methodId, 'idempotency_key' => 'durable-send-limit-second',
            'status' => CampaignEnrollmentStatus::Active, 'current_step_ordinal' => 0, 'enrolled_at' => now()]);
        (new ExecuteCampaignStep($tenant->id, $secondEnrollment->id))->handle(app(SuppressionChecker::class));
        $secondMessage = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->where('campaign_recipient_id', $secondEnrollment->id)->first();
        self::assertNotNull($secondMessage);
        $this->approveOutboundMessage($tenant->id, $secondMessage->id, (string) $owner->id);

        (new SendOutboundMessage($tenant->id, $secondMessage->id))->handle(
            app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class),
        );

        $secondMessage = DB::table('outbound_messages')->where('id', $secondMessage->id)->first();
        self::assertSame('queued', $secondMessage->status);
        self::assertNull($secondMessage->attempted_at);
        self::assertSame(1, DB::table('outbound_messages')->where('tenant_id', $tenant->id)->where('status', 'accepted')->count());
    }

    public function test_signed_unsubscribe_stops_enrollment_and_is_idempotent(): void
    {
        [$tenant, , , $enrollment, , $methodId] = $this->activeSequence('unsubscribe');
        $workflow = app(\App\Orchestration\WorkflowService::class)->create($tenant->id, ['company_id' => $enrollment->company_id,
            'campaign_id' => $enrollment->campaign_id, 'enrollment_id' => $enrollment->id]);
        $url = URL::temporarySignedRoute('outbound.unsubscribe', now()->addDay(), ['enrollment' => $enrollment->id]);

        $this->get($url)->assertOk()->assertSee('Confirm unsubscribe');
        $this->post($url)->assertNoContent();
        $this->post($url)->assertNoContent();

        $hash = app(ContactMethodValue::class)->fingerprint('email', 'lead@unsubscribe.test');
        $this->assertDatabaseHas('suppression_lists', ['tenant_id' => $tenant->id, 'identifier_hash' => $hash, 'reason' => 'unsubscribe']);
        $this->assertDatabaseHas('campaign_recipients', ['tenant_id' => $tenant->id, 'id' => $enrollment->id, 'status' => 'unsubscribed']);
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'id' => $workflow->id, 'status' => 'CANCELLED']);
        self::assertSame(1, DB::table('campaign_events')->where('tenant_id', $tenant->id)->where('event_type', 'unsubscribed')->count());
    }

    public function test_signed_bounce_event_is_tenant_bound_replay_safe_and_suppresses_future_send(): void
    {
        [$tenant, $owner, $campaign, $enrollment, , $methodId] = $this->activeSequence('webhook-bounce');
        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        $this->approveOutboundMessage($tenant->id, $message->id, (string) $owner->id);
        (new SendOutboundMessage($tenant->id, $message->id))->handle(
            app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class),
        );
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        $payload = json_encode(['tenant_id' => $tenant->id, 'event_id' => 'provider-event-1', 'message_id' => $message->provider_message_id,
            'status' => 'bounced', 'occurred_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
        $secret = app(TenantWebhookSecretResolver::class)->forTenant($tenant->id, 'fake');
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->call('POST', '/api/v1/webhooks/outbound/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => 'invalid',
        ], $payload)->assertForbidden();
        $this->call('POST', '/api/v1/webhooks/outbound/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $signature,
        ], $payload)->assertNoContent();
        $this->call('POST', '/api/v1/webhooks/outbound/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PROVIDER_SIGNATURE' => $signature,
        ], $payload)->assertNoContent();

        self::assertSame(OutboundMessageStatus::Bounced->value, DB::table('outbound_messages')->where('id', $message->id)->value('status'));
        self::assertSame(CampaignEnrollmentStatus::Bounced->value, DB::table('campaign_recipients')->where('id', $enrollment->id)->value('status'));
        self::assertSame(1, DB::table('outbound_message_events')->where('tenant_id', $tenant->id)->count());
        $hash = app(ContactMethodValue::class)->fingerprint('email', 'lead@webhook-bounce.test');
        $this->assertDatabaseHas('suppression_lists', ['tenant_id' => $tenant->id, 'identifier_hash' => $hash, 'reason' => 'bounced']);
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'enrollment_id' => $enrollment->id, 'status' => 'CANCELLED']);
        self::assertSame('active', $campaign->fresh()->status->value);
        self::assertNotNull(DB::table('contact_methods')->where('id', $methodId)->value('value_hash'));
    }

    public function test_complaint_event_stops_sequence_suppresses_address_and_is_idempotent(): void
    {
        [$tenant, $owner, , $enrollment] = $this->activeSequence('complaint-suppression');
        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        $this->approveOutboundMessage($tenant->id, $message->id, (string) $owner->id);
        (new SendOutboundMessage($tenant->id, $message->id))->handle(
            app(\App\Messaging\OutboundMessagingProviderRouter::class), app(SuppressionChecker::class), app(\App\Campaigns\SendingWindowCalculator::class),
        );
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        $event = new OutboundProviderEvent('complaint-event', $message->provider_message_id, OutboundMessageStatus::Complained, new \DateTimeImmutable);
        $processor = app(\App\Messaging\MessageEventProcessor::class);

        self::assertTrue($processor->process($tenant->id, 'fake', $event));
        self::assertFalse($processor->process($tenant->id, 'fake', $event));
        self::assertSame('complained', DB::table('outbound_messages')->where('id', $message->id)->value('status'));
        self::assertSame('stopped', DB::table('campaign_recipients')->where('id', $enrollment->id)->value('status'));
        $hash = app(ContactMethodValue::class)->fingerprint('email', 'lead@complaint-suppression.test');
        $this->assertDatabaseHas('suppression_lists', ['tenant_id' => $tenant->id, 'identifier_hash' => $hash, 'reason' => 'complaint']);
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'enrollment_id' => $enrollment->id, 'status' => 'CANCELLED']);
    }

    public function test_manual_suppression_terminalizes_a_previously_queued_message(): void
    {
        [$tenant, $owner, , $enrollment] = $this->activeSequence('stop-queued-message');
        Queue::fake();
        (new ExecuteCampaignStep($tenant->id, $enrollment->id))->handle(app(SuppressionChecker::class));
        $message = DB::table('outbound_messages')->where('tenant_id', $tenant->id)->first();
        self::assertSame('queued', $message->status);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/suppressions', ['email' => 'lead@stop-queued-message.test'])->assertCreated();

        self::assertSame('suppressed', DB::table('outbound_messages')->where('id', $message->id)->value('status'));
        self::assertSame('suppressed', DB::table('campaign_recipients')->where('id', $enrollment->id)->value('status'));
        $this->assertDatabaseHas('acquisition_workflows', ['tenant_id' => $tenant->id, 'enrollment_id' => $enrollment->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('campaign_events', ['tenant_id' => $tenant->id, 'campaign_id' => $message->campaign_id, 'event_type' => 'message_suppressed']);
    }

    private function approveOutboundMessage(string $tenantId, string $messageId, string $actorId): void
    {
        $approval = DB::table('workflow_approvals')->where('tenant_id', $tenantId)->where('target_type', 'outbound_message')
            ->where('target_id', $messageId)->where('status', 'PENDING')->first();
        self::assertNotNull($approval, 'The outbound step must wait for an explicit approval.');
        app(\App\Orchestration\ApprovalService::class)->decide($tenantId, $approval->id, 'approve', $actorId);
    }

    private function activeSequence(string $slug): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug]);
        $user = User::create(['name' => 'Campaign owner', 'email' => $slug.'@test.local', 'password' => 'hashed-test-password']);
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);
        DB::table('tenant_messaging_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
            'provider' => 'fake', 'enabled' => true, 'from_name' => 'Yaandu', 'from_email' => 'sales@yaandu.example',
            'hourly_limit' => 10, 'daily_limit' => 100, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'tenant_id' => $tenant->id, 'name' => 'Send Company',
            'normalized_domain' => $slug.'.test', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert(['id' => $contactId, 'tenant_id' => $tenant->id, 'company_id' => $companyId,
            'name' => 'Alex Contact', 'source_url' => 'https://'.$slug.'.test/team', 'observed_at' => now(), 'extraction_method' => 'test',
            'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $methodId = (string) Str::uuid();
        $value = app(ContactMethodValue::class);
        DB::table('contact_methods')->insert(['id' => $methodId, 'tenant_id' => $tenant->id, 'contact_id' => $contactId, 'type' => 'email',
            'value' => $value->encrypt('lead@'.$slug.'.test'), 'value_hash' => $value->fingerprint('email', 'lead@'.$slug.'.test'),
            'source_url' => 'https://'.$slug.'.test/team', 'observed_at' => now(), 'extraction_method' => 'test', 'confidence' => 1,
            'verification_status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        $campaign = Campaign::create(['tenant_id' => $tenant->id, 'name' => 'Outbound test', 'status' => CampaignStatus::Active]);
        $template = CampaignTemplate::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'name' => 'Intro',
            'channel' => 'email', 'subject' => 'Personal note for {{ contact_name }}', 'body' => 'Hello {{ contact_name }} at {{ company_name }}.',
            'status' => 'approved', 'version' => 1]);
        $step = CampaignStep::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'template_id' => $template->id,
            'ordinal' => 1, 'step_type' => 'email', 'delay_seconds' => 0]);
        $enrollment = CampaignEnrollment::create(['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'company_id' => $companyId, 'contact_id' => $contactId, 'contact_method_id' => $methodId,
            'idempotency_key' => hash('sha256', $slug), 'status' => CampaignEnrollmentStatus::Active,
            'current_step_ordinal' => 0, 'enrolled_at' => now(), 'next_step_at' => now()]);

        return [$tenant, $user, $campaign, $enrollment, $step, $methodId];
    }
}
