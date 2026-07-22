<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformAuditLog;
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

    public function test_super_admin_can_inspect_integrations_without_receiving_secrets(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->getJson('/api/platform/integrations');

        $response->assertOk()
            ->assertJsonPath('summary.total', 5)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'name', 'category', 'provider', 'description', 'status', 'mode',
                    'configured', 'last_activity_at', 'metrics', 'safeguards',
                ]],
                'summary' => ['total', 'operational', 'attention', 'sandbox'],
                'checked_at',
            ]);

        $payload = mb_strtolower($response->getContent());
        $this->assertStringNotContainsString('client_secret', $payload);
        $this->assertStringNotContainsString('api_key', $payload);
        $this->assertStringNotContainsString('webhook_secret', $payload);
    }

    public function test_super_admin_can_update_effective_platform_settings_and_changes_are_audited(): void
    {
        $superAdmin = $this->user('super_admin');
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/platform/system-settings')
            ->assertOk()
            ->assertJsonPath('summary.settings', 13)
            ->assertJsonPath('data.settings.0.key', 'client_registration_enabled');

        $this->putJson('/api/platform/system-settings', [
            'settings' => [
                'client_registration_enabled' => false,
                'demo_requests_enabled' => false,
                'employee_invitation_expiration_hours' => 96,
                'support_email' => 'support@example.test',
            ],
        ])->assertOk()
            ->assertJsonPath('summary.overrides', 4);

        $this->assertDatabaseHas('platform_settings', [
            'key' => 'employee_invitation_expiration_hours',
            'value' => '96',
            'updated_by_id' => $superAdmin->id,
        ]);
        $this->assertDatabaseCount('platform_audit_logs', 4);

        $this->postJson('/api/auth/register', [
            'name' => 'Blocked Client',
            'email' => 'blocked-client@example.test',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
            'terms_accepted' => true,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Public client registration is currently disabled.');
        $this->postJson('/api/demo-requests', [
            'full_name' => 'Blocked Administrator',
            'email' => 'blocked-admin@example.test',
            'company_name' => 'Blocked Network',
            'objectives' => ['availability_monitoring'],
            'message' => 'We need a complete demonstration of station availability monitoring.',
            'consent_accepted' => true,
        ])->assertForbidden()
            ->assertJsonPath('message', 'New demo requests are currently disabled.');
    }

    public function test_platform_settings_reject_unknown_or_invalid_values_and_non_super_admins(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson('/api/platform/system-settings', [
            'settings' => [
                'audit_retention_days' => 7,
                'unsupported_key' => true,
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.audit_retention_days', 'settings.unsupported_key']);

        Sanctum::actingAs($this->user('admin', Organization::query()->create([
            'name' => 'Settings Restricted Network',
            'slug' => 'settings-restricted-network',
            'status' => 'active',
        ])));
        $this->getJson('/api/platform/integrations')->assertForbidden();
        $this->getJson('/api/platform/system-settings')->assertForbidden();
        $this->putJson('/api/platform/system-settings', ['settings' => ['demo_requests_enabled' => false]])->assertForbidden();
    }

    public function test_audit_pruning_uses_the_configured_retention_period(): void
    {
        $superAdmin = $this->user('super_admin');
        Sanctum::actingAs($superAdmin);
        $this->putJson('/api/platform/system-settings', [
            'settings' => ['audit_retention_days' => 30],
        ])->assertOk();

        $oldLog = PlatformAuditLog::query()->create([
            'actor_id' => $superAdmin->id,
            'event_type' => 'test.old',
            'description' => 'Old audit entry.',
        ]);
        $oldLog->timestamps = false;
        $oldLog->created_at = now()->subDays(31);
        $oldLog->updated_at = now()->subDays(31);
        $oldLog->save();
        PlatformAuditLog::query()->create([
            'actor_id' => $superAdmin->id,
            'event_type' => 'test.recent',
            'description' => 'Recent audit entry.',
        ]);

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertDatabaseMissing('platform_audit_logs', ['event_type' => 'test.old']);
        $this->assertDatabaseHas('platform_audit_logs', ['event_type' => 'test.recent']);
    }

    private function user(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }
}
