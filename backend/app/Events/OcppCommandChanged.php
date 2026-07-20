<?php

namespace App\Events;

use App\Models\OcppCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OcppCommandChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<string, mixed>|null $result */
    public function __construct(
        public readonly string $uuid,
        public readonly int $organizationId,
        public readonly int $stationId,
        public readonly ?int $connectorId,
        public readonly string $action,
        public readonly string $status,
        public readonly ?array $result,
        public readonly ?string $failureCode,
        public readonly ?string $failureMessage,
        public readonly string $updatedAt,
    ) {}

    public static function fromCommand(OcppCommand $command): self
    {
        return new self(
            uuid: $command->uuid,
            organizationId: $command->organization_id,
            stationId: $command->station_id,
            connectorId: $command->connector_id,
            action: $command->action,
            status: $command->status,
            result: $command->result_payload,
            failureCode: $command->failure_code,
            failureMessage: $command->failure_message,
            updatedAt: $command->updated_at->toISOString(),
        );
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("organizations.{$this->organizationId}.stations"),
            new PrivateChannel('stations.super-admin'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ocpp-command.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->uuid,
            'station_id' => $this->stationId,
            'connector_id' => $this->connectorId,
            'action' => $this->action,
            'status' => $this->status,
            'result' => $this->result,
            'failure_code' => $this->failureCode,
            'failure_message' => $this->failureMessage,
            'updated_at' => $this->updatedAt,
        ];
    }
}
