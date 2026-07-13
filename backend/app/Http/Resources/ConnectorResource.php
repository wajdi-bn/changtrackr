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
            'type' => $this->type,
            'current_type' => $this->current_type,
            'max_power_kw' => $this->max_power_kw,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'last_status_at' => $this->last_status_at?->toISOString(),
            'last_status_relative' => $this->last_status_at?->diffForHumans(),
        ];
    }
}
