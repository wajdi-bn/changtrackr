<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'station_id', 'connector_id', 'ocpp_id_tag_id', 'start_event_id',
    'stop_event_id', 'id_tag_hash', 'id_tag_masked', 'status', 'meter_start_wh',
    'last_meter_wh', 'meter_stop_wh', 'started_at', 'last_meter_value_at', 'stopped_at',
    'stop_reason', 'rejection_reason',
])]
class OcppTransaction extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function idTag(): BelongsTo
    {
        return $this->belongsTo(OcppIdTag::class, 'ocpp_id_tag_id');
    }

    public function startEvent(): BelongsTo
    {
        return $this->belongsTo(OcppEvent::class, 'start_event_id');
    }

    public function stopEvent(): BelongsTo
    {
        return $this->belongsTo(OcppEvent::class, 'stop_event_id');
    }

    public function chargingSession(): HasOne
    {
        return $this->hasOne(ChargingSession::class);
    }

    public function meterSamples(): HasMany
    {
        return $this->hasMany(OcppMeterSample::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(OcppCommand::class);
    }

    protected function casts(): array
    {
        return [
            'meter_start_wh' => 'integer',
            'last_meter_wh' => 'integer',
            'meter_stop_wh' => 'integer',
            'started_at' => 'datetime',
            'last_meter_value_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }
}
