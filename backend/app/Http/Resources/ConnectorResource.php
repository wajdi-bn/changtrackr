<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConnectorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'ocpp_connector_id' => $this->ocpp_connector_id,
            'type' => $this->type,
            'current_type' => $this->current_type,
            'max_power_kw' => $this->max_power_kw,
            'status' => $this->status,
            'availability_reason' => $this->availability_reason,
            'availability_source' => $this->availability_source,
            'availability_calculated_at' => $this->availability_calculated_at?->toISOString(),
            'error_code' => $this->error_code,
            'last_status_at' => $this->last_status_at?->toISOString(),
            'last_status_relative' => $this->last_status_at?->diffForHumans(),
            'ocpp_status' => $this->ocpp_status,
            'ocpp_error_code' => $this->ocpp_error_code,
            'ocpp_last_status_at' => $this->ocpp_last_status_at?->toISOString(),
        ];
    }
}
