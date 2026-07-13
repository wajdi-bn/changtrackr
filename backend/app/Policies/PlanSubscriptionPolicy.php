<?php

namespace App\Policies;

use App\Models\PlanSubscription;
use App\Models\User;

class PlanSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('client') && $user->can('subscriptions.view');
    }

    public function view(User $user, PlanSubscription $subscription): bool
    {
        return $this->viewAny($user) && $subscription->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('client') && $user->can('subscriptions.manage');
    }

    public function update(User $user, PlanSubscription $subscription): bool
    {
        return $user->can('subscriptions.manage')
            && $subscription->user_id === $user->id
            && $subscription->status === 'active';
    }

    public function delete(User $user, PlanSubscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }
}
