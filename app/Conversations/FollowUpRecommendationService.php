<?php

namespace App\Conversations;

use App\Agents\AgentOrchestrator;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class FollowUpRecommendationService
{
    public function __construct(private readonly AgentOrchestrator $orchestrator) {}

    public function analyze(string $tenantId,string $conversationId,?string $actorId,string $idempotencyKey):object
    {
        $conversation=Conversation::where('tenant_id',$tenantId)->findOrFail($conversationId);
        $source=DB::table('conversation_messages')->where('tenant_id',$tenantId)->where('conversation_id',$conversationId)
            ->where('direction','inbound')->orderByDesc('created_at')->first(['id']);
        if($source){
            $existing=DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('source_message_id',$source->id)->first();
            if($existing)return $existing;
        }
        $scopedKey='followup:'.$conversationId.':'.$idempotencyKey;
        $existing=DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('idempotency_key',$scopedKey)->first();
        if($existing)return $existing;
        $existingRun=DB::table('agent_runs')->where('tenant_id',$tenantId)->where('idempotency_key',$scopedKey)->first();
        if($existingRun && in_array($existingRun->status,['running','succeeded','cancelled'],true)) throw new RuntimeException('This analysis request has already been processed.');
        $runId=$existingRun?->id??(string)Str::uuid();$correlation=$existingRun?->correlation_id??(string)Str::uuid();
        if(!$existingRun)DB::table('agent_runs')->insert(['id'=>$runId,'tenant_id'=>$tenantId,'agent_key'=>'FollowUpAgent','status'=>'queued',
            'requested_by'=>$actorId,'input_hash'=>hash('sha256',$conversationId.':'.($source->id??'no-reply')),'idempotency_key'=>$scopedKey,
            'correlation_id'=>$correlation,'created_at'=>now(),'updated_at'=>now()]);
        $result=$this->orchestrator->run('FollowUpAgent',$tenantId,['conversation_id'=>$conversationId],$actorId,$runId);
        $data=$result->data;
        return DB::transaction(function()use($tenantId,$actorId,$conversation,$source,$scopedKey,$runId,$correlation,$data){
            $existing=DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where(function($query)use($source,$scopedKey):void{
                if($source)$query->where('source_message_id',$source->id);
                $query->orWhere('idempotency_key',$scopedKey);
            })->first();
            if($existing)return $existing;
            if (in_array($data['intent'], ['unsubscribe', 'not_interested', 'wrong_contact'], true) && $conversation->campaign_recipient_id) {
                $enrollment = DB::table('campaign_recipients')->where('tenant_id', $tenantId)->where('id', $conversation->campaign_recipient_id)->first();
                if ($enrollment) {
                    $unsubscribe = $data['intent'] === 'unsubscribe';
                    if ($unsubscribe && $enrollment->contact_method_id) {
                        $method = DB::table('contact_methods')->where('tenant_id', $tenantId)->where('id', $enrollment->contact_method_id)->first(['type','value']);
                        if ($method && $method->type === 'email') {
                            $contactValues = app(\App\Contacts\ContactMethodValue::class);
                            $hash = $contactValues->fingerprint('email', $contactValues->decrypt($method->value));
                            DB::table('suppression_lists')->insertOrIgnore(['id'=>(string)Str::uuid(),'tenant_id'=>$tenantId,'identifier_hash'=>$hash,
                                'identifier_type'=>'email','scope'=>'tenant','reason'=>'unsubscribe','source'=>'inbound_reply','suppressed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                        }
                    }
                    DB::table('campaign_recipients')->where('tenant_id',$tenantId)->where('id',$enrollment->id)->update([
                        'status'=>$unsubscribe?'unsubscribed':'stopped','suppression_outcome'=>$unsubscribe?'unsubscribe':null,
                        'stop_reason'=>$unsubscribe?'unsubscribe':'not_interested','stopped_at'=>now(),'next_step_at'=>null,'updated_at'=>now()]);
                    app(\App\Campaigns\CampaignMessageStopper::class)->stopEnrollment($tenantId,$enrollment->id,$unsubscribe?'suppressed':'cancelled',
                        $unsubscribe?'Prospect replied with an unsubscribe request.':'Prospect declined further outreach.');
                    app(\App\Orchestration\WorkflowService::class)->stopEnrollment($tenantId,$enrollment->id,$unsubscribe?'unsubscribe':'not_interested');
                }
            }
            if (in_array($data['intent'], ['unsubscribe', 'not_interested', 'wrong_contact'], true)) {
                app(\App\Orchestration\WorkflowService::class)->stopConversation($tenantId, $conversation->id, $data['intent']);
            }
            $id=(string)Str::uuid();DB::table('follow_up_recommendations')->insert(['id'=>$id,'tenant_id'=>$tenantId,'conversation_id'=>$conversation->id,
                'source_message_id'=>$source->id??null,'agent_run_id'=>$runId,'action'=>$data['action'],'intent'=>$data['intent'],'confidence'=>$data['confidence'],
                'reasoning_summary'=>$data['reasoning_summary'],'draft_message'=>$data['draft_message']?\Illuminate\Support\Facades\Crypt::encryptString($data['draft_message']):null,
                'evidence_references'=>json_encode($data['evidence_references']),
                'recommended_delay_hours'=>$data['recommended_delay_hours'],'requires_human_review'=>true,'provider'=>$data['provider'],'model'=>$data['model'],
                'task_key'=>$data['task_key'],'prompt_template_id'=>$data['prompt_template_id'],'prompt_version'=>$data['prompt_version'],
                'correlation_id'=>$correlation,'idempotency_key'=>$scopedKey,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            DB::table('audit_logs')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenantId,'actor_user_id'=>$actorId,'action'=>'follow_up.analyzed',
                'subject_type'=>'follow_up_recommendation','subject_id'=>$id,'request_id'=>$correlation,
                'metadata'=>json_encode(['agent_run_id'=>$runId,'action'=>$data['action'],'intent'=>$data['intent'],'task'=>$data['task_key'],
                    'prompt_version'=>$data['prompt_version'],'provider'=>$data['provider'],'model'=>$data['model'],'correlation_id'=>$correlation]),'created_at'=>now()]);
            return DB::table('follow_up_recommendations')->where('tenant_id',$tenantId)->where('id',$id)->first();
        });
    }
}
