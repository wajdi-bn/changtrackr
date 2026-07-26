<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function update(Request $request, PlatformAuditService $audit): JsonResponse
    {
        $attributes = $request->validate([
            'action' => ['required', Rule::in(['progress', 'dismiss', 'complete'])],
            'current_step' => ['nullable', 'integer', 'min:0', 'max:10'],
            'completed_steps' => ['nullable', 'array', 'max:10'],
            'completed_steps.*' => ['string', 'max:80'],
            'tour_completed' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $existingProgress = $user->onboarding_progress ?? [];
        $progress = [
            'current_step' => (int) ($attributes['current_step'] ?? 0),
            'completed_steps' => array_values(array_unique($attributes['completed_steps'] ?? [])),
            'tour_completed' => (bool) ($attributes['tour_completed'] ?? $existingProgress['tour_completed'] ?? false),
        ];

        $updates = ['onboarding_progress' => $progress];
        if ($attributes['action'] === 'dismiss') {
            $updates['onboarding_dismissed_at'] = now();
        } elseif ($attributes['action'] === 'complete') {
            $updates['onboarding_version'] = User::ONBOARDING_VERSION;
            $updates['onboarding_completed_at'] = now();
            $updates['onboarding_dismissed_at'] = null;
            $updates['onboarding_progress']['tour_completed'] = false;
        }

        $user->forceFill($updates)->save();

        if ($attributes['action'] !== 'progress') {
            $audit->record(
                $user,
                "onboarding.{$attributes['action']}",
                $user,
                $attributes['action'] === 'complete'
                    ? 'Completed the guided workspace setup.'
                    : 'Dismissed the guided workspace setup.',
                ['version' => User::ONBOARDING_VERSION],
            );
        }

        return response()->json([
            'data' => new UserResource($user->fresh()->load('organization')),
        ]);
    }

    public function updateOrganization(Request $request, PlatformAuditService $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole('admin') && $user->organization_id !== null, 403);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_email' => [
                'nullable',
                'email',
                'max:160',
                Rule::unique('organizations', 'contact_email')->ignore($user->organization_id),
            ],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $organization = Organization::query()->findOrFail($user->organization_id);
        $organization->update($attributes);
        $audit->record($user, 'organization.onboarding_updated', $organization, 'Updated organization details during onboarding.');

        return response()->json([
            'data' => new UserResource($user->fresh()->load('organization')),
        ]);
    }

    public function storeOrganizationLogo(Request $request, PlatformAuditService $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole('admin') && $user->organization_id !== null, 403);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $organization = Organization::query()->findOrFail($user->organization_id);
        $this->deleteLocalLogo($organization->logo_url);
        $path = $request->file('logo')->store("organizations/{$organization->id}", 'public');
        $organization->update(['logo_url' => Storage::disk('public')->url($path)]);
        $audit->record($user, 'organization.logo_updated', $organization, 'Updated organization logo during onboarding.');

        return response()->json([
            'data' => new UserResource($user->fresh()->load('organization')),
        ]);
    }

    private function deleteLocalLogo(?string $logoUrl): void
    {
        if ($logoUrl === null || ! str_starts_with($logoUrl, '/storage/organizations/')) {
            return;
        }

        Storage::disk('public')->delete(ltrim(str_replace('/storage/', '', $logoUrl), '/'));
    }
}
