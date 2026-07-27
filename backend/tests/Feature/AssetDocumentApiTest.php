<?php

namespace Tests\Feature;

use App\Models\AssetDocument;
use App\Models\InternalReport;
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

class AssetDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_operator_can_upload_preview_list_and_delete_a_private_station_document(): void
    {
        [$operator, $organization] = $this->userWithRole('operator');
        $station = $this->station($organization);
        Sanctum::actingAs($operator);

        $documentId = $this->post("/api/stations/{$station->id}/documents", [
            'file' => $this->pdf('charger-manual.pdf'),
            'category' => 'manual',
            'title' => 'Charger installation manual',
            'version_label' => 'v2.1',
            'visibility' => 'organization',
            'issued_at' => '2026-07-01',
        ])->assertCreated()
            ->assertJsonPath('data.previewable', true)
            ->assertJsonPath('data.uploaded_by.name', $operator->name)
            ->json('data.id');

        $document = AssetDocument::query()->findOrFail($documentId);
        Storage::disk('local')->assertExists($document->path);

        $this->getJson("/api/stations/{$station->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.can_manage', true)
            ->assertJsonPath('data.0.version_label', 'v2.1');

        $this->get("/api/asset-documents/{$documentId}/content?inline=true")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->deleteJson("/api/asset-documents/{$documentId}")->assertOk();
        Storage::disk('local')->assertMissing($document->path);
        $this->assertDatabaseMissing('asset_documents', ['id' => $documentId]);
    }

    public function test_document_access_is_isolated_between_organizations(): void
    {
        [$operator, $organization] = $this->userWithRole('operator');
        [$externalOperator, $externalOrganization] = $this->userWithRole('operator');
        $station = $this->station($organization);
        $externalStation = $this->station($externalOrganization);
        Sanctum::actingAs($operator);
        $documentId = $this->uploadStationDocument($station, 'organization');

        Sanctum::actingAs($externalOperator);
        $this->getJson("/api/stations/{$station->id}/documents")->assertForbidden();
        $this->get("/api/asset-documents/{$documentId}/content?inline=true")->assertForbidden();
        $this->deleteJson("/api/asset-documents/{$documentId}")->assertForbidden();
        $this->getJson("/api/stations/{$externalStation->id}/documents")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_client_only_sees_public_station_documents_and_cannot_upload(): void
    {
        [$operator, $organization] = $this->userWithRole('operator');
        $station = $this->station($organization);
        Sanctum::actingAs($operator);
        $privateId = $this->uploadStationDocument($station, 'organization');
        $publicId = $this->uploadStationDocument($station, 'public');
        [$client] = $this->userWithRole('client');
        $client->update(['organization_id' => null]);
        Sanctum::actingAs($client);

        $this->getJson("/api/stations/{$station->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $publicId)
            ->assertJsonPath('meta.can_manage', false);
        $this->get("/api/asset-documents/{$privateId}/content?inline=true")->assertForbidden();
        $this->post("/api/stations/{$station->id}/documents", [
            'file' => $this->pdf('forbidden.pdf'),
            'category' => 'manual',
            'title' => 'Forbidden',
        ])->assertForbidden();
    }

    public function test_assigned_technician_can_attach_intervention_documents_but_not_station_documents(): void
    {
        [$technician, $organization] = $this->userWithRole('technician');
        $station = $this->station($organization);
        $intervention = Intervention::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'assigned_technician_id' => $technician->id,
            'reference' => 'INT-DOC-001',
            'status' => 'in-progress',
            'priority' => 'high',
            'problem' => 'Connector diagnosis required.',
        ]);
        Sanctum::actingAs($technician);

        $this->post("/api/interventions/{$intervention->id}/documents", [
            'file' => $this->pdf('diagnostic.pdf'),
            'category' => 'diagnostic',
            'title' => 'Connector diagnostic output',
        ])->assertCreated();
        $this->getJson("/api/interventions/{$intervention->id}/documents")
            ->assertOk()
            ->assertJsonPath('meta.can_manage', true);
        $this->post("/api/stations/{$station->id}/documents", [
            'file' => $this->pdf('station.pdf'),
            'category' => 'manual',
            'title' => 'Station manual',
        ])->assertForbidden();
    }

    public function test_report_attachments_stay_private_until_the_report_is_sent(): void
    {
        [$sender, $organization] = $this->userWithRole('operator');
        $recipient = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $recipient->assignRole('technician');
        $report = InternalReport::query()->create([
            'organization_id' => $organization->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'title' => 'Shift handover',
            'category' => 'handover',
            'priority' => 'normal',
            'status' => 'draft',
            'body' => 'Operational handover information.',
        ]);
        Sanctum::actingAs($sender);
        $documentId = $this->post("/api/internal-reports/{$report->id}/attachments", [
            'file' => $this->pdf('handover.pdf'),
            'category' => 'report_attachment',
            'title' => 'Detailed handover',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($recipient);
        $this->getJson("/api/internal-reports/{$report->id}/attachments")->assertForbidden();
        $this->get("/api/asset-documents/{$documentId}/content?inline=true")->assertForbidden();

        $report->update(['status' => 'sent', 'sent_at' => now()]);
        $this->getJson("/api/internal-reports/{$report->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.can_manage', false);
        $this->get("/api/asset-documents/{$documentId}/content?inline=true")->assertOk();
        $this->deleteJson("/api/asset-documents/{$documentId}")->assertForbidden();
    }

    private function uploadStationDocument(Station $station, string $visibility): int
    {
        return $this->post("/api/stations/{$station->id}/documents", [
            'file' => $this->pdf($visibility.'-manual.pdf'),
            'category' => 'manual',
            'title' => ucfirst($visibility).' manual',
            'visibility' => $visibility,
        ])->assertCreated()->json('data.id');
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    /** @return array{User, Organization} */
    private function userWithRole(string $role): array
    {
        $organization = Organization::query()->create([
            'name' => ucfirst($role).' Document Network',
            'slug' => $role.'-document-'.uniqid(),
            'status' => 'active',
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $user->assignRole($role);

        return [$user, $organization];
    }

    private function station(Organization $organization): Station
    {
        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Document Station',
            'reference' => 'CT-DOC-'.uniqid(),
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Model X',
            'manufacturer' => 'ChargeTrackr',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
    }
}
