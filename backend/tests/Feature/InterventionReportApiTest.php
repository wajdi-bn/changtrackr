<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterventionReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_technician_cannot_bypass_the_final_report(): void
    {
        [$technician, $intervention] = $this->activeIntervention('report-required');
        Sanctum::actingAs($technician);

        $this->patchJson("/api/interventions/{$intervention->id}", ['status' => 'resolved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('interventions', ['id' => $intervention->id, 'status' => 'in-progress']);
        $this->assertDatabaseCount('intervention_reports', 0);
    }

    public function test_photos_are_private_and_limited_to_the_assigned_technician_scope(): void
    {
        [$technician, $intervention] = $this->activeIntervention('private-evidence');
        Sanctum::actingAs($technician);

        $response = $this->post("/api/interventions/{$intervention->id}/photos", [
            'phase' => 'before',
            'caption' => 'Connector before repair',
            'photo' => UploadedFile::fake()->image('before.jpg', 900, 700),
        ])->assertCreated()->assertJsonPath('data.photos.0.phase', 'before');
        $photoId = $response->json('data.photos.0.id');
        $path = $intervention->photos()->firstOrFail()->path;
        Storage::disk('local')->assertExists($path);

        $this->get("/api/interventions/{$intervention->id}/photos/{$photoId}")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $otherOrganization = $this->organization('external-evidence');
        Sanctum::actingAs($this->user($otherOrganization, 'technician'));
        $this->get("/api/interventions/{$intervention->id}/photos/{$photoId}")->assertForbidden();
    }

    public function test_report_requires_before_and_after_evidence(): void
    {
        [$technician, $intervention] = $this->activeIntervention('evidence-required');
        Sanctum::actingAs($technician);
        $this->uploadPhoto($intervention, 'before');

        $this->postJson("/api/interventions/{$intervention->id}/report", $this->reportPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos');
    }

    public function test_final_report_completes_the_intervention_and_becomes_immutable(): void
    {
        [$technician, $intervention, $alert] = $this->activeIntervention('complete-report');
        Sanctum::actingAs($technician);
        $beforeId = $this->uploadPhoto($intervention, 'before');
        $this->uploadPhoto($intervention, 'after');

        $this->postJson("/api/interventions/{$intervention->id}/report", $this->reportPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.report.final_outcome', 'operational')
            ->assertJsonPath('data.report.submitted_by.id', $technician->id)
            ->assertJsonCount(2, 'data.photos');

        $this->assertDatabaseHas('intervention_reports', [
            'intervention_id' => $intervention->id,
            'final_outcome' => 'operational',
        ]);
        $this->assertDatabaseHas('alerts', ['id' => $alert->id, 'status' => 'resolved']);

        $admin = $this->user(Organization::query()->findOrFail($intervention->organization_id), 'admin');
        Sanctum::actingAs($admin);
        $this->getJson("/api/interventions/{$intervention->id}")
            ->assertOk()
            ->assertJsonPath('data.report.diagnosis', $this->reportPayload()['diagnosis'])
            ->assertJsonCount(2, 'data.photos');
        $this->get("/api/interventions/{$intervention->id}/photos/{$beforeId}")->assertOk();

        $this->patchJson("/api/interventions/{$intervention->id}", ['comments' => 'Attempted edit'])
            ->assertUnprocessable();
        $this->deleteJson("/api/interventions/{$intervention->id}/photos/{$beforeId}")
            ->assertForbidden();
        $this->assertDatabaseCount('intervention_reports', 1);
        $this->assertDatabaseCount('intervention_photos', 2);
    }

    public function test_follow_up_outcome_returns_the_alert_to_the_assignment_queue(): void
    {
        [$technician, $intervention, $alert] = $this->activeIntervention('follow-up-report');
        Sanctum::actingAs($technician);
        $this->uploadPhoto($intervention, 'before');
        $this->uploadPhoto($intervention, 'after');
        $payload = $this->reportPayload();
        $payload['final_outcome'] = 'follow-up-required';
        $payload['observations'] = 'A replacement power module must be ordered before another intervention.';

        $this->postJson("/api/interventions/{$intervention->id}/report", $payload)
            ->assertOk()
            ->assertJsonPath('data.report.final_outcome', 'follow-up-required');

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'status' => 'new',
            'assigned_technician_id' => null,
        ]);
        $this->assertDatabaseHas('alert_events', ['alert_id' => $alert->id, 'event_type' => 'follow_up_required']);
    }

    /** @return array{User, Intervention, Alert} */
    private function activeIntervention(string $slug): array
    {
        $organization = $this->organization($slug);
        $technician = $this->user($organization, 'technician');
        $station = $this->station($organization, 'CT-'.strtoupper(substr($slug, 0, 12)));
        $alert = Alert::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'assigned_technician_id' => $technician->id,
            'reference' => 'ALT-'.strtoupper($slug),
            'title' => 'Connector fault',
            'problem_type' => 'Connector lock failure',
            'severity' => 'critical',
            'status' => 'in-progress',
            'description' => 'The connector lock does not confirm its mechanical position.',
            'detected_at' => now()->subHour(),
        ]);
        $intervention = Intervention::query()->create([
            'organization_id' => $organization->id,
            'alert_id' => $alert->id,
            'station_id' => $station->id,
            'assigned_technician_id' => $technician->id,
            'reference' => 'INT-'.strtoupper($slug),
            'status' => 'in-progress',
            'priority' => 'critical',
            'started_at' => now()->subMinutes(42),
            'problem' => $alert->description,
        ]);

        return [$technician, $intervention, $alert];
    }

    private function uploadPhoto(Intervention $intervention, string $phase): int
    {
        return $this->post("/api/interventions/{$intervention->id}/photos", [
            'phase' => $phase,
            'photo' => UploadedFile::fake()->image($phase.'.jpg', 900, 700),
        ])->assertCreated()->json('data.photos.'.($phase === 'before' ? 0 : 1).'.id');
    }

    /** @return array<string, mixed> */
    private function reportPayload(): array
    {
        return [
            'diagnosis' => 'The connector lock actuator was contaminated and could not reach the closed position.',
            'actions_taken' => 'The actuator was cleaned, lubricated, refitted and validated through three locking cycles.',
            'final_outcome' => 'operational',
            'observations' => 'The connector is operational after the final test.',
            'parts' => ['Contact cleaner'],
            'safety_checks' => [
                'work_area_safe' => true,
                'connector_inspected' => true,
                'station_status_verified' => true,
            ],
        ];
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
            'name' => 'Report Test Station',
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
}
