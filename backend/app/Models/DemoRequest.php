<?php

namespace App\Models;

use Database\Factories\DemoRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'full_name', 'email', 'company_name', 'phone', 'objectives',
    'estimated_stations', 'message', 'status', 'internal_notes', 'rejection_reason',
    'handled_by_id', 'organization_id', 'consent_at', 'submitted_ip_hash',
    'review_started_at', 'decided_at', 'provisioned_at',
])]
class DemoRequest extends Model
{
    /** @use HasFactory<DemoRequestFactory> */
    use HasFactory;

    public const STATUSES = [
        'submitted',
        'under_review',
        'provisioned',
        'rejected',
    ];

    public const OBJECTIVES = [
        'availability_monitoring',
        'remote_supervision',
        'maintenance_coordination',
        'charging_activity',
        'team_access',
        'ocpp_onboarding',
        'performance_uptime',
    ];

    public const OBJECTIVE_LABELS = [
        'availability_monitoring' => 'Monitor station availability and detect outages',
        'remote_supervision' => 'Supervise stations and connectors remotely',
        'maintenance_coordination' => 'Coordinate incidents, interventions and maintenance',
        'charging_activity' => 'Track charging activity and energy consumption',
        'team_access' => 'Manage operators, technicians and customer access',
        'ocpp_onboarding' => 'Integrate and onboard OCPP-compatible stations',
        'performance_uptime' => 'Analyze network performance and improve uptime',
    ];

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    protected function casts(): array
    {
        return [
            'objectives' => 'array',
            'estimated_stations' => 'integer',
            'consent_at' => 'datetime',
            'review_started_at' => 'datetime',
            'decided_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }
}
