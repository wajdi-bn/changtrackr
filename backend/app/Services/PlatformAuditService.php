<?php

namespace App\Services;

use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PlatformAuditService
{
    /** @param array<string, mixed> $metadata */
    public function record(User $actor, string $eventType, Model $subject, string $description, array $metadata = []): void
    {
        PlatformAuditLog::query()->create([
            'actor_id' => $actor->id,
            'organization_id' => $subject instanceof \App\Models\Organization ? $subject->id : $subject->organization_id,
            'event_type' => $eventType,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
