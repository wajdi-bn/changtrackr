<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'organization_id', 'user_id', 'station_id', 'connector_id', 'ocpp_id_tag_id',
    'charging_session_id', 'status', 'payment_provider', 'payment_method', 'payment_status',
    'preauthorized_amount_millimes', 'currency', 'payment_idempotency_key',
    'capture_idempotency_key', 'provider_authorization_id', 'simulation_outcome',
    'limit_energy_kwh', 'limit_amount_millimes', 'limit_duration_minutes',
    'failure_code', 'failure_message', 'authorized_at', 'command_queued_at',
    'started_at', 'completed_at', 'expires_at',
])]
class ChargingAttempt extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function chargingSession(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(OcppCommand::class);
    }

    protected function casts(): array
    {
        return [
            'preauthorized_amount_millimes' => 'integer',
            'limit_energy_kwh' => 'float',
            'limit_amount_millimes' => 'integer',
            'limit_duration_minutes' => 'integer',
            'authorized_at' => 'datetime',
            'command_queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
