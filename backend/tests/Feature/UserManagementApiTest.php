<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_only_lists_users_from_its_organization_and_can_filter_them(): void
    {
        $organization = $this->organization('north-network');
        $otherOrganization = $this->organization('south-network');
        $administrator = $this->user($organization, 'admin', ['name' => 'Organization Admin']);
        $this->user($organization, 'operator', ['name' => 'Tunis Operator', 'team' => 'Network Operations']);
        $this->user($organization, 'technician', ['name' => 'Field Technician', 'team' => 'Field Maintenance']);
        $this->user($otherOrganization, 'operator', ['name' => 'Hidden Operator']);
        $this->user(null, 'client', ['name' => 'Global Client']);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/users?role=operator&search=tunis')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Tunis Operator')
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.by_role.operator', 1);
    }

    public function test_administrator_invites_an_employee_who_activates_before_being_managed(): void
    {
        Notification::fake();
        $organization = $this->organization('crud-network');
        $administrator = $this->user($organization, 'admin');
        Sanctum::actingAs($administrator);

        $userId = $this->postJson('/api/users', [
            'name' => 'New Technician',
            'email' => 'new.technician@example.com',
            'phone' => '+216 20 123 456',
            'team' => 'Field Maintenance',
            'address' => 'Ariana, Tunisia',
            'role' => 'technician',
        ])
            ->assertCreated()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.roles.0', 'technician')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.invitation.status', 'pending')
            ->json('data.id');

        $managedUser = User::query()->findOrFail($userId);
        $this->assertDatabaseHas('account_invitations', [
            'user_id' => $managedUser->id,
            'organization_id' => $organization->id,
            'invited_by_id' => $administrator->id,
            'status' => 'pending',
        ]);

        $activationUrl = null;
        Notification::assertSentTo($managedUser, AccountInvitationNotification::class, function (AccountInvitationNotification $notification) use ($managedUser, &$activationUrl): bool {
            $activationUrl = $notification->toMail($managedUser)->actionUrl;

            return true;
        });
        $this->assertIsString($activationUrl);
        parse_str((string) parse_url($activationUrl, PHP_URL_QUERY), $activationQuery);

        $this->postJson('/api/account-invitations/accept', [
            'email' => $managedUser->email,
            'token' => $activationQuery['token'],
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ])->assertOk();

        $this->assertSame('active', $managedUser->fresh()->status);
        $this->assertTrue(Hash::check('SecurePass123', $managedUser->fresh()->password));

        $this->patchJson("/api/users/{$userId}", [
            'name' => 'Network Operator',
            'role' => 'operator',
            'team' => 'Network Operations',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Network Operator')
            ->assertJsonPath('data.roles.0', 'operator');

        $managedUser = User::query()->findOrFail($userId);
        $managedUser->createToken('test-session');

        $this->deleteJson("/api/users/{$userId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('users', ['id' => $userId, 'status' => 'inactive']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_employee_invitation_actions_are_contextual_and_scoped(): void
    {
        Notification::fake();
        $organization = $this->organization('invitation-network');
        $otherOrganization = $this->organization('other-invitation-network');
        $administrator = $this->user($organization, 'admin');
        $otherAdministrator = $this->user($otherOrganization, 'admin');
        Sanctum::actingAs($administrator);

        $employeeId = $this->postJson('/api/users', [
            'name' => 'Pending Operator',
            'email' => 'pending.operator@example.com',
            'role' => 'operator',
            'team' => 'Network Operations',
        ])->assertCreated()->json('data.id');
        $employee = User::query()->findOrFail($employeeId);

        $this->postJson("/api/users/{$employeeId}/invitation/remind")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invitation');

        $this->travel(11)->minutes();
        $this->postJson("/api/users/{$employeeId}/invitation/remind")
            ->assertOk()
            ->assertJsonPath('data.invitation.status', 'pending');
        Notification::assertSentToTimes($employee, AccountInvitationNotification::class, 2);

        Sanctum::actingAs($otherAdministrator);
        $this->deleteJson("/api/users/{$employeeId}/invitation")->assertForbidden();

        Sanctum::actingAs($administrator);
        $this->deleteJson("/api/users/{$employeeId}/invitation")
            ->assertOk()
            ->assertJsonPath('data.invitation.status', 'revoked')
            ->assertJsonPath('data.invitation.can_renew', true);

        $this->postJson("/api/users/{$employeeId}/invitation/renew")
            ->assertOk()
            ->assertJsonPath('data.invitation.status', 'pending');

        $this->assertDatabaseCount('account_invitations', 2);
        $this->assertSame(1, AccountInvitation::query()->where('status', 'revoked')->count());
        Notification::assertSentToTimes($employee, AccountInvitationNotification::class, 3);
        $this->travelBack();
    }

    public function test_administrator_cannot_manage_another_organization_or_assign_super_administrator_role(): void
    {
        $organization = $this->organization('protected-network');
        $otherOrganization = $this->organization('external-network');
        $administrator = $this->user($organization, 'admin');
        $externalUser = $this->user($otherOrganization, 'operator');
        Sanctum::actingAs($administrator);

        $this->getJson("/api/users/{$externalUser->id}")->assertForbidden();
        $this->patchJson("/api/users/{$externalUser->id}", ['name' => 'Forbidden'])->assertForbidden();

        $this->postJson('/api/users', [
            'name' => 'Forbidden Super Admin',
            'email' => 'forbidden.super@example.com',
            'role' => 'super_admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->postJson('/api/users', [
            'name' => 'Forbidden Administrator',
            'email' => 'forbidden.admin@example.com',
            'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->postJson('/api/users', [
            'organization_id' => $otherOrganization->id,
            'name' => 'Injected Operator',
            'email' => 'injected.operator@example.com',
            'role' => 'operator',
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->postJson('/api/users', [
            'name' => 'Client Account',
            'email' => 'client.account@example.com',
            'role' => 'client',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_operator_cannot_access_user_management(): void
    {
        $organization = $this->organization('operator-network');
        $operator = $this->user($organization, 'operator');
        Sanctum::actingAs($operator);

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', [
            'name' => 'Denied User',
            'email' => 'denied@example.com',
            'role' => 'technician',
        ])->assertForbidden();
    }

    public function test_administrator_cannot_deactivate_or_remove_its_own_role(): void
    {
        $organization = $this->organization('self-protection');
        $administrator = $this->user($organization, 'admin');
        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$administrator->id}", ['status' => 'inactive'])
            ->assertForbidden();
        $this->patchJson("/api/users/{$administrator->id}", ['role' => 'operator'])
            ->assertForbidden();
        $this->deleteJson("/api/users/{$administrator->id}")
            ->assertForbidden();
    }

    public function test_super_administrator_can_create_and_transfer_an_organization_administrator(): void
    {
        $firstOrganization = $this->organization('first-admin-network');
        $secondOrganization = $this->organization('second-admin-network');
        $superAdministrator = $this->user(null, 'super_admin');
        Sanctum::actingAs($superAdministrator);

        $administratorId = $this->postJson('/api/users', [
            'organization_id' => $firstOrganization->id,
            'name' => 'New Administrator',
            'email' => 'new.organization.admin@example.com',
            'status' => 'active',
            'role' => 'admin',
            'password' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('data.organization.id', $firstOrganization->id)
            ->json('data.id');

        $this->patchJson("/api/users/{$administratorId}", [
            'organization_id' => $secondOrganization->id,
        ])->assertOk()
            ->assertJsonPath('data.organization.id', $secondOrganization->id);

        $this->assertDatabaseHas('users', [
            'id' => $administratorId,
            'organization_id' => $secondOrganization->id,
        ]);
    }

    public function test_administrator_can_export_only_its_filtered_organization_users(): void
    {
        $organization = $this->organization('export-network');
        $otherOrganization = $this->organization('hidden-export-network');
        $administrator = $this->user($organization, 'admin', ['name' => 'Export Admin']);
        $this->user($organization, 'technician', ['name' => 'Export Technician']);
        $this->user($otherOrganization, 'technician', ['name' => 'Hidden Technician']);
        $this->user(null, 'client', ['name' => 'Global Client']);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/users/export?format=json&role=technician')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Export Technician');
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => 'active',
        ]);
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
