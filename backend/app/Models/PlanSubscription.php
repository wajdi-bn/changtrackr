<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'user_id', 'charging_plan_id', 'status', 'auto_renew',
    'cancel_at_period_end', 'billing_provider', 'payment_method',
    'monthly_fee_millimes', 'discount_basis_points', 'starts_at',
    'current_period_ends_at', 'cancellation_requested_at', 'past_due_at',
    'grace_ends_at', 'last_renewed_at', 'ended_at', 'cancelled_at',
])]
class PlanSubscription extends Model
{
    public const STATUSES = ['active', 'past_due', 'cancelled', 'expired'];

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

    public function invoices(): HasMany
    {
        return $this->hasMany(PlanSubscriptionInvoice::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $query) => $query
                    ->where('status', 'active')
                    ->where('current_period_ends_at', '>', now()))
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', 'past_due')
                        ->where('grace_ends_at', '>', now()));
            });
    }

    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'monthly_fee_millimes' => 'integer',
            'discount_basis_points' => 'integer',
            'starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'past_due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'last_renewed_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
