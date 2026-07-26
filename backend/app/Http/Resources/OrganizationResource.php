<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'logo_url' => $this->logo_url,
            'status' => $this->status,
            'settings' => $this->settings,
            'commercial' => $this->when($this->relationLoaded('commercialSubscription'), fn () => $this->commercialSubscription ? [
                'status' => $this->commercialSubscription->status,
                'plan' => $this->commercialSubscription->plan?->name,
                'trial_ends_at' => $this->commercialSubscription->trial_ends_at?->toISOString(),
                'current_period_ends_at' => $this->commercialSubscription->current_period_ends_at?->toISOString(),
                'grace_ends_at' => $this->commercialSubscription->grace_ends_at?->toISOString(),
                'operations_blocked' => $this->commercialSubscription->blocksOperations(),
            ] : null),
        ];
    }
}
