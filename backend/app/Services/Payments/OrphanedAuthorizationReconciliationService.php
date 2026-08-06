<?php

namespace App\Services\Payments;

use App\Events\ChargingSessionChanged;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\OcppTransaction;
use App\Services\ChargingSessionService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class OrphanedAuthorizationReconciliationService
{
    public function __construct(
        private readonly ChargingSessionService $sessions,
        private readonly PaymentService $payments,
        private readonly PaymentReconciliationAlertService $alerts,
    ) {}

    /** @return array{captured:int,released:int,failed:int,skipped:int} */
    public function scan(?CarbonInterface $at = null): array
    {
        $now = $at === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($at)->utc();
        $graceCutoff = $now->subHours(max(1, (int) config('payments.orphan_reconciliation_grace_hours', 24)));
        $retryCutoff = $now->subMinutes(max(1, (int) config('payments.orphan_reconciliation_retry_minutes', 15)));
        $batchSize = max(1, min(500, (int) config('payments.orphan_reconciliation_batch_size', 100)));

        $attemptIds = ChargingAttempt::query()
            ->whereNotNull('charging_session_id')
            ->whereNotNull('provider_authorization_id')
            ->whereIn('payment_status', [
                'authorized', 'capture_pending', 'capture_failed',
                'release_pending', 'release_failed',
            ])
            ->whereHas('chargingSession', fn ($query) => $query
                ->where('source', 'ocpp')
                ->where('status', 'interrupted')
                ->whereNotNull('ended_at')
                ->where('ended_at', '<=', $graceCutoff))
            ->where(function ($query) use ($retryCutoff): void {
                $query->whereNull('reconciliation_status')
                    ->orWhere(function ($query) use ($retryCutoff): void {
                        $query->whereIn('reconciliation_status', ['pending', 'failed'])
                            ->where(function ($query) use ($retryCutoff): void {
                                $query->whereNull('reconciliation_started_at')
                                    ->orWhere('reconciliation_started_at', '<=', $retryCutoff);
                            });
                    });
            })
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        $result = ['captured' => 0, 'released' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($attemptIds as $attemptId) {
            $outcome = $this->reconcile((int) $attemptId, $now, $graceCutoff, $retryCutoff);
            $result[$outcome]++;
        }

        return $result;
    }

    private function reconcile(
        int $attemptId,
        CarbonImmutable $now,
        CarbonImmutable $graceCutoff,
        CarbonImmutable $retryCutoff,
    ): string {
        $decision = $this->prepareDecision($attemptId, $now, $graceCutoff, $retryCutoff);
        if ($decision === null) {
            return 'skipped';
        }

        $attempt = ChargingAttempt::query()->findOrFail($attemptId);
        $session = ChargingSession::query()->findOrFail($decision['session_id']);

        if ($decision['action'] === 'capture') {
            $session = $this->sessions->reconcileInterruptedFromLastMeter($session);
            if ($session === null) {
                $this->markFailed($attemptId, 'ocpp_meter_reconciliation_failed', 'The stored OCPP meter could not finalize the interrupted session.');

                return 'failed';
            }

            $payment = $this->payments->captureAuthorized($session);
            $attempt->refresh();
            if ($payment?->status === 'paid' || $attempt->payment_status === 'captured') {
                $this->updateSessionLifecycle($session->id, 'ocpp_orphan_authorization_captured');

                return 'captured';
            }

            if ($attempt->reconciliation_status !== 'failed') {
                $this->markFailed($attemptId, 'payment_capture_incomplete', 'The orphaned authorization could not be captured.');
            }

            return 'failed';
        }

        $released = $this->payments->releaseAuthorized($attempt);
        $attempt->refresh();
        if (! $released && $attempt->payment_status !== 'released') {
            if ($attempt->reconciliation_status !== 'failed') {
                $this->markFailed($attemptId, 'payment_release_incomplete', 'The orphaned authorization could not be released.');
            }

            return 'failed';
        }

        $this->finalizeReleaseWithoutMeter($session->id);
        $this->alerts->openMissingMeter($attempt->fresh(), $now);

        return 'released';
    }

    /** @return array{action:string,session_id:int}|null */
    private function prepareDecision(
        int $attemptId,
        CarbonImmutable $now,
        CarbonImmutable $graceCutoff,
        CarbonImmutable $retryCutoff,
    ): ?array {
        $lookup = ChargingAttempt::query()->select(['id', 'charging_session_id'])->find($attemptId);
        if ($lookup?->charging_session_id === null) {
            return null;
        }

        return DB::transaction(function () use ($lookup, $now, $graceCutoff, $retryCutoff): ?array {
            $session = ChargingSession::query()->lockForUpdate()->find($lookup->charging_session_id);
            $attempt = ChargingAttempt::query()->lockForUpdate()->find($lookup->id);
            if ($session === null || $attempt === null
                || $session->source !== 'ocpp'
                || $session->status !== 'interrupted'
                || $session->ended_at === null
                || $session->ended_at->gt($graceCutoff)
                || $attempt->provider_authorization_id === null
                || $attempt->reconciliation_status === 'completed') {
                return null;
            }
            if ($attempt->reconciliation_status === 'pending'
                && $attempt->reconciliation_started_at?->gt($retryCutoff)) {
                return null;
            }

            $transaction = $session->ocpp_transaction_id === null
                ? null
                : OcppTransaction::query()->lockForUpdate()->find($session->ocpp_transaction_id);
            $action = $attempt->reconciliation_action;
            if ($action === null) {
                if ($attempt->payment_status !== 'authorized') {
                    return null;
                }
                $action = $this->meterIsReliable($transaction, $session) ? 'capture' : 'release';
            }

            $allowedStatuses = $action === 'capture'
                ? ['authorized', 'capture_pending', 'capture_failed']
                : ['authorized', 'release_pending', 'release_failed'];
            if (! in_array($attempt->payment_status, $allowedStatuses, true)) {
                return null;
            }

            $attempt->update([
                'reconciliation_action' => $action,
                'reconciliation_status' => 'pending',
                'reconciliation_reason' => $attempt->reconciliation_reason
                    ?? ($action === 'capture' ? 'recent_consistent_ocpp_meter' : 'missing_or_stale_ocpp_meter'),
                'reconciliation_started_at' => $now,
                'reconciled_at' => null,
            ]);

            return ['action' => $action, 'session_id' => $session->id];
        });
    }

    private function meterIsReliable(?OcppTransaction $transaction, ChargingSession $session): bool
    {
        if ($transaction === null
            || $transaction->last_meter_wh === null
            || $transaction->last_meter_value_at === null
            || $transaction->last_meter_wh < $transaction->meter_start_wh
            || $session->ended_at === null) {
            return false;
        }

        $maxAge = max(1, (int) config('payments.orphan_meter_max_age_minutes', 15));

        return $transaction->last_meter_value_at->gte($session->ended_at->copy()->subMinutes($maxAge))
            && $transaction->last_meter_value_at->lte($session->ended_at->copy()->addMinute());
    }

    private function finalizeReleaseWithoutMeter(int $sessionId): void
    {
        DB::transaction(function () use ($sessionId): void {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($sessionId);
            $transaction = $session->ocpp_transaction_id === null
                ? null
                : OcppTransaction::query()->lockForUpdate()->find($session->ocpp_transaction_id);

            if ($session->payment_status !== 'paid') {
                $session->update([
                    'payment_status' => 'released',
                    'lifecycle_reason' => 'ocpp_orphan_authorization_released_without_reliable_meter',
                    'current_power_kw' => 0,
                ]);
                event(ChargingSessionChanged::fromSession($session->fresh()));
            }
            if ($transaction !== null && $transaction->status === 'awaiting_reconciliation') {
                $transaction->update([
                    'status' => 'reconciled_without_meter',
                    'stopped_at' => $session->ended_at,
                    'stop_reason' => 'ConnectivityTimeoutNoReliableMeter',
                ]);
            }
        });
    }

    private function updateSessionLifecycle(int $sessionId, string $reason): void
    {
        $session = ChargingSession::query()->find($sessionId);
        if ($session !== null && $session->lifecycle_reason !== $reason) {
            $session->update(['lifecycle_reason' => $reason]);
        }
    }

    private function markFailed(int $attemptId, string $code, string $message): void
    {
        ChargingAttempt::query()
            ->whereKey($attemptId)
            ->where('reconciliation_status', '!=', 'completed')
            ->update([
                'reconciliation_status' => 'failed',
                'failure_code' => $code,
                'failure_message' => $message,
            ]);
    }
}
