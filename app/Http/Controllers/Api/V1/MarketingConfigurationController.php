<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class MarketingConfigurationController extends Controller
{
    public function prompts(){return DB::table('prompt_templates')->where('tenant_id',app('tenant.id'))->whereIn('agent_key',['MarketingAgent','FollowUpAgent','SalesAgent','ProposalAgent','WebsiteIntelligenceAgent'])->orderBy('agent_key')->orderByDesc('version')->get();}
    public function createPrompt(Request $request)
    {
        $this->authorizeManager($request);$data=$request->validate(['agent_key'=>['required',Rule::in(['MarketingAgent','FollowUpAgent','SalesAgent','ProposalAgent','WebsiteIntelligenceAgent'])],
            'system_instruction'=>['required','string','max:8000'],'template'=>['required','string','max:12000'],'schema_version'=>['required','string','max:64']]);
        $tenantId=app('tenant.id');$version=(int)DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('agent_key',$data['agent_key'])->max('version')+1;$id=(string)Str::uuid();
        DB::table('prompt_templates')->insert(['id'=>$id,'tenant_id'=>$tenantId,'agent_key'=>$data['agent_key'],'version'=>$version,
            'system_instruction'=>$data['system_instruction'],'template'=>$data['template'],'schema_version'=>$data['schema_version'],
            'active'=>false,'status'=>'draft','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        $this->audit($request,'prompt_template.created',$id,['agent_key'=>$data['agent_key'],'version'=>$version]);
        return response()->json(DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('id',$id)->first(),201);
    }
    public function updatePromptDraft(Request $request,string $id)
    {
        $this->authorizeManager($request);$tenantId=app('tenant.id');
        $prompt=DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('id',$id)->where('status','draft')->where('active',false)->first();abort_unless($prompt,404);
        $data=$request->validate(['system_instruction'=>['required','string','max:8000'],'template'=>['required','string','max:12000'],'schema_version'=>['required','string','max:64']]);
        DB::transaction(function()use($request,$tenantId,$id,$prompt,$data){
            DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('id',$id)->where('status','draft')->where('active',false)
                ->update([...$data,'updated_at'=>now()]);
            $this->audit($request,'prompt_template.draft_updated',$id,['agent_key'=>$prompt->agent_key,'version'=>$prompt->version]);
        });
        return response()->json(DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('id',$id)->first());
    }
    public function approvePrompt(Request $request,string $id)
    {
        $this->authorizeManager($request);$tenantId=app('tenant.id');
        $prompt=DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('id',$id)->first();abort_unless($prompt,404);
        DB::transaction(function()use($request,$tenantId,$id,$prompt){
            DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('agent_key',$prompt->agent_key)->update(['active'=>false,'updated_at'=>now()]);
            DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('id',$id)->update(['active'=>true,'status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now(),'updated_at'=>now()]);
        });
        $this->audit($request,'prompt_template.approved',$id,['agent_key'=>$prompt->agent_key,'version'=>$prompt->version]);
        return response()->json(DB::table('prompt_templates')->where('tenant_id',$tenantId)->where('id',$id)->first());
    }
    public function knowledge(){return DB::table('tenant_marketing_knowledge')->where('tenant_id',app('tenant.id'))->orderByDesc('updated_at')->limit(200)->get();}
    public function createKnowledge(Request $request)
    {
        $this->authorizeManager($request);$data=$request->validate(['kind'=>['required',Rule::in(['service','capability','value_proposition','proof_point','case_study','cta'])],
            'title'=>['required','string','max:180'],'content'=>['required','string','max:6000']]);$tenantId=app('tenant.id');$id=(string)Str::uuid();
        DB::table('tenant_marketing_knowledge')->insert(['id'=>$id,'tenant_id'=>$tenantId,'kind'=>$data['kind'],'title'=>trim($data['title']),
            'content'=>trim($data['content']),'status'=>'draft','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        $this->audit($request,'marketing_knowledge.created',$id,['kind'=>$data['kind']]);
        return response()->json(DB::table('tenant_marketing_knowledge')->where('tenant_id',$tenantId)->where('id',$id)->first(),201);
    }
    public function approveKnowledge(Request $request,string $id)
    {
        $this->authorizeManager($request);$tenantId=app('tenant.id');$item=DB::table('tenant_marketing_knowledge')->where('tenant_id',$tenantId)->where('id',$id)->first();abort_unless($item,404);
        DB::table('tenant_marketing_knowledge')->where('tenant_id',$tenantId)->where('id',$id)->update(['status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now(),'updated_at'=>now()]);
        $this->audit($request,'marketing_knowledge.approved',$id,['kind'=>$item->kind]);
        return response()->json(DB::table('tenant_marketing_knowledge')->where('tenant_id',$tenantId)->where('id',$id)->first());
    }
    private function authorizeManager(Request $request):void
    { $role=$request->user()->tenants()->whereKey(app('tenant.id'))->value('tenant_user.role');abort_unless(in_array($role,['owner','admin'],true),403,'Marketing configuration requires an owner or admin role.'); }
    private function audit(Request $request,string $action,string $id,array $metadata):void
    { DB::table('audit_logs')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>app('tenant.id'),'actor_user_id'=>$request->user()->id,'action'=>$action,
        'subject_type'=>'marketing_configuration','subject_id'=>$id,'request_id'=>substr((string)$request->header('X-Request-ID',''),0,255)?:null,
        'metadata'=>json_encode($metadata),'created_at'=>now()]); }
}
