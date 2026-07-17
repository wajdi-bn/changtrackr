<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DemoRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $invitation = $this->relationLoaded('invitations')
            ? $this->invitations->sortByDesc('id')->first()
            : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'company_name' => $this->company_name,
            'phone' => $this->phone,
            'topic' => $this->topic,
            'estimated_stations' => $this->estimated_stations,
            'message' => $this->message,
            'status' => $this->status,
            'allowed_transitions' => $this->allowedTransitions(),
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'internal_notes' => $this->internal_notes,
            'handled_by' => $this->whenLoaded('handledBy', fn () => $this->handledBy ? [
                'id' => $this->handledBy->id,
                'name' => $this->handledBy->name,
            ] : null),
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'invitation' => $invitation ? [
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at?->toISOString(),
                'accepted_at' => $invitation->accepted_at?->toISOString(),
            ] : null,
            'consent_at' => $this->consent_at?->toISOString(),
            'provisioned_at' => $this->provisioned_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
