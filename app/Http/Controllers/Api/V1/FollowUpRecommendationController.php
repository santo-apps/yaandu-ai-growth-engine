<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Campaigns\CampaignMessageStopper;
use App\Conversations\FollowUpRecommendationService;
use App\Conversations\ConversationWorkflowStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FollowUpRecommendationController extends Controller
{
    public function index()
    {
        $tenantId=app('tenant.id');
        return DB::table('follow_up_recommendations')->where('follow_up_recommendations.tenant_id',$tenantId)
            ->join('conversations',function($join):void{$join->on('conversations.id','=','follow_up_recommendations.conversation_id')->on('conversations.tenant_id','=','follow_up_recommendations.tenant_id');})
            ->leftJoin('companies',function($join):void{$join->on('companies.id','=','conversations.company_id')->on('companies.tenant_id','=','conversations.tenant_id');})
            ->leftJoin('contacts',function($join):void{$join->on('contacts.id','=','conversations.contact_id')->on('contacts.tenant_id','=','conversations.tenant_id');})
            ->orderByDesc('follow_up_recommendations.created_at')->limit(100)->get([
                'follow_up_recommendations.*','companies.name as company_name','contacts.name as contact_name',
            ])->map(function($row)use($tenantId){
                $refs=json_decode((string)$row->evidence_references,true)?:[];
                $row->evidence=$this->followUpEvidence($tenantId,$row->conversation_id,$refs);
                return (array)$this->castRow($row);
            });
    }

    public function analyze(Request $request,string $conversation,FollowUpRecommendationService $service)
    {
        $this->authorizeManager($request);$tenantId=app('tenant.id');
        Conversation::where('tenant_id',$tenantId)->findOrFail($conversation);
        $key=substr((string)$request->header('Idempotency-Key',''),0,128);abort_if($key==='',422,'An Idempotency-Key header is required.');
        try{$recommendation=$service->analyze($tenantId,$conversation,(string)$request->user()->id,$key);}
        catch(\Throwable $exception){
            if($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException)throw $exception;
            return response()->json(['message'=>'Conversation analysis is temporarily unavailable.'],503);
        }
        return response()->json($this->castRow($recommendation));
    }

    public function approve(Request $request,string $id)
    {
        $record=DB::table('follow_up_recommendations')->where('tenant_id',app('tenant.id'))->where('id',$id)->first();abort_unless($record,404);
        abort_unless($record->action==='DRAFT_FOLLOW_UP' && $record->draft_message,409,'Only a follow-up draft can be approved for manual use.');
        return $this->review($request,$id,'approved','follow_up.draft_approved');
    }
    public function reject(Request $request,string $id)
    { return $this->review($request,$id,'rejected','follow_up.rejected'); }

    public function takeover(Request $request,string $id)
    {
        $this->authorizeManager($request);$tenantId=app('tenant.id');
        $result=DB::transaction(function()use($request,$tenantId,$id){
            $recommendation=DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('id',$id)->lockForUpdate()->first();abort_unless($recommendation,404);
            abort_unless($recommendation->status==='pending',409,'Only a pending recommendation can be taken over.');
            $conversation=Conversation::where('tenant_id',$tenantId)->whereKey($recommendation->conversation_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($conversation->status,[ConversationWorkflowStatus::AiActive,ConversationWorkflowStatus::HumanReview],true),409,'Conversation is not eligible for human takeover.');
            $conversation->update(['status'=>ConversationWorkflowStatus::HumanActive,'ownership_state'=>'HUMAN_ACTIVE','owner_user_id'=>$request->user()->id,
                'handed_off_by'=>$request->user()->id,'handed_off_at'=>now(),'handoff_reason'=>'manual_takeover']);
            if($conversation->campaign_recipient_id) {
                DB::table('campaign_recipients')->where('tenant_id',$tenantId)->where('id',$conversation->campaign_recipient_id)
                    ->whereIn('status',['pending','active'])->update(['status'=>'handed_off','stop_reason'=>'human_takeover','stopped_at'=>now(),'next_step_at'=>null,'updated_at'=>now()]);
                app(CampaignMessageStopper::class)->stopEnrollment($tenantId,$conversation->campaign_recipient_id,'cancelled','human_takeover');
            }
            DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('id',$id)->update(['status'=>'human_takeover','reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now()]);
            if ($conversation->sales_opportunity_id) DB::table('opportunity_activities')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenantId,'sales_opportunity_id'=>$conversation->sales_opportunity_id,
                'actor_user_id'=>$request->user()->id,'activity_type'=>'human_takeover','details'=>json_encode([]),'correlation_id'=>$conversation->correlation_id,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            $this->audit($tenantId,$request,'follow_up.human_takeover',$id,['conversation_id'=>$conversation->id]);
            return ['recommendation'=>DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('id',$id)->first(),'conversation'=>$conversation->fresh()];
        });
        $result['recommendation']=$this->castRow($result['recommendation']);return response()->json($result);
    }

    private function review(Request $request,string $id,string $status,string $action)
    {
        $this->authorizeManager($request);$tenantId=app('tenant.id');
        $row=DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('id',$id)->where('status','pending')->first();abort_unless($row,404);
        DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('id',$id)->update(['status'=>$status,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now()]);
        $this->audit($tenantId,$request,$action,$id,[]);
        return response()->json($this->castRow(DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('id',$id)->first()));
    }
    private function authorizeManager(Request $request):void
    { $role=$request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role');abort_unless(in_array($role,['owner','admin'],true),403,'Follow-up review requires an owner or admin role.'); }
    private function audit(string $tenantId,Request $request,string $action,string $id,array $metadata):void
    { DB::table('audit_logs')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenantId,'actor_user_id'=>$request->user()->id,'action'=>$action,
        'subject_type'=>'follow_up_recommendation','subject_id'=>$id,'request_id'=>substr((string)$request->header('X-Request-ID',''),0,255)?:null,
        'metadata'=>json_encode($metadata),'created_at'=>now()]); }
    private function castRow(object $row):object
    { $row->requires_human_review=(bool)$row->requires_human_review;if($row->draft_message!==null)$row->draft_message=\Illuminate\Support\Facades\Crypt::decryptString($row->draft_message);return $row; }
    private function followUpEvidence(string $tenantId,string $conversationId,array $ids):array
    {
        if($ids===[])return [];
        $items=ConversationMessage::where('tenant_id',$tenantId)->where('conversation_id',$conversationId)->whereIn('id',$ids)->get()
            ->map(fn($message)=>['id'=>$message->id,'type'=>'conversation_'.$message->direction,'text'=>mb_substr($message->content(),0,1800)])->all();
        $conversation=Conversation::where('tenant_id',$tenantId)->find($conversationId);
        if($conversation)$items=array_merge($items,DB::table('lead_evidence')->join('lead_insights',function($join):void{
            $join->on('lead_insights.id','=','lead_evidence.lead_insight_id')->on('lead_insights.tenant_id','=','lead_evidence.tenant_id');
        })->where('lead_evidence.tenant_id',$tenantId)->where('lead_insights.tenant_id',$tenantId)->where('lead_insights.company_id',$conversation->company_id)
            ->whereIn('lead_evidence.id',$ids)->get(['lead_evidence.id','lead_evidence.evidence_type as type','lead_evidence.source_url','lead_evidence.excerpt','lead_insights.statement as claim'])
            ->map(fn($row)=>(array)$row)->all());
        return $items;
    }
}
