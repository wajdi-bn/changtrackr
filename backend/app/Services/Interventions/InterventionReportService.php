<?php

namespace App\Services\Interventions;

use App\Models\Alert;
use App\Models\Intervention;
use App\Models\InterventionReport;
use App\Models\Station;
use App\Models\User;
use App\Services\Maintenance\MaintenanceLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InterventionReportService
{
    public function __construct(private readonly MaintenanceLifecycleService $maintenanceLifecycle) {}

    /** @param array<string, mixed> $attributes */
    public function submit(Intervention $intervention, array $attributes, User $actor): Intervention
    {
        return DB::transaction(function () use ($intervention, $attributes, $actor): Intervention {
            $intervention = Intervention::query()->lockForUpdate()->findOrFail($intervention->id);
            if (! in_array($intervention->status, ['in-progress', 'paused', 'waiting-parts'], true)) {
                throw ValidationException::withMessages([
                    'intervention' => ['Only an active intervention can be completed.'],
                ]);
            }
            if ($intervention->report()->exists()) {
                throw ValidationException::withMessages([
                    'report' => ['The final report has already been submitted and cannot be changed.'],
                ]);
            }

            $photoCounts = $intervention->photos()
                ->reorder()
                ->selectRaw('phase, count(*) as aggregate')
                ->whereIn('phase', ['before', 'after'])
                ->groupBy('phase')
                ->pluck('aggregate', 'phase');
            if (($photoCounts['before'] ?? 0) < 1 || ($photoCounts['after'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'photos' => ['Add at least one before photo and one after photo before submitting the report.'],
                ]);
            }

            $previousStatus = $intervention->status;
            $endedAt = now();
            $actualDuration = max(1, (int) ceil($intervention->started_at?->diffInMinutes($endedAt) ?? 1));
            $outcomeLabel = match ($attributes['final_outcome']) {
                'operational' => 'Operational',
                'operational-monitoring' => 'Operational - monitoring required',
                default => 'Follow-up required',
            };

            InterventionReport::query()->create([
                ...$attributes,
                'intervention_id' => $intervention->id,
                'submitted_by_id' => $actor->id,
                'actual_duration_minutes' => $actualDuration,
                'submitted_at' => $endedAt,
            ]);
            $intervention->update([
                'status' => 'resolved',
                'ended_at' => $endedAt,
                'diagnosis' => $attributes['diagnosis'],
                'resolution' => $attributes['actions_taken'],
                'final_status' => $outcomeLabel,
                'comments' => $attributes['observations'] ?? null,
                'parts' => $attributes['parts'],
            ]);
            $intervention->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'report_submitted',
                'description' => 'Final intervention report submitted with outcome: '.$outcomeLabel.'.',
                'occurred_at' => $endedAt,
            ]);

            $this->maintenanceLifecycle->applyTransition($intervention, $previousStatus, 'resolved', $actor);
            $this->updateLinkedAlert($intervention, $attributes['final_outcome'], $actor);

            return $intervention->fresh();
        });
    }

    private function updateLinkedAlert(Intervention $intervention, string $outcome, User $actor): void
    {
        if ($intervention->alert_id === null) {
            return;
        }

        $alert = $intervention->alert()->lockForUpdate()->firstOrFail();
        if ($outcome === 'follow-up-required') {
            $alert->update([
                'status' => 'new',
                'assigned_technician_id' => null,
                'resolved_at' => null,
            ]);
            $alert->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'follow_up_required',
                'description' => 'Intervention '.$intervention->reference.' was completed but further work is required.',
                'occurred_at' => now(),
            ]);
        } else {
            $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
            $alert->events()->create([
                'actor_id' => $actor->id,
                'event_type' => 'resolved',
                'description' => 'Alert resolved through intervention '.$intervention->reference.'.',
                'occurred_at' => now(),
            ]);
        }

        Station::query()->whereKey($intervention->station_id)->update([
            'open_alerts_count' => Alert::query()
                ->where('station_id', $intervention->station_id)
                ->where('status', '!=', 'resolved')
                ->count(),
        ]);
    }
}
