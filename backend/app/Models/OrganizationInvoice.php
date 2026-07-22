<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'organization_subscription_id', 'saas_plan_id', 'requested_by_id',
    'settled_by_id', 'number', 'status', 'billing_cycle', 'amount_millimes', 'currency',
    'period_starts_at', 'period_ends_at', 'due_at', 'paid_at', 'payment_provider',
    'provider_reference', 'snapshot',
])]
class OrganizationInvoice extends Model
{
    public const STATUSES = ['open', 'paid', 'failed', 'void', 'overdue'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_id');
    }

    protected function casts(): array
    {
        return [
            'amount_millimes' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }
}
