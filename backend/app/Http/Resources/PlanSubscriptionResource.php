<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => OrganizationSummaryResource::make($this->whenLoaded('organization')),
            'plan' => $this->whenLoaded('chargingPlan', fn () => $this->chargingPlan ? [
                'id' => $this->chargingPlan->id,
                'name' => $this->chargingPlan->name,
                'code' => $this->chargingPlan->code,
                'description' => $this->chargingPlan->description,
                'audience' => $this->chargingPlan->audience,
                'status' => $this->chargingPlan->status,
            ] : null),
            'status' => $this->status,
            'auto_renew' => $this->auto_renew,
            'billing_provider' => $this->billing_provider,
            'monthly_fee_millimes' => $this->monthly_fee_millimes,
            'discount_basis_points' => $this->discount_basis_points,
            'starts_at' => $this->starts_at?->toISOString(),
            'current_period_ends_at' => $this->current_period_ends_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
