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
        $dailyEnergyKwh = (float) ($this->daily_energy_kwh ?? 0);
        $dailySessionsCount = (int) ($this->daily_sessions_count ?? 0);
        $dailyRevenueMillimes = (int) ($this->daily_revenue_millimes ?? 0);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization' => OrganizationSummaryResource::make($this->whenLoaded('organization')),
            'name' => $this->name,
            'reference' => $this->reference,
            'ocpp_identity' => $this->ocpp_identity,
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
            'availability_override' => $this->availability_override,
            'maintenance_intervention_id' => $this->maintenance_intervention_id,
            'availability_reason' => $this->availability_reason,
            'availability_source' => $this->availability_source,
            'availability_calculated_at' => $this->availability_calculated_at?->toISOString(),
            'max_power_kw' => $this->max_power_kw,
            'model' => $this->model,
            'manufacturer' => $this->manufacturer,
            'ocpp_version' => $this->ocpp_version,
            'ocpp_commissioning_target' => $this->ocpp_commissioning_target,
            'ocpp_simulator_profile' => $this->ocpp_simulator_profile,
            'ocpp_provisioning_status' => $this->ocpp_provisioning_status,
            'ocpp_provisioning_error' => $this->ocpp_provisioning_error,
            'ocpp_provisioned_at' => $this->ocpp_provisioned_at?->toISOString(),
            'commissioning_status' => $this->commissioningStatus(),
            'ocpp_secret_configured' => $this->ocpp_auth_secret_hash !== null,
            'ocpp_registration_status' => $this->ocpp_registration_status,
            'ocpp_status' => $this->ocpp_status,
            'ocpp_error_code' => $this->ocpp_error_code,
            'ocpp_connected_at' => $this->ocpp_connected_at?->toISOString(),
            'ocpp_disconnected_at' => $this->ocpp_disconnected_at?->toISOString(),
            'ocpp_last_message_at' => $this->ocpp_last_message_at?->toISOString(),
            'ocpp_last_status_at' => $this->ocpp_last_status_at?->toISOString(),
            'ocpp_is_connected' => $this->hasFreshOcppConnection(),
            'model_image' => $this->model_image,
            'last_heartbeat_at' => $this->last_heartbeat_at?->toISOString(),
            'last_heartbeat_relative' => $this->last_heartbeat_at?->diffForHumans() ?? 'Never',
            'uptime_percent' => $this->uptime_percent,
            'energy_today_kwh' => round($dailyEnergyKwh, 3),
            'sessions_today' => $dailySessionsCount,
            'utilization_percent' => $this->utilization_percent,
            'revenue_today' => round($dailyRevenueMillimes / 1000, 3),
            'open_alerts_count' => $this->open_alerts_count,
            'connectors_count' => $this->whenCounted('connectors'),
            'available_connectors_count' => $this->relationLoaded('connectors')
                ? $this->connectors->where('status', 'available')->count()
                : null,
            'connectors' => ConnectorResource::collection($connectors),
        ];
    }
}
