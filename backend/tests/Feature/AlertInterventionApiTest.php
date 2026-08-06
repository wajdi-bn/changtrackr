<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $hidden = $this->alert($this->station($otherOrganization, 'CT-ALERT-002'), 'ALT-TEST-002');
        Sanctum::actingAs($operator);

        $this->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);

        $this->getJson("/api/alerts/{$hidden->id}")->assertForbidden();
        $this->patchJson("/api/alerts/{$hidden->id}", ['status' => 'resolved'])->assertForbidden();
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

    public function test_alert_index_can_be_scoped_to_one_station(): void
    {
        $organization = $this->organization('station-alert-filter');
        $operator = $this->user($organization, 'operator');
        $selectedStation = $this->station($organization, 'CT-ALERT-FILTER-001');
        $otherStation = $this->station($organization, 'CT-ALERT-FILTER-002');
        $visible = $this->alert($selectedStation, 'ALT-FILTER-001');
        $this->alert($otherStation, 'ALT-FILTER-002');
        Sanctum::actingAs($operator);

        $this->getJson("/api/alerts?station_id={$selectedStation->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.critical', 1);
    }

    public function test_alert_index_is_paginated_without_truncating_the_summary(): void
    {
        $organization = $this->organization('paginated-alert-network');
        $operator = $this->user($organization, 'operator');
        $station = $this->station($organization, 'CT-ALERT-PAGE-001');

        foreach (range(1, 12) as $index) {
            $this->alert($station, sprintf('ALT-PAGE-%03d', $index));
        }

        Sanctum::actingAs($operator);

        $this->getJson('/api/alerts?page=2&per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('summary.total', 12)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12);

        $this->getJson('/api/alerts?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
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
            ->assertJsonPath('data.assigned_technician.id', $technician->id)
            ->assertJsonPath('data.status', 'in-progress');

        $this->postJson("/api/alerts/{$alert->id}/interventions", [
            'assigned_technician_id' => $technician->id,
            'estimated_duration_minutes' => 90,
        ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_technician.id', $technician->id)
            ->assertJsonPath('data.status', 'assigned');
    }

    public function test_admin_manages_only_their_organization_alerts_and_can_cancel_an_intervention(): void
    {
        $organization = $this->organization('admin-workflow-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($organization, 'technician');
        $otherOrganization = $this->organization('external-workflow-network');
        $otherTechnician = $this->user($otherOrganization, 'technician');
        $alert = $this->alert($this->station($organization, 'CT-ADMIN-WORKFLOW'), 'ALT-ADMIN-WORKFLOW');
        $hidden = $this->alert($this->station($otherOrganization, 'CT-ADMIN-HIDDEN'), 'ALT-ADMIN-HIDDEN');
        Sanctum::actingAs($admin);

        $this->getJson('/api/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alert->id)
            ->assertJsonCount(1, 'technicians')
            ->assertJsonPath('technicians.0.id', $technician->id);

        $this->getJson("/api/alerts/{$hidden->id}")->assertForbidden();
        $this->patchJson("/api/alerts/{$alert->id}", ['assigned_technician_id' => $otherTechnician->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_technician_id');

        $interventionId = $this->postJson("/api/alerts/{$alert->id}/interventions", [
            'assigned_technician_id' => $technician->id,
            'scheduled_at' => now()->addDay()->toISOString(),
            'estimated_duration_minutes' => 60,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/interventions/{$interventionId}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.final_status', 'Cancelled');

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'status' => 'new',
            'assigned_technician_id' => null,
        ]);
        $this->assertDatabaseHas('alert_events', [
            'alert_id' => $alert->id,
            'event_type' => 'intervention_cancelled',
        ]);

        $this->postJson("/api/alerts/{$alert->id}/interventions", [
            'assigned_technician_id' => $technician->id,
            'estimated_duration_minutes' => 45,
        ])->assertCreated()->assertJsonPath('data.status', 'assigned');
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

    public function test_resolved_alert_cannot_be_assigned_or_receive_a_new_intervention(): void
    {
        $organization = $this->organization('resolved-alert-network');
        $admin = $this->user($organization, 'admin');
        $technician = $this->user($organization, 'technician');
        $alert = $this->alert($this->station($organization, 'CT-RESOLVED-ALERT'), 'ALT-RESOLVED');
        $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/alerts/{$alert->id}", ['assigned_technician_id' => $technician->id])
            ->assertForbidden();
        $this->postJson("/api/alerts/{$alert->id}/interventions", [
            'assigned_technician_id' => $technician->id,
        ])->assertForbidden();
    }

    public function test_operator_cannot_resolve_an_alert_while_its_intervention_is_active(): void
    {
        $organization = $this->organization('active-intervention-alert');
        $operator = $this->user($organization, 'operator');
        $technician = $this->user($organization, 'technician');
        $alert = $this->alert($this->station($organization, 'CT-ACTIVE-INTERVENTION'), 'ALT-ACTIVE');
        $this->intervention($alert, $technician, 'INT-ACTIVE');
        Sanctum::actingAs($operator);

        $this->patchJson("/api/alerts/{$alert->id}", ['status' => 'resolved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_operator_alert_uses_the_default_sla_when_no_due_date_is_given(): void
    {
        $now = Carbon::parse('2026-07-22 09:00:00');
        Carbon::setTestNow($now);
        try {
            $organization = $this->organization('default-sla-alert');
            $operator = $this->user($organization, 'operator');
            $station = $this->station($organization, 'CT-DEFAULT-SLA');
            Sanctum::actingAs($operator);

            $alertId = $this->postJson('/api/alerts', [
                'station_id' => $station->id,
                'title' => 'Connector needs inspection',
                'problem_type' => 'Connector warning',
                'severity' => 'warning',
                'description' => 'A technician should inspect the connector.',
                'source' => 'operator',
            ])->assertCreated()->json('data.id');

            $alert = Alert::query()->findOrFail($alertId);
            $this->assertTrue($alert->due_at->equalTo($now->copy()->addHour()));
        } finally {
            Carbon::setTestNow();
        }
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
