<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenancePlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'type' => $this->type,
            'priority' => $this->priority,
            'status' => $this->status,
            'instructions' => $this->instructions,
            'first_scheduled_at' => $this->first_scheduled_at?->toISOString(),
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'recurrence_frequency' => $this->recurrence_frequency,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_ends_at' => $this->recurrence_ends_at?->toISOString(),
            'next_occurrence_at' => $this->next_occurrence_at?->toISOString(),
            'station' => $this->whenLoaded('station', fn () => [
                'id' => $this->station->id,
                'name' => $this->station->name,
                'reference' => $this->station->reference,
                'city' => $this->station->city,
            ]),
            'connector' => $this->whenLoaded('connector', fn () => $this->connector ? [
                'id' => $this->connector->id,
                'external_id' => $this->connector->external_id,
                'type' => $this->connector->type,
            ] : null),
            'assigned_technician' => $this->whenLoaded('assignedTechnician', fn () => [
                'id' => $this->assignedTechnician->id,
                'name' => $this->assignedTechnician->name,
                'avatar_url' => $this->assignedTechnician->avatar_url,
            ]),
        ];
    }
}
