<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $subscription = $this->relationLoaded('subscriptions')
            ? $this->subscriptions->first()
            : null;

        return [
            'id' => $this->id,
            'organization' => OrganizationSummaryResource::make($this->whenLoaded('organization')),
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'monthly_fee_millimes' => $this->monthly_fee_millimes,
            'discount_basis_points' => $this->discount_basis_points,
            'audience' => $this->audience,
            'member_count' => (int) ($this->current_member_count ?? $this->member_count),
            'collected_millimes' => (int) ($this->collected_millimes ?? 0),
            'requires_subscription' => $this->monthly_fee_millimes > 0 || $this->discount_basis_points > 0,
            'current_subscription' => $subscription
                ? new PlanSubscriptionResource($subscription)
                : null,
        ];
    }
}
