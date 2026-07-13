<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'name', 'code', 'description', 'status', 'currency',
    'price_per_kwh_millimes', 'session_fee_millimes', 'idle_fee_per_minute_millimes',
    'minimum_charge_millimes', 'valid_from', 'valid_until', 'is_default',
])]
class Tariff extends Model
{
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TariffAssignment::class);
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    protected function casts(): array
    {
        return [
            'price_per_kwh_millimes' => 'integer',
            'session_fee_millimes' => 'integer',
            'idle_fee_per_minute_millimes' => 'integer',
            'minimum_charge_millimes' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_default' => 'boolean',
        ];
    }
}
