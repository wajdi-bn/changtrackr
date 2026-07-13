<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'client_id', 'station_id', 'connector_id', 'reference',
    'client_name', 'station_name', 'connector_external_id', 'status', 'payment_status',
    'started_at', 'ended_at', 'duration_seconds', 'meter_start_kwh', 'meter_stop_kwh',
    'energy_kwh', 'price_per_kwh_millimes', 'session_fee_millimes', 'total_millimes', 'currency',
])]
class ChargingSession extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'meter_start_kwh' => 'float',
            'meter_stop_kwh' => 'float',
            'energy_kwh' => 'float',
            'price_per_kwh_millimes' => 'integer',
            'session_fee_millimes' => 'integer',
            'total_millimes' => 'integer',
        ];
    }
}
