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
use App\Services\Maintenance\MaintenanceLifecycleService;
use App\Services\Notifications\OperationalNotificationService;
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
    private const RELATIONS = [
        'alert', 'maintenancePlan', 'station', 'connector', 'assignedTechnician', 'events',
        'report.submittedBy', 'photos.uploadedBy',
    ];

    public function __construct(
        private readonly MaintenanceLifecycleService $maintenanceLifecycle,
        private readonly OperationalNotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Intervention::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['assigned', 'in-progress', 'paused', 'waiting-parts', 'resolved', 'cancelled'])],
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

        $technicians = $user->can('interventions.manage')
            ? User::query()
                ->role('technician')
                ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'avatar_url'])
            : collect();

        return response()->json([
            'data' => InterventionResource::collection($interventions),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'assigned' => (clone $summaryQuery)->where('status', 'assigned')->count(),
                'in_progress' => (clone $summaryQuery)->where('status', 'in-progress')->count(),
                'resolved' => (clone $summaryQuery)->where('status', 'resolved')->count(),
                'cancelled' => (clone $summaryQuery)->where('status', 'cancelled')->count(),
            ],
            'technicians' => $technicians,
        ]);
    }

    public function store(StoreInterventionRequest $request, Alert $alert): JsonResponse
    {
        Gate::authorize('createIntervention', $alert);
        if ($alert->interventions()->whereIn('status', ['assigned', 'in-progress', 'paused', 'waiting-parts'])->exists()) {
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

        $this->notifications->notifyInterventionAssigned(
            $intervention->loadMissing(['station', 'assignedTechnician']),
            (int) $intervention->events()->where('event_type', 'assigned')->latest('id')->value('id'),
        );

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

        if (in_array($intervention->status, ['resolved', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'intervention' => ['A completed or cancelled intervention is read-only.'],
            ]);
        }
        if (($attributes['status'] ?? null) === 'resolved') {
            throw ValidationException::withMessages([
                'status' => ['Complete the guided final report to resolve this intervention.'],
            ]);
        }

        if ($user->hasRole('technician')) {
            $restricted = array_intersect(array_keys($attributes), ['assigned_technician_id', 'scheduled_at', 'estimated_duration_minutes']);
            abort_if($restricted !== [], 403, 'Technicians cannot change assignment or scheduling fields.');
            abort_if(($attributes['status'] ?? null) === 'cancelled', 403, 'Technicians cannot cancel interventions.');
        }

        if ($intervention->maintenance_plan_id !== null
            && isset($attributes['status'])
            && in_array($attributes['status'], ['in-progress', 'resolved'], true)
            && ! $user->hasRole('technician')) {
            abort(403, 'Only the assigned technician can start or complete a planned maintenance intervention.');
        }

        if (array_key_exists('assigned_technician_id', $attributes) && $attributes['assigned_technician_id'] !== null) {
            $this->assertTechnicianScope($attributes['assigned_technician_id'], $intervention->organization_id);
        }

        $previousStatus = $intervention->status;
        $previousTechnician = $intervention->assigned_technician_id;

        DB::transaction(function () use ($intervention, $attributes, $user, $previousStatus, $previousTechnician): void {
            $nextStatus = $attributes['status'] ?? $previousStatus;
            $this->assertTransition($intervention, $previousStatus, $nextStatus);

            if ($nextStatus === 'in-progress' && $intervention->started_at === null) {
                $attributes['started_at'] = now();
            }
            if ($nextStatus === 'cancelled') {
                $attributes['ended_at'] = now();
                $attributes['final_status'] ??= 'Cancelled';
            }

            $intervention->update($attributes);
            if ($intervention->assigned_technician_id !== $previousTechnician) {
                $technician = $intervention->assignedTechnician;
                $intervention->events()->create([
                    'actor_id' => $user->id,
                    'event_type' => 'assigned',
                    'description' => $technician ? 'Intervention assigned to '.$technician->name : 'Technician assignment removed',
                    'occurred_at' => now(),
                ]);
            }
            if ($nextStatus !== $previousStatus) {
                $intervention->events()->create([
                    'actor_id' => $user->id,
                    'event_type' => 'status_changed',
                    'description' => 'Status changed from '.$previousStatus.' to '.$nextStatus,
                    'occurred_at' => now(),
                ]);
            }

            $this->maintenanceLifecycle->applyTransition($intervention, $previousStatus, $nextStatus, $user);

            if ($nextStatus === 'cancelled' && $intervention->alert_id !== null) {
                $intervention->alert()->update([
                    'status' => 'new',
                    'assigned_technician_id' => null,
                    'resolved_at' => null,
                ]);
                $intervention->alert->events()->create([
                    'actor_id' => $user->id,
                    'event_type' => 'intervention_cancelled',
                    'description' => 'Intervention '.$intervention->reference.' cancelled; alert returned to the assignment queue',
                    'occurred_at' => now(),
                ]);
                $this->syncStationAlertCount($intervention->station_id);
            }
        });

        $intervention->refresh()->loadMissing(['station', 'assignedTechnician']);
        if ($intervention->assigned_technician_id !== $previousTechnician && $intervention->assignedTechnician !== null) {
            $assignmentEventId = (int) $intervention->events()->where('event_type', 'assigned')->latest('id')->value('id');
            $this->notifications->notifyInterventionAssigned($intervention, $assignmentEventId);
        }
        if ($intervention->status !== $previousStatus) {
            $statusEventId = (int) $intervention->events()->where('event_type', 'status_changed')->latest('id')->value('id');
            $this->notifications->notifyInterventionStatusChanged($intervention, $previousStatus, $statusEventId);
        }

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

    private function assertTransition(Intervention $intervention, string $current, string $next): void
    {
        if ($current === $next) {
            return;
        }

        $allowed = [
            'assigned' => $intervention->maintenance_plan_id === null
                ? ['in-progress', 'resolved', 'cancelled']
                : ['in-progress', 'cancelled'],
            'in-progress' => ['paused', 'waiting-parts', 'resolved', 'cancelled'],
            'paused' => ['in-progress', 'resolved', 'cancelled'],
            'waiting-parts' => ['in-progress', 'resolved', 'cancelled'],
            'resolved' => [],
            'cancelled' => [],
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
