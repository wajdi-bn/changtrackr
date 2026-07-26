<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_can_persist_dismiss_and_complete_onboarding(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->putJson('/api/onboarding', [
                'action' => 'progress',
                'current_step' => 2,
                'completed_steps' => ['welcome', 'workspace'],
            ])
            ->assertOk()
            ->assertJsonPath('data.onboarding.progress.current_step', 2)
            ->assertJsonPath('data.onboarding.should_show', true);

        $this->actingAs($client)
            ->putJson('/api/onboarding', [
                'action' => 'dismiss',
                'current_step' => 2,
                'completed_steps' => ['welcome', 'workspace'],
            ])
            ->assertOk()
            ->assertJsonPath('data.onboarding.dismissed', true)
            ->assertJsonPath('data.onboarding.should_show', false);

        $this->actingAs($client)
            ->putJson('/api/onboarding', [
                'action' => 'complete',
                'current_step' => 3,
                'completed_steps' => ['welcome', 'workspace', 'setup', 'ready'],
            ])
            ->assertOk()
            ->assertJsonPath('data.onboarding.completed', true)
            ->assertJsonPath('data.onboarding.dismissed', false);
    }

    public function test_admin_can_update_own_organization_and_upload_its_logo(): void
    {
        Storage::fake('public');
        $organization = Organization::query()->create([
            'name' => 'Initial Network',
            'slug' => 'initial-network',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
        ]);
        $admin->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($admin)
            ->putJson('/api/onboarding/organization', [
                'name' => 'Updated Network',
                'contact_email' => 'operations@example.com',
                'contact_phone' => '+216 20 000 000',
            ])
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Updated Network');

        $this->actingAs($admin)
            ->post('/api/onboarding/organization-logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 240, 240),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.organization.logo_url', fn ($value) => str_starts_with($value, '/storage/organizations/'));

        $path = ltrim(str_replace('/storage/', '', $organization->fresh()->logo_url), '/');
        Storage::disk('public')->assertExists($path);
    }

    public function test_non_admin_cannot_edit_organization_onboarding_details(): void
    {
        $this->actingAs($this->client())
            ->putJson('/api/onboarding/organization', ['name' => 'Forbidden'])
            ->assertForbidden();
    }

    private function client(): User
    {
        $client = User::factory()->create([
            'organization_id' => null,
            'status' => 'active',
        ]);
        $client->assignRole(Role::findOrCreate('client', 'web'));

        return $client;
    }
}
