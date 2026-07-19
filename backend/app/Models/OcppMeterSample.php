<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'station_id', 'connector_id', 'ocpp_transaction_id', 'ocpp_event_id',
    'sample_index', 'sampled_at', 'value', 'measurand', 'context', 'phase', 'location', 'unit',
])]
class OcppMeterSample extends Model
{
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(OcppTransaction::class, 'ocpp_transaction_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OcppEvent::class, 'ocpp_event_id');
    }

    protected function casts(): array
    {
        return [
            'sample_index' => 'integer',
            'sampled_at' => 'datetime',
            'value' => 'float',
        ];
    }
}
