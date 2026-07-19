<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'organization_id', 'user_id', 'station_id', 'connector_id',
    'charging_attempt_id', 'charging_session_id', 'ocpp_transaction_id',
    'action', 'status', 'encrypted_payload', 'result_payload', 'idempotency_key',
    'claimed_by', 'queued_at', 'sent_at', 'responded_at', 'confirmed_at',
    'expires_at', 'failure_code', 'failure_message',
])]
class OcppCommand extends Model
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

    public function chargingAttempt(): BelongsTo
    {
        return $this->belongsTo(ChargingAttempt::class);
    }

    public function chargingSession(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class);
    }

    public function ocppTransaction(): BelongsTo
    {
        return $this->belongsTo(OcppTransaction::class);
    }

    protected function casts(): array
    {
        return [
            'encrypted_payload' => 'encrypted:array',
            'result_payload' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
