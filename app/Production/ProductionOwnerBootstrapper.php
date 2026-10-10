<?php

namespace App\Production;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ProductionOwnerBootstrapper
{
    private const AUDIT_ACTION = 'production_owner_bootstrap.created';

    public function assertAvailable(): void
    {
        $this->assertProduction();

        if (DB::table('tenant_user')->where('role', 'owner')->where('status', 'active')->exists()) {
            throw new LogicException('An active owner membership already exists; production bootstrap is closed.');
        }

        if (DB::table('audit_logs')->where('action', self::AUDIT_ACTION)->exists()) {
            throw new LogicException('A production owner bootstrap marker already exists; production bootstrap is closed.');
        }
    }

    public function create(string $tenantName, string $ownerName, string $email, string $password): User
    {
        $this->assertProduction();
        $tenantName = trim($tenantName);
        $ownerName = trim($ownerName);
        $email = mb_strtolower(trim($email));

        if ($tenantName === '' || mb_strlen($tenantName) > 255 || $ownerName === '' || mb_strlen($ownerName) > 255) {
            throw new LogicException('Tenant and owner names are required and must be 255 characters or fewer.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw new LogicException('Enter a valid owner email address.');
        }
        if (mb_strlen($password) < 16) {
            throw new LogicException('The owner password must contain at least 16 characters.');
        }

        return DB::transaction(function () use ($tenantName, $ownerName, $email, $password): User {
            $this->acquireBootstrapLock();
            $this->assertAvailable();

            if (User::query()->where('email', $email)->exists()) {
                throw new LogicException('That email address already belongs to an account.');
            }

            $tenant = Tenant::query()->create([
                'name' => $tenantName,
                'slug' => Str::slug($tenantName).'-'.Str::lower(Str::random(10)),
                'status' => 'active',
                'settings' => ['sales_intelligence_mode' => 'human_assisted'],
            ]);
            $owner = User::query()->create([
                'name' => $ownerName,
                'email' => $email,
                'password' => $password,
            ]);
            $tenant->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'actor_user_id' => null,
                'action' => self::AUDIT_ACTION,
                'subject_type' => 'tenant',
                'subject_id' => $tenant->id,
                'metadata' => json_encode([
                    'owner_user_id' => $owner->id,
                    'actor' => 'system',
                    'sales_intelligence_mode' => 'human_assisted',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $owner;
        });
    }

    private function assertProduction(): void
    {
        if (! app()->environment('production')) {
            throw new LogicException('This command is available only when APP_ENV is production.');
        }
    }

    private function acquireBootstrapLock(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select("select pg_advisory_xact_lock(hashtext('yaandu:production-owner-bootstrap'))");
        }
    }
}
