<?php

namespace App\Jobs;

use App\LeadDiscovery\LeadDiscoveryCandidateVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class VerifyLeadDiscoveryCandidateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 45;
    public int $uniqueFor = 300;

    public function __construct(public readonly string $tenantId, public readonly string $candidateId)
    {
        $this->onQueue((string) config('lead_discovery.validation_queue', 'lead-validation'));
    }

    public function uniqueId(): string { return $this->tenantId.':'.$this->candidateId; }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('lead-discovery-validation:'.$this->tenantId))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(LeadDiscoveryCandidateVerifier $verifier): void
    {
        $verifier->verify($this->tenantId, $this->candidateId);
    }
}
