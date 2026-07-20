<?php

namespace App\Services\Payments;

use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Payment;
use App\Models\PaymentProviderEvent;
use App\Models\Station;
use App\Services\Notifications\OperationalNotificationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class PaymentProviderEventService
{
    public function __construct(
        private readonly PaymentWebhookSignature $signatures,
        private readonly OperationalNotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array{event: PaymentProviderEvent, duplicate: bool}
     */
    public function ingest(array $payload, ?string $signature): array
    {
        if (! $this->signatures->verify($payload, $signature)) {
            throw new UnauthorizedHttpException('PaymentWebhook', 'Invalid payment webhook signature.');
        }

        [$payment, $attempt] = $this->findTargets($payload);
        $event = PaymentProviderEvent::query()->firstOrCreate(
            ['provider' => 'wiremock', 'event_id' => $payload['event_id']],
            [
                'organization_id' => $payment?->organization_id ?? $attempt?->organization_id,
                'payment_id' => $payment?->id,
                'charging_attempt_id' => $attempt?->id,
                'type' => $payload['type'],
                'operation' => $payload['operation'],
                'status' => $payload['status'],
                'payment_reference' => $payload['payment_reference'] ?? null,
                'provider_transaction_id' => $payload['provider_transaction_id'] ?? null,
                'processing_status' => 'received',
                'payload' => $payload,
                'received_at' => now(),
            ],
        );
        $duplicate = ! $event->wasRecentlyCreated;

        if ($event->processing_status !== 'processed') {
            $this->reconcile($event);
        }

        return ['event' => $event->fresh(), 'duplicate' => $duplicate];
    }

    public function reconcileReference(string $paymentReference): void
    {
        PaymentProviderEvent::query()
            ->where('provider', 'wiremock')
            ->where('payment_reference', $paymentReference)
            ->whereIn('processing_status', ['received', 'pending_reconciliation'])
            ->orderBy('id')
            ->get()
            ->each(fn (PaymentProviderEvent $event) => $this->reconcile($event));
    }

    public function reconcile(PaymentProviderEvent $providerEvent): PaymentProviderEvent
    {
        $event = DB::transaction(function () use ($providerEvent): PaymentProviderEvent {
            $event = PaymentProviderEvent::query()->lockForUpdate()->findOrFail($providerEvent->id);
            if ($event->processing_status === 'processed') {
                return $event;
            }

            $payload = $event->payload;
            [$payment, $attempt] = $this->findTargets($payload, true);
            $event->fill([
                'organization_id' => $payment?->organization_id ?? $attempt?->organization_id,
                'payment_id' => $payment?->id,
                'charging_attempt_id' => $attempt?->id,
            ]);

            $outcome = match ($event->operation) {
                'authorize' => $this->reconcileAuthorization($event, $attempt),
                'capture', 'charge' => $this->reconcileSettlement($event, $payment, $attempt),
                'release' => $this->reconcileRelease($event, $attempt),
                default => 'requires_review',
            };

            $event->update([
                'processing_status' => $outcome,
                'processed_at' => $outcome === 'processed' ? now() : null,
                'error_message' => $outcome === 'requires_review'
                    ? 'The provider event conflicts with the current local payment state.'
                    : null,
            ]);

            return $event->fresh();
        });

        if (in_array($event->operation, ['capture', 'charge'], true)
            && in_array($event->status, ['declined', 'failed'], true)
            && $event->payment_id !== null) {
            $payment = Payment::query()->with(['user', 'chargingSession'])->find($event->payment_id);
            if ($payment?->status === 'failed') {
                $this->notifications->notifyPaymentFailure($payment);
            }
        }

        return $event;
    }

    private function reconcileAuthorization(PaymentProviderEvent $event, ?ChargingAttempt $attempt): string
    {
        if ($attempt === null) {
            return 'pending_reconciliation';
        }

        if (in_array($event->status, ['declined', 'failed'], true)) {
            if ($attempt->payment_status === 'pending') {
                $attempt->update([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                    'failure_code' => $event->payload['failure_code'] ?? 'payment_declined',
                    'failure_message' => $event->payload['failure_reason'] ?? 'The payment provider declined the authorization.',
                    'completed_at' => now(),
                ]);

                return 'processed';
            }

            return $attempt->payment_status === 'failed' ? 'processed' : 'requires_review';
        }

        return in_array($attempt->payment_status, ['authorized', 'captured', 'released'], true)
            ? 'processed'
            : 'pending_reconciliation';
    }

    private function reconcileSettlement(PaymentProviderEvent $event, ?Payment $payment, ?ChargingAttempt $attempt): string
    {
        if ($payment === null) {
            return 'pending_reconciliation';
        }

        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        $session = ChargingSession::query()->lockForUpdate()->findOrFail($payment->charging_session_id);

        if (in_array($event->status, ['declined', 'failed'], true)) {
            if ($payment->status === 'paid' || $session->payment_status === 'paid') {
                return 'requires_review';
            }

            $payment->update([
                'status' => 'failed',
                'provider_transaction_id' => $event->provider_transaction_id,
                'failure_reason' => $event->payload['failure_reason'] ?? 'The payment provider declined the payment.',
                'metadata' => [...($payment->metadata ?? []), 'provider_event_id' => $event->event_id],
                'failed_at' => now(),
            ]);
            $session->update(['payment_status' => 'failed']);
            if ($attempt !== null && $event->operation === 'capture') {
                $attempt->update([
                    'payment_status' => 'capture_failed',
                    'status' => 'completed',
                    'failure_code' => $event->payload['failure_code'] ?? 'payment_capture_failed',
                    'failure_message' => $event->payload['failure_reason'] ?? 'The final payment capture failed.',
                    'completed_at' => now(),
                ]);
            }

            return 'processed';
        }

        if ($payment->status !== 'paid') {
            $payment->update([
                'status' => 'paid',
                'provider_transaction_id' => $event->provider_transaction_id,
                'failure_reason' => null,
                'metadata' => [...($payment->metadata ?? []), 'provider_event_id' => $event->event_id],
                'paid_at' => now(),
                'failed_at' => null,
            ]);
        }
        if ($session->payment_status !== 'paid') {
            $session->update(['payment_status' => 'paid']);
            Station::query()->whereKey($session->station_id)->increment('revenue_today', $payment->amount_millimes / 1000);
        }
        if ($attempt !== null && $event->operation === 'capture') {
            $attempt->update(['payment_status' => 'captured', 'status' => 'completed', 'completed_at' => now()]);
        }

        return 'processed';
    }

    private function reconcileRelease(PaymentProviderEvent $event, ?ChargingAttempt $attempt): string
    {
        if ($attempt === null) {
            return 'pending_reconciliation';
        }
        if (in_array($attempt->payment_status, ['captured', 'capture_failed'], true)) {
            return 'requires_review';
        }

        $attempt->update(['payment_status' => $event->status === 'released' ? 'released' : 'release_failed']);

        return 'processed';
    }

    /** @param array<string, mixed> $payload
     * @return array{0: ?Payment, 1: ?ChargingAttempt}
     */
    private function findTargets(array $payload, bool $lock = false): array
    {
        $reference = (string) ($payload['payment_reference'] ?? '');
        $paymentQuery = Payment::query()->where('reference', $reference);
        $payment = $lock ? $paymentQuery->lockForUpdate()->first() : $paymentQuery->first();

        $attemptQuery = ChargingAttempt::query();
        if (str_starts_with($reference, 'ATT-')) {
            $attemptQuery->where('uuid', substr($reference, 4));
        } elseif (($payload['authorization_id'] ?? '') !== '') {
            $attemptQuery->where('provider_authorization_id', $payload['authorization_id']);
        } elseif ($payment !== null) {
            $attemptQuery->where('charging_session_id', $payment->charging_session_id);
        } else {
            return [$payment, null];
        }

        $attempt = $lock ? $attemptQuery->lockForUpdate()->first() : $attemptQuery->first();

        return [$payment, $attempt];
    }
}
