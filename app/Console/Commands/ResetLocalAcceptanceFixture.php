<?php

namespace App\Console\Commands;

use App\Acceptance\LocalAcceptanceFixture;
use Illuminate\Console\Command;
use Throwable;

final class ResetLocalAcceptanceFixture extends Command
{
    protected $signature = 'product:acceptance-reset';
    protected $description = 'Reset the dedicated local Sprint 1C browser acceptance fixture.';

    public function handle(LocalAcceptanceFixture $fixture): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('ai.local_acceptance.enabled')) {
            $this->error('Acceptance reset requires APP_ENV=local/testing and AI_DETERMINISTIC_ENABLED=true.');
            return self::FAILURE;
        }

        try {
            $details = $fixture->reset();
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }

        $this->info('Created/reset the dedicated LOCAL ACCEPTANCE tenant.');
        $this->line('Sign in: '.$details['email'].' / '.$details['password']);
        $this->line('Company: '.$details['company']);
        $this->line('Starting state: draft marketing approval; no enrollment, outbound message, opportunity, meeting, or proposal.');
        $this->line('Outbound, scheduling, and AI integrations are deterministic local fakes.');

        return self::SUCCESS;
    }
}
