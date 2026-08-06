<?php

namespace App\Services\Payments;

use App\Events\ChargingAttemptChanged;
use App\Events\ChargingSessionChanged;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Throwable;

class FailedCaptureRecoveryService
{
    public function __construct(
        private readonly PaymentProviderEventService $providerEvents,
        private readonly PaymentReconciliationAlertService $alerts,
    ) {}

    public function handle(int $chargingSessionId): void
    {
        $payment = Payment::query()->where('charging_session_id', $chargingSessionId)->first();
        if ($payment !== null) {
            try {
                $this->providerEvents->reconcileReference($payment->reference);
            } catch (Throwable $reconciliationException) {
                report($reconciliationException);
            }
        }

        $attempt = DB::transaction(function () use ($chargingSessionId): ?ChargingAttempt {
            $session = ChargingSession::query()->lockForUpdate()->find($chargingSessionId);
            if ($session === null) {
                return null;
            }

            $attempt = ChargingAttempt::query()
                ->where('charging_session_id', $session->id)
                ->lockForUpdate()
                ->first();
            $payment = Payment::query()
                ->where('charging_session_id', $session->id)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                return null;
            }
            if ($session->payment_status === 'paid'
                || $payment?->status === 'paid'
                || $attempt->payment_status === 'captured') {
                if ($attempt->reconciliation_action === 'capture') {
                    $attempt->update([
                        'reconciliation_status' => 'completed',
                        'reconciled_at' => now(),
                    ]);
                }

                return null;
            }

            $attempt->update([
                'status' => 'reconciliation_required',
                'payment_status' => 'capture_failed',
                'reconciliation_action' => 'capture',
                'reconciliation_status' => 'requires_review',
                'reconciliation_reason' => 'capture_retries_exhausted',
                'reconciliation_started_at' => $attempt->reconciliation_started_at ?? now(),
                'reconciled_at' => null,
                'failure_code' => 'payment_capture_retries_exhausted',
                'failure_message' => 'Payment capture could not be confirmed after all automatic retry attempts.',
            ]);
            if ($session->payment_status !== 'paid') {
                $session->update(['payment_status' => 'failed']);
                event(ChargingSessionChanged::fromSession($session->fresh()));
            }
            event(ChargingAttemptChanged::fromAttempt($attempt->fresh()));

            return $attempt->fresh();
        });

        if ($attempt !== null) {
            $this->alerts->openCaptureExhausted($attempt, now());
        }
    }
}
