<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\StoreAlertRequest;
use App\Http\Requests\Alerts\UpdateAlertRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AlertController extends Controller
{
    private const RELATIONS = ['station', 'connector', 'assignedTechnician', 'events', 'intervention'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Alert::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(['critical', 'warning', 'info'])],
            'status' => ['nullable', Rule::in(['new', 'in-progress', 'resolved'])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $scope = Alert::query()
            ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
            ->when($user->hasRole('technician'), fn (Builder $query) => $query->where('assigned_technician_id', $user->id));

        $summaryQuery = clone $scope;
        $alerts = $scope
            ->with(self::RELATIONS)
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity) => $query->where('severity', $severity))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('problem_type', 'like', "%{$search}%")
                        ->orWhereHas('station', fn (Builder $stationQuery) => $stationQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('detected_at')
            ->get();

        $technicians = $user->can('alerts.assign')
            ? User::query()
                ->role('technician')
                ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'avatar_url'])
            : collect();

        return response()->json([
            'data' => AlertResource::collection($alerts),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'critical' => (clone $summaryQuery)->where('severity', 'critical')->where('status', '!=', 'resolved')->count(),
                'new' => (clone $summaryQuery)->where('status', 'new')->count(),
                'in_progress' => (clone $summaryQuery)->where('status', 'in-progress')->count(),
            ],
            'technicians' => $technicians,
        ]);
    }

    public function store(StoreAlertRequest $request): JsonResponse
    {
        Gate::authorize('create', Alert::class);
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        $station = Station::query()->findOrFail($attributes['station_id']);
        $organizationId = $user->hasRole('super_admin') ? ($attributes['organization_id'] ?? $station->organization_id) : $user->organization_id;

        $this->assertStationScope($station, $organizationId);
        $this->assertConnectorScope($attributes['connector_id'] ?? null, $station);
        $this->assertTechnicianScope($attributes['assigned_technician_id'] ?? null, $organizationId);

        $alert = DB::transaction(function () use ($attributes, $organizationId, $user): Alert {
            $alert = Alert::query()->create([
                ...$attributes,
                'organization_id' => $organizationId,
                'reference' => 'ALT-'.Str::upper(Str::random(8)),
                'status' => 'new',
                'detected_at' => $attributes['detected_at'] ?? now(),
            ]);
            $alert->events()->create([
                'actor_id' => $user->id,
                'event_type' => 'created',
                'description' => 'Alert created by '.$user->name,
                'occurred_at' => now(),
            ]);
            $this->syncStationAlertCount($alert->station_id);

            return $alert;
        });

        return (new AlertResource($alert->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Alert $alert): AlertResource
    {
        Gate::authorize('view', $alert);

        return new AlertResource($alert->load(self::RELATIONS));
    }

    public function update(UpdateAlertRequest $request, Alert $alert): AlertResource
    {
        Gate::authorize('update', $alert);
        $attributes = $request->validated();
        /** @var User $user */
        $user = $request->user();

        if (array_key_exists('assigned_technician_id', $attributes)) {
            Gate::authorize('assign', $alert);
            $this->assertTechnicianScope($attributes['assigned_technician_id'], $alert->organization_id);
        }

        DB::transaction(function () use ($alert, $attributes, $user): void {
            $previousStatus = $alert->status;
            $previousTechnician = $alert->assigned_technician_id;
            $attributes['resolved_at'] = ($attributes['status'] ?? null) === 'resolved' ? now() : ($previousStatus === 'resolved' ? null : $alert->resolved_at);
            $alert->update($attributes);

            if (($attributes['assigned_technician_id'] ?? $previousTechnician) !== $previousTechnician) {
                $technician = User::query()->find($attributes['assigned_technician_id']);
                $alert->events()->create([
                    'actor_id' => $user->id,
                    'event_type' => 'assigned',
                    'description' => $technician ? 'Assigned to '.$technician->name : 'Technician assignment removed',
                    'occurred_at' => now(),
                ]);
            }

            if (($attributes['status'] ?? $previousStatus) !== $previousStatus) {
                $alert->events()->create([
                    'actor_id' => $user->id,
                    'event_type' => 'status_changed',
                    'description' => 'Status changed from '.$previousStatus.' to '.$attributes['status'],
                    'occurred_at' => now(),
                ]);
            }

            $this->syncStationAlertCount($alert->station_id);
        });

        return new AlertResource($alert->fresh()->load(self::RELATIONS));
    }

    private function assertStationScope(Station $station, ?int $organizationId): void
    {
        if ($organizationId === null || $station->organization_id !== $organizationId) {
            throw ValidationException::withMessages(['station_id' => ['The station does not belong to this organization.']]);
        }
    }

    private function assertConnectorScope(?int $connectorId, Station $station): void
    {
        if ($connectorId !== null && ! Connector::query()->whereKey($connectorId)->where('station_id', $station->id)->exists()) {
            throw ValidationException::withMessages(['connector_id' => ['The connector does not belong to the selected station.']]);
        }
    }

    private function assertTechnicianScope(?int $technicianId, int $organizationId): void
    {
        if ($technicianId === null) {
            return;
        }

        $valid = User::query()->whereKey($technicianId)->where('organization_id', $organizationId)->role('technician')->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['assigned_technician_id' => ['The selected user is not a technician in this organization.']]);
        }
    }

    private function syncStationAlertCount(int $stationId): void
    {
        Station::query()->whereKey($stationId)->update([
            'open_alerts_count' => Alert::query()->where('station_id', $stationId)->where('status', '!=', 'resolved')->count(),
        ]);
    }
}
