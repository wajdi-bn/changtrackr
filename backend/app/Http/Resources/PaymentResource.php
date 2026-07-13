<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'method' => $this->method,
            'status' => $this->status,
            'amount_millimes' => $this->amount_millimes,
            'amount' => number_format($this->amount_millimes / 1000, 3, '.', ''),
            'currency' => $this->currency,
            'provider_transaction_id' => $this->provider_transaction_id,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'client' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'session' => $this->whenLoaded('chargingSession', fn () => [
                'id' => $this->chargingSession->id,
                'reference' => $this->chargingSession->reference,
                'station_name' => $this->chargingSession->station_name,
                'connector_external_id' => $this->chargingSession->connector_external_id,
                'energy_kwh' => $this->chargingSession->energy_kwh,
            ]),
        ];
    }
}
