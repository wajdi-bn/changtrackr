<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'team' => $this->team,
            'address' => $this->address,
            'status' => $this->status,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'last_login_at' => $this->last_login_at?->toISOString(),
            'activity' => [
                'assigned_alerts' => (int) ($this->assigned_alerts_count ?? 0),
                'assigned_interventions' => (int) ($this->assigned_interventions_count ?? 0),
                'charging_sessions' => (int) ($this->charging_sessions_count ?? 0),
                'payments' => (int) ($this->payments_count ?? 0),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
