<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'problem_type' => $this->problem_type,
            'severity' => $this->severity,
            'status' => $this->status,
            'source' => $this->source,
            'description' => $this->description,
            'ocpp_log' => $this->ocpp_log,
            'suggested_cause' => $this->suggested_cause,
            'recommended_action' => $this->recommended_action,
            'detected_at' => $this->detected_at?->toISOString(),
            'detected_relative' => $this->detected_at?->diffForHumans(),
            'due_at' => $this->due_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'station' => $this->whenLoaded('station', fn () => [
                'id' => $this->station->id,
                'name' => $this->station->name,
                'city' => $this->station->city,
                'reference' => $this->station->reference,
            ]),
            'connector' => $this->whenLoaded('connector', fn () => $this->connector ? [
                'id' => $this->connector->id,
                'external_id' => $this->connector->external_id,
                'type' => $this->connector->type,
            ] : null),
            'assigned_technician' => $this->whenLoaded('assignedTechnician', fn () => $this->assignedTechnician ? [
                'id' => $this->assignedTechnician->id,
                'name' => $this->assignedTechnician->name,
                'avatar_url' => $this->assignedTechnician->avatar_url,
            ] : null),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'description' => $event->description,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'occurred_relative' => $event->occurred_at?->diffForHumans(),
            ])->values()),
            'intervention' => $this->whenLoaded('intervention', fn () => $this->intervention ? [
                'id' => $this->intervention->id,
                'reference' => $this->intervention->reference,
                'status' => $this->intervention->status,
            ] : null),
        ];
    }
}
