<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Production\ProductionOwnerBootstrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class BootstrapProductionOwnerTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = Str::random(40);
    }

    public function test_production_bootstrap_creates_tenant_owner_membership_and_safe_audit_atomically(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $owner = app(ProductionOwnerBootstrapper::class)->create('Pulam', 'Pulam Owner', 'owner@pulam.example', $this->password);
        $tenant = $owner->tenants()->firstOrFail();
        $audit = DB::table('audit_logs')->where('action', 'production_owner_bootstrap.created')->firstOrFail();

        self::assertSame('Pulam', $tenant->name);
        self::assertSame('active', $tenant->status);
        self::assertSame('human_assisted', $tenant->settings['sales_intelligence_mode']);
        self::assertSame('owner', $owner->tenants()->whereKey($tenant->id)->first()->pivot->role);
        self::assertSame('active', $owner->tenants()->whereKey($tenant->id)->first()->pivot->status);
        self::assertTrue(Hash::check($this->password, $owner->password));
        self::assertSame('tenant', $audit->subject_type);
        self::assertSame($tenant->id, $audit->subject_id);
        self::assertStringNotContainsString($this->password, $audit->metadata);
        self::assertSame((string) $owner->id, (string) json_decode($audit->metadata, true)['owner_user_id']);
    }

    public function test_command_refuses_outside_production_without_creating_records(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        $this->artisan('production:bootstrap-owner')->expectsOutputToContain('only when APP_ENV is production')->assertExitCode(1);

        self::assertDatabaseCount('tenants', 0);
        self::assertDatabaseCount('users', 0);
    }

    public function test_existing_active_owner_or_bootstrap_marker_prevents_a_second_bootstrap(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $tenant = Tenant::query()->create(['name' => 'Existing', 'slug' => 'existing-'.Str::uuid(), 'status' => 'active']);
        $owner = User::query()->create(['name' => 'Existing Owner', 'email' => 'existing@example.test', 'password' => $this->password]);
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        try {
            app(ProductionOwnerBootstrapper::class)->create('Another', 'Another Owner', 'another@example.test', $this->password);
            self::fail('An active owner must close bootstrap.');
        } catch (LogicException $error) {
            self::assertStringContainsString('active owner membership', $error->getMessage());
        }

        self::assertDatabaseCount('tenants', 1);
        self::assertDatabaseCount('users', 1);

        DB::table('tenant_user')->update(['status' => 'inactive']);
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'action' => 'production_owner_bootstrap.created',
            'subject_type' => 'tenant', 'subject_id' => $tenant->id, 'metadata' => '{}', 'created_at' => now(),
        ]);

        try {
            app(ProductionOwnerBootstrapper::class)->create('Another', 'Another Owner', 'another@example.test', $this->password);
            self::fail('The bootstrap marker must close bootstrap.');
        } catch (LogicException $error) {
            self::assertStringContainsString('marker already exists', $error->getMessage());
        }

        self::assertDatabaseCount('tenants', 1);
        self::assertDatabaseCount('users', 1);
    }

    public function test_audit_failure_rolls_back_tenant_user_and_membership(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        DB::connection()->beforeExecuting(static function (string $query): void {
            if (str_contains(strtolower($query), 'insert into "audit_logs"')) {
                throw new \RuntimeException('simulated audit failure');
            }
        });

        try {
            app(ProductionOwnerBootstrapper::class)->create('Pulam', 'Pulam Owner', 'owner@pulam.example', $this->password);
            self::fail('Audit failure must abort the transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame('simulated audit failure', $error->getMessage());
        }

        self::assertDatabaseCount('tenants', 0);
        self::assertDatabaseCount('users', 0);
        self::assertDatabaseCount('tenant_user', 0);
        self::assertDatabaseCount('audit_logs', 0);
    }

    public function test_no_prospect_campaign_or_simulated_records_are_created(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        app(ProductionOwnerBootstrapper::class)->create('Pulam', 'Pulam Owner', 'owner@pulam.example', $this->password);

        foreach (['companies', 'campaigns', 'campaign_recipients', 'website_scans', 'sales_opportunities'] as $table) {
            self::assertDatabaseCount($table, 0);
        }
    }

    public function test_command_output_does_not_disclose_password(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $command = \Illuminate\Support\Facades\Artisan::all()['production:bootstrap-owner'];
        $tester = new CommandTester($command);
        $tester->setInputs(['Pulam', 'Pulam Owner', 'owner@pulam.example', $this->password, $this->password]);

        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        self::assertStringNotContainsString($this->password, $tester->getDisplay());
        self::assertStringContainsString('created successfully', $tester->getDisplay());
    }
}
