<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_organization_employee_search_is_limited_to_their_tenant_and_permissions(): void
    {
        $organization = $this->organization('visible-network');
        $otherOrganization = $this->organization('hidden-network');
        $admin = $this->user($organization, 'admin', 'Searchable Admin');
        $this->user($otherOrganization, 'operator', 'Searchable Hidden Operator');
        $visibleStation = $this->station($organization, 'Searchable Visible Hub');
        $this->station($otherOrganization, 'Searchable Hidden Hub');
        $this->alert($organization, $visibleStation, 'Searchable visible alert');
        $this->alert($otherOrganization, $this->station($otherOrganization, 'Other Hub'), 'Searchable hidden alert');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/search?q=Searchable')
            ->assertOk()
            ->assertJsonPath('summary.groups.People', 1)
            ->assertJsonPath('summary.groups.Stations', 1)
            ->assertJsonPath('summary.groups.Alerts', 1);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Searchable Admin'));
        $this->assertTrue($titles->contains('Searchable Visible Hub'));
        $this->assertTrue($titles->contains('Searchable visible alert'));
        $this->assertFalse($titles->contains('Searchable Hidden Operator'));
        $this->assertFalse($titles->contains('Searchable Hidden Hub'));
        $this->assertFalse($titles->contains('Searchable hidden alert'));
    }

    public function test_client_search_returns_public_stations_and_only_their_sessions_domain(): void
    {
        $organization = $this->organization('public-network');
        $client = User::factory()->create(['status' => 'active']);
        $client->assignRole('client');
        $this->station($organization, 'Searchable Public Hub');
        $this->user($organization, 'operator', 'Searchable Operator');
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/search?q=Searchable')
            ->assertOk()
            ->assertJsonPath('summary.groups.Stations', 1);

        $groups = collect($response->json('data'))->pluck('group')->unique();
        $this->assertTrue($groups->contains('Stations'));
        $this->assertFalse($groups->contains('People'));
        $this->assertFalse($groups->contains('Alerts'));
    }

    public function test_super_administrator_can_search_across_organizations(): void
    {
        $superAdmin = User::factory()->create(['status' => 'active']);
        $superAdmin->assignRole('super_admin');
        $first = $this->organization('searchable-alpha');
        $second = $this->organization('searchable-beta');
        $this->station($first, 'Searchable Alpha Hub');
        $this->station($second, 'Searchable Beta Hub');
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/search?q=Searchable')
            ->assertOk()
            ->assertJsonPath('summary.groups.Organizations', 2)
            ->assertJsonPath('summary.groups.Stations', 2);
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create([
            'name' => str($slug)->replace('-', ' ')->title(),
            'slug' => $slug,
            'contact_email' => $slug.'@example.test',
            'status' => 'active',
        ]);
    }

    private function user(Organization $organization, string $role, string $name): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function station(Organization $organization, string $name): Station
    {
        return Station::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'reference' => 'CT-'.strtoupper(substr(md5($organization->slug.$name), 0, 10)),
            'location_name' => 'Searchable location',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
        ]);
    }

    private function alert(Organization $organization, Station $station, string $title): Alert
    {
        return Alert::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'reference' => 'ALT-'.strtoupper(substr(md5($title), 0, 8)),
            'title' => $title,
            'problem_type' => 'Searchable communication issue',
            'severity' => 'warning',
            'status' => 'new',
            'source' => 'test',
            'description' => 'Search coverage.',
            'detected_at' => now(),
        ]);
    }
}
