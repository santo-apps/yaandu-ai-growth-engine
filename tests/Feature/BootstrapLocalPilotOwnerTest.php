<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Pilot\LocalPilotOwnerProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BootstrapLocalPilotOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_command_creates_dedicated_active_owner_only_for_a_real_pilot_tenant(): void
    {
        config(['app.env' => 'local']);
        $tenant = $this->realPilotTenant();
        $email = 'sprint-7c-local-owner@example.test';
        $password = 'local-pilot-owner-password-2026';

        $user = app(LocalPilotOwnerProvisioner::class)->create($tenant->id, $email, $password);
        self::assertTrue(Hash::check($password, $user->password));
        self::assertIsInt($user->getKey());
        self::assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'local_pilot_owner.created')->first();
        self::assertNotNull($audit);
        self::assertSame('user', $audit->subject_type);
        self::assertNull($audit->subject_id);
        self::assertSame('owner', json_decode($audit->metadata, true)['role']);
        self::assertDatabaseCount('users', 1);
        self::assertDatabaseCount('tenant_user', 1);
        self::assertDatabaseCount('audit_logs', 1);
    }

    public function test_local_owner_command_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $tenant = $this->realPilotTenant();

        try {
            app(LocalPilotOwnerProvisioner::class)->create($tenant->id, 'local-owner@example.test', 'local-pilot-owner-password');
            self::fail('Production must be rejected.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('available only when APP_ENV is local or testing', $error->getMessage());
        }

        self::assertDatabaseCount('users', 0);
    }

    public function test_local_owner_command_refuses_to_reuse_any_existing_account(): void
    {
        config(['app.env' => 'local']);
        $tenant = $this->realPilotTenant();
        User::query()->create(['name' => 'Existing test account', 'email' => 'sprint-7c-local-owner@example.test', 'password' => Hash::make(Str::random(32))]);

        try {
            app(LocalPilotOwnerProvisioner::class)->create($tenant->id, 'sprint-7c-local-owner@example.test', 'local-pilot-owner-password-2026');
            self::fail('Existing account must not be reused.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('That account already exists', $error->getMessage());
        }

        self::assertDatabaseCount('tenant_user', 0);
        self::assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_the_user_and_membership(): void
    {
        config(['app.env' => 'local']);
        $tenant = $this->realPilotTenant();
        DB::connection()->beforeExecuting(static function (string $query): void {
            if (str_contains(strtolower($query), 'insert into "audit_logs"')) {
                throw new \RuntimeException('Simulated audit write failure.');
            }
        });

        try {
            app(LocalPilotOwnerProvisioner::class)->create($tenant->id, 'atomic-owner@example.test', 'local-pilot-owner-password-2026');
            self::fail('The simulated audit failure should be propagated.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulated audit write failure.', $error->getMessage());
        }

        self::assertDatabaseMissing('users', ['email' => 'atomic-owner@example.test']);
        self::assertDatabaseCount('tenant_user', 0);
        self::assertDatabaseCount('audit_logs', 0);
    }

    public function test_repeating_bootstrap_does_not_create_duplicate_owner_records(): void
    {
        config(['app.env' => 'local']);
        $tenant = $this->realPilotTenant();
        $email = 'sprint-7c-local-owner@example.test';
        $password = 'local-pilot-owner-password-2026';
        app(LocalPilotOwnerProvisioner::class)->create($tenant->id, $email, $password);

        try {
            app(LocalPilotOwnerProvisioner::class)->create($tenant->id, $email, $password);
            self::fail('Repeated bootstrap must reject the existing account.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('That account already exists', $error->getMessage());
        }

        self::assertDatabaseCount('users', 1);
        self::assertDatabaseCount('tenant_user', 1);
        self::assertDatabaseCount('audit_logs', 1);
    }

    public function test_interactive_command_never_echoes_or_logs_the_operator_password(): void
    {
        config(['app.env' => 'local']);
        $tenant = $this->realPilotTenant();
        $password = 'hidden-local-owner-password-2026';

        $this->artisan('pilot:bootstrap-local-owner', ['tenant' => $tenant->id])
            ->expectsConfirmation("Create a LOCAL TEST owner for active real pilot tenant {$tenant->id} using sprint-7c-local-owner@example.test?", 'yes')
            ->expectsQuestion('Choose a password (at least 16 characters)', $password)
            ->expectsQuestion('Confirm password', $password)
            ->doesntExpectOutput($password)
            ->assertExitCode(0);

        self::assertDatabaseHas('users', ['email' => 'sprint-7c-local-owner@example.test']);
    }

    private function realPilotTenant(): Tenant
    {
        $tenant = Tenant::query()->create(['name' => 'Controlled Real Pilot', 'slug' => 'local-owner-test-'.Str::uuid(), 'status' => 'active']);
        \Illuminate\Support\Facades\DB::table('pilot_cohorts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Real pilot cohort',
            'data_classification' => 'real', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $tenant;
    }
}
