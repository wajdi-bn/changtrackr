<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformGovernanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_can_inspect_and_update_system_role_permissions(): void
    {
        $superAdmin = $this->user('super_admin');
        $this->user('operator', Organization::query()->create([
            'name' => 'Access Test Network',
            'slug' => 'access-test-network',
            'status' => 'active',
        ]));
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/platform/roles-permissions')
            ->assertOk()
            ->assertJsonPath('summary.roles', 5)
            ->assertJsonPath('summary.editable_roles', 4)
            ->assertJsonFragment([
                'name' => 'super_admin',
                'immutable' => true,
                'boundary' => 'Platform-wide',
            ]);

        $this->putJson('/api/platform/roles/operator/permissions', [
            'permissions' => ['stations.view', 'alerts.view', 'sessions.view'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'operator')
            ->assertJsonCount(3, 'data.permissions');

        $this->assertEqualsCanonicalizing(
            ['stations.view', 'alerts.view', 'sessions.view'],
            Role::findByName('operator', 'web')->permissions->pluck('name')->all(),
        );
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $superAdmin->id,
            'event_type' => 'role.permissions_updated',
            'subject_type' => Role::class,
        ]);
    }

    public function test_super_admin_role_is_immutable_and_organization_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson('/api/platform/roles/super_admin/permissions', [
            'permissions' => ['roles.view'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        Sanctum::actingAs($this->user('admin', Organization::query()->create([
            'name' => 'Restricted Network',
            'slug' => 'restricted-network',
            'status' => 'active',
        ])));
        $this->getJson('/api/platform/roles-permissions')->assertForbidden();
        $this->putJson('/api/platform/roles/operator/permissions', [
            'permissions' => ['stations.view'],
        ])->assertForbidden();
    }

    public function test_audit_log_supports_facets_filters_details_and_csv_export(): void
    {
        $superAdmin = $this->user('super_admin');
        Sanctum::actingAs($superAdmin);

        $organizationId = $this->postJson('/api/organizations', [
            'name' => 'Audited Network',
            'contact_email' => 'audit@example.test',
        ])->assertCreated()->json('data.id');
        $this->putJson('/api/organizations/'.$organizationId, ['contact_phone' => '+216 71 111 111'])
            ->assertOk();

        $response = $this->getJson('/api/platform/audit-logs?module=organization&organization_id='.$organizationId);
        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.actors', 1)
            ->assertJsonPath('summary.organizations', 1)
            ->assertJsonPath('data.0.actor.roles.0', 'super_admin')
            ->assertJsonPath('data.0.organization.id', $organizationId)
            ->assertJsonStructure(['data' => [['module', 'action', 'subject', 'ip_address', 'metadata']], 'facets' => ['event_types', 'actors', 'organizations']]);

        $this->get('/api/platform/audit-logs/export?event_type=organization.created')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    private function user(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }
}
