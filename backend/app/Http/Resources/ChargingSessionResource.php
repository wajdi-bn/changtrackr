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
            'organization_id' => $this->organization_id,
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
            'payment_status' => $this->payment_status,
            'started_at' => $this->started_at?->toISOString(),
            'started_relative' => $this->started_at?->diffForHumans(),
            'ended_at' => $this->ended_at?->toISOString(),
            'duration_seconds' => $this->duration_seconds,
            'duration_minutes' => (int) ceil($this->duration_seconds / 60),
            'meter_start_kwh' => $this->meter_start_kwh,
            'meter_stop_kwh' => $this->meter_stop_kwh,
            'energy_kwh' => $this->energy_kwh,
            'price_per_kwh_millimes' => $this->price_per_kwh_millimes,
            'session_fee_millimes' => $this->session_fee_millimes,
            'total_millimes' => $this->total_millimes,
            'total_amount' => number_format($this->total_millimes / 1000, 3, '.', ''),
            'currency' => $this->currency,
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? new PaymentResource($this->payment) : null),
        ];
    }
}
