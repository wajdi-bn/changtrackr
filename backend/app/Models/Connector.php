<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['station_id', 'external_id', 'type', 'current_type', 'max_power_kw', 'status', 'error_code', 'last_status_at'])]
class Connector extends Model
{
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_power_kw' => 'float',
            'last_status_at' => 'datetime',
        ];
    }
}
