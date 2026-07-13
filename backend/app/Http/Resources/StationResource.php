<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $connectors = $this->whenLoaded('connectors');

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
            'max_power_kw' => $this->max_power_kw,
            'model' => $this->model,
            'manufacturer' => $this->manufacturer,
            'ocpp_version' => $this->ocpp_version,
            'model_image' => $this->model_image,
            'last_heartbeat_at' => $this->last_heartbeat_at?->toISOString(),
            'last_heartbeat_relative' => $this->last_heartbeat_at?->diffForHumans() ?? 'Never',
            'uptime_percent' => $this->uptime_percent,
            'energy_today_kwh' => $this->energy_today_kwh,
            'sessions_today' => $this->sessions_today,
            'utilization_percent' => $this->utilization_percent,
            'revenue_today' => $this->revenue_today,
            'open_alerts_count' => $this->open_alerts_count,
            'connectors_count' => $this->whenCounted('connectors'),
            'available_connectors_count' => $this->relationLoaded('connectors')
                ? $this->connectors->where('status', 'available')->count()
                : null,
            'connectors' => ConnectorResource::collection($connectors),
        ];
    }
}
