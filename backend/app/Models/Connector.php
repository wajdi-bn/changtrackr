<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'station_id', 'external_id', 'ocpp_connector_id', 'type', 'current_type',
    'max_power_kw', 'status', 'availability_reason', 'availability_source',
    'availability_calculated_at', 'error_code', 'last_status_at', 'ocpp_status',
    'ocpp_error_code', 'ocpp_last_status_at',
])]
class Connector extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $connector): void {
            $connector->qr_token ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function tariffAssignment(): HasOne
    {
        return $this->hasOne(TariffAssignment::class);
    }

    /** @return HasMany<AvailabilityTransition, $this> */
    public function availabilityTransitions(): HasMany
    {
        return $this->hasMany(AvailabilityTransition::class);
    }

    public function ocppTransactions(): HasMany
    {
        return $this->hasMany(OcppTransaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_power_kw' => 'float',
            'ocpp_connector_id' => 'integer',
            'last_status_at' => 'datetime',
            'ocpp_last_status_at' => 'datetime',
            'availability_calculated_at' => 'datetime',
        ];
    }
}
