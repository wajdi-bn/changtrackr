<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'event_type' => ['nullable', Rule::in(['organization.created', 'organization.updated'])],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = PlatformAuditLog::query()
            ->with(['actor:id,name,email,avatar_url', 'organization:id,name'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(fn ($query) => $query
                    ->whereRaw('LOWER(description) LIKE ?', [$needle])
                    ->orWhereHas('actor', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$needle]))
                    ->orWhereHas('organization', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$needle])));
            })
            ->when($filters['event_type'] ?? null, fn ($query, string $eventType) => $query->where('event_type', $eventType))
            ->when($filters['organization_id'] ?? null, fn ($query, int $id) => $query->where('organization_id', $id))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $logs->through(fn (PlatformAuditLog $log) => [
                'id' => $log->id,
                'event_type' => $log->event_type,
                'description' => $log->description,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'email' => $log->actor->email, 'avatar_url' => $log->actor->avatar_url] : null,
                'organization' => $log->organization ? ['id' => $log->organization->id, 'name' => $log->organization->name] : null,
            ])->items(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
