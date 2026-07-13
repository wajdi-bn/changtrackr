<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_only_lists_customers_who_used_its_network(): void
    {
        $organization = $this->organization('north-network');
        $otherOrganization = $this->organization('south-network');
        $administrator = $this->user($organization, 'admin', 'North Admin');
        $customer = $this->user(null, 'client', 'Shared Customer');
        $otherCustomer = $this->user(null, 'client', 'Other Customer');
        $this->user(null, 'client', 'Customer Without Sessions');
        $firstStation = $this->station($organization, 'CT-NORTH-001');
        $secondStation = $this->station($organization, 'CT-NORTH-002');
        $otherStation = $this->station($otherOrganization, 'CT-SOUTH-001');

        $this->createSession($organization, $customer, $firstStation, 'SES-NORTH-PAID', 12.5, 4200, 'paid');
        $this->createSession($organization, $customer, $secondStation, 'SES-NORTH-UNPAID', 4.5, 2300, 'unpaid');
        $this->createSession($otherOrganization, $customer, $otherStation, 'SES-SOUTH-SHARED', 30, 9000, 'paid');
        $this->createSession($otherOrganization, $otherCustomer, $otherStation, 'SES-SOUTH-ONLY', 8, 3000, 'paid');
        Sanctum::actingAs($administrator);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.activity.sessions', 2)
            ->assertJsonPath('data.0.activity.stations', 2)
            ->assertJsonPath('data.0.activity.energy_kwh', 17)
            ->assertJsonPath('data.0.activity.paid_millimes', 4200)
            ->assertJsonPath('data.0.activity.outstanding_millimes', 2300)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.sessions', 2)
            ->assertJsonPath('summary.energy_kwh', 17)
            ->assertJsonPath('summary.revenue_millimes', 4200)
            ->assertJsonMissing(['id' => $otherCustomer->id]);
    }

    public function test_customer_details_only_include_sessions_from_the_administrators_organization(): void
    {
        $organization = $this->organization('detail-network');
        $otherOrganization = $this->organization('external-network');
        $administrator = $this->user($organization, 'admin', 'Detail Admin');
        $customer = $this->user(null, 'client', 'Visible Customer');
        $unrelatedCustomer = $this->user(null, 'client', 'Hidden Customer');
        $station = $this->station($organization, 'CT-DETAIL-001');
        $otherStation = $this->station($otherOrganization, 'CT-EXTERNAL-001');
        $this->createSession($organization, $customer, $station, 'SES-DETAIL-VISIBLE', 9, 3500, 'paid');
        $this->createSession($otherOrganization, $customer, $otherStation, 'SES-DETAIL-HIDDEN', 20, 7000, 'paid');
        $this->createSession($otherOrganization, $unrelatedCustomer, $otherStation, 'SES-UNRELATED', 5, 1800, 'paid');
        Sanctum::actingAs($administrator);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_sessions')
            ->assertJsonPath('data.recent_sessions.0.reference', 'SES-DETAIL-VISIBLE')
            ->assertJsonMissing(['reference' => 'SES-DETAIL-HIDDEN']);

        $this->getJson("/api/customers/{$unrelatedCustomer->id}")->assertForbidden();
    }

    public function test_administrator_can_filter_and_export_organization_customers(): void
    {
        $organization = $this->organization('export-network');
        $administrator = $this->user($organization, 'admin', 'Export Admin');
        $station = $this->station($organization, 'CT-EXPORT-001');
        $selectedCustomer = $this->user(null, 'client', 'Selected Customer');
        $otherCustomer = $this->user(null, 'client', 'Different Customer');
        $this->createSession($organization, $selectedCustomer, $station, 'SES-EXPORT-ONE', 10, 4000, 'paid');
        $this->createSession($organization, $otherCustomer, $station, 'SES-EXPORT-TWO', 6, 2400, 'paid');
        Sanctum::actingAs($administrator);

        $this->getJson('/api/customers/export?format=json&search=selected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Selected Customer')
            ->assertJsonPath('data.0.sessions', 1);
    }

    public function test_operator_cannot_access_customer_management(): void
    {
        $organization = $this->organization('operator-network');
        $operator = $this->user($organization, 'operator', 'Network Operator');
        Sanctum::actingAs($operator);

        $this->getJson('/api/customers')->assertForbidden();
        $this->getJson('/api/customers/export?format=json')->assertForbidden();
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function user(?Organization $organization, string $role, string $name): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization?->id,
            'name' => $name,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function station(Organization $organization, string $reference): Station
    {
        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => $reference,
            'reference' => $reference,
            'location_name' => 'Test location',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test Charger',
            'manufacturer' => 'ChargeTrackr',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
    }

    private function createSession(
        Organization $organization,
        User $customer,
        Station $station,
        string $reference,
        float $energy,
        int $total,
        string $paymentStatus,
    ): ChargingSession {
        return ChargingSession::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $customer->id,
            'station_id' => $station->id,
            'reference' => $reference,
            'client_name' => $customer->name,
            'station_name' => $station->name,
            'connector_external_id' => 'A1',
            'status' => 'completed',
            'payment_status' => $paymentStatus,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addMinutes(30),
            'duration_seconds' => 1800,
            'meter_start_kwh' => 0,
            'meter_stop_kwh' => $energy,
            'energy_kwh' => $energy,
            'price_per_kwh_millimes' => 300,
            'session_fee_millimes' => 500,
            'total_millimes' => $total,
            'currency' => 'TND',
        ]);
    }
}
