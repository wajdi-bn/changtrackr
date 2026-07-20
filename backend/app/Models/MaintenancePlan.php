<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'station_id', 'connector_id', 'assigned_technician_id', 'created_by_id',
    'reference', 'title', 'type', 'priority', 'status', 'instructions', 'first_scheduled_at',
    'estimated_duration_minutes', 'recurrence_frequency', 'recurrence_interval',
    'recurrence_ends_at', 'next_occurrence_at', 'last_generated_at', 'last_occurrence_number',
])]
class MaintenancePlan extends Model
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return HasMany<Intervention, $this> */
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class)->orderBy('scheduled_at');
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_frequency !== 'none';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_scheduled_at' => 'datetime',
            'recurrence_ends_at' => 'datetime',
            'next_occurrence_at' => 'datetime',
            'last_generated_at' => 'datetime',
            'estimated_duration_minutes' => 'integer',
            'recurrence_interval' => 'integer',
            'last_occurrence_number' => 'integer',
        ];
    }
}
