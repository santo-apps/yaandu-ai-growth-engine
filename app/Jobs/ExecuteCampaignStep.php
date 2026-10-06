<?php

namespace App\Jobs;

use App\Campaigns\Enums\CampaignEnrollmentStatus;
use App\Campaigns\Enums\CampaignStatus;
use App\Campaigns\CampaignEventRecorder;
use App\Campaigns\SuppressionChecker;
use App\Models\Campaign;
use App\Models\CampaignEnrollment;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\OutboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ExecuteCampaignStep implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    public int $uniqueFor = 600;

    public function __construct(public string $tenantId, public string $enrollmentId)
    {
        $this->onQueue('campaigns');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->enrollmentId; }

    public function handle(SuppressionChecker $suppression): void
    {
        $messageId = DB::transaction(function () use ($suppression): ?string {
            $enrollment = CampaignEnrollment::where('tenant_id', $this->tenantId)->lockForUpdate()->find($this->enrollmentId);
            if (! $enrollment || $enrollment->status !== CampaignEnrollmentStatus::Active) return null;
            $campaign = Campaign::where('tenant_id', $this->tenantId)->find($enrollment->campaign_id);
            if (! $campaign || $campaign->status !== CampaignStatus::Active) return null;

            if (DB::table('conversations')->where('tenant_id', $this->tenantId)->where('contact_id', $enrollment->contact_id)
                ->whereIn('status', ['human_review', 'human_active', 'resolved'])->exists()) {
                $enrollment->update(['status' => CampaignEnrollmentStatus::HandedOff, 'stop_reason' => 'conversation_handoff', 'stopped_at' => now()]);

                return null;
            }

            if ($suppression->isMethodSuppressed($this->tenantId, $enrollment->contact_method_id)) {
                $enrollment->update(['status' => CampaignEnrollmentStatus::Suppressed, 'suppression_outcome' => 'suppressed',
                    'stop_reason' => 'suppressed', 'stopped_at' => now()]);

                return null;
            }

            $step = CampaignStep::where('tenant_id', $this->tenantId)->where('campaign_id', $campaign->id)
                ->where('ordinal', '>', (int) ($enrollment->current_step_ordinal ?? 0))->where('active', true)->orderBy('ordinal')->first();
            if (! $step) {
                $enrollment->update(['status' => CampaignEnrollmentStatus::Completed, 'completed_at' => now(), 'next_step_at' => null]);

                return null;
            }
            $template = CampaignTemplate::where('tenant_id', $this->tenantId)->where('status', 'approved')
                ->where(function ($query) use ($campaign): void {
                    $query->whereNull('campaign_id')->orWhere('campaign_id', $campaign->id);
                })->where('id', $step->template_id)->first();
            if (! $template || trim((string) $template->subject) === '') return null;
            $method = DB::table('contact_methods')->where('tenant_id', $this->tenantId)->where('id', $enrollment->contact_method_id)->first();
            $company = DB::table('companies')->where('tenant_id', $this->tenantId)->where('id', $enrollment->company_id)->first(['name', 'normalized_domain']);
            $contact = DB::table('contacts')->where('tenant_id', $this->tenantId)->where('id', $enrollment->contact_id)->first(['name']);
            $sender = DB::table('tenant_messaging_configurations')->where('tenant_id', $this->tenantId)->where('enabled', true)->first();
            if (! $method || $method->type !== 'email' || ! $sender || ! $sender->from_email) return null;

            $recipient = Crypt::decryptString($method->value);
            $firstName = trim(explode(' ', trim((string) ($contact->name ?? '')))[0] ?? '');
            $domain = (string) ($company->normalized_domain ?? '');
            $variables = [
                'company_name' => (string) ($company->name ?? ''),
                'contact_name' => (string) ($contact->name ?? ''),
                'contact_first_name' => $firstName,
                'website' => $domain === '' ? '' : 'https://'.$domain,
                'sender_name' => (string) ($sender->from_name ?? ''),
            ];
            $rendered = app(\App\Campaigns\CampaignTemplateRenderer::class)->render($template->subject, $template->body, $variables);
            $unsubscribe = URL::temporarySignedRoute('outbound.unsubscribe', now()->addYear(), ['enrollment' => $enrollment->id]);
            $rendered['body'] .= "\n\nTo stop these emails, unsubscribe here: {$unsubscribe}";
            $idempotencyKey = hash_hmac('sha256', $this->tenantId.':'.$enrollment->id.':'.$step->id, (string) config('app.key'));
            $existing = OutboundMessage::where('tenant_id', $this->tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                app(CampaignEventRecorder::class)->record($this->tenantId, $campaign->id, 'message_queued', 'message:'.$existing->id.':queued',
                    $enrollment->id, $existing->correlation_id, ['outbound_message_id' => $existing->id, 'step_id' => $step->id]);
                return $existing->id;
            }

            $id = (string) Str::uuid();
            $correlationId = (string) Str::uuid();
            DB::table('outbound_messages')->insert([
                'id' => $id, 'tenant_id' => $this->tenantId, 'campaign_id' => $campaign->id,
                'campaign_recipient_id' => $enrollment->id, 'campaign_step_id' => $step->id,
                'contact_method_id' => $method->id, 'idempotency_key' => $idempotencyKey, 'provider' => $sender->provider,
                'status' => 'queued', 'channel' => 'email', 'correlation_id' => $correlationId,
                'subject_ciphertext' => Crypt::encryptString($rendered['subject']),
                'body_ciphertext' => Crypt::encryptString($rendered['body']),
                'recipient_hash' => hash_hmac('sha256', mb_strtolower($recipient), (string) config('app.key')),
                'attempt_count' => 0, 'scheduled_at' => now(), 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(CampaignEventRecorder::class)->record($this->tenantId, $campaign->id, 'message_queued', 'message:'.$id.':queued',
                $enrollment->id, $correlationId, ['outbound_message_id' => $id, 'step_id' => $step->id]);

            return $id;
        });

        if ($messageId) {
            $message = DB::table('outbound_messages')->where('tenant_id', $this->tenantId)->where('id', $messageId)->first();
            $step = $message ? CampaignStep::where('tenant_id', $this->tenantId)->where('id', $message->campaign_step_id)->first() : null;
            $enrollment = $message ? CampaignEnrollment::where('tenant_id', $this->tenantId)->where('id', $message->campaign_recipient_id)->first() : null;
            if (! $message || ! $step || ! $enrollment) return;
            $workflow = app(\App\Orchestration\WorkflowService::class)->ensureEnrollmentWorkflow($this->tenantId, $enrollment->company_id, $message->campaign_id, $enrollment->id);
            app(\App\Orchestration\WorkflowService::class)->recordCompanyEvent($this->tenantId, $enrollment->company_id, 'message_queued',
                'workflow:message-queued:'.$message->id, ['campaign_id' => $message->campaign_id, 'enrollment_id' => $enrollment->id, 'outbound_message_id' => $message->id]);
            $action = $step->ordinal > 1 ? 'SEND_FOLLOW_UP' : 'SEND_OUTREACH';
            app(\App\Orchestration\ApprovalService::class)->request($this->tenantId, $workflow->id, $action,
                ['target_type' => 'outbound_message', 'target_id' => $message->id],
                $step->ordinal > 1 ? 'Approval required before sending a follow-up message.' : 'Approval required before sending the first outreach message.',
                ($step->ordinal > 1 ? 'follow-up-message:' : 'outreach-message:').$message->id);
        }
    }
}
