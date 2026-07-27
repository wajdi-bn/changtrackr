<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'payment_id', 'plan_subscription_invoice_id',
    'charging_attempt_id', 'provider', 'event_id', 'type', 'operation', 'status',
    'payment_reference', 'provider_transaction_id', 'processing_status', 'payload',
    'error_message', 'received_at', 'processed_at',
])]
class PaymentProviderEvent extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function planSubscriptionInvoice(): BelongsTo
    {
        return $this->belongsTo(PlanSubscriptionInvoice::class);
    }

    public function chargingAttempt(): BelongsTo
    {
        return $this->belongsTo(ChargingAttempt::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
