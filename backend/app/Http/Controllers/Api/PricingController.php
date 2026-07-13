<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargingPlan;
use App\Models\Connector;
use App\Models\PlanSubscription;
use App\Models\Station;
use App\Models\User;
use App\Services\TariffResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    public function simulate(Request $request, TariffResolver $resolver): JsonResponse
    {
        $attributes = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'connector_id' => ['nullable', 'integer', 'exists:connectors,id'],
            'charging_plan_id' => ['nullable', 'integer', 'exists:charging_plans,id'],
            'energy_kwh' => ['required', 'numeric', 'min:0', 'max:10000'],
            'duration_minutes' => ['required', 'integer', 'min:0', 'max:100000'],
            'idle_minutes' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
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
        $energyGross = (int) round((float) $attributes['energy_kwh'] * $tariff->pricePerKwhMillimes);
        $discount = $plan ? (int) round($energyGross * $plan->discount_basis_points / 10000) : 0;
        $energyNet = $energyGross - $discount;
        $idleFee = $attributes['idle_minutes'] * $tariff->idleFeePerMinuteMillimes;
        $subtotal = $energyNet + $tariff->sessionFeeMillimes + $idleFee;
        $total = max($subtotal, $tariff->minimumChargeMillimes);

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
                'energy_kwh' => (float) $attributes['energy_kwh'],
                'duration_minutes' => $attributes['duration_minutes'],
                'idle_minutes' => $attributes['idle_minutes'],
            ],
            'breakdown' => [
                'energy_gross_millimes' => $energyGross,
                'discount_millimes' => $discount,
                'energy_net_millimes' => $energyNet,
                'time_cost_millimes' => 0,
                'session_fee_millimes' => $tariff->sessionFeeMillimes,
                'idle_fee_millimes' => $idleFee,
                'minimum_charge_millimes' => $tariff->minimumChargeMillimes,
                'subtotal_millimes' => $subtotal,
                'total_millimes' => $total,
            ],
        ]]);
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
