<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'organization_id', 'alert_id', 'maintenance_plan_id', 'maintenance_occurrence_number', 'station_id', 'connector_id',
    'assigned_technician_id', 'created_by_id', 'reference', 'status', 'priority',
    'scheduled_at', 'started_at', 'ended_at', 'estimated_duration_minutes',
    'problem', 'diagnosis', 'resolution', 'final_status', 'comments', 'parts',
])]
class Intervention extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Alert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /** @return BelongsTo<MaintenancePlan, $this> */
    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
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

    /** @return HasMany<InterventionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(InterventionEvent::class)->orderBy('occurred_at');
    }

    /** @return HasOne<InterventionReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(InterventionReport::class);
    }

    /** @return HasMany<InterventionPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(InterventionPhoto::class)->orderBy('created_at');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(AssetDocument::class, 'documentable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'estimated_duration_minutes' => 'integer',
            'maintenance_occurrence_number' => 'integer',
            'parts' => 'array',
        ];
    }
}
