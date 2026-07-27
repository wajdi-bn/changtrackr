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
    private const CONTEXT_CATEGORIES = [
        'alerts' => ['alert', 'assignment', 'sla'],
        'interventions' => ['intervention'],
        'maintenance' => ['maintenance'],
        'payments' => ['payment'],
        'reports' => ['report'],
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'unread'])],
            'category' => ['nullable', 'string', 'max:40'],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $scope = $user->operationalNotifications();
        $notifications = (clone $scope)
            ->with('deliveries')
            ->when(($filters['status'] ?? 'all') === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($filters['severity'] ?? null, fn ($query, string $severity) => $query->where('severity', $severity))
            ->latest('id')
            ->limit((int) ($filters['limit'] ?? 20))
            ->get();
        $unreadByCategory = (clone $scope)
            ->whereNull('read_at')
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category')
            ->map(fn ($value) => (int) $value)
            ->all();

        return response()->json([
            'data' => UserNotificationResource::collection($notifications),
            'summary' => [
                'unread' => (clone $scope)->whereNull('read_at')->count(),
                'total' => (clone $scope)->count(),
                'unread_by_category' => $unreadByCategory,
                'unread_by_context' => collect(self::CONTEXT_CATEGORIES)
                    ->map(fn (array $categories) => collect($categories)->sum(fn (string $category) => $unreadByCategory[$category] ?? 0))
                    ->all(),
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

    public function readContext(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'context' => ['required', Rule::in(array_keys(self::CONTEXT_CATEGORIES))],
        ]);
        /** @var User $user */
        $user = $request->user();
        $updated = $user->operationalNotifications()
            ->whereNull('read_at')
            ->whereIn('category', self::CONTEXT_CATEGORIES[$attributes['context']])
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }
}
