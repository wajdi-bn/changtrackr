<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'name', 'code', 'description', 'monthly_fee_millimes',
    'discount_basis_points', 'audience', 'status', 'member_count',
])]
class ChargingPlan extends Model
{
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }

    protected function casts(): array
    {
        return [
            'monthly_fee_millimes' => 'integer',
            'discount_basis_points' => 'integer',
            'member_count' => 'integer',
        ];
    }
}
