<?php

namespace App\Pilot;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class LocalPilotOwnerProvisioner
{
    public function create(string $tenantId, string $email, string $password): User
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Local pilot owner setup is available only when APP_ENV is local or testing.');
        }

        $tenant = Tenant::query()->whereKey($tenantId)->where('status', 'active')->first();
        if (! $tenant || ! DB::table('pilot_cohorts')->where('tenant_id', $tenantId)->where('data_classification', 'real')->exists()) {
            throw new LogicException('An active tenant with a real pilot cohort is required.');
        }

        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_ends_with($email, '@example.test')) {
            throw new InvalidArgumentException('Use a valid .example.test email address for this local-only account.');
        }
        if (mb_strlen($password) < 16) {
            throw new InvalidArgumentException('The local-only account password must contain at least 16 characters.');
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new LogicException('That account already exists; no password or tenant access was changed.');
        }

        return DB::transaction(function () use ($tenant, $email, $password): User {
            $user = User::query()->create([
                'name' => 'LOCAL TEST · Sprint 7C Pilot Owner',
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            $tenant->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'actor_user_id' => null,
                'action' => 'local_pilot_owner.created',
                'subject_type' => 'user',
                // Users have integer primary keys, while audit subjects are UUIDs.
                // Preserve the event and subject type without inventing an identifier.
                'subject_id' => null,
                'metadata' => json_encode(['role' => 'owner', 'environment' => app()->environment(), 'email_domain' => 'example.test']),
                'created_at' => now(),
            ]);

            return $user;
        });
    }
}
