<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_administrator_can_create_update_and_logically_deactivate_an_organization_user(): void
    {
        $organization = $this->organization('crud-network');
        $administrator = $this->user($organization, 'admin');
        Sanctum::actingAs($administrator);

        $userId = $this->postJson('/api/users', [
            'name' => 'New Technician',
            'email' => 'new.technician@example.com',
            'phone' => '+216 20 123 456',
            'team' => 'Field Maintenance',
            'address' => 'Ariana, Tunisia',
            'status' => 'active',
            'role' => 'technician',
            'password' => 'password123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.roles.0', 'technician')
            ->json('data.id');

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
            'status' => 'active',
            'role' => 'super_admin',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->postJson('/api/users', [
            'name' => 'Forbidden Administrator',
            'email' => 'forbidden.admin@example.com',
            'status' => 'active',
            'role' => 'admin',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->postJson('/api/users', [
            'organization_id' => $otherOrganization->id,
            'name' => 'Injected Operator',
            'email' => 'injected.operator@example.com',
            'status' => 'active',
            'role' => 'operator',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->postJson('/api/users', [
            'name' => 'Client Account',
            'email' => 'client.account@example.com',
            'status' => 'active',
            'role' => 'client',
            'password' => 'password123',
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
            'status' => 'active',
            'role' => 'technician',
            'password' => 'password123',
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
