<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OcppSimulatorActionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'action' => $this->action,
            'status' => $this->status,
            'station_id' => $this->station_id,
            'connector' => $this->whenLoaded('connector', fn () => $this->connector === null ? null : [
                'id' => $this->connector->id,
                'external_id' => $this->connector->external_id,
                'ocpp_connector_id' => $this->connector->ocpp_connector_id,
            ]),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy === null ? null : [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
                'avatar_url' => $this->requestedBy->avatar_url,
            ]),
            'result' => $this->result_payload,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'queued_at' => $this->queued_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
