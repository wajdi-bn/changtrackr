<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountPreferenceRequest;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->preferences($user)]);
    }

    public function update(UpdateAccountPreferenceRequest $request, PlatformAuditService $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->exists('timezone')) {
            $timezone = $request->validated('timezone');
            if ($user->timezone !== $timezone) {
                $user->update(['timezone' => $timezone]);
                $audit->record($user, 'account.timezone_updated', $user, 'Updated date and time display preference.', [
                    'timezone' => $timezone,
                ]);
            }
        }

        if ($request->exists('near_me_radius_km')) {
            $radius = (int) $request->validated('near_me_radius_km');
            if ((int) $user->near_me_radius_km !== $radius) {
                $user->update(['near_me_radius_km' => $radius]);
                $audit->record($user, 'account.near_me_radius_updated', $user, 'Updated nearby station search radius.', [
                    'near_me_radius_km' => $radius,
                ]);
            }
        }

        return response()->json(['data' => $this->preferences($user->fresh())]);
    }

    /** @return array{timezone: ?string, near_me_radius_km: int} */
    private function preferences(User $user): array
    {
        return [
            'timezone' => $user->timezone,
            'near_me_radius_km' => (int) ($user->near_me_radius_km ?? 25),
        ];
    }
}
