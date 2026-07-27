<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'user_id', 'charging_plan_id', 'plan_subscription_id',
    'reference', 'status', 'billing_reason', 'payment_provider', 'payment_method',
    'provider_transaction_id', 'idempotency_key', 'amount_millimes', 'currency',
    'period_starts_at', 'period_ends_at', 'due_at', 'paid_at', 'failed_at',
    'failure_code', 'failure_reason', 'metadata',
])]
class PlanSubscriptionInvoice extends Model
{
    public const STATUSES = ['pending', 'paid', 'failed', 'void'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chargingPlan(): BelongsTo
    {
        return $this->belongsTo(ChargingPlan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PlanSubscription::class, 'plan_subscription_id');
    }

    protected function casts(): array
    {
        return [
            'amount_millimes' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
