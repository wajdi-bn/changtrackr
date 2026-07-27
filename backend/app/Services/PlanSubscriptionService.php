<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Models\ChargingPlan;
use App\Models\PlanSubscription;
use App\Models\PlanSubscriptionInvoice;
use App\Models\User;
use App\Services\Notifications\OperationalNotificationService;
use App\Services\Payments\PaymentProviderEventService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanSubscriptionService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentProviderEventService $providerEvents,
        private readonly OperationalNotificationService $notifications,
    ) {}

    public function subscribe(
        User $client,
        int $planId,
        bool $autoRenew,
        string $paymentMethod,
        string $idempotencyKey,
        string $simulationOutcome = 'success',
    ): PlanSubscription {
        $existingInvoice = PlanSubscriptionInvoice::query()
            ->where('user_id', $client->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existingInvoice?->status === 'paid' && $existingInvoice->plan_subscription_id !== null) {
            return PlanSubscription::query()
                ->with(['organization', 'chargingPlan', 'invoices'])
                ->findOrFail($existingInvoice->plan_subscription_id);
        }
        if ($existingInvoice !== null) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This payment request has already been processed. Start a new checkout attempt.'],
            ]);
        }

        $invoice = DB::transaction(function () use ($client, $planId, $paymentMethod, $idempotencyKey): PlanSubscriptionInvoice {
            User::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $plan = ChargingPlan::query()->with('organization')->lockForUpdate()->findOrFail($planId);
            $this->assertPlanCanBeSubscribedTo($plan);

            $current = $this->currentForOrganization($client->id, $plan->organization_id, true);
            if ($current?->charging_plan_id === $plan->id) {
                throw ValidationException::withMessages([
                    'charging_plan_id' => ['You are already subscribed to this plan.'],
                ]);
            }

            $startsAt = now();

            return PlanSubscriptionInvoice::query()->create([
                'organization_id' => $plan->organization_id,
                'user_id' => $client->id,
                'charging_plan_id' => $plan->id,
                'reference' => $this->newInvoiceReference(),
                'status' => 'pending',
                'billing_reason' => 'initial',
                'payment_provider' => $this->gateway->name(),
                'payment_method' => $paymentMethod,
                'idempotency_key' => $idempotencyKey,
                'amount_millimes' => $plan->monthly_fee_millimes,
                'currency' => 'TND',
                'period_starts_at' => $startsAt,
                'period_ends_at' => $startsAt->copy()->addMonthNoOverflow(),
                'due_at' => $startsAt,
                'metadata' => ['replaces_subscription_id' => $current?->id],
            ]);
        });

        $invoice = $this->chargeInvoice($invoice, $simulationOutcome);
        if ($invoice->status !== 'paid') {
            $this->throwPaymentFailure($invoice);
        }

        $subscription = DB::transaction(function () use ($client, $invoice, $autoRenew): PlanSubscription {
            $invoice = PlanSubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->plan_subscription_id !== null) {
                return PlanSubscription::query()->findOrFail($invoice->plan_subscription_id);
            }

            $current = $this->currentForOrganization($client->id, $invoice->organization_id, true);
            if ($current !== null) {
                $this->endLocked($current, 'cancelled');
            }

            $plan = ChargingPlan::query()->lockForUpdate()->findOrFail($invoice->charging_plan_id);
            $subscription = PlanSubscription::query()->create([
                'organization_id' => $invoice->organization_id,
                'user_id' => $client->id,
                'charging_plan_id' => $invoice->charging_plan_id,
                'status' => 'active',
                'auto_renew' => $autoRenew,
                'cancel_at_period_end' => false,
                'billing_provider' => $invoice->payment_provider,
                'payment_method' => $invoice->payment_method,
                'monthly_fee_millimes' => $invoice->amount_millimes,
                'discount_basis_points' => $plan->discount_basis_points,
                'starts_at' => $invoice->period_starts_at,
                'current_period_ends_at' => $invoice->period_ends_at,
                'last_renewed_at' => now(),
            ]);
            $invoice->update(['plan_subscription_id' => $subscription->id]);
            $this->syncMemberCount($plan->id);

            return $subscription;
        });

        $this->notifySubscriptionActivated($subscription);

        return $subscription->load(['organization', 'chargingPlan', 'invoices']);
    }

    public function requestCancellation(PlanSubscription $subscription): PlanSubscription
    {
        $subscription = DB::transaction(function () use ($subscription): PlanSubscription {
            $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if (! in_array($subscription->status, ['active', 'past_due'], true)) {
                throw ValidationException::withMessages([
                    'subscription' => ['Only a current subscription can be scheduled for cancellation.'],
                ]);
            }
            if ($subscription->cancel_at_period_end) {
                return $subscription;
            }

            $subscription->update([
                'auto_renew' => false,
                'cancel_at_period_end' => true,
                'cancellation_requested_at' => now(),
            ]);

            return $subscription;
        });

        return $subscription->load(['organization', 'chargingPlan', 'invoices']);
    }

    public function resume(PlanSubscription $subscription): PlanSubscription
    {
        $subscription = DB::transaction(function () use ($subscription): PlanSubscription {
            $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($subscription->status !== 'active' || ! $subscription->cancel_at_period_end) {
                throw ValidationException::withMessages([
                    'subscription' => ['Only an active subscription pending cancellation can be resumed.'],
                ]);
            }

            $subscription->update([
                'auto_renew' => true,
                'cancel_at_period_end' => false,
                'cancellation_requested_at' => null,
            ]);

            return $subscription;
        });

        return $subscription->load(['organization', 'chargingPlan', 'invoices']);
    }

    public function updateAutoRenew(PlanSubscription $subscription, bool $autoRenew): PlanSubscription
    {
        $subscription = DB::transaction(function () use ($subscription, $autoRenew): PlanSubscription {
            $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($subscription->status !== 'active') {
                throw ValidationException::withMessages([
                    'subscription' => ['Renewal preferences can only be changed for an active subscription.'],
                ]);
            }

            $subscription->update([
                'auto_renew' => $autoRenew,
                'cancel_at_period_end' => $autoRenew ? false : $subscription->cancel_at_period_end,
                'cancellation_requested_at' => $autoRenew ? null : $subscription->cancellation_requested_at,
            ]);

            return $subscription;
        });

        return $subscription->load(['organization', 'chargingPlan', 'invoices']);
    }

    public function retry(
        PlanSubscription $subscription,
        string $paymentMethod,
        string $idempotencyKey,
        string $simulationOutcome = 'success',
    ): PlanSubscription {
        $subscription = PlanSubscription::query()->findOrFail($subscription->id);
        if ($subscription->status !== 'past_due') {
            throw ValidationException::withMessages([
                'subscription' => ['Only a past-due subscription requires a payment retry.'],
            ]);
        }
        $subscription->update(['payment_method' => $paymentMethod]);

        return $this->renew($subscription, $idempotencyKey, $simulationOutcome);
    }

    /** @return array{renewed:int,past_due:int,expired:int} */
    public function scan(): array
    {
        $result = ['renewed' => 0, 'past_due' => 0, 'expired' => 0];

        PlanSubscription::query()
            ->whereIn('status', ['active', 'past_due'])
            ->where(function ($query): void {
                $query->where('current_period_ends_at', '<=', now())
                    ->orWhere('grace_ends_at', '<=', now());
            })
            ->orderBy('id')
            ->chunkById(100, function (Collection $subscriptions) use (&$result): void {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->status === 'past_due' && $subscription->grace_ends_at?->isPast()) {
                        $this->expire($subscription);
                        $result['expired']++;

                        continue;
                    }
                    if ($subscription->status !== 'active' || ! $subscription->current_period_ends_at?->isPast()) {
                        continue;
                    }
                    if ($subscription->cancel_at_period_end || ! $subscription->auto_renew) {
                        $this->expire($subscription);
                        $result['expired']++;

                        continue;
                    }

                    $periodKey = $subscription->current_period_ends_at->format('YmdHis');
                    try {
                        $renewed = $this->renew(
                            $subscription,
                            $this->deterministicUuid("subscription-renewal:{$subscription->id}:{$periodKey}"),
                        );
                        $result[$renewed->status === 'active' ? 'renewed' : 'past_due']++;
                    } catch (ValidationException) {
                        $result['past_due']++;
                    }
                }
            });

        return $result;
    }

    private function renew(
        PlanSubscription $subscription,
        string $idempotencyKey,
        string $simulationOutcome = 'success',
    ): PlanSubscription {
        $invoice = DB::transaction(function () use ($subscription, $idempotencyKey): PlanSubscriptionInvoice {
            $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $existing = PlanSubscriptionInvoice::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $startsAt = $subscription->current_period_ends_at->copy();

            return PlanSubscriptionInvoice::query()->create([
                'organization_id' => $subscription->organization_id,
                'user_id' => $subscription->user_id,
                'charging_plan_id' => $subscription->charging_plan_id,
                'plan_subscription_id' => $subscription->id,
                'reference' => $this->newInvoiceReference(),
                'status' => 'pending',
                'billing_reason' => 'renewal',
                'payment_provider' => $this->gateway->name(),
                'payment_method' => $subscription->payment_method,
                'idempotency_key' => $idempotencyKey,
                'amount_millimes' => $subscription->monthly_fee_millimes,
                'currency' => 'TND',
                'period_starts_at' => $startsAt,
                'period_ends_at' => $startsAt->copy()->addMonthNoOverflow(),
                'due_at' => now(),
            ]);
        });

        if ($invoice->status === 'pending') {
            $invoice = $this->chargeInvoice($invoice, $simulationOutcome);
        }

        if ($invoice->status !== 'paid') {
            $subscription = DB::transaction(function () use ($subscription): PlanSubscription {
                $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
                $pastDueAt = $subscription->past_due_at ?? now();
                $subscription->update([
                    'status' => 'past_due',
                    'past_due_at' => $pastDueAt,
                    'grace_ends_at' => $subscription->grace_ends_at
                        ?? $pastDueAt->copy()->addDays(max(1, (int) config('payments.subscription_grace_days', 3))),
                ]);
                $this->syncMemberCount($subscription->charging_plan_id);

                return $subscription;
            });
            $this->notifyPaymentFailed($subscription, $invoice);
            $this->throwPaymentFailure($invoice);
        }

        $subscription = DB::transaction(function () use ($subscription, $invoice): PlanSubscription {
            $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $invoice = PlanSubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($subscription->current_period_ends_at?->greaterThanOrEqualTo($invoice->period_ends_at)) {
                return $subscription;
            }

            $subscription->update([
                'status' => 'active',
                'auto_renew' => true,
                'cancel_at_period_end' => false,
                'payment_method' => $invoice->payment_method,
                'current_period_ends_at' => $invoice->period_ends_at,
                'past_due_at' => null,
                'grace_ends_at' => null,
                'last_renewed_at' => now(),
            ]);
            $this->syncMemberCount($subscription->charging_plan_id);

            return $subscription;
        });

        return $subscription->load(['organization', 'chargingPlan', 'invoices']);
    }

    private function chargeInvoice(PlanSubscriptionInvoice $invoice, string $simulationOutcome): PlanSubscriptionInvoice
    {
        if ($invoice->amount_millimes === 0) {
            $invoice->update([
                'status' => 'paid',
                'provider_transaction_id' => 'FREE-'.$invoice->reference,
                'paid_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
            ]);

            return $invoice->fresh();
        }

        $result = $this->gateway->charge(new PaymentCharge(
            paymentReference: $invoice->reference,
            amountMillimes: $invoice->amount_millimes,
            currency: $invoice->currency,
            method: $invoice->payment_method,
            idempotencyKey: $invoice->idempotency_key,
            simulationOutcome: $simulationOutcome,
        ));

        $invoice = DB::transaction(function () use ($invoice, $result): PlanSubscriptionInvoice {
            $invoice = PlanSubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'paid') {
                return $invoice;
            }

            $invoice->update($result->successful ? [
                'status' => 'paid',
                'provider_transaction_id' => $result->transactionId,
                'paid_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
                'failure_reason' => null,
                'metadata' => [...($invoice->metadata ?? []), ...$result->metadata],
            ] : [
                'status' => 'failed',
                'provider_transaction_id' => $result->transactionId,
                'failed_at' => now(),
                'failure_code' => $result->metadata['error_code'] ?? 'payment_declined',
                'failure_reason' => $result->failureReason ?? 'The payment provider rejected this payment.',
                'metadata' => [...($invoice->metadata ?? []), ...$result->metadata],
            ]);

            return $invoice->fresh();
        });

        $this->providerEvents->reconcileReference($invoice->reference);

        return $invoice->fresh();
    }

    private function expire(PlanSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $subscription = PlanSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if (! in_array($subscription->status, ['active', 'past_due'], true)) {
                return;
            }
            $this->endLocked($subscription, 'expired');
            $this->syncMemberCount($subscription->charging_plan_id);
        });
    }

    private function endLocked(PlanSubscription $subscription, string $status): void
    {
        $endedAt = now();
        $subscription->update([
            'status' => $status,
            'auto_renew' => false,
            'cancel_at_period_end' => false,
            'cancelled_at' => $status === 'cancelled' ? $endedAt : $subscription->cancelled_at,
            'ended_at' => $endedAt,
            'current_period_ends_at' => $endedAt,
        ]);
        $this->syncMemberCount($subscription->charging_plan_id);
    }

    private function currentForOrganization(int $userId, int $organizationId, bool $lock = false): ?PlanSubscription
    {
        $query = PlanSubscription::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->current();

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    private function syncMemberCount(int $planId): void
    {
        $count = PlanSubscription::query()
            ->where('charging_plan_id', $planId)
            ->current()
            ->count();
        ChargingPlan::query()->whereKey($planId)->update(['member_count' => $count]);
    }

    private function assertPlanCanBeSubscribedTo(ChargingPlan $plan): void
    {
        if ($plan->status !== 'active' || $plan->organization?->status !== 'active') {
            throw ValidationException::withMessages([
                'charging_plan_id' => ['This plan is not currently available.'],
            ]);
        }
        if ($plan->monthly_fee_millimes === 0 && $plan->discount_basis_points === 0) {
            throw ValidationException::withMessages([
                'charging_plan_id' => ['This pay-as-you-go plan does not require a subscription.'],
            ]);
        }
    }

    private function newInvoiceReference(): string
    {
        return 'CPS-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function deterministicUuid(string $value): string
    {
        $hex = substr(hash('sha256', $value), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }

    private function throwPaymentFailure(PlanSubscriptionInvoice $invoice): never
    {
        throw ValidationException::withMessages([
            'payment' => [$invoice->failure_reason ?: 'The subscription payment could not be completed.'],
        ]);
    }

    private function notifySubscriptionActivated(PlanSubscription $subscription): void
    {
        $subscription->loadMissing(['user', 'organization', 'chargingPlan']);
        $this->notifications->notifyUser($subscription->user, [
            'category' => 'commercial',
            'severity' => 'success',
            'title' => 'Charging plan activated',
            'message' => "{$subscription->chargingPlan->name} is now active for {$subscription->organization->name}.",
            'action_url' => '/subscriptions',
            'entity_type' => PlanSubscription::class,
            'entity_id' => $subscription->id,
            'deduplication_key' => "client-subscription:{$subscription->id}:activated",
        ], ['in_app']);
    }

    private function notifyPaymentFailed(PlanSubscription $subscription, PlanSubscriptionInvoice $invoice): void
    {
        $subscription->loadMissing(['user', 'chargingPlan']);
        $this->notifications->notifyUser($subscription->user, [
            'category' => 'commercial',
            'severity' => 'warning',
            'title' => 'Plan renewal payment failed',
            'message' => "Retry payment for {$subscription->chargingPlan->name} before the grace period ends.",
            'action_url' => '/subscriptions',
            'entity_type' => PlanSubscriptionInvoice::class,
            'entity_id' => $invoice->id,
            'deduplication_key' => "client-subscription-invoice:{$invoice->id}:failed",
        ], ['in_app', 'email']);
    }
}
