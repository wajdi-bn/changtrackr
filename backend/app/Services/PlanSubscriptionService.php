<?php

namespace App\Services;

use App\Models\ChargingPlan;
use App\Models\PlanSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanSubscriptionService
{
    public function subscribe(User $client, int $planId, bool $autoRenew): PlanSubscription
    {
        return DB::transaction(function () use ($client, $planId, $autoRenew): PlanSubscription {
            User::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $plan = ChargingPlan::query()->with('organization')->lockForUpdate()->findOrFail($planId);
            if ($plan->status !== 'active' || $plan->organization?->status !== 'active') {
                throw ValidationException::withMessages(['charging_plan_id' => ['This plan is not currently available.']]);
            }
            if ($plan->monthly_fee_millimes === 0 && $plan->discount_basis_points === 0) {
                throw ValidationException::withMessages(['charging_plan_id' => ['This pay-as-you-go plan does not require a subscription.']]);
            }

            $current = PlanSubscription::query()
                ->where('user_id', $client->id)
                ->where('organization_id', $plan->organization_id)
                ->current()
                ->lockForUpdate()
                ->first();
            if ($current?->charging_plan_id === $plan->id) {
                throw ValidationException::withMessages(['charging_plan_id' => ['You are already subscribed to this plan.']]);
            }

            if ($current) {
                $this->cancelLocked($current);
            }

            $startsAt = now();
            $subscription = PlanSubscription::query()->create([
                'organization_id' => $plan->organization_id,
                'user_id' => $client->id,
                'charging_plan_id' => $plan->id,
                'status' => 'active',
                'auto_renew' => $autoRenew,
                'billing_provider' => 'simulated',
                'monthly_fee_millimes' => $plan->monthly_fee_millimes,
                'discount_basis_points' => $plan->discount_basis_points,
                'starts_at' => $startsAt,
                'current_period_ends_at' => $startsAt->copy()->addMonthNoOverflow(),
            ]);
            $plan->increment('member_count');

            return $subscription->load(['organization', 'chargingPlan']);
        });
    }

    public function cancel(PlanSubscription $subscription): PlanSubscription
    {
        return DB::transaction(function () use ($subscription): PlanSubscription {
            $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($subscription->status !== 'active') {
                throw ValidationException::withMessages(['subscription' => ['Only an active subscription can be cancelled.']]);
            }

            $this->cancelLocked($subscription);

            return $subscription->fresh()->load(['organization', 'chargingPlan']);
        });
    }

    private function cancelLocked(PlanSubscription $subscription): void
    {
        $subscription->update([
            'status' => 'cancelled',
            'auto_renew' => false,
            'cancelled_at' => now(),
            'current_period_ends_at' => now(),
        ]);
        ChargingPlan::query()
            ->whereKey($subscription->charging_plan_id)
            ->where('member_count', '>', 0)
            ->decrement('member_count');
    }
}
