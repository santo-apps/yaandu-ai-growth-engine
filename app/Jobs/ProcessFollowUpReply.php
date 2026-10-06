<?php

namespace App\Jobs;

use App\Conversations\FollowUpRecommendationService;
use App\Sales\SalesExecutionService;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessFollowUpReply implements ShouldQueue,ShouldBeUniqueUntilProcessing
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=3;
    public int $timeout=120;
    public int $uniqueFor=600;
    public function __construct(public string $tenantId,public string $conversationId){$this->onQueue('conversations');}
    public function uniqueId():string{return $this->tenantId.':'.$this->conversationId;}
    public function backoff():array{return [15,60];}
    public function handle(FollowUpRecommendationService $service,?SalesExecutionService $sales=null):void
    {
        $conversation=Conversation::where('tenant_id',$this->tenantId)->find($this->conversationId);
        if(!$conversation || in_array($conversation->ownership_state,['HUMAN_ACTIVE','RESOLVED'],true))return;
        $message=DB::table('conversation_messages')->where('tenant_id',$this->tenantId)->where('conversation_id',$this->conversationId)
            ->where('direction','inbound')->orderByDesc('created_at')->first(['id']);
        if(!$message)return;
        $recommendation = $service->analyze($this->tenantId,$this->conversationId,null,'inbound:'.$message->id);
        if (in_array($recommendation->action ?? null, ['STOP_SEQUENCE','REQUEST_HUMAN_REVIEW'], true)) return;
        $sales?->analyze($this->tenantId,$this->conversationId,null,'inbound:'.$message->id);
    }
    public function failed(?\Throwable $exception):void
    { Log::warning('Queued follow-up analysis failed safely.',['tenant_id'=>$this->tenantId,'conversation_id'=>$this->conversationId,
        'exception'=>$exception?class_basename($exception):'UnknownException']); }
}
