<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'unread'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $scope = $user->operationalNotifications();
        $notifications = (clone $scope)
            ->with('deliveries')
            ->when(($filters['status'] ?? 'all') === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->latest('id')
            ->limit((int) ($filters['limit'] ?? 20))
            ->get();

        return response()->json([
            'data' => UserNotificationResource::collection($notifications),
            'summary' => [
                'unread' => (clone $scope)->whereNull('read_at')->count(),
                'total' => (clone $scope)->count(),
            ],
        ]);
    }

    public function read(Request $request, UserNotification $userNotification): UserNotificationResource
    {
        abort_unless($userNotification->user_id === $request->user()->id, 404);
        $userNotification->markAsRead();

        return new UserNotificationResource($userNotification->fresh()->load('deliveries'));
    }

    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $user->operationalNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }
}
