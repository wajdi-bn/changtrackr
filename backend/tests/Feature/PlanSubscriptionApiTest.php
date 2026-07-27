<?php

namespace Tests\Feature;

use App\Models\ChargingPlan;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\PlanSubscription;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_client_catalog_contains_active_plans_from_all_active_organizations(): void
    {
        $client = $this->user(null, 'client');
        $firstOrganization = $this->organization('first-network');
        $secondOrganization = $this->organization('second-network');
        $inactiveOrganization = $this->organization('inactive-network', 'inactive');
        $payg = $this->plan($firstOrganization, 'PAYG', 0, 0);
        $member = $this->plan($firstOrganization, 'MEMBER', 19000, 800);
        $this->plan($firstOrganization, 'DRAFT', 10000, 500, 'draft');
        $coast = $this->plan($secondOrganization, 'COAST', 12000, 600);
        $this->plan($inactiveOrganization, 'HIDDEN', 15000, 700);
        $this->subscription($client, $member);
        Sanctum::actingAs($client);

        $this->getJson('/api/subscription-plans')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['id' => $payg->id, 'requires_subscription' => false])
            ->assertJsonFragment(['id' => $coast->id, 'requires_subscription' => true])
            ->assertJsonPath('data.1.current_subscription.plan.id', $member->id)
            ->assertJsonMissing(['code' => 'DRAFT'])
            ->assertJsonMissing(['code' => 'HIDDEN']);
    }

    public function test_client_can_subscribe_to_multiple_organizations_and_switch_plan_within_one(): void
    {
        $client = $this->user(null, 'client');
        $firstOrganization = $this->organization('urban-network');
        $secondOrganization = $this->organization('coast-network');
        $member = $this->plan($firstOrganization, 'MEMBER', 19000, 800);
        $premium = $this->plan($firstOrganization, 'PREMIUM', 39000, 1500);
        $coast = $this->plan($secondOrganization, 'COAST', 12000, 600);
        $payg = $this->plan($secondOrganization, 'PAYG', 0, 0);
        Sanctum::actingAs($client);

        $firstSubscriptionId = $this->postJson('/api/subscriptions', [
            'charging_plan_id' => $member->id,
            'auto_renew' => true,
            'payment_method' => 'simulated_card',
            'idempotency_key' => '10000000-0000-4000-8000-000000000001',
        ])->assertCreated()
            ->assertJsonPath('data.organization.id', $firstOrganization->id)
            ->assertJsonPath('data.billing_provider', 'simulated')
            ->json('data.id');

        $this->postJson('/api/subscriptions', [
            'charging_plan_id' => $coast->id,
            'auto_renew' => false,
            'payment_method' => 'simulated_d17',
            'idempotency_key' => '10000000-0000-4000-8000-000000000002',
        ])->assertCreated();

        $this->postJson('/api/subscriptions', [
            'charging_plan_id' => $premium->id,
            'auto_renew' => true,
            'payment_method' => 'simulated_edinar',
            'idempotency_key' => '10000000-0000-4000-8000-000000000003',
        ])->assertCreated();

        $this->assertDatabaseHas('plan_subscriptions', ['id' => $firstSubscriptionId, 'status' => 'cancelled']);
        $this->assertSame(2, PlanSubscription::query()->where('user_id', $client->id)->where('status', 'active')->count());
        $this->assertDatabaseHas('users', ['id' => $client->id, 'organization_id' => null]);
        $this->assertDatabaseHas('charging_plans', ['id' => $member->id, 'member_count' => 0]);
        $this->assertDatabaseHas('charging_plans', ['id' => $premium->id, 'member_count' => 1]);
        $this->assertDatabaseHas('charging_plans', ['id' => $coast->id, 'member_count' => 1]);

        $this->postJson('/api/subscriptions', [
            'charging_plan_id' => $premium->id,
            'auto_renew' => true,
            'payment_method' => 'simulated_card',
            'idempotency_key' => '10000000-0000-4000-8000-000000000004',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('charging_plan_id');
        $this->postJson('/api/subscriptions', [
            'charging_plan_id' => $payg->id,
            'auto_renew' => true,
            'payment_method' => 'simulated_card',
            'idempotency_key' => '10000000-0000-4000-8000-000000000005',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('charging_plan_id');

        $this->assertDatabaseCount('plan_subscription_invoices', 3);
        $this->assertDatabaseHas('plan_subscription_invoices', [
            'charging_plan_id' => $premium->id,
            'status' => 'paid',
            'payment_method' => 'simulated_edinar',
        ]);
    }

    public function test_client_can_update_renewal_and_cancel_only_their_subscription(): void
    {
        $organization = $this->organization('renewal-network');
        $plan = $this->plan($organization, 'MEMBER', 19000, 800);
        $client = $this->user(null, 'client');
        $otherClient = $this->user(null, 'client');
        $subscription = $this->subscription($client, $plan);

        Sanctum::actingAs($otherClient);
        $this->patchJson("/api/subscriptions/{$subscription->id}", ['auto_renew' => false])->assertForbidden();

        Sanctum::actingAs($client);
        $this->patchJson("/api/subscriptions/{$subscription->id}", ['auto_renew' => false])
            ->assertOk()
            ->assertJsonPath('data.auto_renew', false);
        $this->deleteJson("/api/subscriptions/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.cancel_at_period_end', true);
        $this->assertDatabaseHas('charging_plans', ['id' => $plan->id, 'member_count' => 1]);

        $this->postJson("/api/subscriptions/{$subscription->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.cancel_at_period_end', false);
    }

    public function test_declined_checkout_creates_failed_invoice_without_activating_plan(): void
    {
        $organization = $this->organization('declined-network');
        $plan = $this->plan($organization, 'MEMBER', 19000, 800);
        $client = $this->user(null, 'client');
        Sanctum::actingAs($client);

        $this->postJson('/api/subscriptions', [
            'charging_plan_id' => $plan->id,
            'auto_renew' => true,
            'payment_method' => 'simulated_card',
            'idempotency_key' => '10000000-0000-4000-8000-000000000006',
            'simulation_outcome' => 'declined',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertDatabaseMissing('plan_subscriptions', [
            'user_id' => $client->id,
            'charging_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('plan_subscription_invoices', [
            'user_id' => $client->id,
            'charging_plan_id' => $plan->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('charging_plans', ['id' => $plan->id, 'member_count' => 0]);
    }

    public function test_lifecycle_command_renews_or_expires_due_subscriptions(): void
    {
        $organization = $this->organization('lifecycle-network');
        $plan = $this->plan($organization, 'MEMBER', 19000, 800);
        $renewingClient = $this->user(null, 'client');
        $endingClient = $this->user(null, 'client');
        $renewing = $this->subscription($renewingClient, $plan);
        $ending = $this->subscription($endingClient, $plan);
        $renewing->update(['current_period_ends_at' => now()->subMinute(), 'payment_method' => 'simulated_card']);
        $ending->update([
            'current_period_ends_at' => now()->subMinute(),
            'auto_renew' => false,
            'cancel_at_period_end' => true,
            'payment_method' => 'simulated_card',
        ]);

        $this->artisan('client-subscriptions:sync')->assertSuccessful();

        $this->assertDatabaseHas('plan_subscriptions', ['id' => $renewing->id, 'status' => 'active']);
        $this->assertTrue($renewing->fresh()->current_period_ends_at->isFuture());
        $this->assertDatabaseHas('plan_subscriptions', ['id' => $ending->id, 'status' => 'expired']);
        $this->assertDatabaseHas('charging_plans', ['id' => $plan->id, 'member_count' => 1]);
        $this->assertDatabaseHas('plan_subscription_invoices', [
            'plan_subscription_id' => $renewing->id,
            'billing_reason' => 'renewal',
            'status' => 'paid',
        ]);
    }

    public function test_client_can_list_and_preview_only_their_membership_invoices(): void
    {
        $organization = $this->organization('invoice-network');
        $plan = $this->plan($organization, 'MEMBER', 19000, 800);
        $client = $this->user(null, 'client');
        $otherClient = $this->user(null, 'client');
        Sanctum::actingAs($client);

        $invoiceId = $this->postJson('/api/subscriptions', [
            'charging_plan_id' => $plan->id,
            'auto_renew' => true,
            'payment_method' => 'simulated_card',
            'idempotency_key' => '10000000-0000-4000-8000-000000000007',
        ])->assertCreated()->json('data.latest_invoice.id');

        $this->getJson('/api/subscription-invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoiceId);

        Sanctum::actingAs($otherClient);
        $this->get("/api/subscription-invoices/{$invoiceId}/document")->assertForbidden();

        Sanctum::actingAs($client);
        $this->get("/api/subscription-invoices/{$invoiceId}/document")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_subscription_discount_is_applied_to_real_sessions_and_arbitrary_plans_are_rejected(): void
    {
        $organization = $this->organization('discount-network');
        $client = $this->user(null, 'client');
        $subscribedPlan = $this->plan($organization, 'MEMBER', 19000, 1000);
        $otherPlan = $this->plan($organization, 'PREMIUM', 39000, 2000);
        [$station, $connector] = $this->stationWithPricing($organization);
        $this->subscription($client, $subscribedPlan);
        Sanctum::actingAs($client);

        $this->postJson('/api/pricing/simulate', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'charging_plan_id' => $otherPlan->id,
            'energy_kwh' => 10,
            'duration_minutes' => 30,
            'idle_minutes' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('charging_plan_id');

        $this->postJson('/api/pricing/simulate', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'energy_kwh' => 10,
            'duration_minutes' => 30,
            'idle_minutes' => 0,
        ])->assertOk()
            ->assertJsonPath('data.plan.id', $subscribedPlan->id)
            ->assertJsonPath('data.breakdown.discount_millimes', 1000)
            ->assertJsonPath('data.breakdown.total_millimes', 9000);

        $sessionId = $this->postJson('/api/charging-sessions', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
        ])->assertCreated()
            ->assertJsonPath('data.plan.id', $subscribedPlan->id)
            ->assertJsonPath('data.plan.discount_basis_points', 1000)
            ->json('data.id');
        ChargingSession::query()->whereKey($sessionId)->update(['started_at' => now()->subHour()]);

        $response = $this->postJson("/api/charging-sessions/{$sessionId}/stop")
            ->assertOk()
            ->assertJsonPath('data.plan.id', $subscribedPlan->id);
        $gross = $response->json('data.energy_gross_millimes');
        $discount = $response->json('data.discount_millimes');
        $this->assertSame((int) round($gross * 0.10), $discount);
        $this->assertSame($gross - $discount, $response->json('data.total_millimes'));
    }

    public function test_operator_cannot_access_client_subscriptions(): void
    {
        $organization = $this->organization('operator-network');
        $operator = $this->user($organization, 'operator');
        Sanctum::actingAs($operator);

        $this->getJson('/api/subscription-plans')->assertForbidden();
        $this->getJson('/api/subscriptions')->assertForbidden();
    }

    private function organization(string $slug, string $status = 'active'): Organization
    {
        return Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
    }

    private function user(?Organization $organization, string $role): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function plan(Organization $organization, string $code, int $fee, int $discount, string $status = 'active'): ChargingPlan
    {
        return ChargingPlan::query()->create([
            'organization_id' => $organization->id,
            'name' => $code.' Plan',
            'code' => $code,
            'description' => 'Test plan',
            'monthly_fee_millimes' => $fee,
            'discount_basis_points' => $discount,
            'audience' => 'Test drivers',
            'status' => $status,
            'member_count' => 0,
        ]);
    }

    private function subscription(User $client, ChargingPlan $plan): PlanSubscription
    {
        $subscription = PlanSubscription::query()->create([
            'organization_id' => $plan->organization_id,
            'user_id' => $client->id,
            'charging_plan_id' => $plan->id,
            'status' => 'active',
            'auto_renew' => true,
            'billing_provider' => 'simulated',
            'monthly_fee_millimes' => $plan->monthly_fee_millimes,
            'discount_basis_points' => $plan->discount_basis_points,
            'starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
        $plan->increment('member_count');

        return $subscription;
    }

    /** @return array{Station, Connector} */
    private function stationWithPricing(Organization $organization): array
    {
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Discount Station',
            'reference' => 'CT-DISCOUNT-001',
            'location_name' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test',
            'manufacturer' => 'Test',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);
        Tariff::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Default',
            'code' => 'DEFAULT',
            'status' => 'active',
            'currency' => 'TND',
            'price_per_kwh_millimes' => 1000,
            'session_fee_millimes' => 0,
            'idle_fee_per_minute_millimes' => 0,
            'minimum_charge_millimes' => 0,
            'is_default' => true,
        ]);

        return [$station, $connector];
    }
}
