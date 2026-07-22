<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $filters = $this->validatedFilters($request);
        $logs = $this->applyFilters(PlatformAuditLog::query(), $filters)
            ->with(['actor:id,name,email,avatar_url', 'actor.roles:id,name', 'organization:id,name'])
            ->latest()
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return response()->json([
            'data' => $logs->through(fn (PlatformAuditLog $log) => $this->payload($log))->items(),
            'summary' => $this->summary(),
            'facets' => $this->facets(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeView($request);
        $filters = $this->validatedFilters($request);
        $logs = $this->applyFilters(PlatformAuditLog::query(), $filters)
            ->with(['actor.roles', 'organization'])
            ->latest()
            ->limit(10000)
            ->get();

        return response()->streamDownload(function () use ($logs): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fputcsv($output, ['Date/time', 'Actor', 'Email', 'Role', 'Event', 'Organization', 'Subject', 'IP address', 'Description']);
            foreach ($logs as $log) {
                $payload = $this->payload($log);
                fputcsv($output, [
                    $log->created_at?->toIso8601String(),
                    $payload['actor']['name'] ?? 'System',
                    $payload['actor']['email'] ?? '',
                    $payload['actor']['roles'][0] ?? '',
                    $log->event_type,
                    $payload['organization']['name'] ?? 'Platform',
                    trim(($payload['subject']['type'] ?? '').' #'.($payload['subject']['id'] ?? '')),
                    $payload['ip_address'],
                    $log->description,
                ]);
            }
            fclose($output);
        }, 'platform-audit-logs.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'event_type' => ['nullable', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['nullable', Rule::in(['super_admin', 'admin', 'operator', 'technician', 'client'])],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(description) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(event_type) LIKE ?', [$needle])
                    ->orWhereHas('actor', fn (Builder $query) => $query
                        ->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$needle]))
                    ->orWhereHas('organization', fn (Builder $query) => $query->whereRaw('LOWER(name) LIKE ?', [$needle])));
            })
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType) => $query->where('event_type', $eventType))
            ->when($filters['module'] ?? null, fn (Builder $query, string $module) => $query->where('event_type', 'like', $module.'.%'))
            ->when($filters['actor_id'] ?? null, fn (Builder $query, int $actorId) => $query->where('actor_id', $actorId))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->whereHas('actor.roles', fn (Builder $query) => $query->where('name', $role)))
            ->when($filters['organization_id'] ?? null, fn (Builder $query, int $organizationId) => $query->where('organization_id', $organizationId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('created_at', '<=', Carbon::parse($date)->endOfDay()));
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        return [
            'total' => PlatformAuditLog::query()->count(),
            'today' => PlatformAuditLog::query()->whereDate('created_at', today())->count(),
            'actors' => PlatformAuditLog::query()->whereNotNull('actor_id')->distinct()->count('actor_id'),
            'organizations' => PlatformAuditLog::query()->whereNotNull('organization_id')->distinct()->count('organization_id'),
        ];
    }

    /** @return array<string, mixed> */
    private function facets(): array
    {
        $actorIds = PlatformAuditLog::query()->whereNotNull('actor_id')->distinct()->pluck('actor_id');
        $organizationIds = PlatformAuditLog::query()->whereNotNull('organization_id')->distinct()->pluck('organization_id');
        $eventTypes = PlatformAuditLog::query()
            ->selectRaw('event_type, COUNT(*) as aggregate')
            ->groupBy('event_type')
            ->orderBy('event_type')
            ->get()
            ->map(fn (PlatformAuditLog $log) => ['value' => $log->event_type, 'count' => (int) $log->getAttribute('aggregate')]);

        return [
            'event_types' => $eventTypes,
            'actors' => User::query()->whereKey($actorIds)->with('roles:id,name')->orderBy('name')->get()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->getRoleNames()->first()]),
            'organizations' => Organization::query()->whereKey($organizationIds)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(PlatformAuditLog $log): array
    {
        [$module, $action] = array_pad(explode('.', $log->event_type, 2), 2, 'changed');
        $metadata = $log->metadata ?? [];

        return [
            'id' => $log->id,
            'event_type' => $log->event_type,
            'module' => $module,
            'action' => $action,
            'description' => $log->description,
            'metadata' => $metadata,
            'ip_address' => $metadata['ip_address'] ?? null,
            'created_at' => $log->created_at?->toIso8601String(),
            'subject' => $log->subject_type ? ['type' => class_basename($log->subject_type), 'id' => $log->subject_id] : null,
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'email' => $log->actor->email,
                'avatar_url' => $log->actor->avatar_url,
                'roles' => $log->actor->getRoleNames()->values(),
            ] : null,
            'organization' => $log->organization ? ['id' => $log->organization->id, 'name' => $log->organization->name] : null,
        ];
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin') && $request->user()->can('audit.view'), 403);
    }
}
