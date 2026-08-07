<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Maintenances\StoreMaintenancePlanRequest;
use App\Http\Requests\Maintenances\UpdateMaintenanceOccurrenceRequest;
use App\Http\Resources\InterventionResource;
use App\Http\Resources\MaintenancePlanResource;
use App\Models\Connector;
use App\Models\Intervention;
use App\Models\MaintenancePlan;
use App\Models\Station;
use App\Models\User;
use App\Services\Maintenance\MaintenancePlanService;
use App\Services\PlatformAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaintenanceController extends Controller
{
    private const RELATIONS = [
        'alert', 'station', 'connector', 'assignedTechnician', 'events', 'report.submittedBy', 'photos.uploadedBy',
        'maintenancePlan.station', 'maintenancePlan.connector', 'maintenancePlan.assignedTechnician',
    ];

    public function __construct(
        private readonly MaintenancePlanService $plans,
        private readonly PlatformAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MaintenancePlan::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['assigned', 'in-progress', 'paused', 'waiting-parts', 'resolved', 'cancelled'])],
            'type' => ['nullable', Rule::in(['preventive', 'corrective'])],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $scope = Intervention::query()
            ->whereNotNull('maintenance_plan_id')
            ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
            ->when($user->hasRole('technician'), fn (Builder $query) => $query->where('assigned_technician_id', $user->id));
        $summaryQuery = clone $scope;
        $occurrences = $scope
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['station_id'] ?? null, fn (Builder $query, int $stationId) => $query->where('station_id', $stationId))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->whereHas('maintenancePlan', fn (Builder $planQuery) => $planQuery->where('type', $type)))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $from) => $query->where('scheduled_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $to) => $query->where('scheduled_at', '<=', CarbonImmutable::parse($to)->endOfDay()))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('problem', 'like', "%{$search}%")
                        ->orWhereHas('maintenancePlan', fn (Builder $planQuery) => $planQuery->where('title', 'like', "%{$search}%"))
                        ->orWhereHas('station', fn (Builder $stationQuery) => $stationQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 1 WHEN 'assigned' THEN 2 WHEN 'paused' THEN 3 WHEN 'waiting-parts' THEN 4 ELSE 5 END")
            ->orderBy('scheduled_at')
            ->get();

        $organizationId = $user->organization_id;
        $canManage = $user->can('maintenances.manage') && $organizationId !== null;
        $technicians = $canManage
            ? User::query()->role('technician')->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'avatar_url'])
            : collect();
        $stations = $canManage
            ? Station::query()->where('organization_id', $organizationId)->orderBy('name')->with('connectors')->get()
            : collect();

        return response()->json([
            'data' => InterventionResource::collection($occurrences),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'planned' => (clone $summaryQuery)->where('status', 'assigned')->count(),
                'overdue' => (clone $summaryQuery)
                    ->whereIn('status', ['assigned', 'in-progress', 'paused', 'waiting-parts'])
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '<', now())
                    ->count(),
                'in_progress' => (clone $summaryQuery)->whereIn('status', ['in-progress', 'paused', 'waiting-parts'])->count(),
                'completed' => (clone $summaryQuery)->where('status', 'resolved')->count(),
                'cancelled' => (clone $summaryQuery)->where('status', 'cancelled')->count(),
            ],
            'technicians' => $technicians,
            'stations' => $stations->map(fn (Station $station) => [
                'id' => $station->id,
                'name' => $station->name,
                'reference' => $station->reference,
                'connectors' => $station->connectors->map(fn (Connector $connector) => [
                    'id' => $connector->id,
                    'external_id' => $connector->external_id,
                    'type' => $connector->type,
                ])->values(),
            ])->values(),
        ]);
    }

    public function store(StoreMaintenancePlanRequest $request): JsonResponse
    {
        Gate::authorize('create', MaintenancePlan::class);
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        $organizationId = $user->hasRole('super_admin')
            ? (int) $attributes['organization_id']
            : (int) $user->organization_id;
        unset($attributes['organization_id']);

        $this->assertScope($attributes, $organizationId);
        $result = $this->plans->create($attributes, $user, $organizationId);

        return response()->json([
            'data' => new InterventionResource($result['occurrence']->load(self::RELATIONS)),
            'plan' => new MaintenancePlanResource($result['plan']->load(['station', 'connector', 'assignedTechnician'])),
        ], 201);
    }

    public function update(UpdateMaintenanceOccurrenceRequest $request, Intervention $maintenance): InterventionResource
    {
        abort_if($maintenance->maintenance_plan_id === null, 404);
        $plan = $maintenance->maintenancePlan()->firstOrFail();
        Gate::authorize('update', $plan);
        if ($maintenance->status !== 'assigned') {
            throw ValidationException::withMessages([
                'maintenance' => ['Only a planned maintenance occurrence can be rescheduled.'],
            ]);
        }

        $attributes = $request->validated();
        if (isset($attributes['assigned_technician_id'])) {
            $this->assertTechnicianScope((int) $attributes['assigned_technician_id'], $maintenance->organization_id);
        }

        $before = $maintenance->only(array_keys($attributes));
        DB::transaction(function () use ($maintenance, $attributes, $before, $request): void {
            $maintenance->update($attributes);
            $description = isset($attributes['scheduled_at'])
                ? sprintf(
                    'Rescheduled maintenance from %s to %s.',
                    $before['scheduled_at']
                        ? CarbonImmutable::parse($before['scheduled_at'])->toIso8601String()
                        : 'an unscheduled state',
                    CarbonImmutable::parse($maintenance->scheduled_at)->toIso8601String(),
                )
                : 'Maintenance assignment or schedule updated.';
            $maintenance->events()->create([
                'actor_id' => $request->user()->id,
                'event_type' => 'maintenance_rescheduled',
                'description' => $description,
                'occurred_at' => now(),
            ]);
            $this->audit->record(
                $request->user(),
                'maintenance.rescheduled',
                $maintenance,
                $description,
                [
                    'changed_fields' => array_keys($attributes),
                    'before' => $before,
                    'after' => $maintenance->only(array_keys($attributes)),
                ],
            );
        });

        return new InterventionResource($maintenance->fresh()->load(self::RELATIONS));
    }

    /** @param array<string, mixed> $attributes */
    private function assertScope(array $attributes, int $organizationId): void
    {
        $station = Station::query()->whereKey($attributes['station_id'])->where('organization_id', $organizationId)->first();
        if ($station === null) {
            throw ValidationException::withMessages(['station_id' => ['The selected station does not belong to this organization.']]);
        }
        if (isset($attributes['connector_id']) && ! $station->connectors()->whereKey($attributes['connector_id'])->exists()) {
            throw ValidationException::withMessages(['connector_id' => ['The selected connector does not belong to this station.']]);
        }
        $this->assertTechnicianScope((int) $attributes['assigned_technician_id'], $organizationId);
    }

    private function assertTechnicianScope(int $technicianId, int $organizationId): void
    {
        $valid = User::query()->whereKey($technicianId)->where('organization_id', $organizationId)->where('status', 'active')->role('technician')->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['assigned_technician_id' => ['The selected user is not an active technician in this organization.']]);
        }
    }
}
