<?php

namespace App\Services\Availability;

use App\Events\StationAvailabilityChanged;
use App\Models\Alert;
use App\Models\AvailabilityTransition;
use App\Models\Connector;
use App\Models\Station;
use App\Services\ChargingSessionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AvailabilityProjectionService
{
    public function __construct(private readonly ChargingSessionService $chargingSessions) {}

    public function resolveDeletedConnectorAlert(Station $station, Connector $connector): void
    {
        DB::transaction(function () use ($station, $connector): void {
            $this->resolveAutomaticAlert(
                "availability:connector:{$connector->id}:faulted",
                now()->utc(),
            );
            $this->syncStationAlertCount($station);
        });
    }

    /** @return array{station: Station, changed: bool} */
    public function project(Station|int $station, ?int $ocppEventId = null): array
    {
        $stationId = $station instanceof Station ? $station->id : $station;

        $result = DB::transaction(function () use ($stationId, $ocppEventId): array {
            $station = Station::query()->lockForUpdate()->findOrFail($stationId);

            if (! $station->isOcppManaged()) {
                return ['station' => $station, 'changed' => false];
            }

            $connectors = $station->connectors()->lockForUpdate()->orderBy('ocpp_connector_id')->get();
            $calculatedAt = now()->utc();
            $connectivity = $this->connectivityState($station, $calculatedAt);
            $stationState = $this->stationState($station, $connectors, $connectivity);
            $changed = false;

            foreach ($stationState['connectors'] as $connectorId => $state) {
                /** @var Connector $connector */
                $connector = $connectors->firstWhere('id', $connectorId);
                $connectorChanged = $this->stateChanged($connector, $state);

                if ($connectorChanged) {
                    $this->recordTransition($station, $connector, $state, $calculatedAt, $ocppEventId);
                    $changed = true;
                }

                $connector->update([
                    'status' => $state['status'],
                    'availability_reason' => $state['reason'],
                    'availability_source' => $state['source'],
                    'availability_calculated_at' => $calculatedAt,
                    'last_status_at' => $connectorChanged ? $calculatedAt : $connector->last_status_at,
                    'error_code' => $state['error_code'],
                ]);

                if ($connectorChanged) {
                    $this->syncConnectorFaultAlert($station, $connector, $state, $calculatedAt);
                }
            }

            $stationChanged = $this->stateChanged($station, $stationState);
            if ($stationChanged) {
                $this->recordTransition($station, null, $stationState, $calculatedAt, $ocppEventId);
                $changed = true;
            }

            $station->update([
                'status' => $stationState['status'],
                'availability_reason' => $stationState['reason'],
                'availability_source' => $stationState['source'],
                'availability_calculated_at' => $calculatedAt,
            ]);

            if ($stationState['status'] === 'offline'
                && in_array($stationState['reason'], ['connection_closed', 'communication_timeout'], true)) {
                $this->chargingSessions->interruptOcppForConnectivity($station, $stationState['reason']);
            }

            if ($stationChanged) {
                $this->syncCommunicationAlert($station, $stationState, $calculatedAt);
            }

            $this->syncStationAlertCount($station);

            return [
                'station' => $station->fresh()->load('connectors'),
                'changed' => $changed,
            ];
        }, 3);

        if ($result['changed']) {
            event(StationAvailabilityChanged::fromStation($result['station']));
        }

        return $result;
    }

    /** @return array{connected: bool, reason: string} */
    private function connectivityState(Station $station, CarbonInterface $calculatedAt): array
    {
        if ($station->ocpp_disconnected_at !== null
            && ($station->ocpp_connected_at === null || $station->ocpp_disconnected_at->greaterThanOrEqualTo($station->ocpp_connected_at))) {
            return ['connected' => false, 'reason' => 'connection_closed'];
        }

        $lastContact = collect([
            $station->last_heartbeat_at,
            $station->ocpp_last_message_at,
            $station->ocpp_connected_at,
        ])->filter()->sortByDesc(fn (CarbonInterface $date) => $date->getTimestamp())->first();

        if ($lastContact === null) {
            $monitoringStartedAt = $station->availability_monitoring_started_at ?? $station->updated_at;
            $graceExpired = $monitoringStartedAt !== null
                && $monitoringStartedAt->lte($calculatedAt->copy()->subSeconds($this->communicationTimeout()));

            return [
                'connected' => false,
                'reason' => $graceExpired ? 'communication_timeout' : 'awaiting_connection',
            ];
        }

        if ($lastContact->lt($calculatedAt->copy()->subSeconds($this->communicationTimeout()))) {
            return ['connected' => false, 'reason' => 'communication_timeout'];
        }

        return ['connected' => true, 'reason' => 'communication_healthy'];
    }

    /**
     * @param  Collection<int, Connector>  $connectors
     * @param  array{connected: bool, reason: string}  $connectivity
     * @return array{status: string, reason: string, source: string, connectors: array<int, array{status: string, reason: string, source: string, error_code: ?string}>}
     */
    private function stationState(Station $station, $connectors, array $connectivity): array
    {
        if ($station->availability_override === 'disabled') {
            return $this->forcedState($connectors, 'unavailable', 'manually_disabled', 'manual_override');
        }

        if ($station->availability_override === 'maintenance') {
            return $this->forcedState($connectors, 'maintenance', 'planned_maintenance', 'manual_override');
        }

        if (! $connectivity['connected']) {
            return $this->forcedState($connectors, 'offline', $connectivity['reason'], 'connectivity');
        }

        $connectorStates = [];
        foreach ($connectors as $connector) {
            $connectorStates[$connector->id] = $this->connectorState($connector);
        }

        if (strcasecmp((string) $station->ocpp_status, 'Faulted') === 0
            || $this->hasOcppError($station->ocpp_error_code)) {
            return [
                'status' => 'faulted',
                'reason' => 'station_reported_fault',
                'source' => 'ocpp_projection',
                'connectors' => $connectorStates,
            ];
        }

        if (strcasecmp((string) $station->ocpp_status, 'Unavailable') === 0) {
            return [
                'status' => 'unavailable',
                'reason' => 'station_reported_unavailable',
                'source' => 'ocpp_projection',
                'connectors' => $connectorStates,
            ];
        }

        $statuses = collect($connectorStates)->pluck('status');
        if ($statuses->contains('available')) {
            [$status, $reason] = ['available', 'connector_available'];
        } elseif ($statuses->contains('charging')) {
            [$status, $reason] = ['charging', 'all_connectors_occupied'];
        } elseif ($statuses->contains('reserved')) {
            [$status, $reason] = ['reserved', 'all_connectors_reserved'];
        } elseif ($statuses->contains('faulted')) {
            [$status, $reason] = ['faulted', 'no_usable_connector'];
        } elseif ($connectors->isEmpty()) {
            [$status, $reason] = ['unavailable', 'no_connectors'];
        } else {
            [$status, $reason] = ['unavailable', 'no_usable_connector'];
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'source' => 'ocpp_projection',
            'connectors' => $connectorStates,
        ];
    }

    /** @return array{status: string, reason: string, source: string, error_code: ?string} */
    private function connectorState(Connector $connector): array
    {
        $rawStatus = strtolower((string) $connector->ocpp_status);
        $errorCode = $this->hasOcppError($connector->ocpp_error_code)
            ? $connector->ocpp_error_code
            : null;

        if ($rawStatus === 'faulted' || $errorCode !== null) {
            return [
                'status' => 'faulted',
                'reason' => $errorCode
                    ? Str::limit('ocpp_error_'.Str::snake($errorCode), 80, '')
                    : 'connector_reported_fault',
                'source' => 'ocpp_projection',
                'error_code' => $errorCode,
            ];
        }

        return match ($rawStatus) {
            'available' => $this->connectorProjection('available', 'connector_available'),
            'preparing', 'charging', 'suspendedevse', 'suspendedev', 'finishing' => $this->connectorProjection('charging', 'connector_occupied'),
            'reserved' => $this->connectorProjection('reserved', 'connector_reserved'),
            'unavailable' => $this->connectorProjection('unavailable', 'connector_reported_unavailable'),
            default => $this->connectorProjection('unavailable', 'awaiting_connector_status'),
        };
    }

    /** @return array{status: string, reason: string, source: string, error_code: null} */
    private function connectorProjection(string $status, string $reason): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'source' => 'ocpp_projection',
            'error_code' => null,
        ];
    }

    /** @return array{status: string, reason: string, source: string, connectors: array<int, array{status: string, reason: string, source: string, error_code: ?string}>} */
    private function forcedState($connectors, string $status, string $reason, string $source): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'source' => $source,
            'connectors' => $connectors->mapWithKeys(fn (Connector $connector) => [
                $connector->id => [
                    'status' => $status,
                    'reason' => $reason,
                    'source' => $source,
                    'error_code' => $this->hasOcppError($connector->ocpp_error_code)
                        ? $connector->ocpp_error_code
                        : null,
                ],
            ])->all(),
        ];
    }

    /** @param array{status: string, reason: string, source: string} $state */
    private function stateChanged(Station|Connector $model, array $state): bool
    {
        return $model->status !== $state['status']
            || $model->availability_reason !== $state['reason']
            || $model->availability_source !== $state['source'];
    }

    /** @param array{status: string, reason: string, source: string} $state */
    private function recordTransition(
        Station $station,
        ?Connector $connector,
        array $state,
        CarbonInterface $occurredAt,
        ?int $ocppEventId,
    ): void {
        AvailabilityTransition::query()->create([
            'organization_id' => $station->organization_id,
            'station_id' => $station->id,
            'connector_id' => $connector?->id,
            'ocpp_event_id' => $ocppEventId,
            'from_status' => $connector?->status ?? $station->status,
            'to_status' => $state['status'],
            'from_reason' => $connector?->availability_reason ?? $station->availability_reason,
            'to_reason' => $state['reason'],
            'source' => $state['source'],
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @param array{status: string, reason: string, source: string} $state */
    private function syncCommunicationAlert(Station $station, array $state, CarbonInterface $occurredAt): void
    {
        $active = $state['status'] === 'offline'
            && in_array($state['reason'], ['connection_closed', 'communication_timeout'], true);

        $this->syncAutomaticAlert(
            active: $active,
            key: "availability:station:{$station->id}:communication",
            station: $station,
            connector: null,
            title: 'Station communication lost',
            problemType: 'OCPP communication loss',
            description: "No reliable OCPP communication is available for {$station->name}.",
            cause: $state['reason'] === 'connection_closed'
                ? 'The OCPP WebSocket connection was closed.'
                : "No OCPP message was received for more than {$this->communicationTimeout()} seconds.",
            action: 'Check station power and network access, then verify the OCPP Gateway connection.',
            occurredAt: $occurredAt,
        );
    }

    /** @param array{status: string, reason: string, source: string, error_code: ?string} $state */
    private function syncConnectorFaultAlert(
        Station $station,
        Connector $connector,
        array $state,
        CarbonInterface $occurredAt,
    ): void {
        $this->syncAutomaticAlert(
            active: $state['status'] === 'faulted',
            key: "availability:connector:{$connector->id}:faulted",
            station: $station,
            connector: $connector,
            title: "Connector {$connector->external_id} faulted",
            problemType: 'OCPP connector fault',
            description: "Connector {$connector->external_id} at {$station->name} is not usable.",
            cause: $state['error_code'] ?: 'The charging station reported a faulted connector state.',
            action: 'Inspect the connector and OCPP diagnostic data before returning it to service.',
            occurredAt: $occurredAt,
        );
    }

    private function syncAutomaticAlert(
        bool $active,
        string $key,
        Station $station,
        ?Connector $connector,
        string $title,
        string $problemType,
        string $description,
        string $cause,
        string $action,
        CarbonInterface $occurredAt,
    ): void {
        if (! $active) {
            $this->resolveAutomaticAlert($key, $occurredAt);

            return;
        }

        $alert = Alert::query()->where('deduplication_key', $key)->lockForUpdate()->first();

        $attributes = [
            'organization_id' => $station->organization_id,
            'station_id' => $station->id,
            'connector_id' => $connector?->id,
            'title' => $title,
            'problem_type' => $problemType,
            'severity' => 'critical',
            'status' => 'new',
            'source' => 'availability_engine',
            'deduplication_key' => $key,
            'description' => $description,
            'ocpp_log' => $connector?->ocpp_status
                ? "StatusNotification status={$connector->ocpp_status} errorCode=".($connector->ocpp_error_code ?: 'NoError')
                : null,
            'suggested_cause' => $cause,
            'recommended_action' => $action,
            'detected_at' => $occurredAt,
            'due_at' => $occurredAt->copy()->addMinutes((int) config('availability.critical_alert_due_minutes', 15)),
            'resolved_at' => null,
        ];

        if ($alert === null) {
            $alert = Alert::query()->create([
                ...$attributes,
                'reference' => 'AUTO-'.Str::upper(substr(hash('sha256', $key), 0, 10)),
            ]);
            $eventType = 'auto_detected';
        } elseif ($alert->status === 'resolved') {
            $alert->update($attributes);
            $eventType = 'auto_reopened';
        } else {
            return;
        }

        $alert->events()->create([
            'event_type' => $eventType,
            'description' => $description,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function resolveAutomaticAlert(string $key, CarbonInterface $occurredAt): void
    {
        $alert = Alert::query()->where('deduplication_key', $key)->lockForUpdate()->first();

        if ($alert === null || $alert->status === 'resolved') {
            return;
        }

        $alert->update(['status' => 'resolved', 'resolved_at' => $occurredAt]);
        $alert->events()->create([
            'event_type' => 'auto_resolved',
            'description' => 'The availability condition returned to normal.',
            'occurred_at' => $occurredAt,
        ]);
    }

    private function syncStationAlertCount(Station $station): void
    {
        $station->update([
            'open_alerts_count' => Alert::query()
                ->where('station_id', $station->id)
                ->where('status', '!=', 'resolved')
                ->count(),
        ]);
    }

    private function communicationTimeout(): int
    {
        return max(1, (int) config('availability.communication_timeout_seconds', 90));
    }

    private function hasOcppError(?string $errorCode): bool
    {
        return $errorCode !== null
            && $errorCode !== ''
            && strcasecmp($errorCode, 'NoError') !== 0;
    }
}
