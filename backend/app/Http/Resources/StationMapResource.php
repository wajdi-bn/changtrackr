<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationMapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization' => OrganizationSummaryResource::make($this->whenLoaded('organization')),
            'name' => $this->name,
            'reference' => $this->reference,
            'location_name' => $this->location_name,
            'city' => $this->city,
            'location' => "{$this->location_name}, {$this->city}",
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'ocpp_managed' => $this->isOcppManaged(),
            'remote_start_available' => $this->canStartRemotely(),
            'remote_start_unavailable_reason' => $this->remoteStartUnavailableReason(),
            'availability_reason' => $this->availability_reason,
            'availability_source' => $this->availability_source,
            'availability_calculated_at' => $this->availability_calculated_at?->toISOString(),
            'max_power_kw' => $this->max_power_kw,
            'model_image' => $this->model_image,
            'uptime_percent' => $this->uptime_percent,
            'connectors_count' => $this->connectors_count,
            'available_connectors_count' => $this->available_connectors_count,
            'connectors' => ConnectorResource::collection($this->whenLoaded('connectors')),
        ];
    }
}
