<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Marketing\GenerateMarketingDraftCommand;
use App\Marketing\MarketingDraftGenerationException;
use App\Marketing\MarketingDraftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MarketingDraftController extends Controller
{
    public function index()
    {
        $tenantId=app('tenant.id');
        return DB::table('marketing_drafts')->where('marketing_drafts.tenant_id',$tenantId)
            ->leftJoin('companies',function($join):void{$join->on('companies.id','=','marketing_drafts.company_id')->on('companies.tenant_id','=','marketing_drafts.tenant_id');})
            ->leftJoin('contacts',function($join):void{$join->on('contacts.id','=','marketing_drafts.contact_id')->on('contacts.tenant_id','=','marketing_drafts.tenant_id');})
            ->orderByDesc('marketing_drafts.created_at')->limit(100)->get([
                'marketing_drafts.*','companies.name as company_name','contacts.name as contact_name','contacts.title as contact_title',
            ])->map(function($row)use($tenantId){
                $refs=json_decode((string)$row->evidence_references,true)?:[];
                $row->evidence=$this->marketingEvidence($tenantId,$row->company_id,$refs);
                $row->lead_score=DB::table('lead_scores')->where('tenant_id',$tenantId)->where('company_id',$row->company_id)->orderByDesc('scored_at')->value('score');
                $row->human_edited=DB::table('audit_logs')->where('tenant_id',$tenantId)->where('subject_type','marketing_draft')->where('subject_id',$row->id)->where('action','marketing_draft.edited')->exists();
                return $this->castDraft($row);
            });
    }

    public function generate(Request $request, MarketingDraftService $service)
    {
        $this->authorizeManager($request);
        $data=$request->validate(['company_id'=>['required','uuid'],'contact_id'=>['nullable','uuid'],'campaign_id'=>['nullable','uuid'],
            'campaign_objective'=>['nullable','string','max:1000']]);
        return $this->generateResponse($request, $service, $data, null);
    }

    public function regenerate(Request $request,string $id,MarketingDraftService $service)
    {
        $this->authorizeManager($request);
        $previous=DB::table('marketing_drafts')->where('tenant_id',app('tenant.id'))->where('id',$id)->first();
        abort_unless($previous,404);
        abort_unless(in_array($previous->status,['draft','rejected','superseded'],true),409,'Only an unapproved draft can be regenerated.');
        return $this->generateResponse($request, $service, ['company_id'=>$previous->company_id,'contact_id'=>$previous->contact_id,
            'campaign_id'=>$previous->campaign_id], $previous);
    }

    private function generateResponse(Request $request, MarketingDraftService $service, array $input, ?object $previous)
    {
        $key=substr((string)$request->header('Idempotency-Key',''),0,128);
        abort_if($key==='',422,'An Idempotency-Key header is required.');
        $alreadyExists = DB::table('marketing_drafts')->where('tenant_id', app('tenant.id'))->where('idempotency_key', $key)->exists();
        $command = new GenerateMarketingDraftCommand(tenantId: app('tenant.id'), companyId: $input['company_id'], contactId: $input['contact_id'] ?? null,
            campaignId: $input['campaign_id'] ?? null, campaignObjective: $input['campaign_objective'] ?? null, actorId: (string) $request->user()->id,
            idempotencyKey: $key, previousDraftId: $previous?->id, requestId: substr((string) $request->header('X-Request-ID', ''), 0, 255) ?: null);
        try {
            $draft = $service->generate($command);
            return response()->json($draft, $alreadyExists ? 200 : 201);
        } catch (MarketingDraftGenerationException $exception) {
            return response()->json(['message' => 'A safe marketing draft could not be created.', 'correlation_id' => $exception->correlationId], 422);
        }
    }

    public function approve(Request $request,string $id)
    {
        $this->authorizeManager($request);
        return response()->json(app(MarketingDraftService::class)->approve(app('tenant.id'), $id, (string) $request->user()->id));
    }

    public function update(Request $request, string $id)
    {
        $this->authorizeManager($request);
        $data = $request->validate(['subject' => ['required', 'string', 'max:500'], 'message' => ['required', 'string', 'max:20000']]);
        $draft = DB::table('marketing_drafts')->where('tenant_id', app('tenant.id'))->where('id', $id)->first();
        abort_unless($draft, 404);
        abort_unless($draft->status === 'draft', 409, 'Only a draft message can be edited.');
        $updatedCount = DB::table('marketing_drafts')->where('tenant_id', app('tenant.id'))->where('id', $id)->where('status', 'draft')->update([
            'subject' => \Illuminate\Support\Facades\Crypt::encryptString($data['subject']),
            'message' => \Illuminate\Support\Facades\Crypt::encryptString($data['message']), 'updated_at' => now(),
        ]);
        abort_unless($updatedCount === 1, 409, 'Only a draft message can be edited.');
        DB::table('audit_logs')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => app('tenant.id'),
            'actor_user_id' => $request->user()->id, 'action' => 'marketing_draft.edited', 'subject_type' => 'marketing_draft', 'subject_id' => $id,
            'request_id' => substr((string) $request->header('X-Request-ID', ''), 0, 255) ?: null, 'metadata' => json_encode(['fields' => ['subject', 'message']]), 'created_at' => now()]);
        $updated = DB::table('marketing_drafts')->where('tenant_id', app('tenant.id'))->where('id', $id)->first();
        $updated->human_edited = true;
        return response()->json($this->castDraft($updated));
    }

    public function reject(Request $request,string $id)
    {
        $this->authorizeManager($request);
        return response()->json(app(MarketingDraftService::class)->reject(app('tenant.id'), $id, (string) $request->user()->id,
            substr((string) $request->header('X-Request-ID', ''), 0, 255) ?: null));
    }

    private function authorizeManager(Request $request):void
    { $role=$request->user()->tenants()->whereKey(app('tenant.id'))->wherePivot('status','active')->value('tenant_user.role');abort_unless(in_array($role,['owner','admin'],true),403,'Marketing review requires an active owner or admin role.'); }
    private function castDraft(object $draft):object
    { $draft->subject=\Illuminate\Support\Facades\Crypt::decryptString($draft->subject);$draft->message=\Illuminate\Support\Facades\Crypt::decryptString($draft->message);return $draft; }
    private function marketingEvidence(string $tenantId,string $companyId,array $ids):array
    {
        if($ids===[])return [];
        $items=DB::table('lead_evidence')->join('lead_insights',function($join):void{$join->on('lead_insights.id','=','lead_evidence.lead_insight_id')->on('lead_insights.tenant_id','=','lead_evidence.tenant_id');})
            ->where('lead_evidence.tenant_id',$tenantId)->where('lead_insights.tenant_id',$tenantId)->where('lead_insights.company_id',$companyId)
            ->whereIn('lead_evidence.id',$ids)->get(['lead_evidence.id','lead_evidence.evidence_type as type','lead_evidence.source_url','lead_evidence.excerpt','lead_evidence.confidence','lead_insights.statement as claim'])
            ->map(fn($row)=>(array)$row)->all();
        $items=array_merge($items,DB::table('website_issues')->join('website_scans','website_scans.id','=','website_issues.website_scan_id')
            ->join('company_websites','company_websites.id','=','website_scans.company_website_id')->where('website_issues.tenant_id',$tenantId)
            ->where('website_scans.tenant_id',$tenantId)->where('company_websites.tenant_id',$tenantId)->where('company_websites.company_id',$companyId)
            ->whereIn('website_issues.id',$ids)->get(['website_issues.id','website_issues.type','website_issues.summary as claim','website_issues.evidence as excerpt','website_issues.confidence'])
            ->map(fn($row)=>(array)$row)->all());
        $items=array_merge($items,DB::table('website_technologies')->join('website_scans','website_scans.id','=','website_technologies.website_scan_id')
            ->join('company_websites','company_websites.id','=','website_scans.company_website_id')->where('website_technologies.tenant_id',$tenantId)
            ->where('website_scans.tenant_id',$tenantId)->where('company_websites.tenant_id',$tenantId)->where('company_websites.company_id',$companyId)
            ->whereIn('website_technologies.id',$ids)->get(['website_technologies.id','website_technologies.category as type','website_technologies.name as claim','website_technologies.confidence'])
            ->map(fn($row)=>(array)$row)->all());
        $items=array_merge($items,DB::table('tenant_marketing_knowledge')->where('tenant_id',$tenantId)->where('status','approved')->whereIn('id',$ids)
            ->get(['id','kind as type','title as claim','content as excerpt'])->map(fn($row)=>(array)$row)->all());
        return $items;
    }
}
