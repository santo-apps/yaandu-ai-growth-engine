<?php

namespace App\Jobs;

use App\Messaging\OutboundMessageRequest;
use App\Messaging\OutboundMessageStatus;
use App\Messaging\OutboundMessagingProviderRouter;
use App\Models\Proposal;
use App\Models\ProposalDelivery;
use App\Proposals\ProposalStatus;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliverProposal implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 60;
    public int $uniqueFor = 604800;

    public function __construct(public string $tenantId, public string $deliveryId)
    {
        $this->onQueue('outbound');
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->deliveryId; }
    public function backoff(): array { return [30, 300, 900]; }
    public function retryUntil(): DateTimeInterface { return now()->addDays(2); }

    public function handle(OutboundMessagingProviderRouter $providers): void
    {
        $delivery = ProposalDelivery::where('tenant_id', $this->tenantId)->find($this->deliveryId);
        if (! $delivery || $delivery->status !== 'queued') return;
        // Compatibility drain for jobs enqueued by an older deployment: never send proposals in Phase 2E.
        $delivery->update(['status' => 'cancelled', 'safe_error' => 'Proposal delivery is disabled; human handoff is required.']);
    }

    public function failed(?\Throwable $exception): void
    {
        $delivery = ProposalDelivery::where('tenant_id', $this->tenantId)->find($this->deliveryId);
        if (! $delivery || ! in_array($delivery->status, ['queued', 'sending'], true)) return;
        $delivery->update(['status' => 'failed', 'safe_error' => 'Proposal delivery failed after bounded retries.']);
        Log::warning('Proposal delivery failed.', ['tenant_id' => $this->tenantId, 'proposal_delivery_id' => $this->deliveryId,
            'exception' => $exception ? class_basename($exception) : 'Unknown']);
    }
}
