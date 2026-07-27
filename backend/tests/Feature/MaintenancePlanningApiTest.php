<?php

namespace Tests\Feature;

use App\Jobs\GenerateMaintenanceOccurrences;
use App\Models\Intervention;
use App\Models\OcppCommand;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Services\Maintenance\MaintenancePlanService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenancePlanningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_admin_can_plan_recurring_maintenance_and_occurrences_are_idempotent(): void
    {
        Queue::fake();
        $organization = $this->organization('maintenance-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-MNT-001');
        Sanctum::actingAs($admin);
        $first = now()->addDay()->startOfHour();

        $response = $this->postJson('/api/maintenances', [
            'station_id' => $station->id,
            'assigned_technician_id' => $technician->id,
            'title' => 'Quarterly safety inspection',
            'type' => 'preventive',
            'priority' => 'warning',
            'instructions' => 'Inspect the enclosure, cable, connector pins and emergency stop.',
            'first_scheduled_at' => $first->toISOString(),
            'estimated_duration_minutes' => 90,
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_ends_at' => $first->copy()->addWeeks(3)->toISOString(),
        ])->assertCreated()
            ->assertJsonPath('data.source', 'maintenance')
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.maintenance_plan.type', 'preventive')
            ->assertJsonPath('data.assigned_technician.id', $technician->id);

        $planId = $response->json('plan.id');
        Queue::assertPushed(GenerateMaintenanceOccurrences::class, fn ($job) => $job->maintenancePlanId === $planId);
        $this->assertDatabaseHas('interventions', [
            'maintenance_plan_id' => $planId,
            'maintenance_occurrence_number' => 1,
            'alert_id' => null,
        ]);

        $service = app(MaintenancePlanService::class);
        $this->assertSame(3, $service->generateUpcoming($planId, 40));
        $this->assertSame(0, $service->generateUpcoming($planId, 40));
        $this->assertSame(4, Intervention::query()->where('maintenance_plan_id', $planId)->count());
    }

    public function test_organization_scope_is_enforced_for_station_connector_and_technician(): void
    {
        Queue::fake();
        $organization = $this->organization('scoped-network');
        $otherOrganization = $this->organization('external-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($otherOrganization, 'technician');
        $station = $this->station($otherOrganization, 'CT-MNT-HIDDEN');
        Sanctum::actingAs($admin);

        $this->postJson('/api/maintenances', [
            'station_id' => $station->id,
            'assigned_technician_id' => $technician->id,
            'title' => 'Unauthorized maintenance',
            'type' => 'corrective',
            'priority' => 'critical',
            'instructions' => 'This request must not cross organization boundaries.',
            'first_scheduled_at' => now()->addDay()->toISOString(),
            'estimated_duration_minutes' => 60,
            'recurrence_frequency' => 'none',
            'recurrence_interval' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('station_id');

        $this->assertDatabaseCount('maintenance_plans', 0);
    }

    public function test_technician_starts_and_completes_maintenance_with_station_state_synchronization(): void
    {
        Queue::fake();
        $organization = $this->organization('lifecycle-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-MNT-LIFECYCLE');
        $occurrence = $this->createPlan($admin, $technician, $station);

        Sanctum::actingAs($technician);
        $this->patchJson("/api/interventions/{$occurrence->id}", ['status' => 'in-progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in-progress');

        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'status' => 'maintenance',
            'availability_override' => 'maintenance',
            'maintenance_intervention_id' => $occurrence->id,
        ]);
        $this->assertDatabaseHas('intervention_events', [
            'intervention_id' => $occurrence->id,
            'event_type' => 'maintenance_mode_enabled',
        ]);

        $this->submitReport($occurrence);

        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'status' => 'available',
            'availability_override' => null,
            'maintenance_intervention_id' => null,
        ]);
        $this->assertDatabaseHas('maintenance_plans', [
            'id' => $occurrence->maintenance_plan_id,
            'status' => 'completed',
        ]);
    }

    public function test_only_assigned_technician_can_execute_but_manager_can_reschedule_and_cancel(): void
    {
        Queue::fake();
        $organization = $this->organization('permissions-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($organization, 'technician');
        $otherTechnician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-MNT-PERMISSIONS');
        $occurrence = $this->createPlan($admin, $technician, $station);

        Sanctum::actingAs($admin);
        $newDate = now()->addDays(2)->startOfHour();
        $this->patchJson("/api/maintenances/{$occurrence->id}", ['scheduled_at' => $newDate->toISOString()])
            ->assertOk()
            ->assertJsonPath('data.scheduled_at', $newDate->toISOString());
        $this->assertDatabaseHas('platform_audit_logs', [
            'organization_id' => $organization->id,
            'event_type' => 'maintenance.rescheduled',
            'subject_id' => $occurrence->id,
        ]);
        $this->patchJson("/api/interventions/{$occurrence->id}", ['status' => 'in-progress'])->assertForbidden();

        Sanctum::actingAs($otherTechnician);
        $this->patchJson("/api/interventions/{$occurrence->id}", ['status' => 'in-progress'])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/interventions/{$occurrence->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_ocpp_maintenance_queues_inoperative_then_operative_commands(): void
    {
        Queue::fake();
        $organization = $this->organization('ocpp-maintenance-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-MNT-OCPP');
        $station->update([
            'ocpp_auth_secret_hash' => hash('sha256', 'station-secret'),
            'ocpp_identity' => 'CT-MNT-OCPP',
            'ocpp_registration_status' => 'accepted',
            'ocpp_connected_at' => now(),
            'ocpp_last_message_at' => now(),
            'last_heartbeat_at' => now(),
            'ocpp_status' => 'Available',
        ]);
        $occurrence = $this->createPlan($admin, $technician, $station);

        Sanctum::actingAs($technician);
        $this->patchJson("/api/interventions/{$occurrence->id}", ['status' => 'in-progress'])->assertOk();

        $inoperative = OcppCommand::query()->where('station_id', $station->id)->where('action', 'ChangeAvailability')->latest('id')->firstOrFail();
        $this->assertSame(['connectorId' => 0, 'type' => 'Inoperative'], $inoperative->encrypted_payload);
        $inoperative->update(['status' => 'accepted', 'responded_at' => now()]);

        $this->submitReport($occurrence);

        $operative = OcppCommand::query()->where('station_id', $station->id)->where('action', 'ChangeAvailability')->latest('id')->firstOrFail();
        $this->assertNotSame($inoperative->id, $operative->id);
        $this->assertSame(['connectorId' => 0, 'type' => 'Operative'], $operative->encrypted_payload);
        $this->assertNull($station->fresh()->maintenance_intervention_id);
    }

    public function test_technician_and_admin_lists_are_scoped_and_station_filter_is_supported(): void
    {
        Queue::fake();
        $organization = $this->organization('list-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($organization, 'technician');
        $otherTechnician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-MNT-LIST-1');
        $otherStation = $this->station($organization, 'CT-MNT-LIST-2');
        $visible = $this->createPlan($admin, $technician, $station);
        $this->createPlan($admin, $otherTechnician, $otherStation);

        Sanctum::actingAs($technician);
        $this->getJson('/api/maintenances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonCount(0, 'technicians');

        Sanctum::actingAs($admin);
        $this->getJson("/api/maintenances?station_id={$station->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonCount(2, 'technicians')
            ->assertJsonCount(2, 'stations');
    }

    private function createPlan(User $admin, User $technician, Station $station): Intervention
    {
        $result = app(MaintenancePlanService::class)->create([
            'station_id' => $station->id,
            'connector_id' => null,
            'assigned_technician_id' => $technician->id,
            'title' => 'Scheduled inspection',
            'type' => 'preventive',
            'priority' => 'info',
            'instructions' => 'Inspect all safety and charging components.',
            'first_scheduled_at' => now()->addDay()->toISOString(),
            'estimated_duration_minutes' => 45,
            'recurrence_frequency' => 'none',
            'recurrence_interval' => 1,
            'recurrence_ends_at' => null,
        ], $admin, $station->organization_id);

        return $result['occurrence'];
    }

    private function submitReport(Intervention $intervention): void
    {
        foreach (['before', 'after'] as $phase) {
            $this->post("/api/interventions/{$intervention->id}/photos", [
                'phase' => $phase,
                'photo' => UploadedFile::fake()->image($phase.'.jpg', 900, 700),
            ])->assertCreated();
        }

        $this->postJson("/api/interventions/{$intervention->id}/report", [
            'diagnosis' => 'The scheduled inspection confirmed that all charging and safety components operate normally.',
            'actions_taken' => 'The cabinet, connector, protections and communication link were inspected and tested.',
            'final_outcome' => 'operational',
            'observations' => 'No anomaly remains after the maintenance checks.',
            'parts' => [],
            'safety_checks' => [
                'work_area_safe' => true,
                'connector_inspected' => true,
                'station_status_verified' => true,
            ],
        ])->assertOk()->assertJsonPath('data.status', 'resolved');
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active']);
    }

    private function user(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function station(Organization $organization, string $reference): Station
    {
        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Maintenance Station '.$reference,
            'reference' => $reference,
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
    }
}
