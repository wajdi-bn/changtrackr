<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'station_id', 'connector_id', 'ocpp_event_id',
    'from_status', 'to_status', 'from_reason', 'to_reason', 'source', 'occurred_at',
])]
class AvailabilityTransition extends Model
{
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function ocppEvent(): BelongsTo
    {
        return $this->belongsTo(OcppEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
