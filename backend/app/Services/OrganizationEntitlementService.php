<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class OrganizationEntitlementService
{
    public function __construct(private readonly PlatformSettingService $settings) {}

    public function assertCanInviteEmployee(Organization $organization, string $role): void
    {
        $subscription = $organization->commercialSubscription()->with('plan')->first();
        $limit = $this->limits($organization)['employees'];
        if ($limit === null) {
            return;
        }

        $employeeQuery = User::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'pending'])
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['operator', 'technician']));
        $employees = (clone $employeeQuery)->count();
        if ($employees >= $limit) {
            throw ValidationException::withMessages([
                'plan_limit' => ["This organization has reached its limit of {$limit} operator and technician accounts."],
            ]);
        }

        if ($subscription && $this->usesTrialLimits($subscription->status, $subscription->current_period_ends_at !== null)) {
            $roleLimit = $this->settings->integer("trial_{$role}_limit");
            $roleCount = (clone $employeeQuery)
                ->whereHas('roles', fn (Builder $query) => $query->where('name', $role))
                ->count();

            if ($roleCount >= $roleLimit) {
                throw ValidationException::withMessages([
                    'plan_limit' => ["This trial has reached its limit of {$roleLimit} {$role} accounts."],
                ]);
            }
        }
    }

    public function assertCanCreateStation(Organization $organization): void
    {
        $limit = $this->limits($organization)['stations'];
        if ($limit !== null && $organization->stations()->count() >= $limit) {
            throw ValidationException::withMessages([
                'plan_limit' => ["This organization has reached its limit of {$limit} stations."],
            ]);
        }
    }

    /** @return array{employees:?int,stations:?int} */
    public function limits(Organization $organization): array
    {
        $subscription = $organization->commercialSubscription()->with('plan')->first();
        if (! $subscription) {
            return ['employees' => null, 'stations' => null];
        }
        if ($this->usesTrialLimits($subscription->status, $subscription->current_period_ends_at !== null)) {
            return [
                'employees' => $this->settings->integer('trial_employee_limit'),
                'stations' => $this->settings->integer('trial_station_limit'),
            ];
        }

        return ['employees' => $subscription->plan->max_employees, 'stations' => $subscription->plan->max_stations];
    }

    private function usesTrialLimits(string $status, bool $hasPaidPeriod): bool
    {
        return in_array($status, ['trialing', 'grace_period'], true) && ! $hasPaidPeriod;
    }
}
