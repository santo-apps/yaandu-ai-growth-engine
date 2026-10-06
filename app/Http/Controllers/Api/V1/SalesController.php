<?php

namespace App\Http\Controllers\Api\V1;

use App\Conversations\ConversationWorkflowStatus;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Sales\QualificationScorer;
use App\Sales\SalesExecutionService;
use App\Sales\SalesPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SalesController extends Controller
{
    public function opportunity(string $id)
    {
        $tenant=app('tenant.id');$o=DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->first();abort_unless($o,404);$this->authorizeOpportunity(request(),$tenant,$o);
        $activities=DB::table('opportunity_activities')->where('tenant_id',$tenant)->where('sales_opportunity_id',$id)->orderByDesc('occurred_at')->limit(100)->get();
        $meetings=DB::table('meeting_bookings')->where('tenant_id',$tenant)->where('sales_opportunity_id',$id)->orderByDesc('starts_at')->get(['id','conversation_id','title','status','timezone','starts_at','ends_at','meeting_url']);
        return response()->json(['opportunity'=>$o,'activities'=>$activities,'meetings'=>$meetings]);
    }

    public function policy(Request $request)
    {
        $tenant=app('tenant.id');$row=DB::table('tenant_sales_policies')->where('tenant_id',$tenant)->first();
        return response()->json(['weights'=>$row?(json_decode($row->weights,true)?:[]):['NEED'=>25,'FIT'=>25,'AUTHORITY'=>20,'TIMELINE'=>15,'BUDGET'=>15],
            'thresholds'=>$row?(json_decode($row->thresholds,true)?:[]):['DEVELOPING'=>40,'QUALIFIED'=>60,'HIGH_PRIORITY'=>80],
            'high_value_handoff'=>$row&&$row->high_value_handoff?(json_decode($row->high_value_handoff,true)?:[]):null]);
    }

    public function updatePolicy(Request $request)
    {
        $this->authorizeManager($request);$data=$request->validate(['weights'=>['required','array'],'weights.NEED'=>['required','integer','min:0','max:100'],'weights.FIT'=>['required','integer','min:0','max:100'],
            'weights.AUTHORITY'=>['required','integer','min:0','max:100'],'weights.TIMELINE'=>['required','integer','min:0','max:100'],'weights.BUDGET'=>['required','integer','min:0','max:100'],
            'thresholds'=>['required','array'],'thresholds.DEVELOPING'=>['required','integer','min:0','max:100'],'thresholds.QUALIFIED'=>['required','integer','min:1','max:100'],'thresholds.HIGH_PRIORITY'=>['required','integer','min:1','max:100'],
            'high_value_handoff'=>['nullable','array'],'high_value_handoff.minimum_value'=>['required_with:high_value_handoff','numeric','min:0'],'high_value_handoff.currency'=>['required_with:high_value_handoff','string','size:3','alpha']]);
        abort_unless(array_sum($data['weights'])>0 && $data['thresholds']['DEVELOPING']<$data['thresholds']['QUALIFIED'] && $data['thresholds']['QUALIFIED']<$data['thresholds']['HIGH_PRIORITY'],422,'Weights and thresholds must be ordered and non-zero.');
        $tenant=app('tenant.id');DB::table('tenant_sales_policies')->updateOrInsert(['tenant_id'=>$tenant],['weights'=>json_encode($data['weights']),'thresholds'=>json_encode($data['thresholds']),
            'high_value_handoff'=>isset($data['high_value_handoff'])?json_encode($data['high_value_handoff']):null,'updated_at'=>now(),'created_at'=>now()]);
        $this->audit($tenant,$request,'sales_policy.updated',(string)$tenant,[]);return $this->policy($request);
    }
    public function analyze(Request $request, string $conversation, SalesExecutionService $service)
    {
        $tenant = app('tenant.id'); $this->authorizeConversation($request, $tenant, $conversation);
        $key = substr((string) $request->header('Idempotency-Key', ''), 0, 100); abort_if($key === '', 422, 'An Idempotency-Key header is required.');
        try { return response()->json($service->analyze($tenant, $conversation, (string) $request->user()->id, $key)); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { throw $e; }
        catch (\Throwable $e) { Log::warning('Sales analysis failed safely.',['tenant_id'=>$tenant,'conversation_id'=>$conversation,'exception'=>class_basename($e)]); return response()->json(['message' => 'Sales analysis is temporarily unavailable.'], 503); }
    }

    public function drafts(Request $request)
    {
        $tenant = app('tenant.id');
        $role=$request->user()->tenants()->whereKey($tenant)->value('tenant_user.role');
        return DB::table('sales_drafts')->where('sales_drafts.tenant_id', $tenant)->join('conversations', function ($j): void { $j->on('conversations.id','=','sales_drafts.conversation_id')->on('conversations.tenant_id','=','sales_drafts.tenant_id'); })
            ->where(function($q)use($request,$role):void{if(in_array($role,['owner','admin'],true))return;$q->where('conversations.owner_user_id',$request->user()->id);})
            ->leftJoin('companies', function ($j): void { $j->on('companies.id','=','conversations.company_id')->on('companies.tenant_id','=','conversations.tenant_id'); })
            ->leftJoin('contacts', function ($j): void { $j->on('contacts.id','=','conversations.contact_id')->on('contacts.tenant_id','=','conversations.tenant_id'); })
            ->whereIn('sales_drafts.status', ['pending','approved','rejected'])->orderByDesc('sales_drafts.created_at')->limit(100)
            ->get(['sales_drafts.*','companies.name as company_name','contacts.name as contact_name'])->map(function ($row): array { $row->body = Crypt::decryptString($row->body_ciphertext); unset($row->body_ciphertext); return (array) $row; });
    }

    public function reviewDraft(Request $request, string $id, string $decision)
    {
        abort_unless(in_array($decision, ['approve','reject'], true), 404);
        $tenant = app('tenant.id'); $row = DB::table('sales_drafts')->where('tenant_id',$tenant)->where('id',$id)->first(); abort_unless($row,404);
        $this->authorizeConversation($request, $tenant, $row->conversation_id);
        abort_unless($row->status === 'pending', 409, 'This draft has already been reviewed.');
        DB::transaction(function () use ($tenant,$row,$request,$decision): void {
            $draft = DB::table('sales_drafts')->where('tenant_id',$tenant)->where('id',$row->id)->lockForUpdate()->first(); abort_unless($draft && $draft->status === 'pending',409);
            DB::table('sales_drafts')->where('tenant_id',$tenant)->where('id',$row->id)->update(['status' => $decision === 'approve' ? 'approved' : 'rejected','reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now()]);
            $this->audit($tenant,$request,'sales_draft.'.$decision,$row->id,['conversation_id'=>$row->conversation_id,'delivery'=>'not_sent']);
            if ($row->sales_opportunity_id) $this->activity($tenant,$row->sales_opportunity_id,$decision === 'approve' ? 'sales_draft_approved' : 'sales_draft_rejected',$request->user()->id,$row->agent_run_id,$row->correlation_id,['draft_id'=>$row->id]);
        });
        return response()->json(['id'=>$row->id,'status'=>$decision === 'approve' ? 'approved' : 'rejected','sent'=>false]);
    }

    public function regenerate(Request $request, string $id, SalesExecutionService $service)
    {
        $tenant=app('tenant.id');$draft=DB::table('sales_drafts')->where('tenant_id',$tenant)->where('id',$id)->first();abort_unless($draft,404);
        $this->authorizeConversation($request,$tenant,$draft->conversation_id);
        $conversation=Conversation::where('tenant_id',$tenant)->findOrFail($draft->conversation_id);
        abort_if(($conversation->ownership_state ?? 'AI_ACTIVE') === 'HUMAN_ACTIVE' || $conversation->status === ConversationWorkflowStatus::Resolved,409,
            'AI regeneration is unavailable while a human owns or has resolved this conversation.');
        $key=substr((string)$request->header('Idempotency-Key',''),0,100);abort_if($key==='',422,'An Idempotency-Key header is required.');
        return response()->json($service->analyze($tenant,$draft->conversation_id,(string)$request->user()->id,'regenerate:'.$key,true));
    }

    public function handoffQueue(Request $request)
    {
        $tenant=app('tenant.id');$role=$request->user()->tenants()->whereKey($tenant)->value('tenant_user.role');return Conversation::where('tenant_id',$tenant)->whereIn('ownership_state',['HUMAN_REVIEW','HUMAN_ACTIVE'])
            ->when(!in_array($role,['owner','admin'],true),fn($q)=>$q->where('owner_user_id',$request->user()->id))
            ->with(['company:id,name,industry','contact:id,name,title'])->orderByDesc('updated_at')->paginate(30);
    }

    public function returnToAi(Request $request,string $id)
    {
        $tenant=app('tenant.id');$conversation=DB::transaction(function()use($request,$tenant,$id){
            $c=Conversation::where('tenant_id',$tenant)->whereKey($id)->lockForUpdate()->firstOrFail();$this->authorizeOwnership($request,$tenant,$c);
            abort_unless(($c->ownership_state??'AI_ACTIVE')==='HUMAN_ACTIVE' && $c->status!==ConversationWorkflowStatus::Resolved,409,'Only an active human-owned conversation can return to AI assistance.');
            $c->update(['ownership_state'=>'AI_ACTIVE','status'=>ConversationWorkflowStatus::AiActive,'owner_user_id'=>null,'handoff_reason'=>null]);
            $this->audit($tenant,$request,'conversation.returned_to_ai',$id,['sending_enabled'=>false]);
            if($c->sales_opportunity_id)$this->activity($tenant,$c->sales_opportunity_id,'returned_to_ai',$request->user()->id,null,(string)($c->correlation_id??''),[]);
            return $c->fresh();
        });return response()->json($conversation);
    }

    public function qualification(Request $request,string $id,QualificationScorer $scorer)
    {
        $data=$request->validate(['dimensions'=>['required','array'],'dimensions.NEED'=>['required','in:UNKNOWN,WEAK,MODERATE,STRONG'],'dimensions.FIT'=>['required','in:UNKNOWN,WEAK,MODERATE,STRONG'],
            'dimensions.AUTHORITY'=>['required','in:UNKNOWN,WEAK,MODERATE,STRONG'],'dimensions.TIMELINE'=>['required','in:UNKNOWN,WEAK,MODERATE,STRONG'],'dimensions.BUDGET'=>['required','in:UNKNOWN,WEAK,MODERATE,STRONG'],
            'evidence'=>['sometimes','array'],'evidence.*'=>['array'],'evidence.*.*'=>['string','uuid']]);
        $tenant=app('tenant.id');$result=DB::transaction(function()use($request,$id,$tenant,$data,$scorer){
            $c=Conversation::where('tenant_id',$tenant)->whereKey($id)->lockForUpdate()->firstOrFail();$this->authorizeOwnership($request,$tenant,$c);
            $opp=$c->sales_opportunity_id?DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$c->sales_opportunity_id)->lockForUpdate()->first():null;
            if(!$opp){$oppId=(string)Str::uuid();DB::table('sales_opportunities')->insert(['id'=>$oppId,'tenant_id'=>$tenant,'company_id'=>$c->company_id,'contact_id'=>$c->contact_id,'conversation_id'=>$id,'owner_user_id'=>$request->user()->id,'stage'=>'NEW','status'=>'open','qualification_score'=>0,'qualification_level'=>'LOW','source'=>'conversation','qualification'=>'{}','created_at'=>now(),'updated_at'=>now()]);$c->sales_opportunity_id=$oppId;$c->save();$opp=DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$oppId)->first();$this->activity($tenant,$oppId,'created',$request->user()->id,null,(string)Str::uuid(),[]);}
            $evidence=$data['evidence']??[];$refs=collect($evidence)->flatten()->unique();
            $allowed=array_fill_keys(DB::table('lead_evidence')->join('lead_insights',function($join):void{$join->on('lead_insights.id','=','lead_evidence.lead_insight_id')->on('lead_insights.tenant_id','=','lead_evidence.tenant_id');})
                ->where('lead_evidence.tenant_id',$tenant)->where('lead_insights.tenant_id',$tenant)->where('lead_insights.company_id',$c->company_id)->whereIn('lead_evidence.id',$refs)->pluck('lead_evidence.id')->all(),true);
            foreach(DB::table('conversation_messages')->where('tenant_id',$tenant)->where('conversation_id',$id)->whereIn('id',$refs)->pluck('id')->all() as $messageId)$allowed[$messageId]=true;
            foreach($evidence as $refs)foreach($refs as $ref)abort_unless(isset($allowed[$ref]),422,'Qualification evidence must belong to this tenant.');
            $policy=DB::table('tenant_sales_policies')->where('tenant_id',$tenant)->first();$scored=$scorer->score($data['dimensions'],$policy?(json_decode($policy->weights,true)?:[]):[], $policy?(json_decode($policy->thresholds,true)?:[]):[]);
            $q=['dimensions'=>array_map(fn($d)=>['level'=>$data['dimensions'][$d],'evidence_references'=>$evidence[$d]??[]],QualificationScorer::DIMENSIONS),'score'=>$scored,'updated_at'=>now()->toISOString()];
            DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$opp->id)->update(['qualification'=>json_encode($q),'qualification_score'=>$scored['score'],'qualification_level'=>$scored['level'],'qualified_at'=>$scored['score']>=60?($opp->qualified_at??now()):$opp->qualified_at,'updated_at'=>now()]);
            $this->activity($tenant,$opp->id,'qualification_updated',$request->user()->id,null,(string)Str::uuid(),['score'=>$scored['score'],'level'=>$scored['level']]);
            return ['opportunity'=>DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$opp->id)->first(),'qualification'=>$q];
        });return response()->json($result);
    }

    public function updateStage(Request $request,string $id,SalesPolicy $policy)
    {
        $data=$request->validate(['stage'=>['required','in:NEW,ENGAGED,DISCOVERY,QUALIFIED,MEETING_READY,PROPOSAL_READY,CLOSED,NOT_QUALIFIED']]);$tenant=app('tenant.id');
        $opp=DB::transaction(function()use($request,$id,$tenant,$data,$policy){$o=DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->lockForUpdate()->first();abort_unless($o,404);$this->authorizeOpportunity($request,$tenant,$o);
            abort_unless($policy->transitionAllowed($o->stage,$data['stage']),409,'The requested opportunity stage transition is not allowed.');
            DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->update(['stage'=>$data['stage'],'status'=>in_array($data['stage'],['CLOSED','NOT_QUALIFIED'],true)?'closed':'open','closed_at'=>in_array($data['stage'],['CLOSED','NOT_QUALIFIED'],true)?now():null,'updated_at'=>now()]);
            DB::table('conversations')->where('tenant_id',$tenant)->where('sales_opportunity_id',$id)->update(['conversation_stage'=>$data['stage'],'updated_at'=>now()]);
            $this->activity($tenant,$id,'stage_changed',$request->user()->id,null,(string)Str::uuid(),['from'=>$o->stage,'to'=>$data['stage']]);
            if($data['stage']==='QUALIFIED')$this->activity($tenant,$id,'marked_qualified',$request->user()->id,null,(string)Str::uuid(),[]);
            if($data['stage']==='NOT_QUALIFIED')$this->activity($tenant,$id,'marked_not_qualified',$request->user()->id,null,(string)Str::uuid(),[]);
            if(in_array($data['stage'],['CLOSED','NOT_QUALIFIED'],true))app(\App\Orchestration\WorkflowService::class)->stopOpportunity($tenant,$id,$data['stage']==='CLOSED'?'opportunity_closed':'not_qualified');
            return DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->first();});return response()->json($opp);
    }

    public function markQualified(Request $request,string $id){return $this->mark($request,$id,true);}
    public function markNotQualified(Request $request,string $id){return $this->mark($request,$id,false);}
    private function mark(Request $request,string $id,bool $qualified)
    {
        $tenant=app('tenant.id');$o=DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->first();abort_unless($o,404);$this->authorizeOpportunity($request,$tenant,$o);
        if($qualified)abort_unless($o->qualification_score>=60,409,'Qualification evidence must meet the configured threshold.');
        $request->merge(['stage'=>$qualified?'QUALIFIED':'NOT_QUALIFIED']);return $this->updateStage($request,$id,new SalesPolicy());
    }

    public function updateValue(Request $request,string $id)
    {
        $this->authorizeManager($request);$data=$request->validate(['value'=>['required','numeric','min:0','max:999999999999.99'],'currency'=>['required','string','size:3','alpha']]);
        $tenant=app('tenant.id');$o=DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->first();abort_unless($o,404);
        DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->update(['value'=>$data['value'],'currency'=>strtoupper($data['currency']),'updated_at'=>now()]);
        $this->activity($tenant,$id,'estimated_value_updated',$request->user()->id,null,(string)Str::uuid(),['value'=>$data['value'],'currency'=>strtoupper($data['currency'])]);
        return response()->json(DB::table('sales_opportunities')->where('tenant_id',$tenant)->where('id',$id)->first());
    }

    public function pipeline()
    {
        $tenant=app('tenant.id');$counts=DB::table('sales_opportunities')->where('tenant_id',$tenant)->select('stage',DB::raw('count(*) as total'))->groupBy('stage')->pluck('total','stage');
        return response()->json(['stages'=>array_map(fn($stage)=>['stage'=>$stage,'count'=>(int)($counts[$stage]??0)],SalesPolicy::STAGES),'total'=>array_sum($counts->map(fn($n)=>(int)$n)->all())]);
    }

    private function authorizeConversation(Request $r,string $tenant,string $id):void{$c=Conversation::where('tenant_id',$tenant)->findOrFail($id);$this->authorizeOwnership($r,$tenant,$c);}
    private function authorizeManager(Request $r):void{$role=$r->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role');abort_unless(in_array($role,['owner','admin'],true),403,'Sales policy changes require a tenant manager.');}
    private function authorizeOwnership(Request $r,string $tenant,Conversation $c):void{$role=$r->user()->tenants()->whereKey($tenant)->value('tenant_user.role');abort_unless(in_array($role,['owner','admin'],true)||((int)$c->owner_user_id===(int)$r->user()->id),403,'Sales access requires assignment or tenant manager privileges.');}
    private function authorizeOpportunity(Request $r,string $tenant,object $o):void{$role=$r->user()->tenants()->whereKey($tenant)->value('tenant_user.role');abort_unless(in_array($role,['owner','admin'],true)||((int)$o->owner_user_id===(int)$r->user()->id),403,'Opportunity access requires assignment or tenant manager privileges.');}
    private function audit(string $tenant,Request $r,string $action,string $id,array $meta):void{DB::table('audit_logs')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant,'actor_user_id'=>$r->user()->id,'action'=>$action,'subject_type'=>'sales','subject_id'=>$id,'request_id'=>$r->header('X-Request-ID'),'metadata'=>json_encode($meta),'created_at'=>now()]);}
    private function activity(string $tenant,string $id,string $type,?int $actor,?string $run,?string $correlation,array $details):void{DB::table('opportunity_activities')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant,'sales_opportunity_id'=>$id,'activity_type'=>$type,'actor_user_id'=>$actor,'agent_run_id'=>$run,'correlation_id'=>$correlation,'details'=>json_encode($details),'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
}
