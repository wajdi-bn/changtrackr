<?php

namespace App\Models;

use Database\Factories\DemoRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'full_name', 'email', 'company_name', 'phone', 'topic',
    'estimated_stations', 'message', 'status', 'scheduled_at', 'internal_notes',
    'handled_by_id', 'organization_id', 'consent_at', 'submitted_ip_hash', 'provisioned_at',
])]
class DemoRequest extends Model
{
    /** @use HasFactory<DemoRequestFactory> */
    use HasFactory;

    public const STATUSES = [
        'new',
        'under_review',
        'contacted',
        'demo_scheduled',
        'qualified',
        'approved',
        'provisioned',
        'rejected',
    ];

    public const TOPICS = ['platform', 'operator', 'technician', 'client', 'admin'];

    private const TRANSITIONS = [
        'new' => ['under_review', 'contacted', 'rejected'],
        'under_review' => ['contacted', 'rejected'],
        'contacted' => ['demo_scheduled', 'qualified', 'rejected'],
        'demo_scheduled' => ['contacted', 'qualified', 'rejected'],
        'qualified' => ['contacted', 'approved', 'rejected'],
        'approved' => ['rejected'],
        'provisioned' => [],
        'rejected' => ['under_review'],
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

    /** @return list<string> */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    protected function casts(): array
    {
        return [
            'estimated_stations' => 'integer',
            'scheduled_at' => 'datetime',
            'consent_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }
}
