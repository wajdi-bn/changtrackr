<?php

namespace Tests\Feature;

use App\Events\StationAvailabilityChanged;
use App\Models\Alert;
use App\Models\AvailabilityTransition;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\Station;
use App\Services\Availability\AvailabilityProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AvailabilityProjectionTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityProjectionService $projector;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('availability.communication_timeout_seconds', 90);
        $this->projector = app(AvailabilityProjectionService::class);
    }

    public function test_station_is_available_when_at_least_one_connector_is_free(): void
    {
        $station = $this->managedStation();
        $available = $this->connector($station, 1, 'Available');
        $faulted = $this->connector($station, 2, 'Faulted', 'ConnectorLockFailure');

        $this->projector->project($station);

        $this->assertSame('available', $station->fresh()->status);
        $this->assertSame('available', $available->fresh()->status);
        $this->assertSame('faulted', $faulted->fresh()->status);
        $this->assertDatabaseHas('alerts', [
            'deduplication_key' => "availability:connector:{$faulted->id}:faulted",
            'status' => 'new',
        ]);
    }

    public function test_station_is_charging_when_no_connector_is_free_and_one_is_active(): void
    {
        $station = $this->managedStation();
        $this->connector($station, 1, 'Charging');
        $this->connector($station, 2, 'Unavailable');

        $this->projector->project($station);

        $station->refresh();
        $this->assertSame('charging', $station->status);
        $this->assertSame('all_connectors_occupied', $station->availability_reason);
    }

    public function test_station_is_faulted_when_no_connector_is_usable_due_to_errors(): void
    {
        $station = $this->managedStation();
        $this->connector($station, 1, 'Faulted', 'GroundFailure');
        $this->connector($station, 2, 'Faulted', 'OverCurrentFailure');

        $this->projector->project($station);

        $station->refresh();
        $this->assertSame('faulted', $station->status);
        $this->assertSame('no_usable_connector', $station->availability_reason);
        $this->assertSame(2, Alert::query()->where('source', 'availability_engine')->count());
    }

    public function test_manual_override_has_priority_over_connectivity_and_ocpp_status(): void
    {
        $station = $this->managedStation([
            'availability_override' => 'maintenance',
            'ocpp_disconnected_at' => now(),
        ]);
        $connector = $this->connector($station, 1, 'Available');

        $this->projector->project($station);

        $station->refresh();
        $this->assertSame('maintenance', $station->status);
        $this->assertSame('planned_maintenance', $station->availability_reason);
        $this->assertSame('maintenance', $connector->fresh()->status);

        $station->update(['availability_override' => 'disabled']);
        $this->projector->project($station);

        $station->refresh();
        $this->assertSame('unavailable', $station->status);
        $this->assertSame('manually_disabled', $station->availability_reason);
    }

    public function test_three_missed_heartbeats_mark_station_offline_and_create_one_alert(): void
    {
        $station = $this->managedStation([
            'last_heartbeat_at' => now()->subSeconds(91),
            'ocpp_last_message_at' => now()->subSeconds(91),
            'ocpp_connected_at' => now()->subSeconds(91),
        ]);
        $this->connector($station, 1, 'Available');

        $this->projector->project($station);
        $this->projector->project($station);

        $station->refresh();
        $this->assertSame('offline', $station->status);
        $this->assertSame('communication_timeout', $station->availability_reason);
        $this->assertSame(1, Alert::query()->where('deduplication_key', "availability:station:{$station->id}:communication")->count());
        $this->assertSame(1, $station->open_alerts_count);
    }

    public function test_connectivity_recovery_resolves_alert_and_restores_projected_status(): void
    {
        $station = $this->managedStation([
            'last_heartbeat_at' => now()->subSeconds(91),
            'ocpp_last_message_at' => now()->subSeconds(91),
            'ocpp_connected_at' => now()->subSeconds(91),
        ]);
        $this->connector($station, 1, 'Available');
        $this->projector->project($station);

        $station->update([
            'last_heartbeat_at' => now(),
            'ocpp_last_message_at' => now(),
            'ocpp_connected_at' => now(),
            'ocpp_disconnected_at' => null,
        ]);
        $this->projector->project($station);

        $station->refresh();
        $alert = Alert::query()->where('deduplication_key', "availability:station:{$station->id}:communication")->firstOrFail();
        $this->assertSame('available', $station->status);
        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame(0, $station->open_alerts_count);
        $this->assertDatabaseHas('alert_events', ['alert_id' => $alert->id, 'event_type' => 'auto_resolved']);
    }

    public function test_projection_records_auditable_transitions_and_only_broadcasts_changes(): void
    {
        Event::fake([StationAvailabilityChanged::class]);
        $station = $this->managedStation();
        $this->connector($station, 1, 'Available');

        $this->projector->project($station);
        $transitionCount = AvailabilityTransition::query()->count();
        $this->projector->project($station);

        $this->assertSame(2, $transitionCount);
        $this->assertSame($transitionCount, AvailabilityTransition::query()->count());
        Event::assertDispatchedTimes(StationAvailabilityChanged::class, 1);
    }

    public function test_unmanaged_station_keeps_its_manually_defined_status(): void
    {
        $station = $this->managedStation([
            'ocpp_auth_secret_hash' => null,
            'status' => 'maintenance',
        ]);
        $connector = $this->connector($station, 1, 'Available', businessStatus: 'offline');

        $result = $this->projector->project($station);

        $this->assertFalse($result['changed']);
        $this->assertSame('maintenance', $station->fresh()->status);
        $this->assertSame('offline', $connector->fresh()->status);
        $this->assertDatabaseCount('availability_transitions', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function managedStation(array $overrides = []): Station
    {
        $organization = Organization::query()->create([
            'name' => 'Availability Network',
            'slug' => 'availability-'.uniqid(),
            'status' => 'active',
        ]);

        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Availability Station',
            'reference' => 'CT-AVL-'.uniqid(),
            'ocpp_identity' => 'CT-AVL-'.uniqid(),
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'offline',
            'max_power_kw' => 120,
            'model' => 'Simulator',
            'manufacturer' => 'ChargeTrackr',
            'ocpp_version' => 'OCPP 1.6J',
            'ocpp_auth_secret_hash' => Hash::make('station-secret-0123456789abcdef'),
            'availability_monitoring_started_at' => now(),
            'ocpp_connected_at' => now(),
            'ocpp_last_message_at' => now(),
            'last_heartbeat_at' => now(),
            ...$overrides,
        ]);
    }

    private function connector(
        Station $station,
        int $ocppId,
        string $ocppStatus,
        string $errorCode = 'NoError',
        string $businessStatus = 'offline',
    ): Connector {
        return Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => "C{$ocppId}",
            'ocpp_connector_id' => $ocppId,
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => $businessStatus,
            'ocpp_status' => $ocppStatus,
            'ocpp_error_code' => $errorCode,
            'ocpp_last_status_at' => now(),
        ]);
    }
}
