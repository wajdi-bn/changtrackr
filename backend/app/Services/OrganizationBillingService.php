<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\PaymentCharge;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationSubscription;
use App\Models\SaasPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationBillingService
{
    public function __construct(
        private readonly PlatformSettingService $settings,
        private readonly PaymentGateway $gateway,
    ) {}

    public function createTrial(Organization $organization, ?User $actor = null, ?int $trialDays = null): OrganizationSubscription
    {
        return DB::transaction(function () use ($organization, $actor, $trialDays): OrganizationSubscription {
            $plan = $this->defaultPlan();
            $startsAt = now();
            $subscription = OrganizationSubscription::query()->updateOrCreate(
                ['organization_id' => $organization->id],
                [
                    'saas_plan_id' => $plan->id,
                    'status' => 'trialing',
                    'billing_cycle' => 'monthly',
                    'source' => 'demo_request',
                    'auto_renew' => false,
                    'trial_started_at' => $startsAt,
                    'trial_ends_at' => $startsAt->copy()->addDays($trialDays ?? $this->settings->integer('organization_trial_days')),
                    'current_period_starts_at' => null,
                    'current_period_ends_at' => null,
                    'grace_ends_at' => null,
                    'suspended_at' => null,
                    'cancelled_at' => null,
                ],
            );
            $this->event($subscription, $actor, 'trial_started', null, 'trialing', 'Organization trial created.');

            return $subscription->load(['plan', 'organization']);
        });
    }

    public function requestPlan(
        Organization $organization,
        User $actor,
        SaasPlan $plan,
        string $billingCycle,
        string $paymentMethod = 'simulated_card',
        ?string $idempotencyKey = null,
        string $simulationOutcome = 'success',
    ): OrganizationInvoice {
        if ($plan->status !== 'active') {
            throw ValidationException::withMessages(['saas_plan_id' => ['This plan is not available.']]);
        }

        $idempotencyKey ??= (string) Str::uuid();
        $existing = OrganizationInvoice::query()
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing->load(['organization', 'subscription.plan', 'plan', 'requestedBy', 'settledBy']);
        }

        $invoice = DB::transaction(function () use ($organization, $actor, $plan, $billingCycle, $paymentMethod, $idempotencyKey, $simulationOutcome): OrganizationInvoice {
            $subscription = OrganizationSubscription::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $openInvoice = OrganizationInvoice::query()
                ->where('organization_id', $organization->id)
                ->whereIn('status', ['open', 'overdue'])
                ->lockForUpdate()
                ->first();
            if ($openInvoice) {
                throw ValidationException::withMessages(['invoice' => ['Resolve the existing open invoice before starting another checkout.']]);
            }

            $periodStartsAt = now();
            $periodEndsAt = $billingCycle === 'annual'
                ? $periodStartsAt->copy()->addYear()
                : $periodStartsAt->copy()->addMonth();
            $amount = $billingCycle === 'annual' ? $plan->annual_price_millimes : $plan->monthly_price_millimes;

            $invoice = OrganizationInvoice::query()->create([
                'organization_id' => $organization->id,
                'organization_subscription_id' => $subscription->id,
                'saas_plan_id' => $plan->id,
                'requested_by_id' => $actor->id,
                'number' => $this->invoiceNumber(),
                'status' => 'open',
                'billing_cycle' => $billingCycle,
                'amount_millimes' => $amount,
                'currency' => 'TND',
                'period_starts_at' => $periodStartsAt,
                'period_ends_at' => $periodEndsAt,
                'due_at' => now(),
                'payment_provider' => $this->gateway->name(),
                'payment_method' => $paymentMethod,
                'idempotency_key' => $idempotencyKey,
                'snapshot' => [
                    'plan_name' => $plan->name,
                    'plan_code' => $plan->code,
                    'max_stations' => $plan->max_stations,
                    'max_employees' => $plan->max_employees,
                    'features' => $plan->features,
                    'simulation_outcome' => $simulationOutcome,
                ],
            ]);
            $this->event($subscription, $actor, 'checkout_started', $subscription->status, $subscription->status, "Started {$plan->name} checkout ({$billingCycle}).", ['invoice_id' => $invoice->id]);

            return $invoice;
        });

        $result = $this->gateway->charge(new PaymentCharge(
            paymentReference: $invoice->number,
            amountMillimes: $invoice->amount_millimes,
            currency: $invoice->currency,
            method: $paymentMethod,
            idempotencyKey: $idempotencyKey,
            simulationOutcome: $simulationOutcome,
        ));

        return DB::transaction(function () use ($invoice, $actor, $result): OrganizationInvoice {
            $invoice = OrganizationInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'open') {
                return $invoice->load(['organization', 'subscription.plan', 'plan', 'requestedBy', 'settledBy']);
            }
            $subscription = OrganizationSubscription::query()->lockForUpdate()->findOrFail($invoice->organization_subscription_id);
            if (! $result->successful) {
                $invoice->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'failure_reason' => $result->failureReason ?: 'The simulated payment provider rejected the transaction.',
                    'provider_reference' => $result->transactionId,
                    'provider_metadata' => $result->metadata,
                ]);
                $this->event($subscription, $actor, 'invoice_payment_failed', $subscription->status, $subscription->status, "Payment failed for invoice {$invoice->number}.", ['invoice_id' => $invoice->id]);

                return $invoice->fresh(['organization', 'subscription.plan', 'plan', 'requestedBy', 'settledBy']);
            }

            $previousStatus = $subscription->status;
            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'settled_by_id' => $actor->id,
                'provider_reference' => $result->transactionId,
                'provider_metadata' => $result->metadata,
            ]);
            $subscription->update([
                'saas_plan_id' => $invoice->saas_plan_id,
                'status' => 'active',
                'billing_cycle' => $invoice->billing_cycle,
                'source' => 'simulated_payment',
                'auto_renew' => true,
                'current_period_starts_at' => $invoice->period_starts_at,
                'current_period_ends_at' => $invoice->period_ends_at,
                'grace_ends_at' => null,
                'suspended_at' => null,
                'cancelled_at' => null,
            ]);
            $this->event($subscription, $actor, 'invoice_paid', $previousStatus, 'active', "Invoice {$invoice->number} paid through the payment simulator.", ['invoice_id' => $invoice->id, 'provider_reference' => $result->transactionId]);

            return $invoice->fresh(['organization', 'subscription.plan', 'plan', 'requestedBy', 'settledBy']);
        });
    }

    public function settleInvoice(OrganizationInvoice $invoice, User $actor): OrganizationInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): OrganizationInvoice {
            $invoice = OrganizationInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, ['open', 'overdue', 'failed'], true)) {
                throw ValidationException::withMessages(['invoice' => ['Only an open, overdue or failed invoice can be corrected as settled.']]);
            }
            $subscription = OrganizationSubscription::query()->lockForUpdate()->findOrFail($invoice->organization_subscription_id);
            $previousStatus = $subscription->status;
            $providerReference = 'SIM-'.strtoupper(Str::random(12));
            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'settled_by_id' => $actor->id,
                'payment_provider' => 'simulated',
                'provider_reference' => $providerReference,
            ]);
            $subscription->update([
                'saas_plan_id' => $invoice->saas_plan_id,
                'status' => 'active',
                'billing_cycle' => $invoice->billing_cycle,
                'source' => 'simulated_payment',
                'auto_renew' => true,
                'current_period_starts_at' => $invoice->period_starts_at,
                'current_period_ends_at' => $invoice->period_ends_at,
                'grace_ends_at' => null,
                'suspended_at' => null,
                'cancelled_at' => null,
            ]);
            $this->event($subscription, $actor, 'invoice_settled', $previousStatus, 'active', "Invoice {$invoice->number} settled through the payment simulator.", ['invoice_id' => $invoice->id, 'provider_reference' => $providerReference]);

            return $invoice->fresh(['organization', 'subscription.plan', 'plan', 'requestedBy', 'settledBy']);
        });
    }

    public function voidInvoice(OrganizationInvoice $invoice, User $actor): OrganizationInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): OrganizationInvoice {
            $invoice = OrganizationInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, ['open', 'overdue', 'failed'], true)) {
                throw ValidationException::withMessages(['invoice' => ['Only an open, overdue or failed invoice can be voided.']]);
            }
            $subscription = OrganizationSubscription::query()->lockForUpdate()->findOrFail($invoice->organization_subscription_id);
            $invoice->update(['status' => 'void', 'settled_by_id' => $actor->id]);
            $this->event($subscription, $actor, 'invoice_voided', $subscription->status, $subscription->status, "Invoice {$invoice->number} was voided.", ['invoice_id' => $invoice->id]);

            return $invoice->fresh(['organization', 'subscription.plan', 'plan', 'requestedBy', 'settledBy']);
        });
    }

    public function extendTrial(OrganizationSubscription $subscription, User $actor, int $days, ?string $note): OrganizationSubscription
    {
        if (! in_array($subscription->status, ['trialing', 'grace_period'], true) || $subscription->current_period_ends_at !== null) {
            throw ValidationException::withMessages(['subscription' => ['Only a trial can be extended.']]);
        }
        $previousStatus = $subscription->status;
        $base = $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at->copy() : now();
        $subscription->update([
            'status' => 'trialing',
            'trial_ends_at' => $base->addDays($days),
            'grace_ends_at' => null,
            'suspended_at' => null,
        ]);
        $this->event($subscription, $actor, 'trial_extended', $previousStatus, 'trialing', $note ?: "Trial extended by {$days} days.", ['days' => $days]);

        return $subscription->fresh(['organization', 'plan', 'events.actor']);
    }

    public function suspend(OrganizationSubscription $subscription, User $actor, ?string $note): OrganizationSubscription
    {
        if ($subscription->status === 'suspended') {
            throw ValidationException::withMessages(['subscription' => ['This organization is already suspended.']]);
        }
        $previousStatus = $subscription->status;
        $subscription->update(['status' => 'suspended', 'suspended_at' => now()]);
        $this->event($subscription, $actor, 'manually_suspended', $previousStatus, 'suspended', $note ?: 'Commercial access suspended by a platform administrator.');

        return $subscription->fresh(['organization', 'plan', 'events.actor']);
    }

    public function restore(OrganizationSubscription $subscription, User $actor, ?string $note): OrganizationSubscription
    {
        if ($subscription->status !== 'suspended') {
            throw ValidationException::withMessages(['subscription' => ['Only a suspended subscription can be restored.']]);
        }
        $nextStatus = match (true) {
            $subscription->current_period_ends_at?->isFuture() => 'active',
            $subscription->current_period_ends_at === null && $subscription->trial_ends_at?->isFuture() => 'trialing',
            default => 'grace_period',
        };
        $subscription->update([
            'status' => $nextStatus,
            'suspended_at' => null,
            'grace_ends_at' => $nextStatus === 'grace_period' ? now()->addDays($this->settings->integer('organization_grace_days')) : null,
        ]);
        $this->event($subscription, $actor, 'manually_restored', 'suspended', $nextStatus, $note ?: 'Commercial access restored by a platform administrator.');

        return $subscription->fresh(['organization', 'plan', 'events.actor']);
    }

    public function transition(OrganizationSubscription $subscription, string $nextStatus, string $event, string $note): OrganizationSubscription
    {
        $previousStatus = $subscription->status;
        $attributes = ['status' => $nextStatus];
        if ($nextStatus === 'grace_period') {
            $attributes['grace_ends_at'] = now()->addDays($this->settings->integer('organization_grace_days'));
        }
        if ($nextStatus === 'suspended') {
            $attributes['suspended_at'] = now();
        }
        $subscription->update($attributes);
        $this->event($subscription, null, $event, $previousStatus, $nextStatus, $note);

        return $subscription->fresh(['organization', 'plan']);
    }

    private function defaultPlan(): SaasPlan
    {
        return SaasPlan::query()->where('code', 'BUSINESS')->where('status', 'active')->first()
            ?? SaasPlan::query()->where('status', 'active')->orderBy('sort_order')->first()
            ?? SaasPlan::query()->create([
                'name' => 'Business',
                'code' => 'BUSINESS',
                'description' => 'Operations, maintenance and analytics for a growing network.',
                'monthly_price_millimes' => 399000,
                'annual_price_millimes' => 3990000,
                'max_stations' => 50,
                'max_employees' => 25,
                'features' => ['Live station monitoring', 'Remote OCPP operations', 'Advanced analytics and exports'],
                'is_featured' => true,
                'status' => 'active',
                'sort_order' => 20,
            ]);
    }

    /** @param array<string, mixed> $metadata */
    private function event(OrganizationSubscription $subscription, ?User $actor, string $event, ?string $from, ?string $to, ?string $note = null, array $metadata = []): void
    {
        $subscription->events()->create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function invoiceNumber(): string
    {
        do {
            $number = 'CT-'.now()->format('Ym').'-'.strtoupper(Str::random(7));
        } while (OrganizationInvoice::query()->where('number', $number)->exists());

        return $number;
    }
}
