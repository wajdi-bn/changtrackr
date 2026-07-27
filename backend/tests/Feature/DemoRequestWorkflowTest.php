<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\DemoRequest;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Notifications\DemoRequestReceivedNotification;
use App\Notifications\NewDemoRequestNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['demo.notification_email' => 'platform@example.com']);
    }

    public function test_visitor_can_submit_a_valid_demo_request_and_both_notifications_are_queued(): void
    {
        Notification::fake();

        $this->postJson('/api/demo-requests', $this->publicPayload())
            ->assertCreated()
            ->assertJsonStructure(['message', 'reference']);

        $request = DemoRequest::query()->sole();
        $this->assertSame('submitted', $request->status);
        $this->assertSame('contact@northcharge.tn', $request->email);
        $this->assertSame(['availability_monitoring', 'maintenance_coordination'], $request->objectives);
        $this->assertNotNull($request->consent_at);
        $this->assertNotNull($request->submitted_ip_hash);
        $this->assertNotSame('127.0.0.1', $request->submitted_ip_hash);
        Notification::assertSentOnDemand(DemoRequestReceivedNotification::class);
        Notification::assertSentOnDemand(NewDemoRequestNotification::class);

        $applicantMail = (new DemoRequestReceivedNotification($request))->toMail(new \stdClass);
        $internalMail = (new NewDemoRequestNotification($request))->toMail(new \stdClass);
        $this->assertNull($applicantMail->actionUrl);
        $this->assertStringContainsString('/demo-requests', $internalMail->actionUrl);
        $this->assertStringStartsWith('[Internal]', $internalMail->subject);
    }

    public function test_public_request_rejects_spam_and_recent_duplicates(): void
    {
        Notification::fake();
        $payload = $this->publicPayload();
        $this->postJson('/api/demo-requests', [...$payload, 'website' => 'https://spam.example'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('website');

        $this->postJson('/api/demo-requests', $payload)->assertCreated();
        $this->postJson('/api/demo-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_only_super_administrator_can_review_demo_requests(): void
    {
        DemoRequest::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Existing Network',
            'slug' => 'existing-network',
            'status' => 'active',
        ]);
        $administrator = $this->user($organization, 'admin');
        Sanctum::actingAs($administrator);

        $this->getJson('/api/demo-requests')->assertForbidden();

        $superAdministrator = $this->user(null, 'super_admin');
        Sanctum::actingAs($superAdministrator);

        $this->getJson('/api/demo-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total', 1);
    }

    public function test_super_administrator_reviews_and_provisions_an_invited_admin(): void
    {
        Notification::fake();
        $superAdministrator = $this->user(null, 'super_admin');
        $demoRequest = DemoRequest::factory()->create([
            'full_name' => 'Leila Mansour',
            'email' => 'leila@futurecharge.tn',
            'company_name' => 'Future Charge',
            'status' => 'submitted',
        ]);
        Sanctum::actingAs($superAdministrator);

        $this->postJson("/api/demo-requests/{$demoRequest->id}/start-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.handled_by.id', $superAdministrator->id);

        $this->patchJson("/api/demo-requests/{$demoRequest->id}", ['internal_notes' => 'Company and network scope verified.'])
            ->assertOk()
            ->assertJsonPath('data.internal_notes', 'Company and network scope verified.');

        $this->postJson("/api/demo-requests/{$demoRequest->id}/provision", [
            'organization_name' => 'Future Charge Tunisia',
            'admin_name' => 'Leila Mansour',
            'trial_days' => 30,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'provisioned')
            ->assertJsonPath('data.organization.name', 'Future Charge Tunisia')
            ->assertJsonPath('data.invitation.status', 'pending');

        $organization = Organization::query()->where('slug', 'future-charge-tunisia')->sole();
        $administrator = User::query()->where('email', 'leila@futurecharge.tn')->sole();
        $this->assertSame($organization->id, $administrator->organization_id);
        $this->assertSame('pending', $administrator->status);
        $this->assertTrue($administrator->hasRole('admin'));
        $this->assertDatabaseHas('account_invitations', [
            'user_id' => $administrator->id,
            'demo_request_id' => $demoRequest->id,
            'status' => 'pending',
        ]);
        Notification::assertSentTo($administrator, AccountInvitationNotification::class);

        $this->postJson("/api/demo-requests/{$demoRequest->id}/invitation/issue")
            ->assertUnprocessable();

        $this->postJson("/api/demo-requests/{$demoRequest->id}/invitation/revoke")
            ->assertOk()
            ->assertJsonPath('data.invitation.status', 'revoked');

        $this->postJson("/api/demo-requests/{$demoRequest->id}/invitation/issue")
            ->assertOk()
            ->assertJsonPath('data.invitation.status', 'pending');

        $this->assertDatabaseCount('account_invitations', 2);
        $this->assertSame(1, AccountInvitation::query()->where('status', 'revoked')->count());
        Notification::assertSentToTimes($administrator, AccountInvitationNotification::class, 2);

        $this->postJson("/api/demo-requests/{$demoRequest->id}/provision", [
            'organization_name' => 'Duplicate',
            'admin_name' => 'Duplicate',
            'trial_days' => 30,
        ])->assertUnprocessable();
    }

    public function test_rejection_and_reopening_are_explicit_actions(): void
    {
        $superAdministrator = $this->user(null, 'super_admin');
        $demoRequest = DemoRequest::factory()->create(['status' => 'submitted']);
        Sanctum::actingAs($superAdministrator);

        $this->postJson("/api/demo-requests/{$demoRequest->id}/reject", [
            'rejection_reason' => 'The submitted organization details cannot be verified.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The submitted organization details cannot be verified.');

        $this->postJson("/api/demo-requests/{$demoRequest->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.rejection_reason', null);

        $this->postJson("/api/demo-requests/{$demoRequest->id}/start-review")
            ->assertUnprocessable();
    }

    public function test_invited_administrator_can_activate_once_with_the_exact_token(): void
    {
        Storage::fake('public');
        $organization = Organization::query()->create([
            'name' => 'Activation Network',
            'slug' => 'activation-network',
            'status' => 'active',
        ]);
        $superAdministrator = $this->user(null, 'super_admin');
        $administrator = $this->user($organization, 'admin', [
            'email' => 'activate@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);
        $token = str_repeat('a', 80);
        $invitation = AccountInvitation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $administrator->id,
            'invited_by_id' => $superAdministrator->id,
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role' => 'admin',
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/account-invitations/inspect', [
            'email' => $administrator->email,
            'token' => $token,
        ])->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('invitation.organization', 'Activation Network');

        $payload = [
            'email' => $administrator->email,
            'token' => $token,
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ];
        $this->post('/api/account-invitations/accept', [
            ...$payload,
            'phone' => '+216 22 333 444',
            'job_title' => 'Charging Network Director',
            'organization_logo' => UploadedFile::fake()->image('activation-logo.png', 240, 240),
        ])->assertOk();

        $administrator->refresh();
        $organization->refresh();
        $this->assertSame('active', $administrator->status);
        $this->assertSame('+216 22 333 444', $administrator->phone);
        $this->assertSame('Charging Network Director', $administrator->job_title);
        $this->assertNotNull($administrator->email_verified_at);
        $this->assertTrue(Hash::check('SecurePass123', $administrator->password));
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertNotNull($organization->logo_url);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $organization->logo_url));

        $this->postJson('/api/account-invitations/accept', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_expired_invitation_cannot_activate_an_account(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Expired Network',
            'slug' => 'expired-network',
            'status' => 'active',
        ]);
        $superAdministrator = $this->user(null, 'super_admin');
        $administrator = $this->user($organization, 'admin', [
            'email' => 'expired@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);
        $token = str_repeat('b', 80);
        $invitation = AccountInvitation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $administrator->id,
            'invited_by_id' => $superAdministrator->id,
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role' => 'admin',
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/account-invitations/accept', [
            'email' => $administrator->email,
            'token' => $token,
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ])->assertUnprocessable();

        $this->assertSame('expired', $invitation->fresh()->status);
        $this->assertSame('pending', $administrator->fresh()->status);
    }

    public function test_demo_submission_limit_does_not_consume_invitation_activation_attempts(): void
    {
        Notification::fake();
        foreach (range(1, 3) as $index) {
            $this->postJson('/api/demo-requests', [
                ...$this->publicPayload(),
                'email' => "network-{$index}@example.com",
            ])->assertCreated();
        }

        $organization = Organization::query()->create([
            'name' => 'Independent Limits',
            'slug' => 'independent-limits',
            'status' => 'active',
        ]);
        $superAdministrator = $this->user(null, 'super_admin');
        $administrator = $this->user($organization, 'admin', [
            'email' => 'limited@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);
        $token = str_repeat('c', 80);
        AccountInvitation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $administrator->id,
            'invited_by_id' => $superAdministrator->id,
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role' => 'admin',
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/account-invitations/accept', [
            'email' => $administrator->email,
            'token' => $token,
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ])->assertOk();
    }

    /** @return array<string, mixed> */
    private function publicPayload(): array
    {
        return [
            'full_name' => 'Amel Trabelsi',
            'email' => 'Contact@NorthCharge.tn',
            'company_name' => 'North Charge',
            'phone' => '+216 20 123 456',
            'objectives' => ['availability_monitoring', 'maintenance_coordination'],
            'estimated_stations' => 24,
            'message' => 'We want to supervise availability across our charging network.',
            'consent_accepted' => true,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function user(?Organization $organization, string $role, array $attributes = []): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization?->id,
            'status' => 'active',
            ...$attributes,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
