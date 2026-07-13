<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertInterventionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_operator_only_sees_alerts_from_their_organization(): void
    {
        $organization = $this->organization('operator-network');
        $operator = $this->user($organization, 'operator');
        $visible = $this->alert($this->station($organization, 'CT-ALERT-001'), 'ALT-TEST-001');
        $otherOrganization = $this->organization('other-network');
        $this->alert($this->station($otherOrganization, 'CT-ALERT-002'), 'ALT-TEST-002');
        Sanctum::actingAs($operator);

        $this->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_technician_only_sees_alerts_assigned_to_them(): void
    {
        $organization = $this->organization('technician-network');
        $technician = $this->user($organization, 'technician');
        $otherTechnician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-TECH-ALERT-001');
        $visible = $this->alert($station, 'ALT-TECH-001', $technician);
        $this->alert($station, 'ALT-TECH-002', $otherTechnician);
        Sanctum::actingAs($technician);

        $this->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_operator_can_assign_an_alert_and_create_an_intervention(): void
    {
        $organization = $this->organization('workflow-network');
        $operator = $this->user($organization, 'operator');
        $technician = $this->user($organization, 'technician');
        $alert = $this->alert($this->station($organization, 'CT-WORKFLOW-001'), 'ALT-WORKFLOW-001');
        Sanctum::actingAs($operator);

        $this->patchJson("/api/alerts/{$alert->id}", ['assigned_technician_id' => $technician->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_technician.id', $technician->id);

        $this->postJson("/api/alerts/{$alert->id}/interventions", [
            'assigned_technician_id' => $technician->id,
            'estimated_duration_minutes' => 90,
        ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_technician.id', $technician->id)
            ->assertJsonPath('data.status', 'assigned');
    }

    public function test_technician_can_progress_their_intervention_but_not_another_technicians(): void
    {
        $organization = $this->organization('field-network');
        $technician = $this->user($organization, 'technician');
        $otherTechnician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-FIELD-001');
        $ownAlert = $this->alert($station, 'ALT-FIELD-001', $technician);
        $otherAlert = $this->alert($station, 'ALT-FIELD-002', $otherTechnician);
        $own = $this->intervention($ownAlert, $technician, 'INT-FIELD-001');
        $other = $this->intervention($otherAlert, $otherTechnician, 'INT-FIELD-002');
        Sanctum::actingAs($technician);

        $this->patchJson("/api/interventions/{$own->id}", ['status' => 'in-progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in-progress');

        $this->patchJson("/api/interventions/{$other->id}", ['status' => 'in-progress'])
            ->assertForbidden();
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
            'name' => 'Test Station',
            'reference' => $reference,
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'faulted',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
    }

    private function alert(Station $station, string $reference, ?User $technician = null): Alert
    {
        return Alert::query()->create([
            'organization_id' => $station->organization_id,
            'station_id' => $station->id,
            'assigned_technician_id' => $technician?->id,
            'reference' => $reference,
            'title' => 'Station fault',
            'problem_type' => 'Heartbeat timeout',
            'severity' => 'critical',
            'status' => 'new',
            'description' => 'Test alert description',
            'detected_at' => now(),
        ]);
    }

    private function intervention(Alert $alert, User $technician, string $reference): Intervention
    {
        return Intervention::query()->create([
            'organization_id' => $alert->organization_id,
            'alert_id' => $alert->id,
            'station_id' => $alert->station_id,
            'assigned_technician_id' => $technician->id,
            'reference' => $reference,
            'status' => 'assigned',
            'priority' => $alert->severity,
            'problem' => $alert->description,
        ]);
    }
}
