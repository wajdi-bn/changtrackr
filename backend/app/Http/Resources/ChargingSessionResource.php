<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChargingSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'source' => $this->source,
            'organization_id' => $this->organization_id,
            'organization' => OrganizationSummaryResource::make($this->whenLoaded('organization')),
            'client' => [
                'id' => $this->client_id,
                'name' => $this->client_name,
            ],
            'station' => [
                'id' => $this->station_id,
                'name' => $this->station_name,
                'city' => $this->whenLoaded('station', fn () => $this->station?->city),
            ],
            'connector' => [
                'id' => $this->connector_id,
                'external_id' => $this->connector_external_id,
                'type' => $this->whenLoaded('connector', fn () => $this->connector?->type),
                'max_power_kw' => $this->whenLoaded('connector', fn () => $this->connector?->max_power_kw),
            ],
            'status' => $this->status,
            'lifecycle_reason' => $this->lifecycle_reason,
            'payment_status' => $this->payment_status,
            'started_at' => $this->started_at?->toISOString(),
            'started_relative' => $this->started_at?->diffForHumans(),
            'ended_at' => $this->ended_at?->toISOString(),
            'duration_seconds' => $this->duration_seconds,
            'duration_minutes' => (int) ceil($this->duration_seconds / 60),
            'meter_start_kwh' => $this->meter_start_kwh,
            'meter_stop_kwh' => $this->meter_stop_kwh,
            'last_meter_value_at' => $this->last_meter_value_at?->toISOString(),
            'energy_kwh' => $this->energy_kwh,
            'current_power_kw' => $this->current_power_kw,
            'state_of_charge_percent' => $this->state_of_charge_percent,
            'limits' => [
                'energy_kwh' => $this->limit_energy_kwh,
                'amount_millimes' => $this->limit_amount_millimes,
                'duration_minutes' => $this->limit_duration_minutes,
            ],
            'ocpp' => $this->whenLoaded('ocppTransaction', fn () => $this->ocppTransaction ? [
                'transaction_id' => $this->ocppTransaction->id,
                'id_tag' => $this->ocppTransaction->id_tag_masked,
                'status' => $this->ocppTransaction->status,
                'stop_reason' => $this->ocppTransaction->stop_reason,
            ] : null),
            'tariff' => [
                'id' => $this->tariff_id,
                'name' => $this->tariff_name ?? 'Fallback tariff',
            ],
            'plan' => $this->charging_plan_id ? [
                'id' => $this->charging_plan_id,
                'name' => $this->charging_plan_name,
                'discount_basis_points' => $this->discount_basis_points,
            ] : null,
            'price_per_kwh_millimes' => $this->price_per_kwh_millimes,
            'session_fee_millimes' => $this->session_fee_millimes,
            'idle_fee_per_minute_millimes' => $this->idle_fee_per_minute_millimes,
            'minimum_charge_millimes' => $this->minimum_charge_millimes,
            'energy_gross_millimes' => (int) round($this->energy_kwh * $this->price_per_kwh_millimes),
            'discount_millimes' => $this->discount_millimes,
            'energy_cost_millimes' => (int) round($this->energy_kwh * $this->price_per_kwh_millimes) - $this->discount_millimes,
            'minimum_adjustment_millimes' => max(0, $this->minimum_charge_millimes - (((int) round($this->energy_kwh * $this->price_per_kwh_millimes) - $this->discount_millimes) + $this->session_fee_millimes)),
            'total_millimes' => $this->total_millimes,
            'total_amount' => number_format($this->total_millimes / 1000, 3, '.', ''),
            'currency' => $this->currency,
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? new PaymentResource($this->payment) : null),
        ];
    }
}
