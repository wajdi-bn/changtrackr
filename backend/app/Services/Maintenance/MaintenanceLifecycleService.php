<?php

namespace App\Services\Maintenance;

use App\Models\Intervention;
use App\Models\Station;
use App\Models\User;
use App\Services\Availability\AvailabilityProjectionService;
use App\Services\Ocpp\OcppCommandService;
use Illuminate\Validation\ValidationException;

class MaintenanceLifecycleService
{
    public function __construct(
        private readonly OcppCommandService $commands,
        private readonly AvailabilityProjectionService $availability,
    ) {}

    public function applyTransition(Intervention $intervention, string $previousStatus, string $nextStatus, User $actor): void
    {
        if ($intervention->maintenance_plan_id === null || $previousStatus === $nextStatus) {
            return;
        }

        if ($nextStatus === 'in-progress' && $previousStatus !== 'in-progress') {
            $this->enterMaintenance($intervention, $actor);
        }

        if (in_array($nextStatus, ['resolved', 'cancelled'], true)) {
            $this->leaveMaintenance($intervention, $actor);
            $this->completeOneTimePlan($intervention, $nextStatus);
        }
    }

    private function enterMaintenance(Intervention $intervention, User $actor): void
    {
        $station = Station::query()->lockForUpdate()->findOrFail($intervention->station_id);
        if ($station->maintenance_intervention_id !== null && $station->maintenance_intervention_id !== $intervention->id) {
            throw ValidationException::withMessages([
                'status' => ['Another maintenance intervention is already active for this station.'],
            ]);
        }
        if ($station->availability_override === 'maintenance' && $station->maintenance_intervention_id === null) {
            throw ValidationException::withMessages([
                'status' => ['The station is already in manually controlled maintenance mode. Clear it before starting this intervention.'],
            ]);
        }

        $station->update([
            'maintenance_intervention_id' => $intervention->id,
            'status_before_maintenance' => $station->status,
        ]);

        if ($station->isOcppManaged()) {
            $command = $this->commands->setMaintenanceMode($station, $actor, true, $intervention);
            $this->availability->project($station->id);
            $description = $command === null
                ? 'Maintenance mode enabled locally; the station was offline so OCPP synchronization is pending.'
                : 'Maintenance mode enabled and OCPP ChangeAvailability(Inoperative) queued.';
        } else {
            $station->update([
                'availability_override' => 'maintenance',
                'status' => 'maintenance',
                'availability_reason' => 'planned_maintenance',
                'availability_source' => 'maintenance_workflow',
                'availability_calculated_at' => now(),
            ]);
            $description = 'Maintenance mode enabled locally for this station.';
        }

        $intervention->events()->create([
            'actor_id' => $actor->id,
            'event_type' => 'maintenance_mode_enabled',
            'description' => $description,
            'occurred_at' => now(),
        ]);
    }

    private function leaveMaintenance(Intervention $intervention, User $actor): void
    {
        $station = Station::query()->lockForUpdate()->findOrFail($intervention->station_id);
        if ($station->maintenance_intervention_id !== $intervention->id) {
            return;
        }

        if ($station->isOcppManaged()) {
            $command = $this->commands->setMaintenanceMode($station, $actor, false, $intervention);
            $station->update([
                'maintenance_intervention_id' => null,
                'status_before_maintenance' => null,
            ]);
            $this->availability->project($station->id);
            $description = $command === null
                ? 'Maintenance mode cleared locally; the station was offline so no OCPP command was sent.'
                : 'Maintenance mode cleared and OCPP ChangeAvailability(Operative) queued.';
        } else {
            $restoredStatus = $station->status_before_maintenance ?: 'available';
            $station->update([
                'maintenance_intervention_id' => null,
                'status_before_maintenance' => null,
                'availability_override' => null,
                'status' => $restoredStatus,
                'availability_reason' => 'maintenance_completed',
                'availability_source' => 'maintenance_workflow',
                'availability_calculated_at' => now(),
            ]);
            $description = 'Maintenance mode cleared and the previous local station state restored.';
        }

        $intervention->events()->create([
            'actor_id' => $actor->id,
            'event_type' => 'maintenance_mode_cleared',
            'description' => $description,
            'occurred_at' => now(),
        ]);
    }

    private function completeOneTimePlan(Intervention $intervention, string $status): void
    {
        $plan = $intervention->maintenancePlan()->lockForUpdate()->first();
        if ($plan === null || $plan->isRecurring()) {
            return;
        }

        $plan->update(['status' => $status === 'cancelled' ? 'cancelled' : 'completed']);
    }
}
