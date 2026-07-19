<?php

namespace App\Services\Ocpp;

use App\Jobs\CaptureAuthorizedSessionPayment;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppEvent;
use App\Models\OcppMeterSample;
use App\Models\OcppTransaction;
use App\Models\Station;
use App\Services\ChargingSessionService;
use Carbon\CarbonImmutable;

class OcppTransactionProjectionService
{
    private const TRANSACTION_ACTIONS = ['Authorize', 'StartTransaction', 'MeterValues', 'StopTransaction'];

    public function __construct(
        private readonly OcppAuthorizationService $authorization,
        private readonly ChargingSessionService $sessions,
        private readonly OcppCommandService $commands,
    ) {}

    /** @return array<string, mixed> */
    public function project(OcppEvent $event, Station $station): array
    {
        if (! in_array($event->action, self::TRANSACTION_ACTIONS, true)) {
            return [];
        }

        $response = match ($event->action) {
            'Authorize' => $this->authorize($event),
            'StartTransaction' => $this->start($event, $station),
            'MeterValues' => $this->meterValues($event, $station),
            'StopTransaction' => $this->stop($event, $station),
        };

        $event->update(['response_payload' => $response]);

        return $response;
    }

    /** @return array{idTagInfo: array{status: string}} */
    private function authorize(OcppEvent $event): array
    {
        $result = $this->authorization->authorize((string) $event->payload['idTag']);

        return ['idTagInfo' => ['status' => $result->status]];
    }

    /** @return array{transactionId: int, idTagInfo: array{status: string}} */
    private function start(OcppEvent $event, Station $station): array
    {
        $payload = $event->payload;
        $token = (string) $payload['idTag'];
        $authorization = $this->authorization->authorize($token);
        $connector = $station->connectors()
            ->where('ocpp_connector_id', (int) $payload['connectorId'])
            ->lockForUpdate()
            ->first();
        $status = $authorization->status;
        $rejectionReason = null;

        if ($connector === null) {
            [$status, $rejectionReason] = ['Invalid', 'unmapped_connector'];
        } elseif (! $authorization->accepted()) {
            $rejectionReason = 'id_tag_'.strtolower($authorization->status);
        } elseif ($station->availability_override !== null || $station->organization()->where('status', 'active')->doesntExist()) {
            [$status, $rejectionReason] = ['Blocked', 'station_not_available_for_transactions'];
        } elseif ($this->hasConcurrentTransaction(
            $station,
            $connector,
            OcppAuthorizationService::hash($token),
            $authorization->user?->id,
        )) {
            [$status, $rejectionReason] = ['ConcurrentTx', 'active_transaction_exists'];
        }

        $transaction = OcppTransaction::query()->create([
            'organization_id' => $station->organization_id,
            'station_id' => $station->id,
            'connector_id' => $connector?->id,
            'ocpp_id_tag_id' => $authorization->idTag?->id,
            'start_event_id' => $event->id,
            'id_tag_hash' => OcppAuthorizationService::hash($token),
            'id_tag_masked' => OcppAuthorizationService::mask($token),
            'status' => $status === 'Accepted' ? 'active' : 'rejected',
            'meter_start_wh' => (int) $payload['meterStart'],
            'last_meter_wh' => (int) $payload['meterStart'],
            'started_at' => CarbonImmutable::parse($payload['timestamp'])->utc(),
            'rejection_reason' => $rejectionReason,
        ]);

        if ($status === 'Accepted' && $connector !== null && $authorization->user !== null) {
            $this->sessions->startFromOcpp($authorization->user, $station, $connector, $transaction);
            $this->commands->confirmStart($transaction);
        }

        return [
            'transactionId' => $transaction->id,
            'idTagInfo' => ['status' => $status],
        ];
    }

    /** @return array<string, mixed> */
    private function meterValues(OcppEvent $event, Station $station): array
    {
        $payload = $event->payload;
        $transactionQuery = OcppTransaction::query()
            ->where('station_id', $station->id)
            ->whereIn('status', ['active', 'awaiting_reconciliation']);

        if (isset($payload['transactionId'])) {
            $transactionQuery->whereKey((int) $payload['transactionId']);
        } else {
            $connector = $station->connectors()
                ->where('ocpp_connector_id', (int) $payload['connectorId'])
                ->first();
            $transactionQuery->where('connector_id', $connector?->id ?? 0);
        }

        $transaction = $transactionQuery->lockForUpdate()->first();

        if ($transaction === null) {
            $event->update([
                'processing_status' => 'ignored',
                'processing_error' => 'MeterValues references an unknown or inactive transaction.',
            ]);

            return [];
        }

        $sampleIndex = 0;
        $latestEnergyWh = null;
        $latestEnergyAt = null;
        $latestPowerKw = null;
        $latestStateOfCharge = null;

        foreach ($payload['meterValue'] as $meterValue) {
            $sampledAt = CarbonImmutable::parse($meterValue['timestamp'])->utc();
            foreach ($meterValue['sampledValue'] as $sampledValue) {
                $measurand = (string) ($sampledValue['measurand'] ?? 'Energy.Active.Import.Register');
                $unit = (string) ($sampledValue['unit'] ?? ($measurand === 'Energy.Active.Import.Register' ? 'Wh' : 'W'));
                $value = (float) $sampledValue['value'];

                OcppMeterSample::query()->create([
                    'organization_id' => $station->organization_id,
                    'station_id' => $station->id,
                    'connector_id' => $transaction->connector_id,
                    'ocpp_transaction_id' => $transaction->id,
                    'ocpp_event_id' => $event->id,
                    'sample_index' => $sampleIndex++,
                    'sampled_at' => $sampledAt,
                    'value' => $value,
                    'measurand' => $measurand,
                    'context' => $sampledValue['context'] ?? null,
                    'phase' => $sampledValue['phase'] ?? null,
                    'location' => $sampledValue['location'] ?? null,
                    'unit' => $unit,
                ]);

                if ($measurand === 'Energy.Active.Import.Register') {
                    $energyWh = $unit === 'kWh' ? (int) round($value * 1000) : (int) round($value);
                    if ($latestEnergyAt === null || $sampledAt->greaterThanOrEqualTo($latestEnergyAt)) {
                        $latestEnergyWh = $energyWh;
                        $latestEnergyAt = $sampledAt;
                    }
                } elseif ($measurand === 'Power.Active.Import') {
                    $latestPowerKw = $unit === 'kW' ? $value : $value / 1000;
                } elseif ($measurand === 'SoC') {
                    $latestStateOfCharge = $value;
                }
            }
        }

        if ($latestEnergyWh !== null && $latestEnergyAt !== null
            && ($transaction->last_meter_value_at === null || $latestEnergyAt->greaterThanOrEqualTo($transaction->last_meter_value_at))) {
            $effectiveMeterWh = max($transaction->meter_start_wh, $transaction->last_meter_wh ?? 0, $latestEnergyWh);
            $transaction->update([
                'status' => 'active',
                'stop_reason' => null,
                'last_meter_wh' => $effectiveMeterWh,
                'last_meter_value_at' => $latestEnergyAt,
            ]);
            $session = $this->sessions->updateFromOcppMeter(
                $transaction->fresh(),
                $effectiveMeterWh,
                $latestEnergyAt,
                $latestPowerKw,
                $latestStateOfCharge,
            );
            if ($session !== null && $session->status === 'charging') {
                $limitReason = $this->reachedLimit($session);
                if ($limitReason !== null) {
                    $this->commands->queueRemoteStop($session, null, $limitReason);
                }
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function stop(OcppEvent $event, Station $station): array
    {
        $payload = $event->payload;
        $transaction = OcppTransaction::query()
            ->whereKey((int) $payload['transactionId'])
            ->where('station_id', $station->id)
            ->lockForUpdate()
            ->first();
        $response = [];

        if (isset($payload['idTag'])) {
            $authorization = $this->authorization->authorize((string) $payload['idTag']);
            $response['idTagInfo'] = ['status' => $authorization->status];
        }

        if ($transaction === null) {
            $event->update([
                'processing_status' => 'ignored',
                'processing_error' => 'StopTransaction references an unknown transaction.',
            ]);

            return $response;
        }

        if ($transaction->stop_event_id !== null) {
            return $response;
        }

        $reason = (string) ($payload['reason'] ?? 'Local');
        $terminalStatus = in_array($reason, [
            'EmergencyStop', 'PowerLoss', 'Reboot', 'HardReset', 'SoftReset', 'DeAuthorized',
        ], true) ? 'interrupted' : 'completed';
        $meterStopWh = max(
            $transaction->meter_start_wh,
            $transaction->last_meter_wh ?? 0,
            (int) $payload['meterStop'],
        );
        $stoppedAt = CarbonImmutable::parse($payload['timestamp'])->utc();

        $transaction->update([
            'stop_event_id' => $event->id,
            'status' => $terminalStatus,
            'meter_stop_wh' => $meterStopWh,
            'last_meter_wh' => $meterStopWh,
            'stopped_at' => $stoppedAt,
            'stop_reason' => $reason,
        ]);

        if ($transaction->chargingSession()->exists()) {
            $session = $this->sessions->finishFromOcpp($transaction->fresh(), $terminalStatus, $reason);
            $this->commands->confirmStop($transaction->fresh());
            CaptureAuthorizedSessionPayment::dispatch($session->id)->afterCommit();
        }

        return $response;
    }

    private function hasConcurrentTransaction(
        Station $station,
        Connector $connector,
        string $idTagHash,
        ?int $clientId,
    ): bool {
        $technicalTransactionExists = OcppTransaction::query()
            ->where('status', 'active')
            ->where(function ($query) use ($station, $connector, $idTagHash): void {
                $query->where('connector_id', $connector->id)
                    ->orWhere(function ($query) use ($station, $idTagHash): void {
                        $query->where('station_id', $station->id)->where('id_tag_hash', $idTagHash);
                    });
            })
            ->exists();

        if ($technicalTransactionExists) {
            return true;
        }

        return ChargingSession::query()
            ->whereIn('status', ['pending', 'charging', 'stopping'])
            ->where(function ($query) use ($connector, $clientId): void {
                $query->where('connector_id', $connector->id);
                if ($clientId !== null) {
                    $query->orWhere('client_id', $clientId);
                }
            })
            ->exists();
    }

    private function reachedLimit(ChargingSession $session): ?string
    {
        if ($session->limit_energy_kwh !== null && $session->energy_kwh >= $session->limit_energy_kwh) {
            return 'energy_limit_reached';
        }
        if ($session->limit_amount_millimes !== null && $session->total_millimes >= $session->limit_amount_millimes) {
            return 'amount_limit_reached';
        }
        if ($session->limit_duration_minutes !== null
            && $session->duration_seconds >= $session->limit_duration_minutes * 60) {
            return 'duration_limit_reached';
        }

        return null;
    }
}
