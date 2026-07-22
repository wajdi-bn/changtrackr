<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_subscription_id', 'actor_id', 'event', 'from_status', 'to_status', 'note', 'metadata'])]
class OrganizationSubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
