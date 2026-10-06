<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantRolePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_tenant_role_is_returned_for_role_aware_navigation(): void
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Sales workspace', 'slug' => 'sales-workspace', 'status' => 'active']);
        $user = User::create(['name' => 'Sales User', 'email' => 'sales-user@example.test', 'password' => 'password']);
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/tenants')
            ->assertOk()
            ->assertJsonPath('0.id', $tenant->id)
            ->assertJsonPath('0.role', 'member');
    }
}
