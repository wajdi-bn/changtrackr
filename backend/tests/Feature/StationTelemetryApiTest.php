<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppEvent;
use App\Models\OcppMeterSample;
use App\Models\OcppTransaction;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StationTelemetryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_receives_real_session_and_ocpp_power_series_for_their_station(): void
    {
        [$admin, $organization] = $this->userWithRole('admin', 'telemetry-network');
        $station = $this->station($organization, 'CT-TELEMETRY-001');
        $client = User::factory()->create(['status' => 'active']);
        $this->chargingSession($organization, $station, $client, 'SESSION-TODAY', now()->subHour(), 12.345, 9876, 'paid');
        $this->chargingSession($organization, $station, $client, 'SESSION-YESTERDAY', now()->subDay()->subHour(), 4.5, 2500, 'unpaid');
        $this->chargingSession($organization, $station, $client, 'SESSION-OLD', now()->subDays(10), 99, 99000, 'paid');
        $this->powerSamples($organization, $station);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/stations/{$station->id}/telemetry?days=7")
            ->assertOk()
            ->assertJsonPath('data.window.days', 7)
            ->assertJsonPath('data.summary.sessions', 2)
            ->assertJsonPath('data.summary.energy_kwh', 16.845)
            ->assertJsonPath('data.summary.revenue_millimes', 9876)
            ->assertJsonPath('data.summary.power_points', 2)
            ->assertJsonPath('data.summary.latest_power_kw', 22.5)
            ->assertJsonPath('data.sources.daily', 'charging_sessions')
            ->assertJsonPath('data.sources.power', 'ocpp_meter_values')
            ->assertJsonPath('data.sources.financials_visible', true);

        $this->assertEquals(
            [18.0, 22.5],
            collect($response->json('data.power'))->pluck('power_kw')->all(),
        );
        $this->assertCount(7, $response->json('data.daily'));
    }

    public function test_telemetry_is_isolated_by_station_policy(): void
    {
        [$operator] = $this->userWithRole('operator', 'first-network');
        [, $otherOrganization] = $this->userWithRole('operator', 'second-network');
        $otherStation = $this->station($otherOrganization, 'CT-PRIVATE-001');
        Sanctum::actingAs($operator);

        $this->getJson("/api/stations/{$otherStation->id}/telemetry")->assertForbidden();
    }

    public function test_technician_can_view_operational_telemetry_without_financial_values(): void
    {
        [$technician, $organization] = $this->userWithRole('technician', 'field-network');
        $station = $this->station($organization, 'CT-FIELD-001');
        $client = User::factory()->create(['status' => 'active']);
        $this->chargingSession($organization, $station, $client, 'SESSION-FIELD', now()->subHour(), 3.5, 3000, 'paid');
        Sanctum::actingAs($technician);

        $this->getJson("/api/stations/{$station->id}/telemetry?days=1")
            ->assertOk()
            ->assertJsonPath('data.summary.energy_kwh', 3.5)
            ->assertJsonPath('data.summary.revenue_millimes', null)
            ->assertJsonPath('data.sources.financials_visible', false)
            ->assertJsonPath('data.daily.0.revenue_millimes', null);
    }

    /** @return array{User, Organization} */
    private function userWithRole(string $role, string $slug): array
    {
        $organization = Organization::query()->create([
            'name' => str($slug)->headline(),
            'slug' => $slug,
            'status' => 'active',
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $user->assignRole($role);

        return [$user, $organization];
    }

    private function station(Organization $organization, string $reference): Station
    {
        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => str($reference)->headline(),
            'reference' => $reference,
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Telemetry test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test model',
            'manufacturer' => 'Test manufacturer',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
    }

    private function chargingSession(
        Organization $organization,
        Station $station,
        User $client,
        string $reference,
        mixed $startedAt,
        float $energy,
        int $total,
        string $paymentStatus,
    ): void {
        ChargingSession::query()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'station_id' => $station->id,
            'reference' => $reference,
            'client_name' => $client->name,
            'station_name' => $station->name,
            'connector_external_id' => 'A1',
            'status' => 'completed',
            'payment_status' => $paymentStatus,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHour(),
            'duration_seconds' => 3600,
            'meter_start_kwh' => 100,
            'meter_stop_kwh' => 100 + $energy,
            'energy_kwh' => $energy,
            'price_per_kwh_millimes' => 700,
            'total_millimes' => $total,
            'currency' => 'TND',
        ]);
    }

    private function powerSamples(Organization $organization, Station $station): void
    {
        $startEvent = $this->event($organization, $station, 'StartTransaction', 'start');
        $transaction = OcppTransaction::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'start_event_id' => $startEvent->id,
            'id_tag_hash' => hash('sha256', 'test-tag'),
            'id_tag_masked' => 'TEST-***',
            'status' => 'active',
            'meter_start_wh' => 100000,
            'last_meter_wh' => 100000,
            'started_at' => now()->subMinutes(10),
        ]);

        $samples = [
            [$this->event($organization, $station, 'MeterValues', 'meter-1'), 0, now()->subMinutes(5), 18000, 'W'],
            [$this->event($organization, $station, 'MeterValues', 'meter-2'), 0, now()->subMinute(), 22.5, 'kW'],
        ];

        foreach ($samples as [$event, $index, $sampledAt, $value, $unit]) {
            OcppMeterSample::query()->create([
                'organization_id' => $organization->id,
                'station_id' => $station->id,
                'ocpp_transaction_id' => $transaction->id,
                'ocpp_event_id' => $event->id,
                'sample_index' => $index,
                'sampled_at' => $sampledAt,
                'value' => $value,
                'measurand' => 'Power.Active.Import',
                'unit' => $unit,
            ]);
        }
    }

    private function event(Organization $organization, Station $station, string $action, string $message): OcppEvent
    {
        return OcppEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'message_id' => $message.'-'.Str::random(8),
            'protocol_version' => '1.6',
            'action' => $action,
            'payload' => [],
            'payload_hash' => hash('sha256', $message.Str::random()),
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }
}
