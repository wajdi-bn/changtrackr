<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\DemoRequest;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Notifications\NewDemoRequestNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

    public function test_visitor_can_submit_a_valid_demo_request_and_internal_notification_is_queued(): void
    {
        Notification::fake();

        $this->postJson('/api/demo-requests', $this->publicPayload())
            ->assertCreated()
            ->assertJsonStructure(['message', 'reference']);

        $request = DemoRequest::query()->sole();
        $this->assertSame('new', $request->status);
        $this->assertSame('contact@northcharge.tn', $request->email);
        $this->assertNotNull($request->consent_at);
        $this->assertNotNull($request->submitted_ip_hash);
        $this->assertNotSame('127.0.0.1', $request->submitted_ip_hash);
        Notification::assertSentOnDemand(NewDemoRequestNotification::class);
    }

    public function test_public_request_rejects_spam_and_recent_duplicates(): void
    {
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

    public function test_super_administrator_follows_status_transitions_and_provisions_an_invited_admin(): void
    {
        Notification::fake();
        $superAdministrator = $this->user(null, 'super_admin');
        $demoRequest = DemoRequest::factory()->create([
            'full_name' => 'Leila Mansour',
            'email' => 'leila@futurecharge.tn',
            'company_name' => 'Future Charge',
            'status' => 'qualified',
        ]);
        Sanctum::actingAs($superAdministrator);

        $this->patchJson("/api/demo-requests/{$demoRequest->id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

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

        $this->postJson("/api/demo-requests/{$demoRequest->id}/invitation/revoke")
            ->assertOk()
            ->assertJsonPath('data.invitation.status', 'revoked');

        $this->postJson("/api/demo-requests/{$demoRequest->id}/invitation/resend")
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

    public function test_invited_administrator_can_activate_once_with_the_exact_token(): void
    {
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
        $this->postJson('/api/account-invitations/accept', $payload)->assertOk();

        $administrator->refresh();
        $this->assertSame('active', $administrator->status);
        $this->assertNotNull($administrator->email_verified_at);
        $this->assertTrue(Hash::check('SecurePass123', $administrator->password));
        $this->assertSame('accepted', $invitation->fresh()->status);

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

    /** @return array<string, mixed> */
    private function publicPayload(): array
    {
        return [
            'full_name' => 'Amel Trabelsi',
            'email' => 'Contact@NorthCharge.tn',
            'company_name' => 'North Charge',
            'phone' => '+216 20 123 456',
            'topic' => 'platform',
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
