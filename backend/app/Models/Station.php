<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'name', 'reference', 'location_name', 'city', 'address',
    'latitude', 'longitude', 'status', 'max_power_kw', 'model', 'manufacturer',
    'ocpp_version', 'model_image', 'last_heartbeat_at', 'uptime_percent',
    'energy_today_kwh', 'sessions_today', 'utilization_percent', 'revenue_today',
    'open_alerts_count',
])]
class Station extends Model
{
    use SoftDeletes;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Connector, $this> */
    public function connectors(): HasMany
    {
        return $this->hasMany(Connector::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return HasMany<Intervention, $this> */
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class);
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
            'latitude' => 'float',
            'longitude' => 'float',
            'max_power_kw' => 'float',
            'last_heartbeat_at' => 'datetime',
            'uptime_percent' => 'float',
            'energy_today_kwh' => 'float',
            'sessions_today' => 'integer',
            'utilization_percent' => 'float',
            'revenue_today' => 'float',
            'open_alerts_count' => 'integer',
        ];
    }
}
