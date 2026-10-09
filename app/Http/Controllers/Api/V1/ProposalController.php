<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\SalesOpportunity;
use App\Models\TenantService;
use App\Proposals\DecimalMoney;
use App\Proposals\GenerateProposalCommand;
use App\Proposals\ProposalGenerationService;
use App\Proposals\ProposalDocumentRendererInterface;
use App\Proposals\ProposalStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ProposalController extends Controller
{
    public function index(Request $request)
    {
        $query = Proposal::where('tenant_id', app('tenant.id'))->with(['opportunity.company:id,name'])->withCount('items');
        if (! in_array($this->role($request), ['owner','admin'], true)) $query->whereHas('opportunity', fn ($op) => $op->where('owner_user_id', $request->user()->id));
        return $query->orderByDesc('updated_at')->paginate(30);
    }

    public function show(string $id)
    {
        $tenant = app('tenant.id');
        $proposal = Proposal::where('tenant_id', $tenant)->with(['opportunity.company:id,name', 'opportunity.contact:id,name,title', 'items'])->findOrFail($id);
        $this->authorizeOpportunity(request(), $proposal->sales_opportunity_id);
        foreach ($proposal->items as $item) {
            $item->setAttribute('net_total', DecimalMoney::decimal(DecimalMoney::cents((string) $item->line_total) - DecimalMoney::cents((string) $item->discount_amount) + DecimalMoney::cents((string) $item->tax_amount)));
        }
        $proposal->setRelation('versions', ProposalVersion::where('tenant_id', $tenant)->where('proposal_id', $id)->orderByDesc('version')->get());
        $proposal->setRelation('requirements', DB::table('proposal_requirements')->where('tenant_id', $tenant)->where('proposal_id', $id)->latest()->first());
        $proposal->setAttribute('internal_notes', DB::table('proposal_internal_notes')->where('tenant_id', $tenant)->where('proposal_id', $id)->orderBy('created_at')->get()->map(fn ($note) => [
            'id' => $note->id, 'note' => Crypt::decryptString($note->note_ciphertext), 'created_by' => $note->created_by, 'created_at' => $note->created_at,
        ]));
        return $proposal;
    }

    public function versions(Request $request, string $id)
    {
        $proposal = $this->proposal($id); $this->authorizeOpportunity($request, $proposal->sales_opportunity_id);
        return ProposalVersion::where('tenant_id', app('tenant.id'))->where('proposal_id', $id)->orderByDesc('version')->get();
    }
    public function addInternalNote(Request $request, string $proposal)
    {
        $record = $this->proposal($proposal); $this->authorizeOpportunity($request, $record->sales_opportunity_id);
        $data = $request->validate(['note' => ['required','string','min:1','max:4000']]); $id = (string) Str::uuid();
        DB::table('proposal_internal_notes')->insert(['id' => $id, 'tenant_id' => app('tenant.id'), 'proposal_id' => $record->id,
            'note_ciphertext' => Crypt::encryptString(trim($data['note'])), 'created_by' => $request->user()->id, 'created_at' => now()]);
        $this->audit('proposal_internal_note_added', $record->id, ['note_id' => $id]);
        return response()->json(['id' => $id, 'created_at' => now()], 201);
    }
    public function services() { return $this->activeServices(app('tenant.id'))->orderBy('name')->get(); }

    public function storeService(Request $request)
    {
        $this->authorizeManager($request);
        $data = $request->validate(['sku' => ['required','string','max:80'], 'name' => ['required','string','max:255'], 'description' => ['nullable','string','max:4000'],
            'unit_price' => [Rule::requiredIf(($request->input('commercial_model') ?? 'FIXED_PRICE') !== 'custom_quote'),'nullable','regex:/^\d{1,12}(\.\d{1,2})?$/'], 'currency' => ['required','string','size:3','alpha'], 'active' => ['sometimes','boolean'],
            'category' => ['nullable','string','max:100'], 'capabilities' => ['sometimes','array','max:20'], 'capabilities.*' => ['string','max:500'],
            'standard_deliverables' => ['sometimes','array','max:30'], 'standard_deliverables.*' => ['string','max:500'], 'optional_deliverables' => ['sometimes','array','max:30'], 'optional_deliverables.*' => ['string','max:500'],
            'commercial_model' => ['sometimes',Rule::in(['FIXED_PRICE','TIME_AND_MATERIAL','MONTHLY_RETAINER','MILESTONE_BASED','CUSTOM','custom_quote'])], 'unit' => ['nullable','sometimes','string','max:60'],
            'effective_from' => ['nullable','date'], 'effective_until' => ['nullable','date','after_or_equal:effective_from']]);
        $tenant = app('tenant.id'); $policy = $this->pricingPolicyObject($tenant); abort_unless(strtoupper($data['currency']) === $policy->currency, 422, 'Service currency must match the approved tenant pricing currency.');
        if (($data['commercial_model'] ?? 'FIXED_PRICE') === 'custom_quote') { $data['unit_price'] = null; $data['unit'] = null; }
        $service = DB::transaction(function () use ($tenant, $data, $request): TenantService {
            $service = TenantService::create(['tenant_id' => $tenant, ...$data, 'currency' => strtoupper($data['currency']), 'active' => $data['active'] ?? true, 'approved_by' => $request->user()->id]);
            $this->audit('commercial_input_created', $service->id, ['type' => 'service_catalogue', 'sku' => $service->sku], 'tenant_service');
            return $service;
        });
        return response()->json($service, 201);
    }

    public function updateService(Request $request, string $service)
    {
        $this->authorizeManager($request);
        $record = TenantService::where('tenant_id', app('tenant.id'))->findOrFail($service);
        $nextModel = $request->input('commercial_model', $record->commercial_model);
        $data = $request->validate(['sku' => ['sometimes','required','string','max:80'], 'name' => ['sometimes','required','string','max:255'], 'description' => ['sometimes','nullable','string','max:4000'],
            'unit_price' => [Rule::requiredIf(($request->exists('unit_price') && $nextModel !== 'custom_quote') || ($request->exists('commercial_model') && $nextModel !== 'custom_quote' && $record->commercial_model === 'custom_quote')),'nullable','regex:/^\d{1,12}(\.\d{1,2})?$/'], 'currency' => ['sometimes','required','string','size:3','alpha'], 'active' => ['sometimes','boolean'],
            'category' => ['sometimes','nullable','string','max:100'], 'capabilities' => ['sometimes','array','max:20'], 'capabilities.*' => ['string','max:500'],
            'standard_deliverables' => ['sometimes','array','max:30'], 'standard_deliverables.*' => ['string','max:500'], 'optional_deliverables' => ['sometimes','array','max:30'], 'optional_deliverables.*' => ['string','max:500'],
            'commercial_model' => ['sometimes',Rule::in(['FIXED_PRICE','TIME_AND_MATERIAL','MONTHLY_RETAINER','MILESTONE_BASED','CUSTOM','custom_quote'])],
            'unit' => [Rule::requiredIf(($request->exists('unit') && $nextModel !== 'custom_quote') || ($request->exists('commercial_model') && $nextModel !== 'custom_quote' && $record->commercial_model === 'custom_quote' && $record->unit === null)), 'nullable','string','max:60'],
            'effective_from' => ['sometimes','nullable','date'], 'effective_until' => ['sometimes','nullable','date','after_or_equal:effective_from']]);
        if ($nextModel === 'custom_quote') { $data['unit_price'] = null; $data['unit'] = null; }
        elseif (array_key_exists('commercial_model', $data) && $nextModel !== 'custom_quote' && ! array_key_exists('unit_price', $data) && $record->unit_price === null) {
            abort(422, 'A numeric catalog price is required when changing from custom quote to a priced rate model.');
        }
        if (isset($data['currency'])) { $data['currency'] = strtoupper($data['currency']); abort_unless($data['currency'] === $this->pricingPolicyObject(app('tenant.id'))->currency, 422, 'Currency must match the approved tenant pricing currency.'); }
        $data['approved_by'] = $request->user()->id;
        DB::transaction(function () use ($record, $data): void {
            $record->update($data);
            $this->audit('commercial_input_updated', $record->id, ['type' => 'service_catalogue'], 'tenant_service');
        });
        return response()->json($record->fresh());
    }

    public function deactivateService(Request $request, string $service) { $this->authorizeManager($request); $record = TenantService::where('tenant_id', app('tenant.id'))->findOrFail($service); $record->update(['active' => false]); return response()->json($record->fresh()); }
    public function pricingPolicy() { return response()->json($this->pricingPolicyObject(app('tenant.id'))); }

    public function updatePricingPolicy(Request $request)
    {
        $this->authorizeManager($request);
        $data = $request->validate(['currency' => ['required','string','size:3','alpha'], 'max_discount_percent' => ['required','numeric','between:0,100'], 'default_validity_days' => ['required','integer','between:1,365']]);
        $tenant = app('tenant.id'); $currency = strtoupper($data['currency']);
        abort_if(TenantService::where('tenant_id', $tenant)->where('active', true)->where('currency', '!=', $currency)->exists(), 422, 'Active catalog service currency must be updated first.');
        $current = DB::table('tenant_pricing_policies')->where('tenant_id', $tenant)->first();
        $values = ['currency' => $currency, 'max_discount_percent' => $data['max_discount_percent'], 'default_validity_days' => $data['default_validity_days'], 'updated_at' => now()];
        DB::transaction(function () use ($current, $tenant, $values): void {
            if ($current) DB::table('tenant_pricing_policies')->where('tenant_id', $tenant)->update($values);
            else DB::table('tenant_pricing_policies')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, ...$values, 'created_at' => now()]);
            $this->audit('commercial_input_updated', $tenant, ['type' => 'pricing_policy'], 'tenant_pricing_policy');
        });
        return $this->pricingPolicy();
    }

    public function create(Request $request, string $opportunity)
    {
        $this->authorizeOpportunityUser($request, $opportunity, true);
        $data = $request->validate(['requirements' => ['required','array'],
            'requirements.requested_services' => ['required','array','min:1','max:20'], 'requirements.requested_services.*' => ['string','max:255'],
            'requirements.business_requirements' => ['required','array','min:1','max:30'], 'requirements.business_requirements.*' => ['string','max:1500'],
            'requirements.business_objectives' => ['sometimes','array','max:20'], 'requirements.business_objectives.*' => ['string','max:1000'],
            'requirements.known_pain_points' => ['sometimes','array','max:20'], 'requirements.known_pain_points.*' => ['string','max:1000'],
            'requirements.technical_requirements' => ['sometimes','array','max:30'], 'requirements.technical_requirements.*' => ['string','max:1000'],
            'requirements.deliverables' => ['sometimes','array','max:30'], 'requirements.deliverables.*' => ['string','max:1000'],
            'requirements.constraints' => ['sometimes','array','max:20'], 'requirements.constraints.*' => ['string','max:1000'],
            'requirements.timeline_type' => ['nullable',Rule::in(['ESTIMATED','TARGET'])], 'requirements.requested_timeline' => ['nullable','string','max:255'],
            'requirements.approved_budget_information' => ['nullable','string','max:2000'], 'requirements.special_notes' => ['nullable','string','max:3000'],
            'requirements.source_references' => ['sometimes','array','max:30'], 'requirements.source_references.*' => ['string','max:36'],
            'qualification_override' => ['sometimes','boolean'], 'override_reason' => ['required_if:qualification_override,true','nullable','string','min:8','max:1000'],
            'title' => ['sometimes','string','max:255'], 'terms' => ['nullable','string','max:12000']]);
        $tenant = app('tenant.id'); $op = SalesOpportunity::where('tenant_id', $tenant)->with('company')->findOrFail($opportunity);
        if (! empty($data['terms'])) $this->authorizeManager($request);
        abort_unless($op->status === 'open', 409, 'Proposal requires an open opportunity.');
        $qualified = strtoupper((string) $op->stage) === 'QUALIFIED' || in_array(strtoupper((string) $op->qualification_level), ['QUALIFIED', 'HIGH_PRIORITY'], true);
        $override = (bool) ($data['qualification_override'] ?? false);
        abort_unless($qualified || $override, 422, 'Opportunity qualification is required unless an authorized human records an override.');
        if ($override) $this->authorizeManager($request);
        if ($op->contact_id) abort_unless(DB::table('contacts')->where('tenant_id', $tenant)->where('company_id', $op->company_id)->where('id', $op->contact_id)->exists(), 422, 'Opportunity contact must belong to its company.');
        if ($op->conversation_id) {
            $conversation = DB::table('conversations')->where('tenant_id', $tenant)->where('id', $op->conversation_id)->first();
            abort_unless($conversation && $conversation->company_id === $op->company_id && (! $op->contact_id || $conversation->contact_id === $op->contact_id), 422, 'Opportunity conversation must belong to its company and contact.');
        }
        $requirements = $data['requirements'];
        foreach ($requirements['source_references'] ?? [] as $ref) {
            $found = DB::table('lead_evidence as e')->join('lead_insights as i', function ($join): void { $join->on('i.id', '=', 'e.lead_insight_id')->on('i.tenant_id', '=', 'e.tenant_id'); })
                ->where('e.tenant_id', $tenant)->where('i.company_id', $op->company_id)->where('e.id', $ref)->exists()
                || DB::table('conversation_messages')->where('tenant_id', $tenant)->where('conversation_id', $op->conversation_id)->where('id', $ref)->exists();
            abort_unless($found, 422, 'Requirement references must point to evidence on this opportunity.');
        }
        $proposal = DB::transaction(function () use ($request, $tenant, $op, $data, $requirements, $override): Proposal {
            $policy = $this->pricingPolicyObject($tenant);
            $proposal = Proposal::create(['tenant_id' => $tenant, 'sales_opportunity_id' => $op->id, 'status' => ProposalStatus::Draft,
                'version' => 0, 'title' => $data['title'] ?? 'Proposal for '.$op->company->name, 'terms' => $data['terms'] ?? null,
                'currency' => null, 'valid_until' => now()->addDays((int) $policy->default_validity_days)->toDateString()]);
            DB::table('proposal_requirements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'proposal_id' => $proposal->id,
                'company_id' => $op->company_id, 'contact_id' => $op->contact_id, 'conversation_id' => $op->conversation_id,
                'requested_services' => json_encode($requirements['requested_services']), 'business_requirements' => json_encode($requirements['business_requirements']),
                'business_objectives' => json_encode($requirements['business_objectives'] ?? []), 'known_pain_points' => json_encode($requirements['known_pain_points'] ?? []),
                'technical_requirements' => json_encode($requirements['technical_requirements'] ?? []), 'deliverables' => json_encode($requirements['deliverables'] ?? []),
                'constraints' => json_encode($requirements['constraints'] ?? []), 'timeline_type' => $requirements['timeline_type'] ?? null,
                'requested_timeline' => $requirements['requested_timeline'] ?? null, 'approved_budget_information' => $requirements['approved_budget_information'] ?? null,
                'special_notes' => $requirements['special_notes'] ?? null, 'source_references' => json_encode($requirements['source_references'] ?? []),
                'qualification_override' => $override, 'override_reason' => $data['override_reason'] ?? null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->activity($op->id, 'proposal_requested', $request->user()->id, ['proposal_id' => $proposal->id, 'qualification_override' => $override]);
            $this->audit('proposal_requested', $proposal->id, ['opportunity_id' => $op->id, 'qualification_override' => $override]);
            return $proposal;
        });
        app(\App\Orchestration\WorkflowService::class)->recordOpportunityEvent($tenant, $op->id, 'proposal_requested',
            'workflow:proposal-requested:'.$proposal->id, ['proposal_id' => $proposal->id]);
        return response()->json($this->show($proposal->id), 201);
    }

    public function updateRequirements(Request $request, string $proposal)
    {
        $record = $this->proposal($proposal); $this->authorizeOpportunity($request, $record->sales_opportunity_id);
        abort_unless(in_array($record->status->value, [ProposalStatus::Draft->value, ProposalStatus::CommercialInputRequired->value, ProposalStatus::ReviewRequired->value], true), 409, 'Requirements are locked after approval.');
        $data = $request->validate(['requested_services' => ['sometimes','required','array','min:1','max:20'], 'requested_services.*' => ['string','max:255'],
            'business_requirements' => ['sometimes','required','array','min:1','max:30'], 'business_requirements.*' => ['string','max:1500'],
            'business_objectives' => ['sometimes','array','max:20'], 'known_pain_points' => ['sometimes','array','max:20'], 'technical_requirements' => ['sometimes','array','max:30'],
            'deliverables' => ['sometimes','array','max:30'], 'constraints' => ['sometimes','array','max:20'], 'requested_timeline' => ['nullable','string','max:255'], 'special_notes' => ['nullable','string','max:3000']]);
        $req = DB::table('proposal_requirements')->where('tenant_id', app('tenant.id'))->where('proposal_id', $proposal)->latest()->first();
        DB::table('proposal_requirements')->where('tenant_id', app('tenant.id'))->where('id', $req->id)->update([...array_map('json_encode', array_intersect_key($data, array_flip(['requested_services','business_requirements','business_objectives','known_pain_points','technical_requirements','deliverables','constraints']))), ...array_intersect_key($data, array_flip(['requested_timeline','special_notes'])), 'updated_at' => now()]);
        return response()->json($this->show($proposal));
    }

    public function generate(Request $request, string $proposal, ProposalGenerationService $generation)
    {
        $record = $this->proposal($proposal); $this->authorizeOpportunity($request, $record->sales_opportunity_id);
        abort_unless(in_array($record->status->value, [ProposalStatus::Draft->value, ProposalStatus::CommercialInputRequired->value, ProposalStatus::ReviewRequired->value, ProposalStatus::Rejected->value], true), 409, 'Proposal cannot be generated in its current state.');
        $tenant = app('tenant.id');
        $requirementsForKey = DB::table('proposal_requirements')->where('tenant_id', $tenant)->where('proposal_id', $proposal)->latest()->first();
        abort_if(! $requirementsForKey, 409, 'Proposal requirements are missing.');
        $key = substr((string) ($request->header('Idempotency-Key') ?: hash('sha256', $proposal.':'.$requirementsForKey->id.':'.$requirementsForKey->updated_at)), 0, 120);
        try {
            $generation->generate(new GenerateProposalCommand($tenant, $proposal, (string) $request->user()->id, $key, $request->header('X-Request-ID')));
            return response()->json($this->show($proposal));
        } catch (Throwable $e) {
            Proposal::where('tenant_id', app('tenant.id'))->where('id', $proposal)->update(['safe_generation_error' => 'Proposal generation failed. Retry after checking AI configuration.']);
            return response()->json(['message' => 'Proposal generation failed safely. Existing commercial information is unchanged.'], 422);
        }
    }

    public function updateDraft(Request $request, string $proposal, string $version)
    {
        $record = $this->proposal($proposal); $this->authorizeOpportunity($request, $record->sales_opportunity_id); $draft = $this->version($proposal, $version);
        abort_unless($record->latest_version_id === $draft->id && in_array($draft->status, ['review_required','commercial_input_required'], true), 409, 'Only the active review version can be edited.');
        $data = $request->validate(['content' => ['required','array'], 'content.executive_summary' => ['required','string','max:5000'], 'content.client_understanding' => ['required','string','max:5000'],
            'content.objectives' => ['present','array','max:20'], 'content.deliverables' => ['present','array','max:30'], 'content.assumptions' => ['present','array','max:20'],
            'content.exclusions' => ['present','array','max:20'], 'content.implementation_approach' => ['present','array','max:20'], 'content.timeline_narrative' => ['required','string','max:2000'],
            'approved_scope' => ['required','array','max:30'], 'approved_scope.*.service_id' => ['required','uuid'], 'approved_scope.*.description' => ['required','string','max:1000'],
            'approved_scope.*.deliverables' => ['required','array','max:20'], 'timeline_type' => ['nullable',Rule::in(['ESTIMATED','TARGET','COMMITTED'])], 'committed_delivery_date' => ['nullable','date','after:today'],
            'terms' => ['nullable','string','max:12000']]);
        if (array_key_exists('terms', $data)) $this->authorizeManager($request);
        abort_if(($data['timeline_type'] ?? null) === 'COMMITTED' && ! in_array($this->role($request), ['owner','admin'], true), 403, 'Committed delivery dates require an owner or admin.');
        $catalogue = $this->activeServices(app('tenant.id'))->pluck('id')->all();
        foreach ($data['approved_scope'] as $line) abort_unless(in_array($line['service_id'], $catalogue, true) && collect($draft->recommended_scope)->contains(fn ($recommended) => $recommended['service_id'] === $line['service_id']), 422, 'Approved scope must be selected from this draft’s recommended tenant services.');
        abort_if(($data['timeline_type'] ?? null) === 'COMMITTED' && empty($data['committed_delivery_date']), 422, 'A committed timeline requires an authorized date.');
        $draft->update(['draft_content' => [...$draft->draft_content, ...$data['content']], 'human_edits' => [...$draft->human_edits, 'content' => $data['content']],
            'approved_scope' => $data['approved_scope'], 'timeline_type' => $data['timeline_type'] ?? $draft->timeline_type, 'committed_delivery_date' => $data['committed_delivery_date'] ?? null]);
        if (array_key_exists('terms', $data)) {
            $draft->update(['draft_content' => [...$draft->draft_content, 'client_visible_terms' => $data['terms']], 'human_edits' => [...$draft->human_edits, 'client_visible_terms' => $data['terms']]]);
            $record->update(['terms' => $data['terms']]);
        }
        $this->activity($record->sales_opportunity_id, 'proposal_scope_edited', $request->user()->id, ['proposal_id' => $record->id, 'version' => $draft->version]);
        $this->audit('proposal_scope_edited', $record->id, ['version' => $draft->version]);
        return response()->json($this->show($proposal));
    }

    public function updateCommercials(Request $request, string $proposal)
    {
        $this->authorizeManager($request); $record = $this->proposal($proposal); $version = $this->latestVersion($record);
        abort_unless(in_array($record->status->value, [ProposalStatus::ReviewRequired->value, ProposalStatus::CommercialInputRequired->value, ProposalStatus::Draft->value], true), 409, 'Commercial terms are locked after approval.');
        $data = $request->validate(['currency' => ['required','string','size:3','alpha'], 'discount_percent' => ['sometimes','regex:/^(100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/'], 'discount_reason' => ['nullable','string','max:500'],
            'items' => ['required','array','min:1','max:30'], 'items.*.service_id' => ['required','uuid'], 'items.*.quantity' => ['required','integer','between:1,1000'],
            'items.*.unit_price' => ['sometimes','regex:/^\d{1,12}(\.\d{1,2})?$/'], 'items.*.price_reason' => ['required_with:items.*.unit_price','nullable','string','min:8','max:500']]);
        $currency = strtoupper($data['currency']); $policy = $this->pricingPolicyObject(app('tenant.id')); abort_unless($currency === $policy->currency, 422, 'Currency must match an approved tenant pricing source.');
        $discount = (float) ($data['discount_percent'] ?? 0); abort_if($discount > (float) $policy->max_discount_percent, 422, 'Discount exceeds tenant pricing policy.');
        abort_if(DecimalMoney::basisPoints($data['discount_percent'] ?? 0) > 0 && blank($data['discount_reason'] ?? null), 422, 'An authorized discount requires a reason.');
        foreach ($data['items'] as $line) if (isset($line['unit_price'])) abort_if(blank($line['price_reason'] ?? null) || mb_strlen($line['price_reason']) < 8, 422, 'A human price override requires an explanatory reason.');
        $this->persistCommercials($request, $record, $version, $data, $policy);
        $this->activity($record->sales_opportunity_id, 'commercial_input_updated', $request->user()->id, ['proposal_id' => $record->id, 'version' => $version?->version]);
        $this->audit('commercial_input_updated', $record->id, ['version' => $version?->version, 'discount_percent' => $discount]);
        return response()->json($this->show($proposal));
    }

    private function persistCommercials(Request $request, Proposal $proposal, ?ProposalVersion $version, array $data, object $policy): void
    {
        $tenant = app('tenant.id'); $subtotal = 0; $rows = [];
        foreach ($data['items'] as $line) {
            $service = $this->activeServices($tenant)->findOrFail($line['service_id']);
            abort_unless($service->currency === strtoupper($data['currency']), 422, 'Line item currency does not match proposal currency.');
            if ($service->commercial_model === 'custom_quote') {
                abort_unless(isset($line['unit_price']), 422, 'Custom-quote services require an explicitly entered human-approved price before proposal totals can be finalized.');
                abort_unless(blank($line['price_reason'] ?? null) === false && mb_strlen($line['price_reason']) >= 8, 422, 'A custom-quote price requires an approval reason.');
                abort_unless(DecimalMoney::cents($line['unit_price']) > 0, 422, 'A custom-quote service requires a positive human-approved price.');
            }
            abort_unless($service->unit_price !== null || isset($line['unit_price']), 422, 'A proposal line item requires an explicit price.');
            $price = DecimalMoney::cents($line['unit_price'] ?? (string) $service->unit_price); $quantity = (int) $line['quantity'];
            $lineTotal = $price * $quantity; abort_if($lineTotal > 99_999_999_999_999 || $subtotal > 99_999_999_999_999 - $lineTotal, 422, 'Proposal total exceeds supported precision.'); $subtotal += $lineTotal;
            $rows[] = ['tenant_id' => $tenant, 'service_id' => $service->id, 'service_name' => $service->name, 'description' => $service->description,
                'quantity' => $quantity, 'unit' => $service->unit, 'unit_price' => DecimalMoney::decimal($price), 'line_total' => DecimalMoney::decimal($lineTotal),
                'tax_amount' => '0.00', 'discount_amount' => '0.00', 'discount_reason' => null, 'discount_approved_by' => null, 'discount_approved_at' => null,
                'price_override_reason' => isset($line['unit_price']) ? $line['price_reason'] : null, 'price_approved_by' => isset($line['unit_price']) ? $request->user()->id : null,
                'price_approved_at' => isset($line['unit_price']) ? now() : null];
        }
        $discountBasisPoints = DecimalMoney::basisPoints($data['discount_percent'] ?? 0); $discountPct = $discountBasisPoints / 100;
        $discountCents = intdiv(($subtotal * $discountBasisPoints) + 5000, 10000);
        if ($discountCents > 0) { $rows[0]['discount_amount'] = DecimalMoney::decimal($discountCents); $rows[0]['discount_reason'] = $data['discount_reason']; $rows[0]['discount_approved_by'] = $request->user()->id; $rows[0]['discount_approved_at'] = now(); }
        $total = $subtotal - $discountCents;
        DB::transaction(function () use ($proposal, $tenant, $rows, $data, $version, $subtotal, $discountPct, $discountCents, $total): void {
            DB::table('proposal_items')->where('tenant_id', $tenant)->where('proposal_id', $proposal->id)->delete();
            foreach ($rows as $row) DB::table('proposal_items')->insert(['id' => (string) Str::uuid(), 'proposal_id' => $proposal->id, ...$row, 'created_at' => now(), 'updated_at' => now()]);
            $proposal->update(['currency' => strtoupper($data['currency']), 'subtotal' => DecimalMoney::decimal($subtotal), 'discount_type' => $discountPct > 0 ? 'percent' : 'none',
                'discount_value' => number_format($discountPct, 2, '.', ''), 'total' => DecimalMoney::decimal($total), 'status' => ProposalStatus::ReviewRequired]);
            if ($version) $version->update(['commercial_snapshot' => ['currency' => strtoupper($data['currency']), 'subtotal' => DecimalMoney::decimal($subtotal), 'discount_percent' => $discountPct,
                'discount_amount' => DecimalMoney::decimal($discountCents), 'total' => DecimalMoney::decimal($total), 'items' => $rows], 'status' => 'review_required']);
        });
    }

    public function approve(Request $request, string $proposal)
    {
        $this->authorizeManager($request); $record = $this->proposal($proposal); $version = $this->latestVersion($record);
        if ($version && $record->status->value === ProposalStatus::Approved->value) return response()->json($this->show($proposal));
        abort_unless($version && $record->status->value === ProposalStatus::ReviewRequired->value, 409, 'Proposal is not ready for approval.');
        abort_if(($version->approved_scope ?? []) === [], 422, 'Human-approved scope is required.');
        abort_if(! $record->currency || ! $record->items()->exists() || DecimalMoney::cents((string) $record->total) < 1, 422, 'Approved commercial inputs and currency are required.');
        foreach (['executive_summary','client_understanding','objectives','recommended_solution','scope','deliverables','assumptions','dependencies','exclusions','implementation_approach','timeline_narrative','commercial_narrative','next_steps'] as $key) abort_if(! array_key_exists($key, $version->draft_content), 422, 'Proposal content is incomplete.');
        DB::transaction(function () use ($record, $version, $request): void {
            $version->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'commercial_snapshot' => ['currency' => $record->currency,
                'subtotal' => $record->subtotal, 'discount_percent' => $record->discount_value, 'total' => $record->total, 'items' => $record->items()->get()->toArray()]]);
            $record->update(['status' => ProposalStatus::Approved, 'approved_at' => now(), 'approved_by' => $request->user()->id]);
            $this->activity($record->sales_opportunity_id, 'proposal_approved', $request->user()->id, ['proposal_id' => $record->id, 'version' => $version->version]);
            $this->audit('proposal_approved', $record->id, ['version' => $version->version]);
        });
        app(\App\Orchestration\WorkflowService::class)->recordOpportunityEvent(app('tenant.id'), $record->sales_opportunity_id, 'proposal_approved',
            'workflow:proposal-approved:'.$version->id, ['proposal_id' => $record->id, 'version' => $version->version]);
        return response()->json($this->show($proposal));
    }

    public function reject(Request $request, string $proposal)
    {
        $this->authorizeManager($request); $record = $this->proposal($proposal); $version = $this->latestVersion($record);
        abort_unless($version && in_array($record->status->value, [ProposalStatus::ReviewRequired->value, ProposalStatus::CommercialInputRequired->value, ProposalStatus::Draft->value], true), 409, 'Proposal cannot be rejected now.');
        $data = $request->validate(['reason' => ['required','string','min:3','max:1000']]);
        $version->update(['status' => 'rejected', 'human_edits' => [...$version->human_edits, 'rejection_reason' => $data['reason']]]); $record->update(['status' => ProposalStatus::Rejected]);
        $this->activity($record->sales_opportunity_id, 'proposal_rejected', $request->user()->id, ['proposal_id' => $record->id, 'version' => $version->version]); $this->audit('proposal_rejected', $record->id, ['version' => $version->version]);
        return response()->json($this->show($proposal));
    }

    public function supersede(Request $request, string $proposal)
    {
        $this->authorizeManager($request); $record = $this->proposal($proposal); abort_unless(in_array($record->status->value, [ProposalStatus::Approved->value, ProposalStatus::ReadyToSend->value], true), 409, 'Only an approved proposal can be superseded.');
        $record->update(['status' => ProposalStatus::Superseded]); $this->audit('proposal_superseded', $record->id, ['version' => $record->version]); return response()->json($this->show($proposal));
    }

    public function generateDocument(Request $request, string $proposal, ProposalDocumentRendererInterface $renderer)
    {
        $this->authorizeOpportunity($request, $this->proposal($proposal)->sales_opportunity_id); $record = $this->proposal($proposal); $version = $this->latestVersion($record);
        abort_unless($record->status->value === ProposalStatus::Approved->value && $version?->status === 'approved', 409, 'Only approved proposal versions can be rendered.');
        if ($version->document_key && Storage::disk('local')->exists($version->document_key)) return response()->json($this->documentMetadata($version));
        try {
            $company = $record->opportunity()->with('company')->firstOrFail()->company;
            $sections = $version->draft_content; unset($sections['evidence_references'], $sections['knowledge_references'], $sections['case_study_references'], $sections['client_visible_terms'], $sections['case_studies']);
            if ($version->draft_content['case_studies'] ?? []) $sections['Relevant Experience / Case Studies'] = array_map(fn ($case) => $case['title'].': '.$case['content'], $version->draft_content['case_studies']);
            if (! empty($version->draft_content['client_visible_terms'])) $sections['Validity / Terms Summary'] = $version->draft_content['client_visible_terms'];
            if ($version->timeline_type) $sections['Timeline Type'] = $version->timeline_type;
            if ($version->timeline_type === 'COMMITTED' && $version->committed_delivery_date) $sections['Committed Delivery Date'] = $version->committed_delivery_date->toDateString();
            $grounding = isset($version->source_snapshot['ciphertext']) ? json_decode(Crypt::decryptString($version->source_snapshot['ciphertext']), true) : [];
            $items = $record->items()->get()->map(fn ($item) => ['name' => $item->service_name, 'quantity' => $item->quantity, 'unit' => $item->unit, 'line_total' => DecimalMoney::decimal(DecimalMoney::cents((string) $item->line_total) - DecimalMoney::cents((string) $item->discount_amount) + DecimalMoney::cents((string) $item->tax_amount))])->all();
            $pdf = $renderer->render(['tenant_name' => DB::table('tenants')->where('id', app('tenant.id'))->value('name'), 'title' => $record->title,
                'company_name' => $company->name, 'version' => $version->version, 'currency' => $record->currency, 'total' => $record->total,
                'valid_until' => $record->valid_until?->toDateString(), 'sections' => $sections, 'items' => $items]);
            $key = 'proposals/'.app('tenant.id').'/'.$record->id.'/v'.$version->version.'-'.hash('sha256', $pdf).'.pdf';
            Storage::disk('local')->put($key, $pdf);
            $version->update(['document_key' => $key, 'document_sha256' => hash('sha256', $pdf), 'document_generated_at' => now()]);
            $record->update(['safe_document_error' => null]);
            $this->audit('proposal_document_generated', $record->id, ['version' => $version->version, 'sha256' => hash('sha256', $pdf)]);
            $this->activity($record->sales_opportunity_id, 'proposal_document_generated', $request->user()->id, ['proposal_id' => $record->id, 'version' => $version->version]);
            return response()->json($this->documentMetadata($version->fresh()), 201);
        } catch (Throwable $e) {
            Proposal::where('tenant_id', app('tenant.id'))->where('id', $proposal)->update(['safe_document_error' => 'Document generation failed. Retry after checking local storage.']);
            return response()->json(['message' => 'Document generation failed safely. The approved proposal remains unchanged.'], 422);
        }
    }

    public function downloadDocument(Request $request, string $proposal, string $version)
    {
        $record = $this->proposal($proposal); $this->authorizeOpportunity($request, $record->sales_opportunity_id); $ver = $this->version($proposal, $version);
        abort_unless($ver->status === 'approved' && $ver->document_key, 404); $disk = Storage::disk('local'); abort_unless($disk->exists($ver->document_key), 404);
        return response($disk->get($ver->document_key), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="proposal-v'.$ver->version.'.pdf"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function readyToSend(Request $request, string $proposal, \App\Sales\SalesPolicy $salesPolicy)
    {
        $this->authorizeManager($request); $record = $this->proposal($proposal); $version = $this->latestVersion($record);
        if ($record->status->value === ProposalStatus::ReadyToSend->value) return response()->json($this->show($proposal));
        abort_unless($record->status->value === ProposalStatus::Approved->value && $version?->status === 'approved' && $version->document_key && Storage::disk('local')->exists($version->document_key), 409, 'Approval and a generated PDF are required.');
        DB::transaction(function () use ($record, $request, $version, $salesPolicy): void {
            $record->update(['status' => ProposalStatus::ReadyToSend, 'ready_to_send_at' => now()]);
            $opp = SalesOpportunity::where('tenant_id', app('tenant.id'))->lockForUpdate()->findOrFail($record->sales_opportunity_id);
            abort_unless($salesPolicy->transitionAllowed($opp->stage, 'PROPOSAL_READY'), 409, 'Opportunity stage must be qualified or meeting-ready before proposal handoff.');
            $opp->update(['stage' => 'PROPOSAL_READY']);
            $this->activity($opp->id, 'proposal_ready_to_send', $request->user()->id, ['proposal_id' => $record->id, 'version' => $version->version]);
            $this->audit('proposal_ready_to_send', $record->id, ['version' => $version->version]);
        });
        return response()->json($this->show($proposal));
    }

    private function proposal(string $id): Proposal { return Proposal::where('tenant_id', app('tenant.id'))->with('items')->findOrFail($id); }
    private function version(string $proposal, string $version): ProposalVersion { return ProposalVersion::where('tenant_id', app('tenant.id'))->where('proposal_id', $proposal)->where('id', $version)->firstOrFail(); }
    private function latestVersion(Proposal $proposal): ?ProposalVersion { return $proposal->latest_version_id ? ProposalVersion::where('tenant_id', app('tenant.id'))->where('proposal_id', $proposal->id)->find($proposal->latest_version_id) : null; }
    private function requestedScope(object $requirements): array { return json_decode($requirements->requested_services, true) ?: []; }
    private function pricingPolicyObject(string $tenant): object { return DB::table('tenant_pricing_policies')->where('tenant_id', $tenant)->first() ?? (object) ['tenant_id' => $tenant, 'currency' => 'INR', 'max_discount_percent' => 0, 'default_validity_days' => 30]; }
    private function activeServices(string $tenant)
    {
        return TenantService::where('tenant_id', $tenant)->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now()));
    }
    private function role(Request $request): ?string { return $request->user()->tenants()->whereKey(app('tenant.id'))->wherePivot('status', 'active')->value('tenant_user.role'); }
    private function authorizeManager(Request $request): void { abort_unless(in_array($this->role($request), ['owner','admin'], true), 403, 'Proposal commercial and approval changes require an owner or admin.'); }
    private function authorizeOpportunityUser(Request $request, string $opportunityId, bool $allowManagerOnly = false): void
    {
        $op = SalesOpportunity::where('tenant_id', app('tenant.id'))->findOrFail($opportunityId);
        abort_unless(in_array($this->role($request), ['owner','admin'], true) || (string) $op->owner_user_id === (string) $request->user()->id, 403, 'Proposal request requires opportunity assignment or tenant manager privilege.');
    }
    private function authorizeOpportunity(Request $request, string $opportunityId): void
    {
        $op = SalesOpportunity::where('tenant_id', app('tenant.id'))->findOrFail($opportunityId);
        abort_unless(in_array($this->role($request), ['owner','admin'], true) || (string) $op->owner_user_id === (string) $request->user()->id, 403, 'Opportunity assignment or tenant manager privilege required.');
    }
    private function activity(string $opportunity, string $type, ?int $actor, array $details, ?string $run = null, ?string $correlation = null): void
    {
        DB::table('opportunity_activities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => app('tenant.id'), 'sales_opportunity_id' => $opportunity,
            'activity_type' => $type, 'actor_user_id' => $actor, 'agent_run_id' => $run, 'correlation_id' => $correlation, 'details' => json_encode($details), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    private function audit(string $action, string $subject, array $metadata, string $subjectType = 'proposal'): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => app('tenant.id'), 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => $subjectType, 'subject_id' => $subject, 'request_id' => request()->header('X-Request-ID'), 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
    private function documentMetadata(ProposalVersion $version): array { return ['proposal_version_id' => $version->id, 'version' => $version->version, 'sha256' => $version->document_sha256, 'generated_at' => $version->document_generated_at, 'download_url' => '/api/v1/proposals/'.$version->proposal_id.'/versions/'.$version->id.'/document']; }
}
