<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['frontend.url' => 'http://localhost:5173']);
        Role::findOrCreate('client', 'web');
    }

    public function test_google_redirect_starts_the_provider_flow(): void
    {
        Socialite::fake('google');

        $this->get('/auth/oauth/google/redirect')
            ->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_verified_new_google_user_is_created_as_a_global_client(): void
    {
        Socialite::fake('google', $this->googleUser(
            id: 'google-new-client',
            email: 'new.client@example.com',
            name: 'New Client',
        ));

        $this->get('/auth/oauth/google/callback')
            ->assertRedirect('http://localhost:5173/auth/google/callback');

        $client = User::query()->where('email', 'new.client@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($client);
        $this->assertNull($client->organization_id);
        $this->assertTrue($client->hasRole('client'));
        $this->assertNotNull($client->email_verified_at);
        $this->assertNotNull($client->last_login_at);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $client->id,
            'provider' => 'google',
            'provider_user_id' => 'google-new-client',
            'provider_email' => 'new.client@example.com',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_employee_is_linked_without_changing_role_or_organization(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Existing Network',
            'slug' => 'existing-network',
            'status' => 'active',
        ]);
        $operatorRole = Role::findOrCreate('operator', 'web');
        $operator = User::factory()->unverified()->create([
            'organization_id' => $organization->id,
            'email' => 'Operator@Example.com',
            'avatar_url' => null,
            'status' => 'active',
        ]);
        $operator->assignRole($operatorRole);

        Socialite::fake('google', $this->googleUser(
            id: 'google-existing-operator',
            email: 'operator@example.com',
            name: 'Google Name Must Not Replace Employee',
        ));

        $this->get('/auth/oauth/google/callback')
            ->assertRedirect('http://localhost:5173/auth/google/callback');

        $operator->refresh();

        $this->assertAuthenticatedAs($operator);
        $this->assertSame($organization->id, $operator->organization_id);
        $this->assertTrue($operator->hasRole('operator'));
        $this->assertFalse($operator->hasRole('client'));
        $this->assertNotNull($operator->email_verified_at);
        $this->assertSame('https://example.com/google-avatar.png', $operator->avatar_url);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $operator->id,
            'provider_user_id' => 'google-existing-operator',
        ]);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        Socialite::fake('google', $this->googleUser(
            id: 'google-unverified',
            email: 'unverified@example.com',
            verified: false,
        ));

        $this->get('/auth/oauth/google/callback')
            ->assertRedirect('http://localhost:5173/login?oauth_error=email_not_verified');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_inactive_existing_account_is_not_linked(): void
    {
        $inactiveClient = User::factory()->create([
            'organization_id' => null,
            'email' => 'inactive.google@example.com',
            'status' => 'inactive',
        ]);
        $inactiveClient->assignRole('client');

        Socialite::fake('google', $this->googleUser(
            id: 'google-inactive',
            email: 'inactive.google@example.com',
        ));

        $this->get('/auth/oauth/google/callback')
            ->assertRedirect('http://localhost:5173/login?oauth_error=account_inactive');

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_second_google_identity_cannot_take_over_an_existing_link(): void
    {
        $client = User::factory()->create([
            'organization_id' => null,
            'email' => 'linked.client@example.com',
            'status' => 'active',
        ]);
        $client->assignRole('client');
        $client->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'original-google-id',
            'provider_email' => $client->email,
        ]);

        Socialite::fake('google', $this->googleUser(
            id: 'different-google-id',
            email: $client->email,
        ));

        $this->get('/auth/oauth/google/callback')
            ->assertRedirect('http://localhost:5173/login?oauth_error=account_conflict');

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 1);
    }

    private function googleUser(
        string $id,
        string $email,
        string $name = 'Google User',
        bool $verified = true,
    ): SocialiteUser {
        return SocialiteUser::fake([
            'id' => $id,
            'email' => $email,
            'name' => $name,
            'avatar' => 'https://example.com/google-avatar.png',
            'email_verified' => $verified,
        ]);
    }
}
