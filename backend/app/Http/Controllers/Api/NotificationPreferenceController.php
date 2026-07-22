<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    /** @var array<string, bool> */
    private const DEFAULTS = [
        'email_alerts' => true,
        'email_assignments' => true,
        'email_interventions' => true,
        'email_maintenance' => true,
        'email_sla' => true,
        'email_payments' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->preferences($user)]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update([
            'notification_preferences' => [
                ...$this->preferences($user),
                ...$request->validated(),
            ],
        ]);

        return response()->json(['data' => $this->preferences($user->fresh())]);
    }

    /** @return array<string, bool> */
    private function preferences(User $user): array
    {
        return [
            ...self::DEFAULTS,
            ...array_filter((array) $user->notification_preferences, 'is_bool'),
        ];
    }
}
