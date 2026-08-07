<?php

namespace App\Services\Maintenance;

use App\Jobs\GenerateMaintenanceOccurrences;
use App\Models\Intervention;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Services\Notifications\OperationalNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenancePlanService
{
    private const OPEN_OCCURRENCE_STATUSES = ['assigned', 'in-progress', 'paused', 'waiting-parts'];

    public function __construct(private readonly OperationalNotificationService $notifications) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{plan: MaintenancePlan, occurrence: Intervention}
     */
    public function create(array $attributes, User $creator, int $organizationId): array
    {
        $result = DB::transaction(function () use ($attributes, $creator, $organizationId): array {
            $firstScheduledAt = CarbonImmutable::parse($attributes['first_scheduled_at'])->utc();
            $recurrenceEndsAt = isset($attributes['recurrence_ends_at'])
                ? CarbonImmutable::parse($attributes['recurrence_ends_at'])->utc()
                : null;

            $plan = MaintenancePlan::query()->create([
                ...$attributes,
                'organization_id' => $organizationId,
                'created_by_id' => $creator->id,
                'reference' => 'MPL-'.Str::upper(Str::random(8)),
                'status' => 'active',
                'first_scheduled_at' => $firstScheduledAt,
                'recurrence_ends_at' => $recurrenceEndsAt,
                'next_occurrence_at' => $firstScheduledAt,
                'last_occurrence_number' => 0,
            ]);

            $occurrence = $this->generateNextOccurrence($plan);

            return ['plan' => $plan->fresh(), 'occurrence' => $occurrence];
        });

        GenerateMaintenanceOccurrences::dispatch($result['plan']->id);

        return $result;
    }

    public function generateUpcoming(?int $planId = null, int $horizonDays = 90): int
    {
        $horizon = now()->utc()->addDays(max(1, $horizonDays));
        $planIds = MaintenancePlan::query()
            ->where('status', 'active')
            ->where('recurrence_frequency', '!=', 'none')
            ->where(function ($query) use ($horizon): void {
                $query->whereNull('next_occurrence_at')
                    ->orWhere('next_occurrence_at', '<=', $horizon);
            })
            ->when($planId !== null, fn ($query) => $query->whereKey($planId))
            ->orderBy('id')
            ->pluck('id');

        $generated = 0;
        foreach ($planIds as $id) {
            $generated += DB::transaction(function () use ($id, $horizon): int {
                $plan = MaintenancePlan::query()->lockForUpdate()->find($id);
                if ($plan === null || $plan->status !== 'active') {
                    return 0;
                }

                if ($this->hasOpenOccurrence($plan)) {
                    return 0;
                }

                if ($plan->next_occurrence_at === null) {
                    $plan->update(['status' => 'completed']);

                    return 0;
                }

                if ($plan->recurrence_ends_at !== null && $plan->next_occurrence_at->gt($plan->recurrence_ends_at)) {
                    $plan->update(['next_occurrence_at' => null, 'status' => 'completed']);

                    return 0;
                }

                if ($plan->next_occurrence_at->gt($horizon)) {
                    return 0;
                }

                $this->generateNextOccurrence($plan);

                return 1;
            });
        }

        return $generated;
    }

    public function advanceAfterClosure(Intervention $occurrence): ?Intervention
    {
        if ($occurrence->maintenance_plan_id === null) {
            return null;
        }

        return DB::transaction(function () use ($occurrence): ?Intervention {
            $plan = MaintenancePlan::query()->lockForUpdate()->find($occurrence->maintenance_plan_id);
            if ($plan === null || $plan->status !== 'active' || $this->hasOpenOccurrence($plan, $occurrence->id)) {
                return null;
            }

            if (! $plan->isRecurring()) {
                $plan->update(['status' => $occurrence->status === 'cancelled' ? 'cancelled' : 'completed']);

                return null;
            }

            if ($plan->next_occurrence_at === null) {
                $plan->update(['status' => 'completed']);

                return null;
            }

            return $this->generateNextOccurrence($plan);
        });
    }

    private function generateNextOccurrence(MaintenancePlan $plan): Intervention
    {
        $scheduledAt = $plan->next_occurrence_at;
        if ($scheduledAt === null) {
            throw new \LogicException('A maintenance occurrence cannot be generated without a due date.');
        }

        $occurrenceNumber = $plan->last_occurrence_number + 1;
        $technicianId = User::query()
            ->whereKey($plan->assigned_technician_id)
            ->where('organization_id', $plan->organization_id)
            ->where('status', 'active')
            ->role('technician')
            ->value('id');
        $intervention = Intervention::query()->create([
            'organization_id' => $plan->organization_id,
            'alert_id' => null,
            'maintenance_plan_id' => $plan->id,
            'maintenance_occurrence_number' => $occurrenceNumber,
            'station_id' => $plan->station_id,
            'connector_id' => $plan->connector_id,
            'assigned_technician_id' => $technicianId,
            'created_by_id' => $plan->created_by_id,
            'reference' => $plan->reference.'-'.str_pad((string) $occurrenceNumber, 3, '0', STR_PAD_LEFT),
            'status' => 'assigned',
            'priority' => $plan->priority,
            'scheduled_at' => $scheduledAt,
            'estimated_duration_minutes' => $plan->estimated_duration_minutes,
            'problem' => $plan->instructions,
        ]);
        $assignmentNote = $technicianId === null ? ' It requires reassignment to an active organization technician.' : '';
        $intervention->events()->create([
            'actor_id' => $plan->created_by_id,
            'event_type' => 'maintenance_scheduled',
            'description' => "Maintenance {$plan->reference} scheduled for {$scheduledAt->toIso8601String()}.{$assignmentNote}",
            'occurred_at' => now(),
        ]);

        $plan->update([
            'last_occurrence_number' => $occurrenceNumber,
            'last_generated_at' => now(),
            'next_occurrence_at' => $this->nextDate($plan, $occurrenceNumber),
        ]);

        $this->notifications->notifyMaintenanceScheduled(
            $intervention->loadMissing(['station', 'assignedTechnician']),
        );

        return $intervention;
    }

    private function nextDate(MaintenancePlan $plan, int $currentOccurrenceNumber): ?CarbonImmutable
    {
        if (! $plan->isRecurring()) {
            return null;
        }

        $interval = max(1, $plan->recurrence_interval);
        $offset = $interval * $currentOccurrenceNumber;
        $anchor = CarbonImmutable::instance($plan->first_scheduled_at)->utc();
        $next = match ($plan->recurrence_frequency) {
            'daily' => $anchor->addDays($offset),
            'weekly' => $anchor->addWeeks($offset),
            'monthly' => $anchor->addMonthsNoOverflow($offset),
            default => null,
        };

        if ($next !== null && $plan->recurrence_ends_at !== null && $next->gt($plan->recurrence_ends_at)) {
            return null;
        }

        return $next;
    }

    private function hasOpenOccurrence(MaintenancePlan $plan, ?int $exceptId = null): bool
    {
        return $plan->interventions()
            ->reorder()
            ->whereIn('status', self::OPEN_OCCURRENCE_STATUSES)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
