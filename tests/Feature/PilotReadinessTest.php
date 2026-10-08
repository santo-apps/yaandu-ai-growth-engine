<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PilotReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_is_tenant_scoped_and_never_returns_secret_material(): void
    {
        [$tenant, $owner] = $this->workspace('readiness-owner', 'owner');
        Sanctum::actingAs($owner);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/readiness')->assertOk();
        $response->assertJsonPath('secrets_exposed', false)
            ->assertJsonPath('providers.outbound', 'missing')
            ->assertJsonPath('providers.scheduling', 'missing');
        self::assertContains('Approved active MarketingAgent prompt', $response->json('missing_prerequisites'));
        self::assertArrayNotHasKey('api_key', $response->json());
        self::assertArrayNotHasKey('secret', $response->json());
        self::assertArrayNotHasKey('secret_reference', $response->json());

        [$otherTenant] = $this->workspace('readiness-other', 'owner');
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/pilot/readiness')->assertForbidden();
    }

    public function test_readiness_requires_an_active_tenant_manager(): void
    {
        [$tenant, $member] = $this->workspace('readiness-member', 'member');
        Sanctum::actingAs($member);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/pilot/readiness')->assertForbidden();
    }

    /** @return array{Tenant, User} */
    private function workspace(string $slug, string $role): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $user = User::create(['name' => $slug, 'email' => $slug.'@example.test', 'password' => bcrypt(Str::random(32))]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$tenant, $user];
    }
}
