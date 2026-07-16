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
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles.0', 'operator');

        $token = $loginResponse->json('access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
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

    public function test_existing_token_is_rejected_after_the_employee_organization_is_deactivated(): void
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

        $token = $this->postJson('/api/auth/login', [
            'email' => $operator->email,
            'password' => 'password',
        ])->assertOk()->json('access_token');

        $organization->update(['status' => 'inactive']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account does not have a valid organization assignment.');
    }

    public function test_current_profile_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
