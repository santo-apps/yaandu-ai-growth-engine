<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProspectImportRowJob;
use App\Pilot\ProspectCsvImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PilotController extends Controller
{
    public function cohorts()
    {
        $tenant = app('tenant.id');
        return DB::table('pilot_cohorts')->where('tenant_id', $tenant)->orderByDesc('created_at')->paginate(25);
    }

    public function createCohort(Request $request)
    {
        $role = DB::table('tenant_user')->where('tenant_id', app('tenant.id'))->where('user_id', $request->user()->id)->where('status', 'active')->value('role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only tenant managers can create a pilot cohort.');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'starts_on' => ['nullable', 'date'], 'owner_user_id' => ['nullable', 'integer', 'exists:users,id'], 'status' => ['sometimes', 'in:planned,active,paused,completed']]);
        if (! empty($data['owner_user_id'])) abort_unless(DB::table('tenant_user')->where('tenant_id', app('tenant.id'))->where('user_id', $data['owner_user_id'])->where('status', 'active')->exists(), 422, 'The cohort owner must be an active member of this tenant.');
        $id = (string) Str::uuid();
        DB::table('pilot_cohorts')->insert(['id' => $id, 'tenant_id' => app('tenant.id'), 'name' => $data['name'], 'starts_on' => $data['starts_on'] ?? null,
            'owner_user_id' => $data['owner_user_id'] ?? $request->user()->id, 'status' => $data['status'] ?? 'planned', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('pilot_cohorts')->where('tenant_id', app('tenant.id'))->where('id', $id)->first(), 201);
    }

    public function previewImport(Request $request, ProspectCsvImportService $imports)
    {
        $data = $request->validate(['csv' => ['required', 'file', 'max:2048'], 'cohort_id' => ['nullable', 'uuid']]);
        $tenant = app('tenant.id');
        if (! empty($data['cohort_id'])) abort_unless(DB::table('pilot_cohorts')->where('tenant_id', $tenant)->where('id', $data['cohort_id'])->exists(), 404);
        try { return response()->json($imports->preview($tenant, (int) $request->user()->id, $data['csv'], $data['cohort_id'] ?? null), 201); }
        catch (InvalidArgumentException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function batches()
    {
        return DB::table('prospect_import_batches')->where('tenant_id', app('tenant.id'))->orderByDesc('created_at')->paginate(25);
    }

    public function batch(string $batch)
    {
        $tenant = app('tenant.id');
        $record = DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('id', $batch)->first();
        abort_unless($record, 404);
        $record->counts = is_array($record->counts) ? $record->counts : (json_decode((string) $record->counts, true) ?: []);
        $rows = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->where('batch_id', $batch)->orderBy('row_number')->get([
            'id', 'row_number', 'original_name', 'original_website', 'normalized_domain', 'validation_status', 'deduplication_status', 'status', 'errors', 'company_id', 'processed_at',
        ]);
        $rows->transform(function ($row): object { $row->errors = is_array($row->errors) ? $row->errors : (json_decode((string) $row->errors, true) ?: []); return $row; });
        return response()->json(['batch' => $record, 'rows' => $rows]);
    }

    public function confirmImport(Request $request, string $batch)
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:128']]);
        $tenant = app('tenant.id');
        $queued = [];
        DB::transaction(function () use ($tenant, $batch, $data, $request, &$queued): void {
            $record = DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('id', $batch)->lockForUpdate()->first();
            abort_unless($record, 404);
            if ($record->status === 'completed' || $record->status === 'partially_completed' || $record->status === 'importing') return;
            $existing = DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('idempotency_key', $data['idempotency_key'])->where('id', '!=', $batch)->exists();
            abort_if($existing, 409, 'This import idempotency key has already been used.');
            $rows = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->where('batch_id', $batch)->where('status', 'ready')->get(['id']);
            if ($rows->isEmpty()) abort(422, 'There are no valid, non-duplicate rows to import.');
            DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('id', $batch)->update([
                'status' => 'importing', 'idempotency_key' => $data['idempotency_key'], 'confirmed_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($rows as $row) $queued[] = $row->id;
            DB::afterCommit(function () use ($queued, $tenant, $request): void {
                foreach ($queued as $rowId) ProcessProspectImportRowJob::dispatch($tenant, $rowId, (string) $request->user()->id);
            });
        });
        return response()->json(['batch_id' => $batch, 'status' => DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('id', $batch)->value('status'),
            'queued_rows' => count($queued), 'message' => 'Valid, non-duplicate rows are queued. Website identity remains unverified and no outreach was sent.'], 202);
    }

    public function retryImport(string $batch)
    {
        $tenant = app('tenant.id');
        $record = DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('id', $batch)->first();
        abort_unless($record, 404);
        $rows = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->where('batch_id', $batch)->where('status', 'failed')->get(['id']);
        if ($rows->isEmpty()) return response()->json(['batch_id' => $batch, 'queued_rows' => 0, 'status' => $record->status]);
        DB::table('prospect_import_rows')->where('tenant_id', $tenant)->where('batch_id', $batch)->where('status', 'failed')->update(['status' => 'ready', 'errors' => null, 'updated_at' => now()]);
        DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('id', $batch)->update(['status' => 'importing', 'updated_at' => now()]);
        foreach ($rows as $row) ProcessProspectImportRowJob::dispatch($tenant, $row->id, (string) request()->user()->id);
        return response()->json(['batch_id' => $batch, 'queued_rows' => $rows->count(), 'status' => 'importing'], 202);
    }

    public function errorReport(string $batch)
    {
        $tenant = app('tenant.id');
        abort_unless(DB::table('prospect_import_batches')->where('tenant_id', $tenant)->where('id', $batch)->exists(), 404);
        $rows = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->where('batch_id', $batch)->whereIn('status', ['invalid', 'duplicate', 'failed'])->orderBy('row_number')->get();
        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb'); fputcsv($stream, ['row_number', 'business_name', 'website', 'status', 'errors']);
            foreach ($rows as $row) fputcsv($stream, [$row->row_number, $this->safeCsvCell($row->original_name), $this->safeCsvCell($row->original_website), $row->status, $this->safeCsvCell(implode('; ', json_decode((string) $row->errors, true) ?: []))]);
            fclose($stream);
        }, 'prospect-import-errors-'.$batch.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function dashboard(Request $request)
    {
        $tenant = app('tenant.id');
        $cohortId = $request->query('cohort_id');
        if ($cohortId) abort_unless(DB::table('pilot_cohorts')->where('tenant_id', $tenant)->where('id', $cohortId)->exists(), 404);
        $batchIds = DB::table('prospect_import_batches')->where('tenant_id', $tenant)->when($cohortId, fn ($q) => $q->where('pilot_cohort_id', $cohortId))->pluck('id');
        $companyIds = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->whereIn('batch_id', $batchIds)->whereNotNull('company_id')->pluck('company_id');
        $count = fn (string $table) => DB::table($table)->where('tenant_id', $tenant);
        $scans = $count('website_scans')->whereIn('company_website_id', DB::table('company_websites')->where('tenant_id', $tenant)->whereIn('company_id', $companyIds)->select('id'));
        $drafts = $count('marketing_drafts')->whereIn('company_id', $companyIds);
        $opportunities = $count('sales_opportunities')->whereIn('company_id', $companyIds);
        $meetings = $count('meeting_bookings')->whereIn('sales_opportunity_id', (clone $opportunities)->select('id'));
        $proposals = $count('proposals')->whereIn('sales_opportunity_id', (clone $opportunities)->select('id'));
        $messages = DB::table('outbound_messages as messages')->join('campaign_recipients as recipients', function ($join) use ($tenant): void {
            $join->on('recipients.id', '=', 'messages.campaign_recipient_id')->on('recipients.tenant_id', '=', 'messages.tenant_id')->where('recipients.tenant_id', '=', $tenant);
        })->where('messages.tenant_id', $tenant)->whereIn('recipients.company_id', $companyIds);
        $replies = $count('conversation_messages')->where('direction', 'inbound')->whereIn('conversation_id', DB::table('conversations')->where('tenant_id', $tenant)->whereIn('company_id', $companyIds)->select('id'));
        $imported = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->whereIn('batch_id', $batchIds)->where('status', 'completed')->count();
        $valid = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->whereIn('batch_id', $batchIds)->where('validation_status', 'valid')->count();
        $eligibleRows = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->whereIn('batch_id', $batchIds)->where('validation_status', 'valid')->where('deduplication_status', 'new');
        $attemptedRows = (clone $eligibleRows)->whereIn('status', ['completed', 'failed'])->count();
        $failedRows = (clone $eligibleRows)->where('status', 'failed')->count();
        $processingDurations = DB::table('prospect_import_rows')->where('tenant_id', $tenant)->whereIn('batch_id', $batchIds)
            ->whereIn('status', ['completed', 'failed'])->get(['created_at', 'updated_at', 'processed_at'])
            ->map(fn ($row) => max(0, \Illuminate\Support\Carbon::parse($row->created_at)->diffInSeconds(\Illuminate\Support\Carbon::parse($row->processed_at ?: $row->updated_at), false)));
        $scored = $count('lead_scores')->whereIn('company_id', $companyIds)->distinct('company_id')->count('company_id');
        $approvedDrafts = (clone $drafts)->where('status', 'approved')->count();
        $metrics = [
            'prospects_imported' => $imported, 'valid_prospects' => $valid, 'duplicates' => DB::table('prospect_import_rows')->where('tenant_id', $tenant)->whereIn('batch_id', $batchIds)->where('status', 'duplicate')->count(),
            'websites_analyzed' => (clone $scans)->whereIn('status', ['completed', 'partial'])->count(), 'analysis_failures' => (clone $scans)->where('status', 'failed')->count(),
            'leads_scored' => $scored, 'high_priority' => $count('lead_scores')->whereIn('company_id', $companyIds)->where('score', '>=', 70)->distinct('company_id')->count('company_id'),
            'marketing_drafts' => (clone $drafts)->count(), 'drafts_awaiting_approval' => (clone $drafts)->where('status', 'pending_review')->count(),
            'approved_marketing_drafts' => $approvedDrafts, 'sandbox_messages_sent' => (clone $messages)->where('messages.status', 'sent')->where('messages.provider', 'fake')->count(), 'replies' => (clone $replies)->count(),
            'qualified_leads' => (clone $opportunities)->whereNotNull('qualified_at')->distinct('company_id')->count('company_id'), 'opportunities' => (clone $opportunities)->count(),
            'meetings' => (clone $meetings)->count(), 'proposals' => (clone $proposals)->count(),
            'average_processing_time_seconds' => $processingDurations->isEmpty() ? null : round($processingDurations->avg(), 1),
        ];
        $sentCompanies = (clone $messages)->where('messages.status', 'sent')->distinct('recipients.company_id')->count('recipients.company_id');
        $repliedCompanies = DB::table('conversations')->where('tenant_id', $tenant)->whereIn('company_id', $companyIds)->whereIn('id', DB::table('conversation_messages')->where('tenant_id', $tenant)->where('direction', 'inbound')->select('conversation_id'))->distinct('company_id')->count('company_id');
        $qualifiedCompanies = (clone $opportunities)->whereNotNull('qualified_at')->distinct('company_id')->count('company_id');
        $opportunityCompanies = (clone $opportunities)->distinct('company_id')->count('company_id');
        $meetingOpportunities = (clone $meetings)->distinct('sales_opportunity_id')->count('sales_opportunity_id');
        $proposalOpportunities = (clone $proposals)->distinct('sales_opportunity_id')->count('sales_opportunity_id');
        $funnel = ['imported' => $imported, 'analyzed' => (clone $scans)->whereIn('status', ['completed', 'partial'])->distinct('company_website_id')->count('company_website_id'),
            'scored' => $scored, 'drafted' => (clone $drafts)->distinct('company_id')->count('company_id'), 'approved' => (clone $drafts)->where('status', 'approved')->distinct('company_id')->count('company_id'),
            'sent' => $sentCompanies, 'replied' => $repliedCompanies,
            'qualified' => $qualifiedCompanies, 'opportunity' => $opportunityCompanies, 'meeting' => $meetingOpportunities, 'proposal' => $proposalOpportunities];
        $rates = ['import_success_rate' => $this->rate($imported, $valid),
            'duplicate_rate' => $this->rate($metrics['duplicates'], DB::table('prospect_import_rows')->where('tenant_id', $tenant)->whereIn('batch_id', $batchIds)->count()),
            'website_analysis_completion_rate' => $this->rate($metrics['websites_analyzed'], (clone $scans)->count()), 'lead_scoring_coverage' => $this->rate($scored, $imported),
            'draft_approval_rate' => $this->rate($approvedDrafts, (clone $drafts)->count()), 'sandbox_delivery_acceptance_rate' => $this->rate($metrics['sandbox_messages_sent'], (clone $messages)->count()),
            'reply_rate' => $this->rate($funnel['replied'], $sentCompanies), 'qualification_rate' => $this->rate($qualifiedCompanies, $repliedCompanies),
            'opportunity_conversion' => $this->rate($opportunityCompanies, $repliedCompanies), 'meeting_booking_rate' => $this->rate($meetingOpportunities, $opportunityCompanies),
            'proposal_generation_rate' => $this->rate($proposalOpportunities, $opportunityCompanies), 'failed_job_rate' => $this->rate($failedRows, $attemptedRows)];
        $nonFakeOutbound = (clone $messages)->where('messages.provider', '!=', 'fake')->count();
        return response()->json(['cohort_id' => $cohortId, 'metrics' => $metrics, 'funnel' => $funnel, 'rates_percent' => $rates, 'simulated' => $nonFakeOutbound === 0,
            'outbound_provider_counts' => (clone $messages)->select('messages.provider', DB::raw('count(*) as count'))->groupBy('messages.provider')->pluck('count', 'messages.provider'),
            'definitions' => ['rate' => 'Numerator divided by the explicitly named denominator, rounded to one decimal; zero denominator returns null.',
                'import_success_rate' => 'Imported prospect rows divided by validated, non-duplicate new rows; invalid and duplicate rows are reported separately.',
                'failed_job_rate' => 'Failed valid, new import rows divided by valid, new rows that reached a completed or failed terminal state.',
                'average_processing_time_seconds' => 'Mean created-to-terminal timestamp interval across completed or failed import rows; it is a local pilot measurement, not a production SLA.',
                'sandbox_delivery_acceptance_rate' => 'Fake sandbox messages marked sent / all outbound message records for cohort prospects.',
                'reply_rate' => 'Distinct cohort prospects with an inbound message / distinct cohort prospects with a sent outbound message.',
                'qualification_rate' => 'Distinct qualified opportunity companies / distinct replied companies.',
                'opportunity_conversion' => 'Distinct cohort companies with an opportunity / distinct cohort companies with an inbound reply.',
                'meeting_booking_rate' => 'Distinct opportunities with a booked meeting / distinct opportunities.',
                'proposal_generation_rate' => 'Distinct opportunities with a proposal / distinct opportunities.']]);
    }

    public function operations(Request $request)
    {
        $tenant = app('tenant.id');
        $role = DB::table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $request->user()->id)
            ->where('status', 'active')->value('role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Pilot operations requires an active tenant manager.');

        $queues = ['default', 'discovery', 'intake', 'crawl', 'intelligence', 'scoring', 'campaigns', 'outbound', 'conversations', 'workflow', 'candidate-discovery', 'web-index'];
        $redis = 'unavailable';
        $queueBacklog = [];
        $horizon = ['status' => 'unavailable', 'supervisors' => []];
        try {
            $connection = Queue::connection('redis');
            foreach ($queues as $queue) $queueBacklog[$queue] = $connection->size($queue);
            $supervisors = app(\Laravel\Horizon\Contracts\SupervisorRepository::class)->all();
            $masters = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
            $redis = 'available';
            $horizon = [
                'status' => count($masters) > 0 && collect($masters)->contains(fn ($master) => $master->status === 'running') ? 'running' : 'inactive',
                'supervisors' => collect($supervisors)->map(function ($supervisor): array {
                    return ['status' => $supervisor->status, 'queues' => collect($supervisor->processes ?? [])->map(fn ($workers, $queue) => [
                        'queue' => preg_replace('/^redis:/', '', (string) $queue), 'workers' => (int) $workers,
                    ])->values()->all()];
                })->values()->all(),
            ];
        } catch (\Throwable) {
            // Infrastructure errors are reported as unavailable; exception text and connection details stay private.
        }

        $events = DB::table('workflow_events')->where('tenant_id', $tenant)->orderByDesc('created_at')->limit(15)
            ->get(['event', 'stage', 'created_at'])->map(fn ($event): array => [
                'event' => preg_replace('/[^a-z0-9_.-]/i', '', (string) $event->event),
                'stage' => preg_replace('/[^a-z0-9_.-]/i', '', (string) $event->stage),
                'occurred_at' => $event->created_at,
            ])->values();

        return response()->json([
            'infrastructure_scope' => 'installation',
            'redis' => $redis,
            'horizon' => $horizon,
            'queue_backlog' => $queueBacklog,
            'failed_queue_jobs' => DB::table('failed_jobs')->count(),
            'tenant_metrics' => [
                'failed_import_rows' => DB::table('prospect_import_rows')->where('tenant_id', $tenant)->where('status', 'failed')->count(),
                'website_crawl_failures' => DB::table('website_scans')->where('tenant_id', $tenant)->where('status', 'failed')->count(),
                'ai_provider_failures' => DB::table('agent_runs')->where('tenant_id', $tenant)->where('error_code', 'AI_PROVIDER_UNAVAILABLE')->count(),
                'budget_exhaustion_events' => DB::table('workflow_events')->where('tenant_id', $tenant)->where('event', 'ai_budget_exceeded')->count(),
                'workflow_errors' => DB::table('acquisition_workflows')->where('tenant_id', $tenant)->whereIn('status', ['FAILED', 'PAUSED'])->count(),
                'retryable_workflow_errors' => DB::table('workflow_events')->where('tenant_id', $tenant)->where('event', 'agent_run_retryable_failure')->count(),
            ],
            'recent_events' => $events,
            'safety' => ['provider_payloads_exposed' => false, 'credentials_exposed' => false, 'outbound_control' => 'existing approval workflow only'],
        ]);
    }

    /** Tenant-scoped readiness summary; this never returns credentials or provider payloads. */
    public function readiness(Request $request)
    {
        $tenant = (string) app('tenant.id');
        $role = DB::table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $request->user()->id)
            ->where('status', 'active')->value('role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Workspace readiness requires an active tenant manager.');

        $requiredPrompts = ['MarketingAgent', 'FollowUpAgent', 'SalesAgent', 'ProposalAgent'];
        $prompts = DB::table('prompt_templates')->where('tenant_id', $tenant)->whereIn('agent_key', $requiredPrompts)
            ->where('status', 'approved')->where('active', true)->pluck('agent_key')->all();
        $missing = [];
        foreach ($requiredPrompts as $agent) if (! in_array($agent, $prompts, true)) $missing[] = 'Approved active '.$agent.' prompt';

        $registeredProviders = collect(app()->tagged('ai.providers'))->map(fn ($provider) => $provider->providerKey())->all();
        $requiredTasks = ['website_reasoning', 'content_generation', 'sales_reasoning', 'proposal_generation'];
        $aiRoutes = [];
        foreach ($requiredTasks as $task) {
            $route = DB::table('ai_model_configurations')->where('tenant_id', $tenant)->where('task_key', $task)->first();
            $default = config('ai.tasks.'.$task, []);
            $provider = $route->provider ?? ($default['provider'] ?? null);
            $enabled = (bool) ($route->enabled ?? true);
            $available = $provider && in_array($provider, $registeredProviders, true);
            $credentialPresent = $provider === 'deterministic' || filled(config('ai.providers.'.$provider.'.key'));
            $ready = $enabled && $available && $credentialPresent;
            $aiRoutes[$task] = ['ready' => $ready, 'provider' => $provider, 'enabled' => $enabled];
            if (! $ready) $missing[] = 'Enabled, available AI route for '.$task;
        }

        $fakeOutbound = false;
        try { $fakeOutbound = app(\App\Messaging\OutboundMessagingProviderRouter::class)->forTenant($tenant)->providerKey() === 'fake'; }
        catch (\Throwable) {}
        if (! $fakeOutbound) $missing[] = 'Enabled fake outbound provider';
        $fakeScheduling = false;
        try { $fakeScheduling = app(\App\Scheduling\SchedulingProviderRouter::class)->forTenant($tenant)->providerKey() === 'fake'; }
        catch (\Throwable) {}
        if (! $fakeScheduling) $missing[] = 'Enabled fake scheduling provider';

        $queue = ['redis' => false, 'horizon' => 'unavailable'];
        try {
            Queue::connection('redis')->size('default');
            $queue['redis'] = true;
            $masters = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
            $queue['horizon'] = collect($masters)->contains(fn ($master) => $master->status === 'running') ? 'running' : 'inactive';
        } catch (\Throwable) {}
        if (! $queue['redis']) $missing[] = 'Redis queue connection';
        if ($queue['horizon'] !== 'running') $missing[] = 'Running Horizon worker';

        return response()->json([
            'status' => $missing === [] ? 'ready' : 'incomplete',
            'ai_routes' => $aiRoutes,
            'approved_prompts' => collect($requiredPrompts)->mapWithKeys(fn ($agent) => [$agent => in_array($agent, $prompts, true)]),
            'providers' => ['outbound' => $fakeOutbound ? 'fake_available' : 'missing', 'scheduling' => $fakeScheduling ? 'fake_available' : 'missing'],
            'queue' => $queue,
            'missing_prerequisites' => array_values(array_unique($missing)),
            'configuration_links' => ['ai' => 'AI Configuration', 'prompts' => 'Approved knowledge and prompts', 'messaging' => 'Outreach', 'scheduling' => 'Setup & readiness'],
            'secrets_exposed' => false,
        ]);
    }

    private function rate(int $numerator, int $denominator): ?float { return $denominator === 0 ? null : round($numerator * 100 / $denominator, 1); }

    private function safeCsvCell(?string $value): string
    {
        $value = $value ?? '';
        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
    }
}
