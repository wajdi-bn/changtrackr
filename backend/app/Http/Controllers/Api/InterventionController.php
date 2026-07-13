<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interventions\StoreInterventionNoteRequest;
use App\Http\Requests\Interventions\StoreInterventionRequest;
use App\Http\Requests\Interventions\UpdateInterventionRequest;
use App\Http\Resources\InterventionResource;
use App\Models\Alert;
use App\Models\Intervention;
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

class InterventionController extends Controller
{
    private const RELATIONS = ['alert', 'station', 'connector', 'assignedTechnician', 'events'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Intervention::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['assigned', 'in-progress', 'paused', 'waiting-parts', 'resolved'])],
        ]);
        /** @var User $user */
        $user = $request->user();

        $scope = Intervention::query()
            ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
            ->when($user->hasRole('technician'), fn (Builder $query) => $query->where('assigned_technician_id', $user->id));
        $summaryQuery = clone $scope;
        $interventions = $scope
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('problem', 'like', "%{$search}%")
                        ->orWhereHas('station', fn (Builder $stationQuery) => $stationQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderBy('scheduled_at')
            ->get();

        return response()->json([
            'data' => InterventionResource::collection($interventions),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'assigned' => (clone $summaryQuery)->where('status', 'assigned')->count(),
                'in_progress' => (clone $summaryQuery)->where('status', 'in-progress')->count(),
                'resolved' => (clone $summaryQuery)->where('status', 'resolved')->count(),
            ],
        ]);
    }

    public function store(StoreInterventionRequest $request, Alert $alert): JsonResponse
    {
        Gate::authorize('createIntervention', $alert);
        if ($alert->intervention()->exists()) {
            throw ValidationException::withMessages(['alert' => ['An intervention already exists for this alert.']]);
        }

        $attributes = $request->validated();
        $this->assertTechnicianScope($attributes['assigned_technician_id'], $alert->organization_id);
        /** @var User $user */
        $user = $request->user();

        $intervention = DB::transaction(function () use ($alert, $attributes, $user): Intervention {
            $intervention = Intervention::query()->create([
                ...$attributes,
                'organization_id' => $alert->organization_id,
                'alert_id' => $alert->id,
                'station_id' => $alert->station_id,
                'connector_id' => $alert->connector_id,
                'created_by_id' => $user->id,
                'reference' => 'INT-'.Str::upper(Str::random(8)),
                'status' => 'assigned',
                'priority' => $alert->severity,
                'problem' => $attributes['problem'] ?? $alert->description,
            ]);
            $technician = User::query()->findOrFail($attributes['assigned_technician_id']);
            $intervention->events()->create([
                'actor_id' => $user->id,
                'event_type' => 'assigned',
                'description' => 'Intervention assigned to '.$technician->name,
                'occurred_at' => now(),
            ]);
            $alert->update(['assigned_technician_id' => $technician->id, 'status' => 'in-progress']);
            $alert->events()->create([
                'actor_id' => $user->id,
                'event_type' => 'intervention_created',
                'description' => 'Intervention '.$intervention->reference.' created',
                'occurred_at' => now(),
            ]);

            return $intervention;
        });

        return (new InterventionResource($intervention->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Intervention $intervention): InterventionResource
    {
        Gate::authorize('view', $intervention);

        return new InterventionResource($intervention->load(self::RELATIONS));
    }

    public function update(UpdateInterventionRequest $request, Intervention $intervention): InterventionResource
    {
        Gate::authorize('update', $intervention);
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();

        if ($user->hasRole('technician')) {
            $restricted = array_intersect(array_keys($attributes), ['assigned_technician_id', 'scheduled_at', 'estimated_duration_minutes']);
            abort_if($restricted !== [], 403, 'Technicians cannot change assignment or scheduling fields.');
        }

        if (array_key_exists('assigned_technician_id', $attributes) && $attributes['assigned_technician_id'] !== null) {
            $this->assertTechnicianScope($attributes['assigned_technician_id'], $intervention->organization_id);
        }

        DB::transaction(function () use ($intervention, $attributes, $user): void {
            $previousStatus = $intervention->status;
            $nextStatus = $attributes['status'] ?? $previousStatus;
            $this->assertTransition($previousStatus, $nextStatus);

            if ($nextStatus === 'in-progress' && $intervention->started_at === null) {
                $attributes['started_at'] = now();
            }
            if ($nextStatus === 'resolved') {
                $attributes['ended_at'] = now();
                $attributes['final_status'] ??= 'Resolved';
            }

            $intervention->update($attributes);
            if ($nextStatus !== $previousStatus) {
                $intervention->events()->create([
                    'actor_id' => $user->id,
                    'event_type' => 'status_changed',
                    'description' => 'Status changed from '.$previousStatus.' to '.$nextStatus,
                    'occurred_at' => now(),
                ]);
            }

            if ($nextStatus === 'resolved') {
                $intervention->alert()->update(['status' => 'resolved', 'resolved_at' => now()]);
                $intervention->alert->events()->create([
                    'actor_id' => $user->id,
                    'event_type' => 'resolved',
                    'description' => 'Alert resolved through intervention '.$intervention->reference,
                    'occurred_at' => now(),
                ]);
                $this->syncStationAlertCount($intervention->station_id);
            }
        });

        return new InterventionResource($intervention->fresh()->load(self::RELATIONS));
    }

    public function addNote(StoreInterventionNoteRequest $request, Intervention $intervention): InterventionResource
    {
        Gate::authorize('update', $intervention);
        $intervention->events()->create([
            'actor_id' => $request->user()->id,
            'event_type' => 'note',
            'description' => $request->validated('description'),
            'occurred_at' => now(),
        ]);

        return new InterventionResource($intervention->load(self::RELATIONS));
    }

    private function assertTechnicianScope(int $technicianId, int $organizationId): void
    {
        $valid = User::query()->whereKey($technicianId)->where('organization_id', $organizationId)->role('technician')->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['assigned_technician_id' => ['The selected user is not a technician in this organization.']]);
        }
    }

    private function assertTransition(string $current, string $next): void
    {
        if ($current === $next) {
            return;
        }

        $allowed = [
            'assigned' => ['in-progress', 'resolved'],
            'in-progress' => ['paused', 'waiting-parts', 'resolved'],
            'paused' => ['in-progress', 'resolved'],
            'waiting-parts' => ['in-progress', 'resolved'],
            'resolved' => [],
        ];

        if (! in_array($next, $allowed[$current] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["Cannot change intervention status from {$current} to {$next}."]]);
        }
    }

    private function syncStationAlertCount(int $stationId): void
    {
        Station::query()->whereKey($stationId)->update([
            'open_alerts_count' => Alert::query()->where('station_id', $stationId)->where('status', '!=', 'resolved')->count(),
        ]);
    }
}
