<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_each_role_receives_its_own_reporting_contract(): void
    {
        $organization = Organization::query()->create(['name' => 'Analytics Network', 'slug' => 'analytics-network', 'status' => 'active']);
        $cases = [
            ['super_admin', null, '/api/reporting/platform', 'data.kpis.organizations'],
            ['admin', $organization, '/api/reporting/organization', 'data.business.sessions'],
            ['operator', $organization, '/api/reporting/operations', 'data.live.available'],
            ['technician', $organization, '/api/reporting/field', 'data.workload.assigned'],
        ];

        foreach ($cases as [$role, $tenant, $endpoint, $path]) {
            Sanctum::actingAs($this->user($role, $tenant));
            $this->getJson($endpoint.'?period=7d')
                ->assertOk()
                ->assertJsonPath('data.role', $role)
                ->assertJsonPath('data.period.key', '7d')
                ->assertJsonPath($path, fn ($value) => is_int($value) || is_float($value));
        }
    }

    public function test_reporting_scope_is_role_protected_and_exports_all_formats(): void
    {
        $organization = Organization::query()->create(['name' => 'Protected Analytics', 'slug' => 'protected-analytics', 'status' => 'active']);
        Sanctum::actingAs($this->user('operator', $organization));
        $this->getJson('/api/reporting/organization')->assertForbidden();

        Sanctum::actingAs($this->user('super_admin'));
        $this->get('/api/reporting/platform/export?period=30d&format=csv')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->getJson('/api/reporting/platform/export?period=30d&format=json')
            ->assertOk()
            ->assertJsonStructure(['metadata', 'data' => [['section', 'indicator', 'result', 'context']]])
            ->assertJsonPath('data.0.section', 'Platform footprint')
            ->assertJsonPath('data.0.indicator', 'Organizations');
        $this->get('/api/reporting/platform/export?period=30d&format=pdf')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    private function user(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }
}
