<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Events\ChargingAttemptChanged;
use App\Events\ChargingSessionChanged;
use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Payment;
use App\Models\User;
use App\Services\Notifications\OperationalNotificationService;
use App\Services\Payments\PaymentProviderEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentProviderEventService $providerEvents,
        private readonly OperationalNotificationService $notifications,
    ) {}

    /** @param array{method:string, idempotency_key:string, simulation_outcome?:string} $attributes */
    public function process(User $user, ChargingSession $session, array $attributes): Payment
    {
        $prepared = DB::transaction(function () use ($user, $session, $attributes): array {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! in_array($session->status, ['completed', 'interrupted'], true)) {
                throw ValidationException::withMessages(['session' => ['Stop the charging session before payment.']]);
            }

            if ($session->source === 'ocpp' && $session->meter_stop_kwh === null) {
                throw ValidationException::withMessages([
                    'session' => ['The station has not supplied its final meter value yet.'],
                ]);
            }

            $attempt = ChargingAttempt::query()
                ->where('charging_session_id', $session->id)
                ->lockForUpdate()
                ->first();
            $payment = Payment::query()->where('charging_session_id', $session->id)->lockForUpdate()->first();
            if ($session->payment_status === 'paid' && $payment) {
                return ['payment' => $payment, 'execute' => false];
            }

            if ($this->hasUnsettledAuthorization($attempt)) {
                throw ValidationException::withMessages([
                    'session' => ['This session has a payment authorization that must be captured or released before another payment can start.'],
                ]);
            }

            if ($payment?->status === 'pending') {
                if ($payment->idempotency_key !== $attributes['idempotency_key']) {
                    throw ValidationException::withMessages([
                        'payment' => ['A payment for this session is already being processed.'],
                    ]);
                }

                return ['payment' => $payment, 'execute' => false];
            }

            if ($payment !== null && $payment->status !== 'failed') {
                throw ValidationException::withMessages([
                    'payment' => ['The existing payment cannot be replaced in its current state.'],
                ]);
            }

            $values = [
                'organization_id' => $session->organization_id,
                'user_id' => $user->id,
                'reference' => $payment?->reference ?? 'PAY-'.Str::upper(Str::random(10)),
                'provider' => $this->gateway->name(),
                'method' => $attributes['method'],
                'status' => 'pending',
                'amount_millimes' => $session->total_millimes,
                'currency' => $session->currency,
                'idempotency_key' => $attributes['idempotency_key'],
                'provider_transaction_id' => null,
                'failure_reason' => null,
                'metadata' => null,
                'paid_at' => null,
                'failed_at' => null,
            ];

            if ($payment) {
                $payment->update($values);

                return ['payment' => $payment->fresh(), 'execute' => true];
            }

            return [
                'payment' => Payment::query()->create(['charging_session_id' => $session->id, ...$values]),
                'execute' => true,
            ];
        });

        /** @var Payment $payment */
        $payment = $prepared['payment'];
        if (! $prepared['execute']) {
            return $payment->load(['organization', 'chargingSession', 'user']);
        }

        $result = $this->gateway->charge(new PaymentCharge(
            paymentReference: $payment->reference,
            amountMillimes: $payment->amount_millimes,
            currency: $payment->currency,
            method: $payment->method,
            idempotencyKey: $payment->idempotency_key,
            simulationOutcome: $attributes['simulation_outcome'] ?? 'success',
        ));

        $settlementKey = $payment->idempotency_key;
        $processedPayment = DB::transaction(function () use ($payment, $result, $settlementKey): Payment {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($payment->charging_session_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'pending' || $payment->idempotency_key !== $settlementKey) {
                return $payment->load(['organization', 'chargingSession', 'user']);
            }

            if ($result->successful) {
                $payment->update([
                    'status' => 'paid',
                    'provider_transaction_id' => $result->transactionId,
                    'metadata' => $result->metadata,
                    'paid_at' => now(),
                ]);

                if ($session->payment_status !== 'paid') {
                    $session->update(['payment_status' => 'paid']);
                }
            } else {
                $payment->update([
                    'status' => 'failed',
                    'failure_reason' => $result->failureReason,
                    'metadata' => $result->metadata,
                    'failed_at' => now(),
                ]);
                $session->update(['payment_status' => 'failed']);
            }

            return $payment->fresh()->load(['organization', 'chargingSession', 'user']);
        });

        $this->providerEvents->reconcileReference($processedPayment->reference);

        $processedPayment = $processedPayment->fresh()->load(['organization', 'chargingSession', 'user', 'latestProviderEvent']);
        if ($processedPayment->status === 'failed') {
            $this->notifications->notifyPaymentFailure($processedPayment);
        }

        return $processedPayment;
    }

    public function captureAuthorized(ChargingSession $session): ?Payment
    {
        $prepared = DB::transaction(function () use ($session): ?array {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($session->id);
            $attempt = ChargingAttempt::query()
                ->where('charging_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->provider_authorization_id === null) {
                return null;
            }
            $payment = Payment::query()
                ->where('charging_session_id', $session->id)
                ->lockForUpdate()
                ->first();
            if ($session->payment_status === 'paid') {
                return ['payment' => $payment, 'attempt' => $attempt, 'execute' => false];
            }
            if (! in_array($session->status, ['completed', 'interrupted'], true) || $session->meter_stop_kwh === null) {
                return null;
            }

            if ($payment !== null) {
                if ($payment->idempotency_key !== $attempt->capture_idempotency_key) {
                    throw ValidationException::withMessages([
                        'payment' => ['This session already has a different settlement operation. Manual reconciliation is required.'],
                    ]);
                }
                if ($payment->status === 'pending' || $payment->status === 'paid') {
                    return ['payment' => $payment, 'attempt' => $attempt, 'execute' => false];
                }
                if ($payment->status !== 'failed') {
                    throw ValidationException::withMessages([
                        'payment' => ['The existing payment cannot be captured in its current state.'],
                    ]);
                }

                $payment->update([
                    'provider' => $this->gateway->name(),
                    'method' => $attempt->payment_method,
                    'status' => 'pending',
                    'amount_millimes' => $session->total_millimes,
                    'currency' => $session->currency,
                    'provider_transaction_id' => null,
                    'failure_reason' => null,
                    'metadata' => null,
                    'paid_at' => null,
                    'failed_at' => null,
                ]);
                $payment = $payment->fresh();
            } else {
                $payment = Payment::query()->create([
                    'charging_session_id' => $session->id,
                    'organization_id' => $session->organization_id,
                    'user_id' => $session->client_id,
                    'reference' => 'PAY-'.Str::upper(Str::random(10)),
                    'provider' => $this->gateway->name(),
                    'method' => $attempt->payment_method,
                    'status' => 'pending',
                    'amount_millimes' => $session->total_millimes,
                    'currency' => $session->currency,
                    'idempotency_key' => $attempt->capture_idempotency_key,
                ]);
            }

            return ['payment' => $payment, 'attempt' => $attempt, 'execute' => true];
        });

        if ($prepared === null || $prepared['payment'] === null) {
            return null;
        }

        /** @var Payment $payment */
        $payment = $prepared['payment'];
        /** @var ChargingAttempt $attempt */
        $attempt = $prepared['attempt'];
        if (! $prepared['execute']) {
            return $payment;
        }

        $result = $this->gateway->capture(new PaymentCharge(
            paymentReference: $payment->reference,
            amountMillimes: $payment->amount_millimes,
            currency: $payment->currency,
            method: $payment->method,
            idempotencyKey: $payment->idempotency_key,
            simulationOutcome: $attempt->simulation_outcome,
        ), $attempt->provider_authorization_id);

        $processedPayment = DB::transaction(function () use ($payment, $attempt, $result): Payment {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($payment->charging_session_id);
            $attempt = ChargingAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'pending' || $payment->idempotency_key !== $attempt->capture_idempotency_key) {
                return $payment->load(['organization', 'chargingSession', 'user']);
            }

            if ($result->successful) {
                $payment->update([
                    'status' => 'paid',
                    'provider_transaction_id' => $result->transactionId,
                    'metadata' => $result->metadata,
                    'paid_at' => now(),
                    'failed_at' => null,
                ]);
                if ($session->payment_status !== 'paid') {
                    $session->update(['payment_status' => 'paid']);
                }
                $attempt->update(['payment_status' => 'captured', 'status' => 'completed', 'completed_at' => now()]);
            } else {
                $payment->update([
                    'status' => 'failed',
                    'failure_reason' => $result->failureReason,
                    'metadata' => $result->metadata,
                    'failed_at' => now(),
                ]);
                $session->update(['payment_status' => 'failed']);
                $attempt->update([
                    'payment_status' => 'capture_failed',
                    'status' => 'completed',
                    'failure_code' => 'payment_capture_failed',
                    'failure_message' => $result->failureReason,
                    'completed_at' => now(),
                ]);
            }

            event(ChargingSessionChanged::fromSession($session->fresh()));
            event(ChargingAttemptChanged::fromAttempt($attempt->fresh()));

            return $payment->fresh()->load(['organization', 'chargingSession', 'user']);
        });

        $this->providerEvents->reconcileReference($processedPayment->reference);

        $processedPayment = $processedPayment->fresh()->load(['organization', 'chargingSession', 'user', 'latestProviderEvent']);
        if ($processedPayment->status === 'failed') {
            $this->notifications->notifyPaymentFailure($processedPayment);
        }

        return $processedPayment;
    }

    private function hasUnsettledAuthorization(?ChargingAttempt $attempt): bool
    {
        return $attempt?->provider_authorization_id !== null
            && ! in_array($attempt->payment_status, ['failed', 'released'], true);
    }
}
