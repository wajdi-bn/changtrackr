<?php

namespace App\Services\Ocpp;

use App\Models\OcppEvent;
use App\Models\Station;
use App\Services\Availability\AvailabilityProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OcppEventIngestionService
{
    public function __construct(
        private readonly AvailabilityProjectionService $availabilityProjector,
        private readonly OcppTransactionProjectionService $transactionProjector,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{event: OcppEvent, duplicate: bool}
     */
    public function ingest(array $attributes): array
    {
        $result = DB::transaction(function () use ($attributes): array {
            $station = Station::query()
                ->where('ocpp_identity', $attributes['station_identity'])
                ->lockForUpdate()
                ->firstOrFail();

            $payloadHash = hash('sha256', json_encode(
                $attributes['payload'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            $existing = OcppEvent::query()
                ->where('event_id', $attributes['event_id'])
                ->orWhere(function ($query) use ($attributes, $station): void {
                    $query
                        ->where('station_id', $station->id)
                        ->where('message_id', $attributes['message_id'])
                        ->where('action', $attributes['action']);
                })
                ->first();

            if ($existing !== null) {
                $this->assertSameEvent($existing, $attributes, $station, $payloadHash);

                return ['event' => $existing, 'duplicate' => true];
            }

            $occurredAt = CarbonImmutable::parse($attributes['occurred_at'])->utc();
            $event = OcppEvent::query()->create([
                'event_id' => $attributes['event_id'],
                'organization_id' => $station->organization_id,
                'station_id' => $station->id,
                'connection_id' => $attributes['connection_id'] ?? null,
                'message_id' => $attributes['message_id'],
                'protocol_version' => $attributes['protocol_version'],
                'action' => $attributes['action'],
                'payload' => $attributes['payload'],
                'payload_hash' => $payloadHash,
                'processing_status' => 'received',
                'occurred_at' => $occurredAt,
                'received_at' => now(),
            ]);

            $this->applyRawTelemetry($event, $station, $occurredAt);
            $this->transactionProjector->project($event, $station->fresh());

            return ['event' => $event->fresh(), 'duplicate' => false];
        });

        if (! $result['duplicate']) {
            $this->availabilityProjector->project($result['event']->station_id, $result['event']->id);
        }

        return $result;
    }

    /** @param array<string, mixed> $attributes */
    private function assertSameEvent(
        OcppEvent $event,
        array $attributes,
        Station $station,
        string $payloadHash,
    ): void {
        if ($event->event_id !== $attributes['event_id']
            || $event->station_id !== $station->id
            || $event->message_id !== $attributes['message_id']
            || $event->action !== $attributes['action']
            || $event->protocol_version !== $attributes['protocol_version']
            || $event->payload_hash !== $payloadHash) {
            throw new ConflictHttpException('The OCPP event identifier was already used for different content.');
        }
    }

    private function applyRawTelemetry(
        OcppEvent $event,
        Station $station,
        CarbonImmutable $occurredAt,
    ): void {
        $stationUpdates = [];

        if ($station->ocpp_last_message_at === null || $occurredAt->greaterThanOrEqualTo($station->ocpp_last_message_at)) {
            $stationUpdates['ocpp_last_message_at'] = $occurredAt;
        }

        if ($event->action === 'ConnectionOpened') {
            if ($station->ocpp_connected_at === null || $occurredAt->greaterThanOrEqualTo($station->ocpp_connected_at)) {
                $stationUpdates['ocpp_connected_at'] = $occurredAt;
            }
            if ($station->ocpp_disconnected_at === null || $occurredAt->greaterThanOrEqualTo($station->ocpp_disconnected_at)) {
                $stationUpdates['ocpp_disconnected_at'] = null;
            }
        } elseif ($event->action === 'ConnectionClosed') {
            if ($station->ocpp_disconnected_at === null || $occurredAt->greaterThanOrEqualTo($station->ocpp_disconnected_at)) {
                $stationUpdates['ocpp_disconnected_at'] = $occurredAt;
            }
        } elseif ($event->action === 'BootNotification') {
            $stationUpdates['ocpp_registration_status'] = 'accepted';
        } elseif ($event->action === 'Heartbeat') {
            if ($station->last_heartbeat_at === null || $occurredAt->greaterThanOrEqualTo($station->last_heartbeat_at)) {
                $stationUpdates['last_heartbeat_at'] = $occurredAt;
            }
        }

        if ($event->action === 'StatusNotification') {
            $payload = $event->payload;
            $statusAt = isset($payload['timestamp'])
                ? CarbonImmutable::parse($payload['timestamp'])->utc()
                : $occurredAt;
            $connectorId = (int) $payload['connectorId'];

            if ($connectorId === 0) {
                if ($station->ocpp_last_status_at === null || $statusAt->greaterThanOrEqualTo($station->ocpp_last_status_at)) {
                    $stationUpdates['ocpp_status'] = $payload['status'];
                    $stationUpdates['ocpp_error_code'] = $payload['errorCode'];
                    $stationUpdates['ocpp_last_status_at'] = $statusAt;
                }
            } else {
                $connector = $station->connectors()
                    ->where('ocpp_connector_id', $connectorId)
                    ->lockForUpdate()
                    ->first();

                if ($connector === null) {
                    $event->update([
                        'processing_status' => 'ignored',
                        'processing_error' => "No connector is mapped to OCPP connector {$connectorId}.",
                    ]);
                    if ($stationUpdates !== []) {
                        $station->update($stationUpdates);
                    }

                    return;
                }

                if ($connector->ocpp_last_status_at === null || $statusAt->greaterThanOrEqualTo($connector->ocpp_last_status_at)) {
                    $connector->update([
                        'ocpp_status' => $payload['status'],
                        'ocpp_error_code' => $payload['errorCode'],
                        'ocpp_last_status_at' => $statusAt,
                    ]);
                }
            }
        }

        if ($stationUpdates !== []) {
            $station->update($stationUpdates);
        }
        $event->update(['processing_status' => 'applied', 'processing_error' => null]);
    }
}
