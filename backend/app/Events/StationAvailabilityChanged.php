<?php

namespace App\Events;

use App\Models\Station;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StationAvailabilityChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, array<string, mixed>> $connectors */
    public function __construct(
        public readonly int $stationId,
        public readonly int $organizationId,
        public readonly string $status,
        public readonly string $reason,
        public readonly string $source,
        public readonly string $calculatedAt,
        public readonly array $connectors,
        public readonly bool $publiclyVisible,
    ) {}

    public static function fromStation(Station $station): self
    {
        return new self(
            stationId: $station->id,
            organizationId: $station->organization_id,
            status: $station->status,
            reason: $station->availability_reason,
            source: $station->availability_source,
            calculatedAt: $station->availability_calculated_at->toISOString(),
            connectors: $station->connectors->map(fn ($connector) => [
                'id' => $connector->id,
                'status' => $connector->status,
                'reason' => $connector->availability_reason,
                'source' => $connector->availability_source,
                'calculated_at' => $connector->availability_calculated_at?->toISOString(),
            ])->values()->all(),
            publiclyVisible: $station->organization()->where('status', 'active')->exists(),
        );
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("organizations.{$this->organizationId}.stations"),
            new PrivateChannel('stations.super-admin'),
        ];

        if ($this->publiclyVisible) {
            $channels[] = new PrivateChannel('stations.public');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'station.availability.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->stationId,
            'organization_id' => $this->organizationId,
            'status' => $this->status,
            'reason' => $this->reason,
            'source' => $this->source,
            'calculated_at' => $this->calculatedAt,
            'connectors' => $this->connectors,
        ];
    }
}
