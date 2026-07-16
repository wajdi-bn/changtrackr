<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyClientEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['frontend.url' => 'http://localhost:5173']);
        Role::findOrCreate('client', 'web');
    }

    public function test_visitor_can_register_only_as_an_unverified_global_client(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => '  Wajdi Client  ',
            'email' => 'New.Client@Example.com ',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'terms_accepted' => true,
            'role' => 'super_admin',
            'organization_id' => 999,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('code', 'verification_required')
            ->assertJsonPath('email', 'new.client@example.com');

        $client = User::query()->where('email', 'new.client@example.com')->firstOrFail();

        $this->assertSame('Wajdi Client', $client->name);
        $this->assertNull($client->organization_id);
        $this->assertNull($client->email_verified_at);
        $this->assertTrue($client->hasRole('client'));
        $this->assertCount(1, $client->roles);
        $this->assertGuest();
        Notification::assertSentTo($client, VerifyClientEmail::class);
    }

    public function test_registration_rejects_a_case_insensitive_duplicate_and_weak_password(): void
    {
        User::factory()->create(['email' => 'Existing@Example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Duplicate Client',
            'email' => 'existing@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_unverified_client_cannot_sign_in_until_signed_link_is_used(): void
    {
        $client = User::factory()->unverified()->create([
            'organization_id' => null,
            'email' => 'verify.client@example.com',
            'status' => 'active',
        ]);
        $client->assignRole('client');

        $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $client->id, 'hash' => sha1($client->email)],
        );

        $this->get($verificationUrl)
            ->assertRedirect('http://localhost:5173/verify-email?status=verified');

        $this->assertNotNull($client->fresh()->email_verified_at);

        $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_invalid_verification_link_returns_to_the_frontend_without_verifying(): void
    {
        $client = User::factory()->unverified()->create([
            'organization_id' => null,
            'status' => 'active',
        ]);
        $client->assignRole('client');

        $this->get("/auth/email/verify/{$client->id}/invalid-hash")
            ->assertRedirect('http://localhost:5173/verify-email?status=invalid');

        $this->assertNull($client->fresh()->email_verified_at);
    }

    public function test_verification_email_can_be_resent_without_account_enumeration(): void
    {
        Notification::fake();
        $client = User::factory()->unverified()->create([
            'organization_id' => null,
            'email' => 'resend.client@example.com',
            'status' => 'active',
        ]);
        $client->assignRole('client');

        $this->postJson('/api/auth/email/resend', ['email' => $client->email])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If an unverified client account exists, a new verification email has been sent.',
            );
        Notification::assertSentTo($client, VerifyClientEmail::class);

        Notification::fake();
        $this->postJson('/api/auth/email/resend', ['email' => 'missing@example.com'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If an unverified client account exists, a new verification email has been sent.',
            );
        Notification::assertNothingSent();
    }

    public function test_client_can_request_and_complete_a_password_reset(): void
    {
        Notification::fake();
        $client = User::factory()->create([
            'organization_id' => null,
            'email' => 'reset.client@example.com',
            'status' => 'active',
        ]);
        $client->assignRole('client');

        $this->postJson('/api/auth/forgot-password', ['email' => $client->email])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If an account exists for this email, a password reset link has been sent.',
            );

        $token = null;
        Notification::assertSentTo(
            $client,
            ResetAccountPassword::class,
            function (ResetAccountPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $client->email,
            'password' => 'NewStrongPass2',
            'password_confirmation' => 'NewStrongPass2',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewStrongPass2', $client->fresh()->password));
        $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'NewStrongPass2',
        ])->assertOk();
    }

    public function test_forgot_password_response_does_not_reveal_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If an account exists for this email, a password reset link has been sent.',
            );

        Notification::assertNothingSent();
    }
}
