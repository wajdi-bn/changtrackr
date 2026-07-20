<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'intervention_id', 'submitted_by_id', 'diagnosis', 'actions_taken', 'final_outcome',
    'safety_checks', 'parts', 'observations', 'actual_duration_minutes', 'submitted_at',
])]
class InterventionReport extends Model
{
    /** @return BelongsTo<Intervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'safety_checks' => 'array',
            'parts' => 'array',
            'actual_duration_minutes' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }
}
