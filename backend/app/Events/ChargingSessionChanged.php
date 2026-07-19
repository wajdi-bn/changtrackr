<?php

namespace App\Events;

use App\Models\ChargingSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChargingSessionChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $sessionId,
        public readonly int $organizationId,
        public readonly ?int $clientId,
        public readonly int $stationId,
        public readonly string $status,
        public readonly float $energyKwh,
        public readonly int $totalMillimes,
        public readonly ?string $lastMeterValueAt,
    ) {}

    public static function fromSession(ChargingSession $session): self
    {
        return new self(
            sessionId: $session->id,
            organizationId: $session->organization_id,
            clientId: $session->client_id,
            stationId: $session->station_id,
            status: $session->status,
            energyKwh: (float) $session->energy_kwh,
            totalMillimes: (int) $session->total_millimes,
            lastMeterValueAt: $session->last_meter_value_at?->toISOString(),
        );
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("organizations.{$this->organizationId}.sessions"),
            new PrivateChannel('sessions.super-admin'),
        ];

        if ($this->clientId !== null) {
            $channels[] = new PrivateChannel("users.{$this->clientId}.sessions");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'charging-session.changed';
    }
}
