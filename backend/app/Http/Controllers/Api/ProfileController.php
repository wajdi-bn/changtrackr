<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreProfileAvatarRequest;
use App\Http\Requests\Profile\UpdateOwnProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($this->loadProfile($request->user()));
    }

    public function update(UpdateOwnProfileRequest $request, PlatformAuditService $audit): ProfileResource
    {
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        if (array_key_exists('country_code', $attributes) && $attributes['country_code'] !== null) {
            $attributes['country_code'] = strtoupper($attributes['country_code']);
        }
        $attributes['address'] = $this->legacyAddress($attributes, $user);

        $before = $user->only(array_keys($attributes));
        $user->update($attributes);

        $changedFields = collect($attributes)
            ->filter(fn ($value, string $key) => $before[$key] !== $value)
            ->keys()
            ->values()
            ->all();
        if ($changedFields !== []) {
            $audit->record($user, 'profile.updated', $user, 'Updated own profile information.', [
                'changed_fields' => $changedFields,
            ]);
        }

        return new ProfileResource($this->loadProfile($user->refresh()));
    }

    public function storeAvatar(StoreProfileAvatarRequest $request, PlatformAuditService $audit): ProfileResource
    {
        /** @var User $user */
        $user = $request->user();
        $this->deleteLocalAvatar($user->avatar_url);
        $path = $request->file('avatar')->store("avatars/{$user->id}", 'public');
        $user->update(['avatar_url' => Storage::disk('public')->url($path)]);
        $audit->record($user, 'profile.avatar_updated', $user, 'Updated own profile avatar.');

        return new ProfileResource($this->loadProfile($user->refresh()));
    }

    public function destroyAvatar(Request $request, PlatformAuditService $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->deleteLocalAvatar($user->avatar_url);
        $user->update(['avatar_url' => null]);
        $audit->record($user, 'profile.avatar_removed', $user, 'Removed own profile avatar.');

        return response()->json(['data' => new ProfileResource($this->loadProfile($user->refresh()))]);
    }

    private function loadProfile(User $user): User
    {
        return $user->load(['organization', 'socialAccounts']);
    }

    /** @param array<string, mixed> $attributes */
    private function legacyAddress(array $attributes, User $user): ?string
    {
        $addressLineOne = array_key_exists('address_line_1', $attributes)
            ? $attributes['address_line_1']
            : ($user->address_line_1 ?? $user->address);
        $city = array_key_exists('city', $attributes) ? $attributes['city'] : $user->city;
        $region = array_key_exists('region', $attributes) ? $attributes['region'] : $user->region;
        $countryCode = array_key_exists('country_code', $attributes) ? $attributes['country_code'] : $user->country_code;
        $segments = array_filter([
            $addressLineOne,
            $city,
            $region,
            $countryCode,
        ]);

        return $segments === [] ? null : implode(', ', $segments);
    }

    private function deleteLocalAvatar(?string $avatarUrl): void
    {
        if ($avatarUrl === null || ! str_starts_with($avatarUrl, '/storage/avatars/')) {
            return;
        }

        Storage::disk('public')->delete(ltrim(str_replace('/storage/', '', $avatarUrl), '/'));
    }
}
