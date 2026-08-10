<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_sender_can_attach_a_file_to_a_draft_before_sending_it(): void
    {
        $organization = $this->organization('Attachment Network');
        $operator = $this->user('operator', $organization);
        $technician = $this->user('technician', $organization);
        Sanctum::actingAs($operator);

        $reportId = $this->postJson('/api/internal-reports', [
            'recipient_id' => $technician->id,
            'title' => 'Connector diagnosis',
            'category' => 'intervention',
            'priority' => 'important',
            'body' => 'The connector requires a field diagnosis before the next shift.',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->post("/api/internal-reports/{$reportId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('diagnosis.pdf', "%PDF-1.4\n%%EOF"),
            'category' => 'report_attachment',
            'title' => 'Connector diagnosis evidence',
        ])->assertCreated()->assertJsonPath('data.original_name', 'diagnosis.pdf');

        $this->postJson("/api/internal-reports/{$reportId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonCount(1, 'data.attachments');

        Sanctum::actingAs($technician);
        $this->getJson('/api/internal-reports?mailbox=inbox')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reportId)
            ->assertJsonPath('data.0.attachments.0.original_name', 'diagnosis.pdf');
    }

    public function test_organization_employees_can_exchange_read_archive_and_download_reports(): void
    {
        $organization = $this->organization('Report Network');
        $admin = $this->user('admin', $organization);
        $operator = $this->user('operator', $organization);
        Sanctum::actingAs($admin);

        $reportId = $this->postJson('/api/internal-reports', [
            'recipient_id' => $operator->id,
            'title' => 'Morning network handover',
            'category' => 'handover',
            'priority' => 'important',
            'summary' => 'Two stations require monitoring during the next shift.',
            'body' => 'Station Lac 1 recovered after a reset. Review the open alert at Ariana before noon.',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'send_now' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipient.id', $operator->id)
            ->json('data.id');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $operator->id,
            'category' => 'report',
            'entity_id' => $reportId,
        ]);

        Sanctum::actingAs($operator);
        $this->getJson('/api/internal-reports?mailbox=inbox')
            ->assertOk()
            ->assertJsonPath('summary.unread', 1)
            ->assertJsonPath('data.0.sender.id', $admin->id);
        $this->postJson('/api/internal-reports/'.$reportId.'/read')
            ->assertOk()
            ->assertJsonPath('data.status', 'read');
        $this->get('/api/internal-reports/'.$reportId.'/document')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->postJson('/api/internal-reports/'.$reportId.'/archive')->assertOk();
        $this->getJson('/api/internal-reports?mailbox=archived')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reportId);
    }

    public function test_reports_are_isolated_by_organization_and_super_admin_cannot_read_them(): void
    {
        $first = $this->organization('First Network');
        $second = $this->organization('Second Network');
        $admin = $this->user('admin', $first);
        $operator = $this->user('operator', $first);
        $outsider = $this->user('operator', $second);
        Sanctum::actingAs($admin);

        $reportId = $this->postJson('/api/internal-reports', [
            'recipient_id' => $operator->id,
            'title' => 'Private organization report',
            'category' => 'operations',
            'priority' => 'normal',
            'body' => 'This report is restricted to employees of the first organization.',
            'send_now' => true,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($outsider);
        $this->getJson('/api/internal-reports?mailbox=inbox')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/internal-reports/'.$reportId.'/document')->assertForbidden();

        Sanctum::actingAs($this->user('super_admin'));
        $this->getJson('/api/internal-reports?mailbox=inbox')->assertForbidden();
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create(['name' => $name, 'slug' => str($name)->slug(), 'status' => 'active']);
    }

    private function user(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }
}
