<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'code', 'description', 'monthly_price_millimes', 'annual_price_millimes',
    'max_stations', 'max_employees', 'features', 'is_featured', 'status', 'sort_order',
])]
class SaasPlan extends Model
{
    public const STATUSES = ['active', 'archived'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizationSubscription::class);
    }

    protected function casts(): array
    {
        return [
            'monthly_price_millimes' => 'integer',
            'annual_price_millimes' => 'integer',
            'max_stations' => 'integer',
            'max_employees' => 'integer',
            'features' => 'array',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
