<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SalesOpportunity;
use App\Scheduling\FakeSchedulingProvider;
use App\Scheduling\SchedulingProviderRouter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchedulingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_schedule_only_books_an_offered_and_selected_slot_and_is_idempotent(): void
    {
        [$tenant, $user, $conversation] = $this->conversation();
        Sanctum::actingAs($user);
        $timezone = 'Asia/Kolkata';
        $day = CarbonImmutable::now($timezone)->addDays(1)->startOfDay();
        while ($day->isWeekend()) $day = $day->addDay();
        $slotStart = $day->setTime(10, 0);
        $slotEnd = $slotStart->addMinutes(30);
        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson('/api/v1/scheduling-configuration', [
            'provider' => 'fake', 'enabled' => true, 'default_timezone' => $timezone, 'owner_timezone' => 'America/New_York', 'meeting_duration_minutes' => 30,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'minimum_notice_minutes' => 60, 'maximum_horizon_days' => 30,
            'allowed_weekdays' => [1,2,3,4,5], 'working_hours_start' => '09:00', 'working_hours_end' => '17:00',
            'meeting_title_template' => 'Discovery meeting with {{company}}',
        ])->assertOk()->assertJsonPath('provider', 'fake');
        $headers = ['X-Tenant-ID' => $tenant->id];
        $request = $this->withHeaders($headers)->postJson('/api/v1/conversations/'.$conversation->id.'/scheduling-requests', ['timezone' => $timezone])->assertCreated()->json();
        $availability = $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/availability', [])->assertOk()->json();
        self::assertNotEmpty($availability['slots'], json_encode($availability));
        $slot = $availability['slots'][0];
        self::assertSame($timezone, $slot['timezone']);

        $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/book', ['slot_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/select-slot', ['slot_id' => $slot['id']])->assertOk();
        $bookingResponse = $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/book', ['slot_id' => $slot['id']]);
        if ($bookingResponse->status() !== 201) self::fail($bookingResponse->getContent());
        $booking = $bookingResponse->assertJsonPath('status', 'SCHEDULED')->json();
        $again = $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/book', ['slot_id' => $slot['id']])->assertCreated()->json();
        self::assertSame($booking['id'], $again['id']);

        $this->withHeaders($headers)->postJson('/api/v1/meetings/'.$booking['id'].'/cancel', ['confirmed' => true])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'meeting.booked']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'meeting.cancelled']);
        $this->assertDatabaseHas('scheduling_requests', ['id' => $request['id'], 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('sales_opportunities', ['tenant_id' => $tenant->id, 'id' => $conversation->sales_opportunity_id, 'stage' => 'MEETING_READY']);
        $this->assertDatabaseHas('opportunity_activities', ['tenant_id' => $tenant->id, 'sales_opportunity_id' => $conversation->sales_opportunity_id, 'activity_type' => 'meeting_booked']);

        $this->withHeaders($headers)->postJson('/api/v1/conversations/'.$conversation->id.'/meetings', [
            'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'timezone' => $timezone, 'confirmed' => true,
        ])->assertNotFound();

        $otherTenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Other tenant', 'slug' => 'other-scheduling-test']);
        $otherUser = User::create(['name' => 'Other user', 'email' => 'other-scheduler@example.test', 'password' => 'hashed-test-password']);
        $otherUser->tenants()->attach($otherTenant->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($otherUser);
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/scheduling-requests/'.$request['id'])->assertNotFound();
        $this->withHeader('X-Tenant-ID', $otherTenant->id)->getJson('/api/v1/meetings/'.$booking['id'])->assertNotFound();
    }

    public function test_expired_requests_and_unassigned_tenant_members_cannot_schedule(): void
    {
        [$tenant, $owner, $conversation] = $this->conversation();
        Sanctum::actingAs($owner);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $this->withHeaders($headers)->putJson('/api/v1/scheduling-configuration', [
            'provider' => 'fake', 'enabled' => true, 'default_timezone' => 'UTC', 'owner_timezone' => 'UTC', 'meeting_duration_minutes' => 30,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'minimum_notice_minutes' => 60, 'maximum_horizon_days' => 30,
            'allowed_weekdays' => [1,2,3,4,5], 'working_hours_start' => '09:00', 'working_hours_end' => '17:00',
            'meeting_title_template' => 'Discovery meeting with {{company}}',
        ])->assertOk();
        $request = $this->withHeaders($headers)->postJson('/api/v1/conversations/'.$conversation->id.'/scheduling-requests', [])->assertCreated()->json();
        DB::table('scheduling_requests')->where('id', $request['id'])->update(['expires_at' => now()->subMinute()]);
        $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/availability', [])->assertStatus(410);
        $this->assertDatabaseHas('scheduling_requests', ['id' => $request['id'], 'status' => 'EXPIRED']);

        $member = User::create(['name' => 'Unassigned', 'email' => 'unassigned@example.test', 'password' => 'hashed-test-password']);
        $member->tenants()->attach($tenant->id, ['role' => 'member', 'status' => 'active']);
        Sanctum::actingAs($member);
        $this->withHeaders($headers)->postJson('/api/v1/conversations/'.$conversation->id.'/scheduling-requests', [])->assertForbidden();
    }

    public function test_provider_failure_is_persisted_as_failed_and_can_be_retried(): void
    {
        [$tenant, $owner, $conversation] = $this->conversation();
        Sanctum::actingAs($owner);
        $fake = new FakeSchedulingProvider();
        app()->instance(FakeSchedulingProvider::class, $fake);
        app()->forgetInstance(SchedulingProviderRouter::class);
        $headers = ['X-Tenant-ID' => $tenant->id];
        $this->withHeaders($headers)->putJson('/api/v1/scheduling-configuration', [
            'provider' => 'fake', 'enabled' => true, 'default_timezone' => 'UTC', 'owner_timezone' => 'UTC', 'meeting_duration_minutes' => 30,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'minimum_notice_minutes' => 60, 'maximum_horizon_days' => 30,
            'allowed_weekdays' => [1,2,3,4,5], 'working_hours_start' => '09:00', 'working_hours_end' => '17:00',
            'meeting_title_template' => 'Discovery meeting with {{company}}',
        ])->assertOk();
        $request = $this->withHeaders($headers)->postJson('/api/v1/conversations/'.$conversation->id.'/scheduling-requests', ['timezone' => 'UTC'])->assertCreated()->json();
        $slots = $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/availability', [])->assertOk()->json('slots');
        $slot = $slots[0];
        $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/select-slot', ['slot_id' => $slot['id']])->assertOk();
        $fake->failNextBooking();
        $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/book', ['slot_id' => $slot['id']])->assertServiceUnavailable();
        $this->assertDatabaseHas('scheduling_requests', ['id' => $request['id'], 'status' => 'FAILED']);
        $retrySlots = $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/availability', [])->assertOk()->json('slots');
        $retry = $retrySlots[0];
        $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/select-slot', ['slot_id' => $retry['id']])->assertOk();
        $meeting = $this->withHeaders($headers)->postJson('/api/v1/scheduling-requests/'.$request['id'].'/book', ['slot_id' => $retry['id']])
            ->assertCreated()->assertJsonMissingPath('provider_booking_id')->assertJsonMissingPath('idempotency_key')->json();
        $this->withHeaders($headers)->patchJson('/api/v1/meetings/'.$meeting['id'].'/status', ['status' => 'COMPLETED'])
            ->assertOk()->assertJsonPath('status', 'COMPLETED');
    }

    private function conversation(): array
    {
        $tenant = Tenant::create(['id' => (string) Str::uuid(), 'name' => 'Scheduling test', 'slug' => 'scheduling-test']);
        $user = User::create(['name' => 'Scheduler', 'email' => 'scheduler@example.test', 'password' => 'hashed-test-password']);
        $user->tenants()->attach($tenant->id, ['role' => 'owner', 'status' => 'active']);
        $company = Company::create(['tenant_id' => $tenant->id, 'name' => 'Scheduling Co', 'normalized_domain' => 'scheduling.test', 'status' => 'new']);
        $conversation = Conversation::create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'channel' => 'email', 'status' => 'ai_active']);
        $opportunity = SalesOpportunity::create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'conversation_id' => $conversation->id,
            'stage' => 'DISCOVERY', 'status' => 'open', 'source' => 'conversation']);
        $conversation->update(['sales_opportunity_id' => $opportunity->id, 'conversation_stage' => 'DISCOVERY']);

        return [$tenant, $user, $conversation];
    }
}
