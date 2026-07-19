<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class RealtimeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        Broadcast::purge();
        require base_path('routes/channels.php');
    }

    public function test_broadcast_authorization_preflight_allows_the_frontend_origin(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/broadcasting/auth')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_operator_can_only_authorize_their_organization_station_channel(): void
    {
        $organization = $this->organization('operator-network');
        $otherOrganization = $this->organization('other-network');
        $operator = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
        ]);
        $operator->assignRole('operator');

        $this->actingAs($operator)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-organizations.{$organization->id}.stations",
            ])->assertOk()->assertJsonStructure(['auth']);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-organizations.{$otherOrganization->id}.stations",
        ])->assertForbidden();
    }

    public function test_client_and_super_admin_use_separate_global_channels(): void
    {
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $client->assignRole('client');
        $superAdmin = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($client)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-stations.public',
            ])->assertOk();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-stations.super-admin',
        ])->assertForbidden();

        $this->actingAs($superAdmin)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-stations.super-admin',
            ])->assertOk();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-stations.public',
        ])->assertForbidden();
    }

    public function test_session_channels_are_isolated_by_client_and_organization(): void
    {
        $organization = $this->organization('session-network');
        $otherOrganization = $this->organization('other-session-network');
        $operator = User::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $operator->assignRole('operator');
        $client = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $client->assignRole('client');
        $otherClient = User::factory()->create(['organization_id' => null, 'status' => 'active']);
        $otherClient->assignRole('client');

        $this->actingAs($operator)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-organizations.{$organization->id}.sessions",
            ])->assertOk();
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-organizations.{$otherOrganization->id}.sessions",
        ])->assertForbidden();

        $this->actingAs($client)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-users.{$client->id}.sessions",
            ])->assertOk();
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-users.{$otherClient->id}.sessions",
        ])->assertForbidden();
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create([
            'name' => str($slug)->headline(),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }
}
