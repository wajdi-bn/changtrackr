<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'user_id', 'charging_session_id', 'reference', 'provider',
    'method', 'status', 'amount_millimes', 'currency', 'idempotency_key',
    'provider_transaction_id', 'failure_reason', 'metadata', 'paid_at', 'failed_at',
])]
class Payment extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chargingSession(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class);
    }

    protected function casts(): array
    {
        return [
            'amount_millimes' => 'integer',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
