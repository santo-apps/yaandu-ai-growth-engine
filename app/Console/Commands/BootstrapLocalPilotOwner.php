<?php

namespace App\Console\Commands;

use App\Pilot\LocalPilotOwnerProvisioner;
use Illuminate\Console\Command;

final class BootstrapLocalPilotOwner extends Command
{
    protected $signature = 'pilot:bootstrap-local-owner {tenant} {--email=sprint-7c-local-owner@example.test}';

    protected $description = 'Create a local-only owner account for an active real pilot tenant using a hidden operator-entered password.';

    public function handle(LocalPilotOwnerProvisioner $provisioner): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Local pilot owner setup is available only when APP_ENV is local or testing.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('An interactive terminal is required so the password can be entered without echo. No account was created.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->option('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_ends_with($email, '@example.test')) {
            $this->error('Use a valid .example.test email address for this local-only account.');

            return self::FAILURE;
        }

        if (! $this->confirm('Create a LOCAL TEST owner for active real pilot tenant '.(string) $this->argument('tenant')." using {$email}?", false)) {
            $this->info('Cancelled. No account was created.');

            return self::SUCCESS;
        }

        $password = $this->secret('Choose a password (at least 16 characters)');
        $confirmation = $this->secret('Confirm password');
        if (! is_string($password) || mb_strlen($password) < 16 || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            unset($password, $confirmation);
            $this->error('Password confirmation failed or did not meet the minimum length. No account was created.');

            return self::FAILURE;
        }

        try {
            $user = $provisioner->create((string) $this->argument('tenant'), $email, $password);
        } catch (\Throwable $error) {
            unset($password, $confirmation);
            $this->error($error->getMessage().' No password was recorded in command output.');

            return self::FAILURE;
        }

        unset($password, $confirmation);
        $this->info('Created local-only pilot owner account '.$user->email.' with active owner membership.');
        $this->line('The password was entered without echo and was not displayed or written to application logs.');

        return self::SUCCESS;
    }
}
