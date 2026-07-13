<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tariff_id', 'station_id', 'connector_id'])]
class TariffAssignment extends Model
{
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }
}
