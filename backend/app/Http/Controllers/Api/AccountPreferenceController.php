<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateTimezonePreferenceRequest;
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

        return response()->json(['data' => ['timezone' => $user->timezone]]);
    }

    public function update(UpdateTimezonePreferenceRequest $request, PlatformAuditService $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $timezone = $request->validated('timezone');
        if ($user->timezone !== $timezone) {
            $user->update(['timezone' => $timezone]);
            $audit->record($user, 'account.timezone_updated', $user, 'Updated date and time display preference.', [
                'timezone' => $timezone,
            ]);
        }

        return response()->json(['data' => ['timezone' => $user->fresh()->timezone]]);
    }
}
