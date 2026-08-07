<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterventionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isOverdue = $this->scheduled_at !== null
            && $this->scheduled_at->isPast()
            && in_array($this->status, ['assigned', 'in-progress', 'paused', 'waiting-parts'], true);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'organization_id' => $this->organization_id,
            'alert_id' => $this->alert_id,
            'maintenance_plan_id' => $this->maintenance_plan_id,
            'maintenance_occurrence_number' => $this->maintenance_occurrence_number,
            'source' => $this->maintenance_plan_id === null ? 'alert' : 'maintenance',
            'status' => $this->status,
            'priority' => $this->priority,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'is_overdue' => $isOverdue,
            'overdue_by_minutes' => $isOverdue ? (int) floor($this->scheduled_at->diffInMinutes(now())) : 0,
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'problem' => $this->problem,
            'diagnosis' => $this->diagnosis,
            'resolution' => $this->resolution,
            'final_status' => $this->final_status,
            'comments' => $this->comments,
            'parts' => $this->parts ?? [],
            'report' => $this->whenLoaded('report', fn () => $this->report ? [
                'id' => $this->report->id,
                'diagnosis' => $this->report->diagnosis,
                'actions_taken' => $this->report->actions_taken,
                'final_outcome' => $this->report->final_outcome,
                'safety_checks' => $this->report->safety_checks,
                'parts' => $this->report->parts ?? [],
                'observations' => $this->report->observations,
                'actual_duration_minutes' => $this->report->actual_duration_minutes,
                'submitted_at' => $this->report->submitted_at?->toISOString(),
                'submitted_by' => $this->report->relationLoaded('submittedBy') && $this->report->submittedBy ? [
                    'id' => $this->report->submittedBy->id,
                    'name' => $this->report->submittedBy->name,
                ] : null,
            ] : null),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'phase' => $photo->phase,
                'caption' => $photo->caption,
                'original_name' => $photo->original_name,
                'mime_type' => $photo->mime_type,
                'size_bytes' => $photo->size_bytes,
                'uploaded_at' => $photo->created_at?->toISOString(),
                'uploaded_by' => $photo->relationLoaded('uploadedBy') && $photo->uploadedBy ? [
                    'id' => $photo->uploadedBy->id,
                    'name' => $photo->uploadedBy->name,
                ] : null,
            ])->values()),
            'alert' => $this->whenLoaded('alert', fn () => $this->alert ? [
                'id' => $this->alert->id,
                'reference' => $this->alert->reference,
                'title' => $this->alert->title,
            ] : null),
            'maintenance_plan' => $this->whenLoaded('maintenancePlan', fn () => $this->maintenancePlan ? [
                'id' => $this->maintenancePlan->id,
                'reference' => $this->maintenancePlan->reference,
                'title' => $this->maintenancePlan->title,
                'type' => $this->maintenancePlan->type,
                'status' => $this->maintenancePlan->status,
                'recurrence_frequency' => $this->maintenancePlan->recurrence_frequency,
                'recurrence_interval' => $this->maintenancePlan->recurrence_interval,
                'recurrence_ends_at' => $this->maintenancePlan->recurrence_ends_at?->toISOString(),
                'next_occurrence_at' => $this->maintenancePlan->next_occurrence_at?->toISOString(),
            ] : null),
            'station' => $this->whenLoaded('station', fn () => [
                'id' => $this->station->id,
                'name' => $this->station->name,
                'city' => $this->station->city,
                'availability_override' => $this->station->availability_override,
                'maintenance_intervention_id' => $this->station->maintenance_intervention_id,
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
