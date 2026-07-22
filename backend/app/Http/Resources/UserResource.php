<?php

namespace App\Http\Resources;

use App\Services\PlatformSettingService;
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
        $invitation = $this->relationLoaded('latestAccountInvitation')
            ? $this->latestAccountInvitation
            : null;
        $invitationStatus = $invitation?->effectiveStatus();
        $lastSentAt = $invitation?->last_sent_at ?? $invitation?->created_at;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'team' => $this->team,
            'address' => $this->address,
            'timezone' => $this->timezone,
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
            'invitation' => $this->when($this->relationLoaded('latestAccountInvitation'), fn () => $invitation ? [
                'status' => $invitationStatus,
                'expires_at' => $invitation->expires_at?->toISOString(),
                'last_sent_at' => $lastSentAt?->toISOString(),
                'accepted_at' => $invitation->accepted_at?->toISOString(),
                'cancelled_at' => $invitation->revoked_at?->toISOString(),
                'can_remind' => $invitationStatus === 'pending'
                    && $lastSentAt?->isBefore(now()->subMinutes(app(PlatformSettingService::class)->integer('employee_invitation_reminder_minutes'))),
                'can_cancel' => $invitationStatus === 'pending',
                'can_renew' => in_array($invitationStatus, ['expired', 'revoked'], true),
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
