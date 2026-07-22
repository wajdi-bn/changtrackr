<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'sender_id', 'recipient_id', 'title', 'category', 'priority', 'status',
    'summary', 'body', 'period_start', 'period_end', 'related_type', 'related_id',
    'sent_at', 'read_at', 'sender_archived_at', 'recipient_archived_at',
])]
class InternalReport extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'sent_at' => 'datetime', 'read_at' => 'datetime',
            'sender_archived_at' => 'datetime', 'recipient_archived_at' => 'datetime',
        ];
    }
}
