<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'name', 'make', 'model', 'model_year', 'license_plate',
    'battery_capacity_kwh', 'max_charging_power_kw', 'connector_types', 'is_default',
])]
class Vehicle extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function supportsConnector(string $type): bool
    {
        return in_array($type, $this->connector_types ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'battery_capacity_kwh' => 'float',
            'max_charging_power_kw' => 'float',
            'connector_types' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
