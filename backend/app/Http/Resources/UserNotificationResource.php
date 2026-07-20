<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserNotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->action_url,
            'entity' => [
                'type' => $this->entity_type,
                'id' => $this->entity_id,
            ],
            'data' => $this->data ?? [],
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'created_relative' => $this->created_at?->diffForHumans(),
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($delivery) => [
                'channel' => $delivery->channel,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'delivered_at' => $delivery->delivered_at?->toISOString(),
                'failed_at' => $delivery->failed_at?->toISOString(),
            ])->values()),
        ];
    }
}
