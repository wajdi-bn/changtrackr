<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PlatformAuditService
{
    /** @param array<string, mixed> $metadata */
    public function record(User $actor, string $eventType, Model $subject, string $description, array $metadata = []): void
    {
        $request = request();
        $organizationId = $subject instanceof Organization
            ? $subject->getKey()
            : $subject->getAttribute('organization_id');

        PlatformAuditLog::query()->create([
            'actor_id' => $actor->id,
            'organization_id' => $organizationId,
            'event_type' => $eventType,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'description' => $description,
            'metadata' => [
                ...$metadata,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255),
            ],
        ]);
    }
}
