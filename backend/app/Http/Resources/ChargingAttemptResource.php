<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChargingAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latestCommand = $this->relationLoaded('commands') ? $this->commands->first() : null;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'preauthorized_amount_millimes' => $this->preauthorized_amount_millimes,
            'preauthorized_amount' => number_format($this->preauthorized_amount_millimes / 1000, 3, '.', ''),
            'currency' => $this->currency,
            'station' => [
                'id' => $this->station_id,
                'name' => $this->whenLoaded('station', fn () => $this->station?->name),
                'city' => $this->whenLoaded('station', fn () => $this->station?->city),
            ],
            'connector' => [
                'id' => $this->connector_id,
                'external_id' => $this->whenLoaded('connector', fn () => $this->connector?->external_id),
                'type' => $this->whenLoaded('connector', fn () => $this->connector?->type),
                'max_power_kw' => $this->whenLoaded('connector', fn () => $this->connector?->max_power_kw),
            ],
            'limits' => [
                'energy_kwh' => $this->limit_energy_kwh,
                'amount_millimes' => $this->limit_amount_millimes,
                'duration_minutes' => $this->limit_duration_minutes,
            ],
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'command' => $latestCommand ? [
                'uuid' => $latestCommand->uuid,
                'action' => $latestCommand->action,
                'status' => $latestCommand->status,
                'failure_message' => $latestCommand->failure_message,
            ] : null,
            'charging_session' => $this->whenLoaded('chargingSession', fn () => $this->chargingSession
                ? new ChargingSessionResource($this->chargingSession->loadMissing(['organization', 'station', 'connector', 'client', 'payment', 'ocppTransaction']))
                : null),
            'authorized_at' => $this->authorized_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
