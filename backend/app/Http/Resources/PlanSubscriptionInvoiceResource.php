<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanSubscriptionInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'billing_reason' => $this->billing_reason,
            'organization' => OrganizationSummaryResource::make($this->whenLoaded('organization')),
            'plan' => $this->whenLoaded('chargingPlan', fn () => [
                'id' => $this->chargingPlan->id,
                'name' => $this->chargingPlan->name,
                'code' => $this->chargingPlan->code,
            ]),
            'subscription_id' => $this->plan_subscription_id,
            'payment_provider' => $this->payment_provider,
            'payment_method' => $this->payment_method,
            'provider_transaction_id' => $this->provider_transaction_id,
            'amount_millimes' => $this->amount_millimes,
            'currency' => $this->currency,
            'period_starts_at' => $this->period_starts_at?->toISOString(),
            'period_ends_at' => $this->period_ends_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
