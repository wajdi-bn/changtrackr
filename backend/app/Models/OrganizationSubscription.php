<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'saas_plan_id', 'status', 'billing_cycle', 'source', 'auto_renew',
    'trial_started_at', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at',
    'grace_ends_at', 'suspended_at', 'cancelled_at', 'metadata',
])]
class OrganizationSubscription extends Model
{
    public const STATUSES = ['trialing', 'active', 'past_due', 'grace_period', 'suspended', 'cancelled'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(OrganizationInvoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrganizationSubscriptionEvent::class);
    }

    public function blocksOperations(): bool
    {
        return in_array($this->status, ['suspended', 'cancelled'], true);
    }

    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
