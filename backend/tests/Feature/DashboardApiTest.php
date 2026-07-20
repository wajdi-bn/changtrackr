<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AvailabilityTransition;
use App\Models\ChargingSession;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_dashboard_aggregates_only_its_organization(): void
    {
        $organization = $this->organization('dashboard-admin');
        $other = $this->organization('dashboard-other');
        $admin = $this->user('admin', $organization);
        $client = $this->user('client');
        $ownStation = $this->station($organization, 'DASH-OWN', 'available', 'Tunis');
        $otherStation = $this->station($other, 'DASH-OTHER', 'offline', 'Sousse');
        $ownSession = $this->chargingSession($organization, $ownStation, $client, 'OWN-SESSION', now()->subDay(), 18.5, 15000);
        $otherSession = $this->chargingSession($other, $otherStation, $client, 'OTHER-SESSION', now()->subDay(), 90, 90000);
        $this->payment($organization, $ownSession, $client, 'OWN-PAYMENT', 15000);
        $this->payment($other, $otherSession, $client, 'OTHER-PAYMENT', 90000);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.period.days', 7)
            ->assertJsonCount(6, 'data.kpis')
            ->assertJsonCount(7, 'data.trend.points')
            ->assertJsonPath('data.kpis.1.key', 'customers')
            ->assertJsonPath('data.kpis.1.value', 1)
            ->assertJsonPath('data.kpis.2.value', 100)
            ->assertJsonPath('data.kpis.3.value', 15)
            ->assertJsonPath('data.widgets.organization.name', (string) $organization->name);

        $json = $response->json('data');
        $this->assertStringNotContainsString('OTHER', json_encode($json));
        $this->assertTrue(collect($json['breakdowns'])->contains('key', 'users_by_role'));
        $this->assertSame('Top clients', $json['rankings'][0]['title']);
        $this->assertSame(15, $json['rankings'][0]['items'][0]['value']);
    }

    public function test_operator_dashboard_uses_live_operational_counts(): void
    {
        $organization = $this->organization('dashboard-operator');
        $operator = $this->user('operator', $organization);
        $available = $this->station($organization, 'DASH-AVAILABLE', 'available');
        $offline = $this->station($organization, 'DASH-OFFLINE', 'offline');
        $client = $this->user('client');
        $this->chargingSession($organization, $available, $client, 'ACTIVE-SESSION', now(), 1.2, 1000, 'charging');
        $this->alert($organization, $offline, null, 'OPEN-ALERT', 'critical', 'new');
        Sanctum::actingAs($operator);

        $response = $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'operator')
            ->assertJsonCount(6, 'data.kpis')
            ->assertJsonPath('data.kpis.0.value', 50)
            ->assertJsonPath('data.kpis.2.value', 1)
            ->assertJsonPath('data.kpis.3.value', 1)
            ->assertJsonPath('data.kpis.4.value', 1.2)
            ->assertJsonPath('data.kpis.5.value', 1);

        $this->assertArrayHasKey('availability_percent', $response->json('data.trend.points.0'));
    }

    public function test_operator_period_availability_is_reconstructed_from_transitions(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 20)->startOfDay());
        $organization = $this->organization('dashboard-availability-history');
        $operator = $this->user('operator', $organization);
        $station = $this->station($organization, 'DASH-HISTORY', 'available');
        AvailabilityTransition::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'from_status' => 'available',
            'to_status' => 'offline',
            'from_reason' => 'connector_available',
            'to_reason' => 'communication_timeout',
            'source' => 'availability_engine',
            'occurred_at' => now()->subDays(4),
        ]);
        AvailabilityTransition::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'from_status' => 'offline',
            'to_status' => 'available',
            'from_reason' => 'communication_timeout',
            'to_reason' => 'connector_available',
            'source' => 'availability_engine',
            'occurred_at' => now()->subDays(2),
        ]);
        Sanctum::actingAs($operator);

        $this->getJson('/api/dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.kpis.0.value', 66.7)
            ->assertJsonPath('data.kpis.1.value', 48);

        $this->travelBack();
    }

    public function test_technician_dashboard_contains_only_assigned_work(): void
    {
        $organization = $this->organization('dashboard-technician');
        $technician = $this->user('technician', $organization);
        $otherTechnician = $this->user('technician', $organization);
        $station = $this->station($organization, 'DASH-TECH', 'faulted');
        $this->alert($organization, $station, $technician, 'TECH-ALERT', 'critical', 'new');
        $this->alert($organization, $station, $otherTechnician, 'OTHER-TECH-ALERT', 'critical', 'new');
        $this->intervention($organization, $station, $technician, 'TECH-RESOLVED', 'resolved', now()->subDay());
        $this->intervention($organization, $station, $otherTechnician, 'OTHER-RESOLVED', 'resolved', now()->subDay());
        Sanctum::actingAs($technician);

        $response = $this->getJson('/api/dashboard?period=30d')
            ->assertOk()
            ->assertJsonPath('data.role', 'technician')
            ->assertJsonCount(6, 'data.kpis')
            ->assertJsonPath('data.kpis.0.value', 1)
            ->assertJsonPath('data.kpis.1.value', 1)
            ->assertJsonPath('data.kpis.3.value', 1);

        $this->assertStringNotContainsString('OTHER-', json_encode($response->json('data')));
        $this->assertCount(1, $response->json('data.widgets.critical_alerts'));
    }

    public function test_client_dashboard_follows_the_client_across_organizations_without_leaking_other_clients(): void
    {
        $firstOrganization = $this->organization('dashboard-client-first');
        $secondOrganization = $this->organization('dashboard-client-second');
        $client = $this->user('client');
        $otherClient = $this->user('client');
        $firstStation = $this->station($firstOrganization, 'CLIENT-FIRST', 'available');
        $secondStation = $this->station($secondOrganization, 'CLIENT-SECOND', 'available');
        $firstSession = $this->chargingSession($firstOrganization, $firstStation, $client, 'CLIENT-SESSION-1', now()->subDays(2), 12.5, 8000);
        $secondSession = $this->chargingSession($secondOrganization, $secondStation, $client, 'CLIENT-SESSION-2', now()->subDay(), 7.5, 5000);
        $otherSession = $this->chargingSession($firstOrganization, $firstStation, $otherClient, 'OTHER-CLIENT-SESSION', now()->subDay(), 80, 80000);
        $this->payment($firstOrganization, $firstSession, $client, 'CLIENT-PAYMENT-1', 8000);
        $this->payment($secondOrganization, $secondSession, $client, 'CLIENT-PAYMENT-2', 5000);
        $this->payment($firstOrganization, $otherSession, $otherClient, 'OTHER-CLIENT-PAYMENT', 80000);
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonCount(6, 'data.kpis')
            ->assertJsonPath('data.kpis.1.value', 2)
            ->assertJsonPath('data.kpis.2.value', 20)
            ->assertJsonPath('data.kpis.3.value', 13)
            ->assertJsonCount(2, 'data.widgets.recent_sessions');

        $this->assertStringNotContainsString('OTHER-CLIENT', json_encode($response->json('data')));
    }

    public function test_super_admin_sees_global_totals_and_period_is_validated(): void
    {
        $firstOrganization = $this->organization('dashboard-platform-first');
        $secondOrganization = $this->organization('dashboard-platform-second');
        $superAdmin = $this->user('super_admin');
        $client = $this->user('client');
        $firstStation = $this->station($firstOrganization, 'PLATFORM-FIRST', 'available');
        $secondStation = $this->station($secondOrganization, 'PLATFORM-SECOND', 'charging');
        $session = $this->chargingSession($firstOrganization, $firstStation, $client, 'PLATFORM-SESSION', now(), 25, 20000);
        $this->payment($firstOrganization, $session, $client, 'PLATFORM-PAYMENT', 20000);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/dashboard?period=90d')
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin')
            ->assertJsonPath('data.kpis.0.value', 2)
            ->assertJsonPath('data.kpis.2.value', 100)
            ->assertJsonPath('data.kpis.3.value', 20)
            ->assertJsonPath('data.widgets.module_counts.organizations', 2)
            ->assertJsonPath('data.widgets.module_counts.stations', 2)
            ->assertJsonCount(90, 'data.trend.points');

        $this->getJson('/api/dashboard?period=365d')->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => str($slug)->headline(), 'slug' => $slug, 'status' => 'active']);
    }

    private function user(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function station(Organization $organization, string $reference, string $status, string $city = 'Tunis'): Station
    {
        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => str($reference)->headline(),
            'reference' => $reference,
            'location_name' => $city.' Center',
            'city' => $city,
            'address' => 'Dashboard test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => $status,
            'availability_monitoring_started_at' => now()->startOfDay()->subDays(90),
            'max_power_kw' => 120,
            'model' => 'Test model',
            'manufacturer' => 'Test manufacturer',
        ]);
    }

    private function chargingSession(Organization $organization, Station $station, User $client, string $reference, mixed $startedAt, float $energy, int $total, string $status = 'completed'): ChargingSession
    {
        return ChargingSession::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'station_id' => $station->id,
            'reference' => $reference,
            'client_name' => $client->name,
            'station_name' => $station->name,
            'connector_external_id' => 'A1',
            'status' => $status,
            'payment_status' => $status === 'charging' ? 'unpaid' : 'paid',
            'started_at' => $startedAt,
            'ended_at' => $status === 'charging' ? null : $startedAt->copy()->addHour(),
            'duration_seconds' => $status === 'charging' ? 0 : 3600,
            'meter_start_kwh' => 100,
            'meter_stop_kwh' => $status === 'charging' ? null : 100 + $energy,
            'energy_kwh' => $energy,
            'price_per_kwh_millimes' => 700,
            'total_millimes' => $total,
            'currency' => 'TND',
        ]);
    }

    private function payment(Organization $organization, ChargingSession $session, User $client, string $reference, int $amount): Payment
    {
        return Payment::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $client->id,
            'charging_session_id' => $session->id,
            'reference' => $reference,
            'provider' => 'simulated',
            'method' => 'card',
            'status' => 'paid',
            'amount_millimes' => $amount,
            'currency' => 'TND',
            'idempotency_key' => fake()->uuid(),
            'provider_transaction_id' => fake()->uuid(),
            'paid_at' => now()->subHour(),
        ]);
    }

    private function alert(Organization $organization, Station $station, ?User $technician, string $reference, string $severity, string $status): Alert
    {
        return Alert::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'assigned_technician_id' => $technician?->id,
            'reference' => $reference,
            'title' => $reference,
            'problem_type' => 'Communication issue',
            'severity' => $severity,
            'status' => $status,
            'source' => 'test',
            'description' => 'Dashboard alert test.',
            'detected_at' => now()->subHour(),
            'due_at' => now()->addHour(),
        ]);
    }

    private function intervention(Organization $organization, Station $station, User $technician, string $reference, string $status, mixed $endedAt): Intervention
    {
        return Intervention::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'assigned_technician_id' => $technician->id,
            'reference' => $reference,
            'status' => $status,
            'priority' => 'high',
            'scheduled_at' => $endedAt->copy()->subHours(2),
            'started_at' => $endedAt->copy()->subHour(),
            'ended_at' => $endedAt,
            'estimated_duration_minutes' => 60,
            'problem' => 'Dashboard intervention test.',
        ]);
    }
}
