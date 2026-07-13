<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Models\ChargingSession;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @param array{method:string, idempotency_key:string, simulation_outcome?:string} $attributes */
    public function process(User $user, ChargingSession $session, array $attributes): Payment
    {
        $payment = DB::transaction(function () use ($user, $session, $attributes): Payment {
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->status !== 'completed') {
                throw ValidationException::withMessages(['session' => ['Stop the charging session before payment.']]);
            }

            $payment = Payment::query()->where('charging_session_id', $session->id)->lockForUpdate()->first();
            if ($session->payment_status === 'paid' && $payment) {
                return $payment;
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

                return $payment->fresh();
            }

            return Payment::query()->create(['charging_session_id' => $session->id, ...$values]);
        });

        if ($payment->status === 'paid') {
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

        return DB::transaction(function () use ($payment, $result): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $session = ChargingSession::query()->lockForUpdate()->findOrFail($payment->charging_session_id);

            if ($result->successful) {
                $payment->update([
                    'status' => 'paid',
                    'provider_transaction_id' => $result->transactionId,
                    'metadata' => $result->metadata,
                    'paid_at' => now(),
                ]);

                if ($session->payment_status !== 'paid') {
                    $session->update(['payment_status' => 'paid']);
                    Station::query()->whereKey($session->station_id)->increment('revenue_today', $payment->amount_millimes / 1000);
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
    }
}
