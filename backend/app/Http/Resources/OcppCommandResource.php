<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OcppCommandResource extends JsonResource
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
            'requested_by' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => $this->user->avatar_url,
            ]),
            'result' => $this->result_payload,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'queued_at' => $this->queued_at?->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(),
            'responded_at' => $this->responded_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
