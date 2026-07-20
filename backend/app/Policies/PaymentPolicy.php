<?php

namespace App\Policies;

use App\Models\ChargingSession;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view') && (
            $user->hasRole('super_admin')
            || ($user->hasRole('client') && $payment->user_id === $user->id)
            || (! $user->hasRole('client') && $user->canAccessOrganization($payment->organization_id))
        );
    }

    public function pay(User $user, ChargingSession $session): bool
    {
        return $user->hasRole('client')
            && $user->can('payments.pay')
            && $session->client_id === $user->id;
    }

    public function export(User $user): bool
    {
        return $user->can('payments.view') && $user->can('reports.export');
    }
}
