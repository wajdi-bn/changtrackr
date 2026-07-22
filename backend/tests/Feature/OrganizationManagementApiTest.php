<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_can_manage_organizations_and_view_tenant_metrics(): void
    {
        $superAdmin = $this->user('super_admin');
        Sanctum::actingAs($superAdmin);

        $organizationId = $this->postJson('/api/organizations', [
            'name' => 'North Charge Network',
            'contact_email' => 'ops@north-charge.test',
        ])->assertCreated()->json('data.id');

        $organization = Organization::query()->findOrFail($organizationId);
        $admin = $this->user('admin', $organization);
        Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'North Hub',
            'reference' => 'NORTH-001',
            'location_name' => 'North District',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
        ]);

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'North Charge Network')
            ->assertJsonPath('summary.active', 1);

        $this->getJson('/api/organizations/'.$organization->id)
            ->assertOk()
            ->assertJsonPath('data.users_count', 1)
            ->assertJsonPath('data.stations_count', 1)
            ->assertJsonPath('data.admins.0.id', $admin->id);

        $this->putJson('/api/organizations/'.$organization->id, ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->getJson('/api/platform/audit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['event_type' => 'organization.created'])
            ->assertJsonFragment(['event_type' => 'organization.updated']);
    }

    public function test_organization_administrator_cannot_access_platform_organization_management(): void
    {
        $organization = Organization::query()->create(['name' => 'Restricted Network', 'slug' => 'restricted-network', 'status' => 'active']);
        Sanctum::actingAs($this->user('admin', $organization));

        $this->getJson('/api/organizations')->assertForbidden();
        $this->postJson('/api/organizations', ['name' => 'Unauthorized tenant'])->assertForbidden();
    }

    private function user(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }
}
