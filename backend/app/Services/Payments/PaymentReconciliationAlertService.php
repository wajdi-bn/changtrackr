<?php

namespace App\Services\Payments;

use App\Models\Alert;
use App\Models\ChargingAttempt;
use App\Models\Station;
use App\Services\Notifications\OperationalNotificationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentReconciliationAlertService
{
    public function __construct(private readonly OperationalNotificationService $notifications) {}

    public function openMissingMeter(ChargingAttempt $attempt, CarbonInterface $occurredAt): Alert
    {
        return $this->open(
            attempt: $attempt,
            type: 'missing-meter',
            title: 'Charging payment released after missing telemetry',
            severity: 'warning',
            description: 'The preauthorization was released because no trustworthy final meter value was available after the connectivity grace period.',
            cause: 'The station stopped reporting before it could provide a final OCPP meter value.',
            action: 'Review the station telemetry and session before attempting any manual settlement.',
            occurredAt: $occurredAt,
        );
    }

    public function openCaptureExhausted(ChargingAttempt $attempt, CarbonInterface $occurredAt): Alert
    {
        return $this->open(
            attempt: $attempt,
            type: 'capture-exhausted',
            title: 'Payment capture requires reconciliation',
            severity: 'critical',
            description: 'The payment provider did not confirm the authorized session capture after all automatic retries.',
            cause: 'The provider remained unavailable or returned an ambiguous technical response.',
            action: 'Check the provider transaction before retrying capture or releasing the authorization.',
            occurredAt: $occurredAt,
        );
    }

    public function resolveCaptureExhausted(ChargingAttempt $attempt, ?CarbonInterface $occurredAt = null): void
    {
        $occurredAt = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($occurredAt)->utc();
        $key = $this->key($attempt, 'capture-exhausted');
        $resolved = DB::transaction(function () use ($attempt, $occurredAt, $key): ?array {
            $alert = Alert::query()->where('deduplication_key', $key)->lockForUpdate()->first();
            if ($alert === null || $alert->status === 'resolved') {
                return null;
            }

            $previousStatus = $alert->status;
            $alert->update(['status' => 'resolved', 'resolved_at' => $occurredAt]);
            $event = $alert->events()->create([
                'event_type' => 'auto_resolved',
                'description' => 'The provider confirmed the capture and the payment reconciliation alert was closed.',
                'occurred_at' => $occurredAt,
            ]);
            $this->syncStationAlertCount($attempt->station_id);

            return ['alert' => $alert->fresh(), 'event_id' => $event->id, 'previous_status' => $previousStatus];
        });

        if ($resolved !== null) {
            $this->notifications->notifyAlertStatusChanged(
                $resolved['alert']->loadMissing(['station', 'assignedTechnician']),
                $resolved['previous_status'],
                $resolved['event_id'],
            );
        }
    }

    private function open(
        ChargingAttempt $attempt,
        string $type,
        string $title,
        string $severity,
        string $description,
        string $cause,
        string $action,
        CarbonInterface $occurredAt,
    ): Alert {
        $occurredAt = CarbonImmutable::instance($occurredAt)->utc();
        $key = $this->key($attempt, $type);
        $created = false;
        $alert = DB::transaction(function () use (
            $attempt,
            $title,
            $severity,
            $description,
            $cause,
            $action,
            $occurredAt,
            $key,
            &$created,
        ): Alert {
            $alert = Alert::query()->where('deduplication_key', $key)->lockForUpdate()->first();
            if ($alert !== null) {
                return $alert;
            }

            $alert = Alert::query()->create([
                'organization_id' => $attempt->organization_id,
                'station_id' => $attempt->station_id,
                'connector_id' => $attempt->connector_id,
                'reference' => 'PAYREC-'.Str::upper(substr(hash('sha256', $key), 0, 10)),
                'title' => $title,
                'problem_type' => 'OCPP payment reconciliation',
                'severity' => $severity,
                'status' => 'new',
                'source' => 'payment_reconciliation',
                'deduplication_key' => $key,
                'description' => $description,
                'suggested_cause' => $cause,
                'recommended_action' => $action,
                'detected_at' => $occurredAt,
                'due_at' => $occurredAt->addHour(),
            ]);
            $alert->events()->create([
                'event_type' => 'auto_detected',
                'description' => $description,
                'occurred_at' => $occurredAt,
            ]);
            $this->syncStationAlertCount($attempt->station_id);
            $created = true;

            return $alert;
        });

        if ($created) {
            $event = $alert->events()->latest('id')->first();
            $this->notifications->notifyAlertOpened(
                $alert->loadMissing('station'),
                $event?->id ?? $occurredAt->timestamp,
            );
        }

        return $alert;
    }

    private function key(ChargingAttempt $attempt, string $type): string
    {
        return "payment-reconciliation:attempt:{$attempt->id}:{$type}";
    }

    private function syncStationAlertCount(int $stationId): void
    {
        Station::query()->whereKey($stationId)->update([
            'open_alerts_count' => Alert::query()
                ->where('station_id', $stationId)
                ->where('status', '!=', 'resolved')
                ->count(),
        ]);
    }
}
