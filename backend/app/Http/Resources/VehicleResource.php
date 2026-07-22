<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'make' => $this->make,
            'model' => $this->model,
            'model_year' => $this->model_year,
            'license_plate' => $this->license_plate,
            'battery_capacity_kwh' => $this->battery_capacity_kwh,
            'max_charging_power_kw' => $this->max_charging_power_kw,
            'connector_types' => $this->connector_types ?? [],
            'is_default' => $this->is_default,
            'charging_sessions_count' => $this->whenCounted('chargingSessions'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
