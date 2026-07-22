<?php

namespace Tests\Feature;

use App\Jobs\SendOperationalNotificationEmail;
use App\Models\Alert;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use App\Notifications\OperationalEmailNotification;
use App\Services\Notifications\NotificationSlaService;
use App\Services\Notifications\OperationalNotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_notifications_are_personal_and_cannot_be_read_by_another_user(): void
    {
        $organization = $this->organization('personal-notifications');
        $first = $this->user($organization, 'operator');
        $second = $this->user($organization, 'operator');
        $service = app(OperationalNotificationService::class);
        $firstNotification = $service->notifyUser($first, $this->attributes('first'));
        $secondNotification = $service->notifyUser($second, $this->attributes('second'));
        Sanctum::actingAs($first);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.unread', 1)
            ->assertJsonPath('data.0.id', $firstNotification->id);

        $this->patchJson("/api/notifications/{$secondNotification->id}/read")->assertNotFound();
        $this->patchJson("/api/notifications/{$firstNotification->id}/read")
            ->assertOk()
            ->assertJsonPath('is_read', true);
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('updated', 0);
    }

    public function test_critical_alert_notifies_only_the_organization_admin_and_operators_once(): void
    {
        Queue::fake([SendOperationalNotificationEmail::class]);
        $organization = $this->organization('alert-notifications');
        $otherOrganization = $this->organization('other-alert-notifications');
        $admin = $this->user($organization, 'admin');
        $operator = $this->user($organization, 'operator');
        $technician = $this->user($organization, 'technician');
        $otherAdmin = $this->user($otherOrganization, 'admin');
        $alert = $this->alert($organization, null, now()->addMinutes(15));
        $service = app(OperationalNotificationService::class);

        $service->notifyAlertOpened($alert->load('station'), 101);
        $service->notifyAlertOpened($alert->load('station'), 101);

        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $admin->id, 'entity_id' => $alert->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $operator->id, 'entity_id' => $alert->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $technician->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $otherAdmin->id]);
        $this->assertDatabaseCount('notification_deliveries', 4);
        Queue::assertPushed(SendOperationalNotificationEmail::class, 2);
    }

    public function test_assigning_an_alert_notifies_the_selected_technician(): void
    {
        Queue::fake([SendOperationalNotificationEmail::class]);
        $organization = $this->organization('assignment-notifications');
        $operator = $this->user($organization, 'operator');
        $technician = $this->user($organization, 'technician');
        $alert = $this->alert($organization, null, now()->addMinutes(15));
        Sanctum::actingAs($operator);

        $this->patchJson("/api/alerts/{$alert->id}", ['assigned_technician_id' => $technician->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_technician.id', $technician->id);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $technician->id,
            'category' => 'assignment',
            'entity_id' => $alert->id,
        ]);
        Queue::assertPushed(SendOperationalNotificationEmail::class, 1);
    }

    public function test_user_can_disable_email_alerts_while_keeping_in_app_alerts(): void
    {
        Queue::fake([SendOperationalNotificationEmail::class]);
        $organization = $this->organization('notification-preferences');
        $operator = $this->user($organization, 'operator');
        $alert = $this->alert($organization, null, now()->addMinutes(15));
        Sanctum::actingAs($operator);

        $this->getJson('/api/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.email_alerts', true);
        $this->putJson('/api/notification-preferences', ['email_alerts' => false])
            ->assertOk()
            ->assertJsonPath('data.email_alerts', false);

        app(OperationalNotificationService::class)->notifyAlertOpened($alert->load('station'), 202);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $operator->id,
            'entity_id' => $alert->id,
            'category' => 'alert',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'in_app',
            'status' => 'delivered',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'channel' => 'email',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_sla_scan_is_idempotent_for_all_alert_stakeholders(): void
    {
        Queue::fake([SendOperationalNotificationEmail::class]);
        $organization = $this->organization('sla-notifications');
        $this->user($organization, 'admin');
        $this->user($organization, 'operator');
        $technician = $this->user($organization, 'technician');
        $this->alert($organization, $technician, now()->subMinute());
        $service = app(NotificationSlaService::class);

        $first = $service->scan();
        $second = $service->scan();

        $this->assertSame(1, $first['overdue']);
        $this->assertSame(1, $second['overdue']);
        $this->assertDatabaseCount('user_notifications', 3);
        $this->assertDatabaseCount('notification_deliveries', 6);
        Queue::assertPushed(SendOperationalNotificationEmail::class, 3);
    }

    public function test_email_job_records_successful_delivery(): void
    {
        Queue::fake([SendOperationalNotificationEmail::class]);
        Notification::fake();
        $organization = $this->organization('email-delivery');
        $operator = $this->user($organization, 'operator');
        $notification = app(OperationalNotificationService::class)->notifyUser(
            $operator,
            $this->attributes('email'),
            ['in_app', 'email'],
        );
        $delivery = $notification->deliveries()->where('channel', 'email')->firstOrFail();

        (new SendOperationalNotificationEmail($delivery->id))->handle();

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->id,
            'status' => 'delivered',
            'attempts' => 1,
        ]);
        Notification::assertSentTo($operator, OperationalEmailNotification::class);
    }

    /** @return array<string, mixed> */
    private function attributes(string $key): array
    {
        return [
            'category' => 'alert',
            'severity' => 'warning',
            'title' => 'Test notification',
            'message' => 'A notification used to verify personal access.',
            'action_url' => '/alerts',
            'deduplication_key' => 'test:'.$key,
        ];
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function user(Organization $organization, string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function alert(Organization $organization, ?User $technician, mixed $dueAt): Alert
    {
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Notification Station',
            'reference' => 'CT-NOTIFY-'.strtoupper(substr($organization->slug, 0, 8)),
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'faulted',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
        ]);

        return Alert::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'assigned_technician_id' => $technician?->id,
            'reference' => 'ALT-'.strtoupper(substr(md5($organization->slug.($technician?->id ?? 'none')), 0, 8)),
            'title' => 'Station communication lost',
            'problem_type' => 'OCPP communication loss',
            'severity' => 'critical',
            'status' => 'new',
            'source' => 'availability_engine',
            'description' => 'The station is no longer responding.',
            'detected_at' => now()->subMinutes(2),
            'due_at' => $dueAt,
        ]);
    }
}
