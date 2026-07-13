<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'user_id', 'charging_plan_id', 'status', 'auto_renew',
    'billing_provider', 'monthly_fee_millimes', 'discount_basis_points',
    'starts_at', 'current_period_ends_at', 'cancelled_at',
])]
class PlanSubscription extends Model
{
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

    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('current_period_ends_at', '>', now());
    }

    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'monthly_fee_millimes' => 'integer',
            'discount_basis_points' => 'integer',
            'starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
