<?php

namespace Tests\Feature;

use App\Models\ChargingPlan;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargingPlanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_manage_charging_plans_for_their_organization(): void
    {
        [$admin, $organization] = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $planId = $this->postJson('/api/charging-plans', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.discount_basis_points', 800)
            ->json('data.id');

        $this->getJson('/api/charging-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/charging-plans/{$planId}", ['status' => 'archived', 'member_count' => 42])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.member_count', 0);

        $this->deleteJson("/api/charging-plans/{$planId}")->assertNoContent();
        $this->assertSoftDeleted('charging_plans', ['id' => $planId]);
    }

    public function test_operator_can_view_but_cannot_manage_charging_plans(): void
    {
        [$operator, $organization] = $this->userWithRole('operator');
        ChargingPlan::query()->create([...$this->payload(), 'organization_id' => $organization->id]);
        Sanctum::actingAs($operator);

        $this->getJson('/api/charging-plans')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/charging-plans', $this->payload('DENIED'))->assertForbidden();
    }

    public function test_pricing_simulation_resolves_tariff_and_applies_active_plan_discount(): void
    {
        [$admin, $organization] = $this->userWithRole('admin');
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Simulation station',
            'reference' => 'SIM-001',
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
            'name' => 'Default tariff',
            'code' => 'DEFAULT',
            'status' => 'active',
            'currency' => 'TND',
            'price_per_kwh_millimes' => 1000,
            'session_fee_millimes' => 500,
            'idle_fee_per_minute_millimes' => 100,
            'minimum_charge_millimes' => 1000,
            'is_default' => true,
        ]);
        $plan = ChargingPlan::query()->create([...$this->payload(), 'organization_id' => $organization->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/pricing/simulate', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'charging_plan_id' => $plan->id,
            'energy_kwh' => 10,
            'duration_minutes' => 30,
            'idle_minutes' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.tariff.source', 'organization_default')
            ->assertJsonPath('data.breakdown.energy_gross_millimes', 10000)
            ->assertJsonPath('data.breakdown.discount_millimes', 800)
            ->assertJsonPath('data.breakdown.idle_fee_millimes', 200)
            ->assertJsonPath('data.breakdown.total_millimes', 9900);
    }

    public function test_energy_target_derives_duration_and_price_from_connector_power(): void
    {
        [$admin, $station, $connector] = $this->pricingContext();
        Sanctum::actingAs($admin);

        $this->postJson('/api/pricing/simulate', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'target_type' => 'energy',
            'target_value' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.inputs.energy_kwh', 10)
            ->assertJsonPath('data.inputs.duration_minutes', 5)
            ->assertJsonPath('data.breakdown.total_millimes', 10500)
            ->assertJsonPath('data.estimate.connector_power_kw', 120)
            ->assertJsonPath('data.estimate.within_preauthorization', true);
    }

    public function test_duration_target_derives_energy_and_price_from_connector_power(): void
    {
        [$admin, $station, $connector] = $this->pricingContext();
        Sanctum::actingAs($admin);

        $this->postJson('/api/pricing/simulate', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'target_type' => 'duration',
            'target_value' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.inputs.energy_kwh', 20)
            ->assertJsonPath('data.inputs.duration_minutes', 10)
            ->assertJsonPath('data.breakdown.total_millimes', 20500);
    }

    public function test_amount_target_derives_an_energy_limit_within_the_authorization(): void
    {
        [$admin, $station, $connector] = $this->pricingContext();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/pricing/simulate', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'target_type' => 'amount',
            'target_value' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('data.inputs.energy_kwh', 14.5)
            ->assertJsonPath('data.inputs.duration_minutes', 8)
            ->assertJsonPath('data.breakdown.total_millimes', 15000)
            ->assertJsonPath('data.estimate.maximums.amount_millimes', 30000)
            ->assertJsonPath('data.estimate.within_preauthorization', true);

        $this->assertLessThanOrEqual(15000, $response->json('data.estimate.amount_millimes'));
    }

    public function test_linked_estimate_rejects_a_connector_from_another_station(): void
    {
        [$admin, $station] = $this->pricingContext();
        [, , $otherConnector] = $this->pricingContext('OTHER');
        Sanctum::actingAs($admin);

        $this->postJson('/api/pricing/simulate', [
            'station_id' => $station->id,
            'connector_id' => $otherConnector->id,
            'target_type' => 'energy',
            'target_value' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('connector_id');
    }

    /** @return array{User, Organization} */
    private function userWithRole(string $role): array
    {
        $organization = Organization::query()->create([
            'name' => ucfirst($role).' organization',
            'slug' => $role.'-'.uniqid(),
            'status' => 'active',
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $user->assignRole($role);

        return [$user, $organization];
    }

    /** @return array<string, mixed> */
    private function payload(string $code = 'MEMBER'): array
    {
        return [
            'name' => 'Member Plan',
            'code' => $code,
            'description' => 'Recurring plan for frequent drivers.',
            'monthly_fee_millimes' => 19000,
            'discount_basis_points' => 800,
            'audience' => 'Frequent drivers',
            'status' => 'active',
            'member_count' => 0,
        ];
    }

    /** @return array{User, Station, Connector} */
    private function pricingContext(string $suffix = ''): array
    {
        [$admin, $organization] = $this->userWithRole('admin');
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Estimate station',
            'reference' => 'ESTIMATE-'.($suffix ?: uniqid()),
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
            'name' => 'Estimate tariff',
            'code' => 'ESTIMATE',
            'status' => 'active',
            'currency' => 'TND',
            'price_per_kwh_millimes' => 1000,
            'session_fee_millimes' => 500,
            'idle_fee_per_minute_millimes' => 0,
            'minimum_charge_millimes' => 1000,
            'is_default' => true,
        ]);

        return [$admin, $station, $connector];
    }
}
