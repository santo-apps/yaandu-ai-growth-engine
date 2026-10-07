<?php

namespace App\Http\Controllers\Api\V1;

use App\Campaigns\Enums\CampaignStatus;
use App\Campaigns\CampaignLifecycle;
use App\Campaigns\CampaignActivationException;
use App\Campaigns\CampaignEventRecorder;
use App\Contacts\ContactMethodValue;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignAudience;
use App\Models\CampaignEnrollment;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', 'string', Rule::enum(CampaignStatus::class)]]);

        return Campaign::query()->where('tenant_id', app('tenant.id'))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->withCount(['enrollments', 'steps'])->orderByDesc('updated_at')->paginate(25);
    }

    public function store(Request $request)
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:4000'],
            'objective' => ['nullable', 'string', 'max:4000'],
            'timezone' => ['sometimes', 'timezone:all'], 'target_audience' => ['nullable', 'array:industries,locations,min_score,max_score,company_status'],
            'target_audience.industries' => ['sometimes', 'array', 'max:50'], 'target_audience.industries.*' => ['string', 'max:150'],
            'target_audience.locations' => ['sometimes', 'array', 'max:50'], 'target_audience.locations.*' => ['string', 'max:150'],
            'target_audience.min_score' => ['sometimes', 'integer', 'between:0,100'], 'target_audience.max_score' => ['sometimes', 'integer', 'between:0,100'],
            'sending_windows' => ['nullable', 'array:weekdays,start,end'], 'sending_windows.weekdays' => ['required_with:sending_windows', 'array', 'min:1', 'max:7'],
            'sending_windows.weekdays.*' => ['integer', 'between:1,7'], 'sending_windows.start' => ['required_with:sending_windows', 'date_format:H:i'],
            'sending_windows.end' => ['required_with:sending_windows', 'date_format:H:i'],
            'rate_limit_per_hour' => ['sometimes', 'integer', 'between:1,1000'], 'daily_limit' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
        if (isset($data['target_audience']['min_score'], $data['target_audience']['max_score'])
            && $data['target_audience']['min_score'] > $data['target_audience']['max_score']) {
            return response()->json(['message' => 'Minimum score cannot exceed maximum score.'], 422);
        }
        if (isset($data['sending_windows']) && $data['sending_windows']['start'] >= $data['sending_windows']['end']) {
            return response()->json(['message' => 'Sending window start must be before its end.'], 422);
        }

        $tenantId = app('tenant.id');
        $campaign = DB::transaction(function () use ($request, $data, $tenantId): Campaign {
            $campaign = Campaign::create([
                'tenant_id' => $tenantId, 'created_by' => $request->user()->id, 'name' => $data['name'],
                'description' => $data['description'] ?? null, 'objective' => $data['objective'] ?? null, 'status' => CampaignStatus::Draft,
                'target_audience' => $data['target_audience'] ?? [], 'timezone' => $data['timezone'] ?? 'UTC',
                'sending_windows' => $data['sending_windows'] ?? null,
                'rate_limit_per_hour' => $data['rate_limit_per_hour'] ?? 60, 'daily_limit' => $data['daily_limit'] ?? 500,
            ]);
            CampaignAudience::create(['tenant_id' => $tenantId, 'campaign_id' => $campaign->id,
                'name' => $data['name'].' audience', 'criteria' => $data['target_audience'] ?? []]);

            return $campaign;
        });

        $this->audit($request, 'campaign_created', 'campaign', $campaign->id);
        app(CampaignEventRecorder::class)->record($tenantId, $campaign->id, 'campaign_created', 'campaign:'.$campaign->id.':created');

        return response()->json($campaign->load('audience'), 201);
    }

    public function show(string $campaign)
    {
        $record = Campaign::where('tenant_id', app('tenant.id'))->with(['audience', 'steps.template', 'templates'])->findOrFail($campaign);
        $record->setRelation('enrollments', CampaignEnrollment::where('tenant_id', app('tenant.id'))
            ->where('campaign_id', $record->id)->with(['company', 'contact'])->orderByDesc('created_at')->paginate(25));
        $record->setAttribute('metrics', [
            'enrolled' => DB::table('campaign_recipients')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->count(),
            'active' => DB::table('campaign_recipients')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->where('status', 'active')->count(),
            'sent' => DB::table('outbound_messages')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->whereIn('status', ['sent', 'accepted', 'delivered'])->count(),
            'delivered' => DB::table('outbound_messages')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->where('status', 'delivered')->count(),
            'replied' => DB::table('campaign_recipients')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->where('status', 'replied')->count(),
            'bounced' => DB::table('campaign_recipients')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->where('status', 'bounced')->count(),
            'unsubscribed' => DB::table('campaign_recipients')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->where('status', 'unsubscribed')->count(),
        ]);
        $record->setRelation('events', DB::table('campaign_events')->where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)
            ->orderByDesc('occurred_at')->limit(100)->get());

        return $record;
    }

    public function update(Request $request, string $campaign)
    {
        $this->authorizeManager($request);
        $record = $this->draftCampaign($campaign);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'objective' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'timezone' => ['sometimes', 'timezone:all'], 'target_audience' => ['sometimes', 'array:industries,locations,min_score,max_score,company_status'],
            'sending_windows' => ['sometimes', 'nullable', 'array:weekdays,start,end'],
            'sending_windows.weekdays' => ['required_with:sending_windows', 'array', 'min:1', 'max:7'],
            'sending_windows.weekdays.*' => ['integer', 'between:1,7'],
            'sending_windows.start' => ['required_with:sending_windows', 'date_format:H:i'],
            'sending_windows.end' => ['required_with:sending_windows', 'date_format:H:i'],
            'rate_limit_per_hour' => ['sometimes', 'integer', 'between:1,1000'], 'daily_limit' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
        if (isset($data['sending_windows']) && $data['sending_windows'] !== null
            && $data['sending_windows']['start'] >= $data['sending_windows']['end']) {
            return response()->json(['message' => 'Sending window start must be before its end.'], 422);
        }
        if (isset($data['target_audience']['min_score'], $data['target_audience']['max_score'])
            && $data['target_audience']['min_score'] > $data['target_audience']['max_score']) {
            return response()->json(['message' => 'Minimum score cannot exceed maximum score.'], 422);
        }
        $record->update($data);
        if (array_key_exists('target_audience', $data)) {
            $record->audience()->updateOrCreate(['tenant_id' => app('tenant.id')], [
                'name' => $record->name.' audience', 'criteria' => $data['target_audience'],
            ]);
        }

        return $record->fresh()->load('audience');
    }

    public function storeTemplate(Request $request, string $campaign)
    {
        $this->authorizeManager($request);
        $record = $this->draftCampaign($campaign);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'channel' => ['sometimes', Rule::in(['email'])],
            'subject' => ['nullable', 'string', 'max:500', 'not_regex:/[\r\n]/'], 'body' => ['required', 'string', 'max:20000'],
        ]);
        try { app(\App\Campaigns\CampaignTemplateRenderer::class)->render($data['subject'] ?? '', $data['body'], [
            'contact_name' => 'Contact', 'contact_first_name' => 'Contact', 'company_name' => 'Company', 'website' => 'https://example.test', 'sender_name' => 'Sender',
        ]); } catch (\InvalidArgumentException) { return response()->json(['message' => 'Templates must be plain text and use supported placeholders only.'], 422); }
        $template = CampaignTemplate::create([
            'tenant_id' => app('tenant.id'), 'campaign_id' => $record->id, 'name' => $data['name'],
            'channel' => 'email', 'subject' => $data['subject'] ?? null, 'body' => $data['body'], 'status' => 'draft', 'version' => 1,
        ]);

        return response()->json($template, 201);
    }

    public function approveTemplate(Request $request, string $campaign, string $template)
    {
        $this->authorizeManager($request);
        $campaignRecord = $this->draftCampaign($campaign);
        $record = CampaignTemplate::where('tenant_id', app('tenant.id'))->where('campaign_id', $campaignRecord->id)->findOrFail($template);
        $record->update(['status' => 'approved']);

        return $record->fresh();
    }

    public function storeStep(Request $request, string $campaign)
    {
        $this->authorizeManager($request);
        $record = $this->draftCampaign($campaign);
        $data = $request->validate([
            'ordinal' => ['required', 'integer', 'between:1,20'], 'delay_seconds' => ['sometimes', 'integer', 'between:0,7776000'],
            'template_id' => ['required', 'uuid'],
        ]);
        $template = CampaignTemplate::where('tenant_id', app('tenant.id'))->where(function ($query) use ($record): void {
            $query->whereNull('campaign_id')->orWhere('campaign_id', $record->id);
        })->findOrFail($data['template_id']);
        abort_unless($template->status === 'approved', 422, 'Only an approved template can be used by a campaign step.');
        abort_if($record->steps()->count() >= 20, 422, 'Campaign sequence is limited to 20 steps.');

        $step = CampaignStep::create(['tenant_id' => app('tenant.id'), 'campaign_id' => $record->id,
            'template_id' => $template->id, 'ordinal' => $data['ordinal'], 'step_type' => 'email', 'delay_seconds' => $data['delay_seconds'] ?? 0]);

        return response()->json($step->load('template'), 201);
    }

    public function steps(string $campaign)
    {
        $record = Campaign::where('tenant_id', app('tenant.id'))->findOrFail($campaign);

        return CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)
            ->with('template')->orderBy('ordinal')->get();
    }

    public function updateStep(Request $request, string $campaign, string $step)
    {
        $this->authorizeManager($request);
        $record = $this->draftCampaign($campaign);
        $stepRecord = CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->findOrFail($step);
        $data = $request->validate([
            'delay_seconds' => ['sometimes', 'integer', 'between:0,7776000'],
            'template_id' => ['sometimes', 'uuid'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['template_id'])) {
            $template = CampaignTemplate::where('tenant_id', app('tenant.id'))->where(function ($query) use ($record): void {
                $query->whereNull('campaign_id')->orWhere('campaign_id', $record->id);
            })->where('status', 'approved')->findOrFail($data['template_id']);
        }
        $stepRecord->update($data);

        return $stepRecord->fresh()->load('template');
    }

    public function deleteStep(Request $request, string $campaign, string $step)
    {
        $this->authorizeManager($request);
        $record = $this->draftCampaign($campaign);
        DB::transaction(function () use ($record, $step): void {
            $steps = CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->orderBy('ordinal')->lockForUpdate()->get();
            $target = $steps->firstWhere('id', $step);
            abort_unless($target, 404);
            abort_if(DB::table('outbound_messages')->where('tenant_id', app('tenant.id'))->where('campaign_step_id', $target->id)->exists(), 409,
                'A step with outbound message history cannot be deleted.');
            $target->delete();
            $remaining = CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->orderBy('ordinal')->get();
            CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->increment('ordinal', 100);
            foreach ($remaining as $index => $item) {
                CampaignStep::where('tenant_id', app('tenant.id'))->whereKey($item->id)->update(['ordinal' => $index + 1]);
            }
        });

        return response()->noContent();
    }

    public function reorderSteps(Request $request, string $campaign)
    {
        $this->authorizeManager($request);
        $record = $this->draftCampaign($campaign);
        $data = $request->validate(['step_ids' => ['required', 'array', 'max:20'], 'step_ids.*' => ['required', 'uuid', 'distinct']]);
        DB::transaction(function () use ($record, $data): void {
            $steps = CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->lockForUpdate()->get();
            abort_unless($steps->count() === count($data['step_ids']) && $steps->pluck('id')->diff($data['step_ids'])->isEmpty(), 422,
                'Reorder must include every campaign step exactly once.');
            CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->increment('ordinal', 100);
            foreach ($data['step_ids'] as $index => $id) {
                CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->where('id', $id)->update(['ordinal' => $index + 1]);
            }
        });

        return CampaignStep::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->with('template')->orderBy('ordinal')->get();
    }

    public function enroll(Request $request, string $campaign)
    {
        $this->authorizeManager($request);
        $record = Campaign::where('tenant_id', app('tenant.id'))->where('status', CampaignStatus::Draft)->findOrFail($campaign);
        $data = $request->validate(['contact_id' => ['required', 'uuid'], 'contact_method_id' => ['nullable', 'uuid']]);
        $tenantId = app('tenant.id');
        $contact = DB::table('contacts')->join('companies', function ($join) use ($tenantId): void {
            $join->on('companies.id', '=', 'contacts.company_id')->where('companies.tenant_id', '=', $tenantId)->where('companies.status', '!=', 'discovery_candidate');
        })->where('contacts.tenant_id', $tenantId)->where('contacts.id', $data['contact_id'])->select('contacts.*')->first();
        abort_unless($contact, 404);
        $query = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('contact_id', $contact->id)->where('type', 'email');
        if (isset($data['contact_method_id'])) $query->where('id', $data['contact_method_id']);
        $method = $query->first();
        abort_unless($method, 422, 'A public email contact method is required.');

        $values = app(ContactMethodValue::class);
        $identifierHash = $values->fingerprint('email', $values->decrypt($method->value));
        $suppressed = DB::table('suppression_lists')->where('tenant_id', $tenantId)->where('identifier_type', 'email')
            ->where('identifier_hash', $identifierHash)->exists();
        $key = $request->header('Idempotency-Key') ?: hash_hmac('sha256', $record->id.':'.$contact->id.':'.$method->id, (string) config('app.key'));
        abort_if(strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key), 422, 'The idempotency key is invalid.');

        $created = false;
        try {
            $enrollment = DB::transaction(function () use ($record, $contact, $method, $tenantId, $key, $suppressed, &$created): CampaignEnrollment {
                $existing = CampaignEnrollment::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    abort_unless($existing->campaign_id === $record->id && $existing->contact_id === $contact->id
                        && $existing->contact_method_id === $method->id, 409, 'The idempotency key was already used for a different enrollment.');

                    return $existing;
                }
                $existing = CampaignEnrollment::where('tenant_id', $tenantId)->where('campaign_id', $record->id)->where('contact_id', $contact->id)->first();
                if ($existing) return $existing;

                $created = true;

                return CampaignEnrollment::create([
                    'tenant_id' => $tenantId, 'campaign_id' => $record->id, 'company_id' => $contact->company_id,
                    'contact_id' => $contact->id, 'contact_method_id' => $method->id,
                    'status' => $suppressed ? 'suppressed' : 'pending', 'suppression_outcome' => $suppressed ? 'suppressed' : null,
                    'stop_reason' => $suppressed ? 'suppressed' : null, 'stopped_at' => $suppressed ? now() : null,
                    'idempotency_key' => $key, 'enrolled_at' => now(),
                ]);
            });
        } catch (QueryException $error) {
            $enrollment = CampaignEnrollment::where('tenant_id', $tenantId)->where('campaign_id', $record->id)
                ->where('contact_id', $contact->id)->first();
            if (! $enrollment) throw $error;
        }

        if ($created) {
            $eventType = $suppressed ? 'enrollment_suppressed' : 'contact_enrolled';
            app(CampaignEventRecorder::class)->record($tenantId, $record->id, $eventType, 'enrollment:'.$enrollment->id.':'.$eventType, $enrollment->id,
                null, ['contact_id' => $contact->id, 'outcome' => $suppressed ? 'suppressed' : 'pending']);
            app(\App\Orchestration\WorkflowService::class)->ensureEnrollmentWorkflow($tenantId, $contact->company_id, $record->id, $enrollment->id);
            if ($suppressed) app(\App\Orchestration\WorkflowService::class)->stopEnrollment($tenantId, $enrollment->id, 'manual_suppression');
            $this->audit($request, 'contact_manually_enrolled', 'campaign_recipient', $enrollment->id);
        }

        return response()->json($enrollment, $created ? 201 : 200);
    }

    public function stopEnrollment(Request $request, string $campaign, string $enrollment)
    {
        $this->authorizeManager($request);
        $record = Campaign::where('tenant_id', app('tenant.id'))->findOrFail($campaign);
        $stopped = DB::transaction(function () use ($record, $enrollment): CampaignEnrollment {
            $row = CampaignEnrollment::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)->lockForUpdate()->findOrFail($enrollment);
            abort_unless(in_array($row->status->value, ['pending', 'active'], true), 409, 'Only pending or active enrollments can be stopped.');
            $row->update(['status' => 'stopped', 'stop_reason' => 'manual_stop', 'stopped_at' => now(), 'next_step_at' => null]);
            app(\App\Campaigns\CampaignMessageStopper::class)->stopEnrollment(app('tenant.id'), $row->id,
                'cancelled', 'Enrollment was manually stopped.');
            app(CampaignEventRecorder::class)->record(app('tenant.id'), $record->id, 'enrollment_stopped', 'enrollment:'.$row->id.':manual_stop', $row->id,
                null, ['reason' => 'manual_stop']);
            app(\App\Orchestration\WorkflowService::class)->stopEnrollment(app('tenant.id'), $row->id, 'enrollment_stopped');

            return $row->fresh();
        });
        $this->audit($request, 'campaign_enrollment_stopped', 'campaign_recipient', $stopped->id);

        return $stopped;
    }

    public function enrollments(string $campaign)
    {
        $record = Campaign::where('tenant_id', app('tenant.id'))->findOrFail($campaign);

        return CampaignEnrollment::where('tenant_id', app('tenant.id'))->where('campaign_id', $record->id)
            ->with(['company:id,name,normalized_domain', 'contact:id,name,title,source_url'])
            ->orderByDesc('enrolled_at')->paginate(25);
    }

    public function activate(Request $request, string $campaign, CampaignLifecycle $lifecycle)
    {
        $this->authorizeManager($request);
        $current = Campaign::where('tenant_id', app('tenant.id'))->findOrFail($campaign);
        if ($current->status !== CampaignStatus::Draft) {
            return $this->activationFailure(new CampaignActivationException('Only draft campaigns can be started.'));
        }
        try {
            $result = $lifecycle->activate(app('tenant.id'), $campaign);
            app(CampaignEventRecorder::class)->record(app('tenant.id'), $campaign, 'campaign_started', 'campaign:'.$campaign.':started:'.($result->started_at?->timestamp ?? 'unknown'));
            app(\App\Orchestration\WorkflowService::class)->recordCampaignEvent(app('tenant.id'), $campaign, 'campaign_started',
                'workflow:campaign-started:'.$campaign.':'.($result->started_at?->timestamp ?? 'unknown'));
            $this->audit($request, 'campaign_started', 'campaign', $campaign);
            return response()->json($result);
        }
        catch (CampaignActivationException $exception) { return $this->activationFailure($exception); }
    }

    public function pause(Request $request, string $campaign, CampaignLifecycle $lifecycle)
    {
        $this->authorizeManager($request);
        try {
            $result = $lifecycle->pause(app('tenant.id'), $campaign);
            app(CampaignEventRecorder::class)->record(app('tenant.id'), $campaign, 'campaign_paused', 'campaign:'.$campaign.':paused:'.$result->paused_at?->timestamp);
            $this->audit($request, 'campaign_paused', 'campaign', $campaign);
            return response()->json($result);
        } catch (CampaignActivationException $exception) { return $this->activationFailure($exception); }
    }

    public function resume(Request $request, string $campaign, CampaignLifecycle $lifecycle)
    {
        $this->authorizeManager($request);
        $current = Campaign::where('tenant_id', app('tenant.id'))->findOrFail($campaign);
        if ($current->status !== CampaignStatus::Paused) {
            return $this->activationFailure(new CampaignActivationException('Only paused campaigns can be resumed.'));
        }
        try {
            $result = $lifecycle->activate(app('tenant.id'), $campaign);
            app(CampaignEventRecorder::class)->record(app('tenant.id'), $campaign, 'campaign_resumed', 'campaign:'.$campaign.':resumed:'.($result->updated_at?->timestamp ?? 'unknown'));
            $this->audit($request, 'campaign_resumed', 'campaign', $campaign);
            return response()->json($result);
        }
        catch (CampaignActivationException $exception) { return $this->activationFailure($exception); }
    }

    public function complete(Request $request, string $campaign, CampaignLifecycle $lifecycle)
    {
        $this->authorizeManager($request);
        try {
            $result = $lifecycle->complete(app('tenant.id'), $campaign);
            app(CampaignEventRecorder::class)->record(app('tenant.id'), $campaign, 'campaign_completed', 'campaign:'.$campaign.':completed');
            $this->audit($request, 'campaign_completed', 'campaign', $campaign);
            return response()->json($result);
        } catch (CampaignActivationException $exception) { return $this->activationFailure($exception); }
    }

    public function cancel(Request $request, string $campaign, CampaignLifecycle $lifecycle)
    {
        $this->authorizeManager($request);
        try {
            $result = $lifecycle->cancel(app('tenant.id'), $campaign);
            app(CampaignEventRecorder::class)->record(app('tenant.id'), $campaign, 'campaign_cancelled', 'campaign:'.$campaign.':cancelled');
            app(\App\Orchestration\WorkflowService::class)->recordCampaignCancellation(app('tenant.id'), $campaign);
            $this->audit($request, 'campaign_cancelled', 'campaign', $campaign);
            return response()->json($result);
        } catch (CampaignActivationException $exception) { return $this->activationFailure($exception); }
    }

    public function messagingConfiguration(Request $request)
    {
        $this->authorizeManager($request);
        $record = DB::table('tenant_messaging_configurations')->where('tenant_id', app('tenant.id'))
            ->first(['provider', 'enabled', 'from_name', 'from_email', 'reply_to_email', 'hourly_limit', 'daily_limit', 'version', 'updated_at']);

        return response()->json($record ?? [
            'provider' => config('outbound.default_provider'), 'enabled' => false, 'from_name' => null,
            'from_email' => null, 'reply_to_email' => null, 'hourly_limit' => 60, 'daily_limit' => 500, 'version' => 0,
        ]);
    }

    public function updateMessagingConfiguration(Request $request)
    {
        $this->authorizeManager($request);
        $providers = config('outbound.enabled_providers', []);
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in($providers)], 'enabled' => ['required', 'boolean'],
            'from_name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'from_email' => ['required_if:enabled,true', 'nullable', 'email', 'max:255', 'not_regex:/[\r\n]/'],
            'reply_to_email' => ['nullable', 'email', 'max:255', 'not_regex:/[\r\n]/'],
            'hourly_limit' => ['required', 'integer', 'between:1,1000'], 'daily_limit' => ['required', 'integer', 'between:1,10000'],
        ]);
        $tenantId = app('tenant.id');
        $pausedCampaignIds = [];
        DB::transaction(function () use ($tenantId, $data, &$pausedCampaignIds): void {
            $existing = DB::table('tenant_messaging_configurations')->where('tenant_id', $tenantId)->lockForUpdate()->first(['id', 'version', 'secret_reference']);
            $values = [
                'provider' => $data['provider'], 'enabled' => $data['enabled'], 'from_name' => $data['from_name'] ?? null,
                'from_email' => $data['from_email'] ?? null, 'reply_to_email' => $data['reply_to_email'] ?? null,
                'hourly_limit' => $data['hourly_limit'], 'daily_limit' => $data['daily_limit'],
                'secret_reference' => $existing?->secret_reference, 'version' => ($existing?->version ?? 0) + 1, 'updated_at' => now(),
            ];
            if ($existing) DB::table('tenant_messaging_configurations')->where('id', $existing->id)->update($values);
            else DB::table('tenant_messaging_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, ...$values, 'created_at' => now()]);

            if (! $data['enabled']) {
                $pauseAt = now();
                $activeCampaigns = Campaign::where('tenant_id', $tenantId)->where('status', CampaignStatus::Active)->lockForUpdate()->get();
                foreach ($activeCampaigns as $campaign) {
                    $campaign->update(['status' => CampaignStatus::Paused, 'paused_at' => $pauseAt]);
                    $pausedCampaignIds[] = $campaign->id;
                }
            }
        });
        foreach ($pausedCampaignIds as $campaignId) {
            app(CampaignEventRecorder::class)->record($tenantId, $campaignId, 'campaign_paused',
                'campaign:'.$campaignId.':messaging-disabled:'.now()->timestamp, null, null, ['reason' => 'messaging_disabled']);
            $this->audit($request, 'campaign_paused', 'campaign', $campaignId);
        }

        return $this->messagingConfiguration($request);
    }

    private function draftCampaign(string $id): Campaign
    {
        return Campaign::where('tenant_id', app('tenant.id'))->where('status', CampaignStatus::Draft)->findOrFail($id);
    }

    private function authorizeManager(Request $request): void
    {
        $role = $request->user()->tenants()->whereKey(app('tenant.id'))->wherePivot('status', 'active')->value('tenant_user.role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Campaign administration requires an owner or admin role.');
    }

    private function activationFailure(CampaignActivationException $exception)
    {
        return response()->json(['error_code' => 'CAMPAIGN_NOT_READY', 'safe_message' => $exception->getMessage(),
            'message' => $exception->getMessage(), 'correlation_id' => (string) Str::uuid()], 422);
    }

    private function audit(Request $request, string $action, string $subjectType, string $subjectId): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => app('tenant.id'), 'actor_user_id' => $request->user()->id,
            'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'request_id' => substr((string) $request->header('X-Request-ID', ''), 0, 255) ?: null,
            'metadata' => json_encode(['campaign_id' => $subjectType === 'campaign' ? $subjectId : null]),
            'created_at' => now(),
        ]);
    }
}
