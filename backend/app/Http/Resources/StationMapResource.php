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
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'max_power_kw' => $this->max_power_kw,
            'model_image' => $this->model_image,
            'connectors_count' => $this->connectors_count,
            'available_connectors_count' => $this->available_connectors_count,
            'connectors' => ConnectorResource::collection($this->whenLoaded('connectors')),
        ];
    }
}
