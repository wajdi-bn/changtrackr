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

    public function test_current_profile_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
