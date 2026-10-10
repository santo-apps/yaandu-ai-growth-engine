<?php

namespace App\Console\Commands;

use App\Production\ProductionOwnerBootstrapper;
use Illuminate\Console\Command;
use Throwable;

final class BootstrapProductionOwner extends Command
{
    protected $signature = 'production:bootstrap-owner';

    protected $description = 'Create the initial production tenant and owner account.';

    public function handle(ProductionOwnerBootstrapper $bootstrapper): int
    {
        if (! app()->environment('production')) {
            $this->error('This command is available only when APP_ENV is production.');

            return self::FAILURE;
        }

        try {
            $bootstrapper->assertAvailable();
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('An interactive terminal is required. No account was created.');

            return self::FAILURE;
        }

        $tenantName = trim((string) $this->ask('Tenant name'));
        $ownerName = trim((string) $this->ask('Owner full name'));
        $email = trim((string) $this->ask('Owner email'));
        $password = $this->secret('Owner password (at least 16 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($password) || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            unset($password, $confirmation);
            $this->error('Password confirmation failed. No account was created.');

            return self::FAILURE;
        }

        try {
            $owner = $bootstrapper->create($tenantName, $ownerName, $email, $password);
        } catch (Throwable) {
            unset($password, $confirmation);
            $this->error('Owner bootstrap failed. No credentials were written to output or logs. Check database and input configuration, then retry if no owner was created.');

            return self::FAILURE;
        }

        unset($password, $confirmation);
        $this->info('Production tenant and owner created successfully.');
        $this->line('Owner email: '.$owner->email);
        $this->line('Tenant mode: human_assisted');

        return self::SUCCESS;
    }
}
