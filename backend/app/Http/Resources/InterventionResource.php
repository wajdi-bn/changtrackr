<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterventionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'organization_id' => $this->organization_id,
            'alert_id' => $this->alert_id,
            'status' => $this->status,
            'priority' => $this->priority,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'problem' => $this->problem,
            'diagnosis' => $this->diagnosis,
            'resolution' => $this->resolution,
            'final_status' => $this->final_status,
            'comments' => $this->comments,
            'parts' => $this->parts ?? [],
            'alert' => $this->whenLoaded('alert', fn () => [
                'id' => $this->alert->id,
                'reference' => $this->alert->reference,
                'title' => $this->alert->title,
            ]),
            'station' => $this->whenLoaded('station', fn () => [
                'id' => $this->station->id,
                'name' => $this->station->name,
                'city' => $this->station->city,
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
        ];
    }
}
