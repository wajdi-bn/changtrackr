<?php

namespace App\Events;

use App\Events\Concerns\RoutesBroadcastsToQueue;
use App\Models\ChargingAttempt;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChargingAttemptChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, RoutesBroadcastsToQueue, SerializesModels;

    public function __construct(
        public readonly string $attemptUuid,
        public readonly int $organizationId,
        public readonly int $userId,
        public readonly int $stationId,
        public readonly string $status,
        public readonly string $paymentStatus,
        public readonly ?int $chargingSessionId,
    ) {}

    public static function fromAttempt(ChargingAttempt $attempt): self
    {
        return new self(
            attemptUuid: $attempt->uuid,
            organizationId: $attempt->organization_id,
            userId: $attempt->user_id,
            stationId: $attempt->station_id,
            status: $attempt->status,
            paymentStatus: $attempt->payment_status,
            chargingSessionId: $attempt->charging_session_id,
        );
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->userId}.sessions"),
            new PrivateChannel("organizations.{$this->organizationId}.sessions"),
            new PrivateChannel('sessions.super-admin'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'charging-attempt.changed';
    }
}
