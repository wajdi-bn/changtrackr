<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authenticated_user_can_view_and_update_its_own_profile_without_changing_access_context(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Profile Network',
            'slug' => 'profile-network',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'team' => 'Network Operations',
            'address' => 'Legacy address',
        ]);
        $user->assignRole('operator');
        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.address.address_line_1', 'Legacy address')
            ->assertJsonPath('data.user.team', 'Network Operations');

        $this->putJson('/api/profile', [
            'name' => 'Updated Operator',
            'phone' => '+216 20 000 000',
            'job_title' => 'Network Operator',
            'bio' => 'Monitors charging network availability.',
            'address_line_1' => '12 Avenue Habib Bourguiba',
            'city' => 'Tunis',
            'region' => 'Tunis',
            'country_code' => 'tn',
            'linkedin_url' => 'https://www.linkedin.com/in/operator',
            'website_url' => 'https://example.com',
            'status' => 'inactive',
            'organization_id' => null,
            'team' => 'Injected team',
        ])
            ->assertOk()
            ->assertJsonPath('data.personal.name', 'Updated Operator')
            ->assertJsonPath('data.address.country_code', 'TN')
            ->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.user.organization.id', $organization->id)
            ->assertJsonPath('data.user.team', 'Network Operations');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Operator',
            'status' => 'active',
            'organization_id' => $organization->id,
            'team' => 'Network Operations',
            'country_code' => 'TN',
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $user->id,
            'event_type' => 'profile.updated',
        ]);

        $this->putJson('/api/profile', ['name' => 'Updated Operator Again'])
            ->assertOk()
            ->assertJsonPath('data.personal.name', 'Updated Operator Again')
            ->assertJsonPath('data.address.city', 'Tunis')
            ->assertJsonPath('data.address.country_code', 'TN');
    }

    public function test_profile_requires_an_authenticated_session(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', ['name' => 'Guest'])->assertUnauthorized();
    }

    public function test_user_can_choose_a_time_zone_or_follow_its_device_time_zone(): void
    {
        $user = User::factory()->create(['status' => 'active', 'timezone' => null]);
        $user->assignRole('client');
        Sanctum::actingAs($user);

        $this->getJson('/api/account-preferences')
            ->assertOk()
            ->assertJsonPath('data.timezone', null)
            ->assertJsonPath('data.near_me_radius_km', 25);

        $this->putJson('/api/account-preferences', ['timezone' => 'Africa/Tunis'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Africa/Tunis');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Africa/Tunis']);

        $this->putJson('/api/account-preferences', ['timezone' => null])
            ->assertOk()
            ->assertJsonPath('data.timezone', null);
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $user->id,
            'event_type' => 'account.timezone_updated',
        ]);

        $this->putJson('/api/account-preferences', ['near_me_radius_km' => 50])
            ->assertOk()
            ->assertJsonPath('data.timezone', null)
            ->assertJsonPath('data.near_me_radius_km', 50);
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $user->id,
            'event_type' => 'account.near_me_radius_updated',
        ]);

        $this->putJson('/api/account-preferences', ['near_me_radius_km' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('near_me_radius_km');
    }

    public function test_local_account_can_change_its_password_but_google_only_account_cannot(): void
    {
        $localUser = User::factory()->create([
            'status' => 'active',
            'password' => Hash::make('CurrentPass1'),
            'password_login_enabled' => true,
        ]);
        $localUser->assignRole('client');
        Sanctum::actingAs($localUser);

        $this->getJson('/api/account-security')
            ->assertOk()
            ->assertJsonPath('data.password_login_enabled', true);

        $this->putJson('/api/account-security/password', [
            'current_password' => 'CurrentPass1',
            'password' => 'UpdatedPass2',
            'password_confirmation' => 'UpdatedPass2',
        ])->assertOk();
        $this->assertTrue(Hash::check('UpdatedPass2', $localUser->fresh()->password));
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_id' => $localUser->id,
            'event_type' => 'account.password_changed',
        ]);

        $googleOnlyUser = User::factory()->create([
            'status' => 'active',
            'password_login_enabled' => false,
        ]);
        $googleOnlyUser->assignRole('client');
        $googleOnlyUser->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-only-profile-test',
            'provider_email' => $googleOnlyUser->email,
        ]);
        Sanctum::actingAs($googleOnlyUser);

        $this->getJson('/api/account-security')
            ->assertOk()
            ->assertJsonPath('data.password_login_enabled', false)
            ->assertJsonPath('data.sign_in_providers.0', 'google');
        $this->putJson('/api/account-security/password', [
            'current_password' => 'AnyPassword1',
            'password' => 'UpdatedPass2',
            'password_confirmation' => 'UpdatedPass2',
        ])->assertForbidden();
    }

    public function test_user_can_replace_and_remove_only_its_local_profile_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('client');
        Sanctum::actingAs($user);

        $avatarUrl = $this->post('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('profile.png', 160, 160),
        ])
            ->assertOk()
            ->json('data.user.avatar_url');

        $this->assertIsString($avatarUrl);
        $path = ltrim(str_replace('/storage/', '', (string) parse_url($avatarUrl, PHP_URL_PATH)), '/');
        Storage::disk('public')->assertExists($path);

        $this->deleteJson('/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.user.avatar_url', null);

        Storage::disk('public')->assertMissing($path);
    }
}
