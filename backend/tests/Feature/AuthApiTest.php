<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_and_fetch_current_profile(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Test Network',
            'slug' => 'test-network-'.uniqid(),
            'status' => 'active',
        ]);

        $role = Role::findOrCreate('operator', 'web');

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'operator-test-'.uniqid().'@example.com',
            'status' => 'active',
        ]);
        $user->assignRole($role);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles.0', 'operator')
            ->assertJsonMissingPath('access_token');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive-'.uniqid().'@example.com',
            'status' => 'inactive',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_organization_employee_without_an_active_organization_cannot_login(): void
    {
        $role = Role::findOrCreate('operator', 'web');
        $user = User::factory()->create([
            'organization_id' => null,
            'email' => 'unscoped-operator-'.uniqid().'@example.com',
            'status' => 'active',
        ]);
        $user->assignRole($role);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'This account does not have a valid organization assignment.');

        $organization = Organization::query()->create([
            'name' => 'Inactive Network',
            'slug' => 'inactive-network-'.uniqid(),
            'status' => 'inactive',
        ]);
        $user->update(['organization_id' => $organization->id]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_global_roles_cannot_keep_an_organization_assignment(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Client Network',
            'slug' => 'client-network-'.uniqid(),
            'status' => 'active',
        ]);
        $role = Role::findOrCreate('client', 'web');
        $client = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'scoped-client-'.uniqid().'@example.com',
            'status' => 'active',
        ]);
        $client->assignRole($role);

        $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_existing_session_is_rejected_after_the_employee_organization_is_deactivated(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Deactivated Network',
            'slug' => 'deactivated-network-'.uniqid(),
            'status' => 'active',
        ]);
        $role = Role::findOrCreate('operator', 'web');
        $operator = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'deactivated-network-operator-'.uniqid().'@example.com',
            'status' => 'active',
        ]);
        $operator->assignRole($role);

        $this->postJson('/api/auth/login', [
            'email' => $operator->email,
            'password' => 'password',
        ])->assertOk();

        $organization->update(['status' => 'inactive']);

        $this->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account does not have a valid organization assignment.');
    }

    public function test_authenticated_user_can_logout_and_invalidate_the_session(): void
    {
        $role = Role::findOrCreate('client', 'web');
        $client = User::factory()->create([
            'organization_id' => null,
            'status' => 'active',
        ]);
        $client->assignRole($role);

        $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->app['auth']->forgetGuards();
        $this->assertGuest();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_current_profile_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
