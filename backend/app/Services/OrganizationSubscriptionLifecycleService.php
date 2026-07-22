<?php

namespace App\Services;

use App\Models\OrganizationInvoice;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Services\Notifications\OperationalNotificationService;
use Illuminate\Database\Eloquent\Collection;

class OrganizationSubscriptionLifecycleService
{
    public function __construct(
        private readonly OrganizationBillingService $billing,
        private readonly OperationalNotificationService $notifications,
    ) {}

    /** @return array{reminders:int,grace:int,suspended:int,overdue_invoices:int} */
    public function scan(): array
    {
        $result = ['reminders' => 0, 'grace' => 0, 'suspended' => 0, 'overdue_invoices' => 0];
        $result['overdue_invoices'] = OrganizationInvoice::query()
            ->where('status', 'open')
            ->where('due_at', '<', now())
            ->update(['status' => 'overdue', 'updated_at' => now()]);

        OrganizationSubscription::query()
            ->with(['organization', 'plan'])
            ->whereIn('status', ['trialing', 'active', 'past_due', 'grace_period'])
            ->chunkById(100, function (Collection $subscriptions) use (&$result): void {
                foreach ($subscriptions as $subscription) {
                    if ($this->sendUpcomingReminder($subscription)) {
                        $result['reminders']++;
                    }
                    if ($subscription->status === 'trialing' && $subscription->trial_ends_at?->isPast()) {
                        $this->billing->transition($subscription, 'grace_period', 'trial_expired', 'The trial ended and the commercial grace period started.');
                        $this->notifyAdmins($subscription, 'Trial ended', 'The organization is now in its grace period. Choose a plan before operational access is suspended.', 'warning', 'trial-ended');
                        $result['grace']++;
                    } elseif ($subscription->status === 'active' && $subscription->current_period_ends_at?->isPast()) {
                        $this->billing->transition($subscription, 'grace_period', 'subscription_expired', 'The paid period ended and the commercial grace period started.');
                        $this->notifyAdmins($subscription, 'Subscription renewal required', 'The paid period ended. Renew before the grace period expires.', 'warning', 'subscription-expired');
                        $result['grace']++;
                    } elseif ($subscription->status === 'grace_period' && $subscription->grace_ends_at?->isPast()) {
                        $this->billing->transition($subscription, 'suspended', 'grace_expired', 'The grace period ended and operational access was suspended.');
                        $this->notifyAdmins($subscription, 'Operational access suspended', 'The grace period ended. Billing and profile access remain available for renewal.', 'critical', 'commercial-suspended');
                        $result['suspended']++;
                    }
                }
            });

        return $result;
    }

    private function sendUpcomingReminder(OrganizationSubscription $subscription): bool
    {
        $deadline = $subscription->status === 'trialing' ? $subscription->trial_ends_at : $subscription->current_period_ends_at;
        if (! $deadline || $deadline->isPast()) {
            return false;
        }
        $days = (int) ceil(now()->diffInHours($deadline) / 24);
        if (! in_array($days, [7, 3, 1], true)) {
            return false;
        }
        $label = $subscription->status === 'trialing' ? 'trial' : 'subscription';
        $this->notifyAdmins(
            $subscription,
            ucfirst($label)." ends in {$days} day".($days === 1 ? '' : 's'),
            "Your ChargeTrackr {$label} ends on {$deadline->format('d M Y')}. Review the available organization plans.",
            $days === 1 ? 'warning' : 'info',
            "{$label}-ending-{$deadline->toDateString()}-{$days}",
        );

        return true;
    }

    private function notifyAdmins(OrganizationSubscription $subscription, string $title, string $message, string $severity, string $key): void
    {
        User::query()
            ->where('organization_id', $subscription->organization_id)
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->each(fn (User $user) => $this->notifications->notifyUser($user, [
                'category' => 'commercial',
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'action_url' => '/organization-billing',
                'entity_type' => OrganizationSubscription::class,
                'entity_id' => $subscription->id,
                'deduplication_key' => "commercial:{$subscription->id}:{$key}",
            ], ['in_app', 'email']));
    }
}
