<?php

namespace App\Services\Maintenance;

use App\Jobs\GenerateMaintenanceOccurrences;
use App\Models\Intervention;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Services\Notifications\OperationalNotificationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenancePlanService
{
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
            ->whereNotNull('next_occurrence_at')
            ->where('next_occurrence_at', '<=', $horizon)
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

                $count = 0;
                while ($plan->next_occurrence_at !== null && $plan->next_occurrence_at->lte($horizon)) {
                    if ($plan->recurrence_ends_at !== null && $plan->next_occurrence_at->gt($plan->recurrence_ends_at)) {
                        $plan->update(['next_occurrence_at' => null, 'status' => 'completed']);
                        break;
                    }

                    $this->generateNextOccurrence($plan);
                    $plan->refresh();
                    $count++;
                }

                return $count;
            });
        }

        return $generated;
    }

    private function generateNextOccurrence(MaintenancePlan $plan): Intervention
    {
        $scheduledAt = $plan->next_occurrence_at;
        if ($scheduledAt === null) {
            throw new \LogicException('A maintenance occurrence cannot be generated without a due date.');
        }

        $occurrenceNumber = $plan->last_occurrence_number + 1;
        $intervention = Intervention::query()->create([
            'organization_id' => $plan->organization_id,
            'alert_id' => null,
            'maintenance_plan_id' => $plan->id,
            'maintenance_occurrence_number' => $occurrenceNumber,
            'station_id' => $plan->station_id,
            'connector_id' => $plan->connector_id,
            'assigned_technician_id' => $plan->assigned_technician_id,
            'created_by_id' => $plan->created_by_id,
            'reference' => $plan->reference.'-'.str_pad((string) $occurrenceNumber, 3, '0', STR_PAD_LEFT),
            'status' => 'assigned',
            'priority' => $plan->priority,
            'scheduled_at' => $scheduledAt,
            'estimated_duration_minutes' => $plan->estimated_duration_minutes,
            'problem' => $plan->instructions,
        ]);
        $intervention->events()->create([
            'actor_id' => $plan->created_by_id,
            'event_type' => 'maintenance_scheduled',
            'description' => "Maintenance {$plan->reference} scheduled for {$scheduledAt->toIso8601String()}",
            'occurred_at' => now(),
        ]);

        $plan->update([
            'last_occurrence_number' => $occurrenceNumber,
            'last_generated_at' => now(),
            'next_occurrence_at' => $this->nextDate($plan, $scheduledAt),
        ]);

        $this->notifications->notifyMaintenanceScheduled(
            $intervention->loadMissing(['station', 'assignedTechnician']),
        );

        return $intervention;
    }

    private function nextDate(MaintenancePlan $plan, CarbonInterface $current): ?CarbonInterface
    {
        if (! $plan->isRecurring()) {
            return null;
        }

        $interval = max(1, $plan->recurrence_interval);
        $next = match ($plan->recurrence_frequency) {
            'daily' => $current->copy()->addDays($interval),
            'weekly' => $current->copy()->addWeeks($interval),
            'monthly' => $current->copy()->addMonthsNoOverflow($interval),
            default => null,
        };

        if ($next !== null && $plan->recurrence_ends_at !== null && $next->gt($plan->recurrence_ends_at)) {
            return null;
        }

        return $next;
    }
}
