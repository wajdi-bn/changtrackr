<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingPlan;
use App\Models\Connector;
use App\Models\PlanSubscription;
use App\Models\Station;
use App\Models\User;
use App\Services\ChargingEstimateService;
use App\Services\TariffResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingController extends Controller
{
    public function effective(Request $request, Station $station, TariffResolver $resolver): JsonResponse
    {
        Gate::authorize('view', $station);
        $attributes = $request->validate(['connector_id' => ['nullable', 'integer', 'exists:connectors,id']]);
        $connector = isset($attributes['connector_id']) ? Connector::query()->findOrFail($attributes['connector_id']) : null;

        if ($connector && $connector->station_id !== $station->id) {
            throw ValidationException::withMessages(['connector_id' => ['The connector does not belong to this station.']]);
        }

        $tariff = $resolver->resolve($station, $connector);
        /** @var User $user */
        $user = $request->user();
        $subscription = $user->hasRole('client')
            ? $this->currentSubscription($user->id, $station->organization_id)
            : null;
        $discountBasisPoints = $subscription?->discount_basis_points ?? 0;

        return response()->json(['data' => [
            'id' => $tariff->id,
            'name' => $tariff->name,
            'source' => $tariff->source,
            'currency' => $tariff->currency,
            'price_per_kwh_millimes' => $tariff->pricePerKwhMillimes,
            'session_fee_millimes' => $tariff->sessionFeeMillimes,
            'idle_fee_per_minute_millimes' => $tariff->idleFeePerMinuteMillimes,
            'minimum_charge_millimes' => $tariff->minimumChargeMillimes,
            'effective_price_per_kwh_millimes' => (int) round($tariff->pricePerKwhMillimes * (10000 - $discountBasisPoints) / 10000),
            'plan' => $subscription ? [
                'id' => $subscription->charging_plan_id,
                'name' => $subscription->chargingPlan?->name,
                'discount_basis_points' => $discountBasisPoints,
            ] : null,
        ]]);
    }

    public function simulate(
        Request $request,
        TariffResolver $resolver,
        ChargingEstimateService $estimates,
    ): JsonResponse {
        $attributes = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'connector_id' => ['nullable', 'integer', 'exists:connectors,id'],
            'charging_plan_id' => ['nullable', 'integer', 'exists:charging_plans,id'],
            'target_type' => ['nullable', Rule::in(['energy', 'duration', 'amount'])],
            'target_value' => ['required_with:target_type', 'numeric', 'min:0.1', 'max:10000'],
            'energy_kwh' => ['required_without:target_type', 'numeric', 'min:0', 'max:10000'],
            'duration_minutes' => ['required_without:target_type', 'integer', 'min:0', 'max:100000'],
            'idle_minutes' => ['required_without:target_type', 'integer', 'min:0', 'max:100000'],
        ]);
        $this->validateTarget($attributes);
        $station = Station::query()->findOrFail($attributes['station_id']);
        Gate::authorize('view', $station);
        $connector = isset($attributes['connector_id']) ? Connector::query()->findOrFail($attributes['connector_id']) : null;
        if ($connector && $connector->station_id !== $station->id) {
            throw ValidationException::withMessages(['connector_id' => ['The connector does not belong to this station.']]);
        }

        /** @var User $user */
        $user = $request->user();
        $subscription = $user->hasRole('client')
            ? $this->currentSubscription($user->id, $station->organization_id)
            : null;
        if ($user->hasRole('client') && isset($attributes['charging_plan_id']) && $subscription?->charging_plan_id !== $attributes['charging_plan_id']) {
            throw ValidationException::withMessages(['charging_plan_id' => ['You are not subscribed to this charging plan.']]);
        }
        $plan = $user->hasRole('client')
            ? $subscription?->chargingPlan
            : (isset($attributes['charging_plan_id']) ? ChargingPlan::query()->findOrFail($attributes['charging_plan_id']) : null);
        if ($plan && ($plan->organization_id !== $station->organization_id || $plan->status !== 'active')) {
            throw ValidationException::withMessages(['charging_plan_id' => ['The charging plan is not active for this organization.']]);
        }

        $tariff = $resolver->resolve($station, $connector);
        $discountBasisPoints = $plan?->discount_basis_points ?? 0;
        $idleMinutes = (int) ($attributes['idle_minutes'] ?? 0);
        $estimate = isset($attributes['target_type'])
            ? $estimates->estimate(
                $tariff,
                (float) ($connector?->max_power_kw ?? $station->max_power_kw),
                $attributes['target_type'],
                (float) $attributes['target_value'],
                $idleMinutes,
                $discountBasisPoints,
                max(1, (int) config('payments.preauthorization_amount_millimes', 30000)),
            )
            : null;
        $energyKwh = $estimate['energy_kwh'] ?? (float) $attributes['energy_kwh'];
        $durationMinutes = $estimate['duration_minutes'] ?? (int) $attributes['duration_minutes'];
        $breakdown = $estimates->breakdown($tariff, $energyKwh, $idleMinutes, $discountBasisPoints);

        return response()->json(['data' => [
            'tariff' => [
                'id' => $tariff->id,
                'name' => $tariff->name,
                'source' => $tariff->source,
                'currency' => $tariff->currency,
            ],
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'discount_basis_points' => $plan->discount_basis_points,
            ] : null,
            'inputs' => [
                'energy_kwh' => $energyKwh,
                'duration_minutes' => $durationMinutes,
                'idle_minutes' => $idleMinutes,
            ],
            'breakdown' => $breakdown,
            'estimate' => $estimate,
        ]]);
    }

    /** @param array<string, mixed> $attributes */
    private function validateTarget(array $attributes): void
    {
        if (! isset($attributes['target_type'])) {
            return;
        }

        $maximum = match ($attributes['target_type']) {
            'energy' => 200,
            'duration' => 1440,
            'amount' => max(1, (int) config('payments.preauthorization_amount_millimes', 30000)) / 1000,
        };
        if ((float) $attributes['target_value'] <= $maximum) {
            return;
        }

        throw ValidationException::withMessages([
            'target_value' => ["The {$attributes['target_type']} target may not be greater than {$maximum}."],
        ]);
    }

    private function currentSubscription(int $userId, int $organizationId): ?PlanSubscription
    {
        return PlanSubscription::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->current()
            ->whereHas('chargingPlan', fn ($query) => $query->where('status', 'active'))
            ->with('chargingPlan')
            ->latest('id')
            ->first();
    }
}
