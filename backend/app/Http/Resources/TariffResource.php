<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TariffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'status' => $this->status,
            'currency' => $this->currency,
            'price_per_kwh_millimes' => $this->price_per_kwh_millimes,
            'session_fee_millimes' => $this->session_fee_millimes,
            'idle_fee_per_minute_millimes' => $this->idle_fee_per_minute_millimes,
            'minimum_charge_millimes' => $this->minimum_charge_millimes,
            'valid_from' => $this->valid_from?->toISOString(),
            'valid_until' => $this->valid_until?->toISOString(),
            'is_default' => $this->is_default,
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'type' => $assignment->connector_id ? 'connector' : 'station',
                'station' => $assignment->station ? [
                    'id' => $assignment->station->id,
                    'name' => $assignment->station->name,
                ] : ($assignment->connector?->station ? [
                    'id' => $assignment->connector->station->id,
                    'name' => $assignment->connector->station->name,
                ] : null),
                'connector' => $assignment->connector ? [
                    'id' => $assignment->connector->id,
                    'external_id' => $assignment->connector->external_id,
                    'type' => $assignment->connector->type,
                ] : null,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
