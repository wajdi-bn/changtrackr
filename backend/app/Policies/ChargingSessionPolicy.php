<?php

namespace App\Policies;

use App\Models\ChargingSession;
use App\Models\User;

class ChargingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sessions.view');
    }

    public function view(User $user, ChargingSession $session): bool
    {
        if (! $user->can('sessions.view')) {
            return false;
        }

        return $user->hasRole('super_admin')
            || ($user->hasRole('client') && $session->client_id === $user->id)
            || (! $user->hasRole('client') && $user->canAccessOrganization($session->organization_id));
    }

    public function create(User $user): bool
    {
        return $user->hasRole('client') && $user->can('sessions.start');
    }

    public function stop(User $user, ChargingSession $session): bool
    {
        if ($user->hasRole('client')) {
            return $user->can('sessions.stop') && $session->client_id === $user->id;
        }

        return $user->can('sessions.manage')
            && $user->canAccessOrganization($session->organization_id);
    }
}
