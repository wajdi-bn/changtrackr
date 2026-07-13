<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'station_id', 'connector_id', 'assigned_technician_id',
    'reference', 'title', 'problem_type', 'severity', 'status', 'source',
    'description', 'ocpp_log', 'suggested_cause', 'recommended_action',
    'detected_at', 'due_at', 'resolved_at',
])]
class Alert extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** @return BelongsTo<Connector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class)->orderBy('occurred_at');
    }

    /** @return HasOne<Intervention, $this> */
    public function intervention(): HasOne
    {
        return $this->hasOne(Intervention::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
