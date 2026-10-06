<?php

namespace App\Jobs;

use App\Conversations\ConversationAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifyConversation implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 90;
    public int $uniqueFor = 300;

    public function __construct(public string $tenantId, public string $conversationId)
    {
        $this->onQueue('conversations');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->conversationId; }
    public function backoff(): array { return [15, 60]; }

    public function handle(ConversationAnalysisService $analysis): void
    {
        try {
            $analysis->analyze($this->tenantId, $this->conversationId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return;
        } catch (\Throwable $exception) {
            Log::warning('Queued conversation analysis failed.', [
                'tenant_id' => $this->tenantId, 'conversation_id' => $this->conversationId,
                'exception' => class_basename($exception),
            ]);
            throw $exception;
        }
    }
}
